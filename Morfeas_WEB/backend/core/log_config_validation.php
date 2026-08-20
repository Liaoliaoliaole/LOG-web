<?php

require_once __DIR__ . '/opcua_config.php'; // ChannelConfigException

/*
 * Whole-document validator for a Morfeas_Config.xml candidate, built for
 * FTP Restore (plan §10.0.3): the candidate must be provably safe to write
 * before it's ever committed to disk, the same standard iso_validate_document()
 * already holds OPC_UA_Config.xml to.
 *
 * Scope note: this is deliberately NOT the full daemon-config contract
 * described in plan §10.0.1 (CAN bus ownership rules, Disable-flag
 * combination semantics, per-component supported/unsupported enforcement).
 * Those need real hardware to verify against Core's actual startup
 * behavior and are not required to make FTP Restore's write path safe, so
 * they are out of scope here. This function covers exactly two things:
 *
 *   1. Structural validity -- via genuine DTD validation, not a hand-rolled
 *      re-implementation. Core's own Morfeas_XML_parsing() parses BOTH
 *      Morfeas_Config.xml and OPC_UA_Config.xml with the libxml
 *      XML_PARSE_DTDVALID flag and checks ctxt->valid (Morfeas_XML.c:176,
 *      184) -- DTD validation is not an approximation of what Core does,
 *      it is what Core does, for both files, so applying it here first is
 *      exactly Core-equivalent rather than stricter or looser.
 *   2. The two semantic rules plan §10.0.3 calls out that DTD cannot
 *      express (DTD's #PCDATA permits empty content, and has no notion of
 *      uniqueness or length limits): no empty leaf element, IOBOX/MTI
 *      DEV_NAME/IPv4_ADDR must be unique, and DEV_NAME must fit Core's
 *      Dev_or_Bus_name_str_size (IFNAMSIZ) buffer.
 */

/*
 * Resolves "Morfeas.dtd" SYSTEM ID references against a real file on disk
 * so DOMDocument::validate() can run against an in-memory XML string (an
 * FTP-downloaded candidate that was never written to $dtdDir itself). Must
 * be installed before validate() is called and removed afterward -- it is
 * process-global state, not scoped to one DOMDocument.
 */
function log_config_install_dtd_entity_loader(string $dtdDir): void
{
    libxml_set_external_entity_loader(static function (?string $public, ?string $system, array $context) use ($dtdDir) {
        if (is_string($system) && basename($system) === 'Morfeas.dtd') {
            $path = rtrim($dtdDir, '/') . '/Morfeas.dtd';
            if (is_file($path)) {
                return fopen($path, 'r');
            }
        }
        return null;
    });
}

function log_config_restore_default_entity_loader(): void
{
    libxml_set_external_entity_loader(null);
}

function log_config_dev_name_max_length(): int
{
    // Dev_or_Bus_name_str_size == IFNAMSIZ (Morfeas_IPC.h:20), a POSIX
    // system constant (net/if.h), not something Core's own repo assigns a
    // numeric value to -- see coreConstantsConsistencyTest.php's F-8 note
    // for the same reasoning applied to the Web strict parsers. 16 has been
    // unchanged across every glibc/Linux version this product targets.
    return 16;
}

/*
 * Shared DTD structural validation for either config document. Core's
 * Morfeas_XML_parsing() parses BOTH files with XML_PARSE_DTDVALID and checks
 * ctxt->valid (Morfeas_XML.c:176/184), so a document that fails here is one
 * Core would refuse to load at all -- for OPC_UA_Config.xml that means an
 * empty ISO object list after the next restart, i.e. the original incident.
 *
 * $expectedRoot is checked explicitly rather than left to the DTD: a
 * document declaring a DOCTYPE whose name does not match its root element,
 * or no DOCTYPE at all, must not slip through (F-12).
 */
function log_config_validate_dtd_structure(DOMDocument $dom, string $dtdDir, string $expectedRoot, string $label): void
{
    $rootName = $dom->documentElement !== null ? $dom->documentElement->nodeName : '';
    if ($rootName !== $expectedRoot) {
        throw new ChannelConfigException(
            "$label has the wrong root element: expected <$expectedRoot>, got "
                . ($rootName === '' ? '(none)' : "<$rootName>"),
            409,
            'invalid_document_structure'
        );
    }

    if ($dom->doctype === null || $dom->doctype->systemId === null || trim((string)$dom->doctype->systemId) === '') {
        throw new ChannelConfigException(
            "$label is missing its DOCTYPE declaration",
            409,
            'invalid_document_structure'
        );
    }
    if ($dom->doctype->name !== $expectedRoot) {
        throw new ChannelConfigException(
            "$label declares DOCTYPE {$dom->doctype->name} but its root element is <$expectedRoot>",
            409,
            'invalid_document_structure'
        );
    }

    log_config_install_dtd_entity_loader($dtdDir);
    libxml_use_internal_errors(true);
    try {
        $valid = $dom->validate();
        $errors = libxml_get_errors();
        libxml_clear_errors();
    } finally {
        log_config_restore_default_entity_loader();
    }

    if (!$valid) {
        $first = $errors[0] ?? null;
        $detail = $first ? trim((string)$first->message) : 'unknown structural error';
        throw new ChannelConfigException(
            "$label failed DTD structural validation: $detail",
            409,
            'invalid_document_structure'
        );
    }
}

/*
 * Core's is_valid_IPv4() equivalent (Morfeas_XML.c:1151 call site). Core
 * rejects the whole config when an IOBOX/MTI/MDAQ handler's IPv4_ADDR is not
 * a valid address, so accepting one here would let FTP Restore write a file
 * Core refuses to start on (F-14).
 */
function log_config_ipv4_is_valid(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

/*
 * Core scans DEV_NAME for space, single quote and double quote and rejects
 * the whole config if any is present (Morfeas_XML.c:1160-1170).
 */
function log_config_dev_name_illegal_char(string $name): ?string
{
    foreach ([' ' => 'space', "'" => "single quote", '"' => 'double quote'] as $ch => $desc) {
        if (strpos($name, $ch) !== false) {
            return $desc;
        }
    }
    return null;
}

function log_config_validate_document(DOMDocument $dom, string $dtdDir): void
{
    log_config_validate_dtd_structure($dom, $dtdDir, 'CONFIG', 'Morfeas_Config.xml');

    $xpath = new DOMXPath($dom);

    // C-1 equivalent: DTD's #PCDATA allows empty content, but an empty
    // leaf (DEV_NAME, IPv4_ADDR, CANBUS_IF, APP_NAME, ...) is as fatal to
    // Core's handlers as an empty OPC_UA_Config.xml element is.
    foreach ($xpath->query('//*[not(*)]') as $leaf) {
        /** @var DOMElement $leaf */
        if (trim($leaf->textContent) === '') {
            throw new ChannelConfigException(
                'Morfeas_Config.xml element ' . $leaf->nodeName . ' must not be empty',
                409,
                'empty_element'
            );
        }
    }

    // Core rejects an APP_NAME containing a space (Morfeas_XML.c:1037).
    foreach ($xpath->query('//OPC_UA_SERVER/APP_NAME') as $node) {
        if (strpos($node->textContent, ' ') !== false) {
            throw new ChannelConfigException(
                'APP_NAME must not contain whitespace: ' . trim($node->textContent),
                409,
                'invalid_app_name'
            );
        }
    }

    // Core scans MDAQ_HANDLER, IOBOX_HANDLER and MTI_HANDLER together for
    // IPv4 validity, DEV_NAME legality/length and duplicates
    // (Morfeas_XML.c:1147-1200). MDAQ is included here for the same reason
    // Core includes it: the DTD still permits an MDAQ_HANDLER element, and a
    // historical .mbl may well carry one -- retiring MDAQ removed it as a
    // *channel anchor* interface, not as a daemon-config handler element.
    $maxNameLen = log_config_dev_name_max_length();
    $handlers = $xpath->query('//MDAQ_HANDLER | //IOBOX_HANDLER | //MTI_HANDLER');

    $seenNames = [];
    $seenIps = [];
    foreach ($handlers as $handler) {
        /** @var DOMElement $handler */
        $nameNode = $handler->getElementsByTagName('DEV_NAME')->item(0);
        $ipNode = $handler->getElementsByTagName('IPv4_ADDR')->item(0);
        $name = $nameNode ? trim($nameNode->textContent) : '';
        $ip = $ipNode ? trim($ipNode->textContent) : '';

        if (!log_config_ipv4_is_valid($ip)) {
            throw new ChannelConfigException(
                "IPv4_ADDR is not a valid IPv4 address: " . ($ip === '' ? '(empty)' : $ip),
                409,
                'invalid_device_ipv4'
            );
        }

        $illegal = log_config_dev_name_illegal_char($name);
        if ($illegal !== null) {
            throw new ChannelConfigException(
                "DEV_NAME contains an illegal character ($illegal): $name",
                409,
                'invalid_device_name'
            );
        }
        if (strlen($name) >= $maxNameLen) {
            throw new ChannelConfigException(
                "DEV_NAME is too long (>= $maxNameLen bytes): $name",
                409,
                'invalid_device_name'
            );
        }

        // Core compares with strcmp(), i.e. case-SENSITIVE exact match. This
        // deliberately mirrors that rather than folding case: this validator
        // gates a *restore* of an existing configuration, so being stricter
        // than Core would false-reject a backup Core itself would load
        // happily -- the failure mode the E8 hardware check exists to catch.
        if ($name !== '') {
            if (isset($seenNames[$name])) {
                throw new ChannelConfigException("Duplicate device DEV_NAME: $name", 409, 'duplicate_device_name');
            }
            $seenNames[$name] = true;
        }
        if (isset($seenIps[$ip])) {
            throw new ChannelConfigException("Duplicate device IPv4_ADDR: $ip", 409, 'duplicate_device_ipv4');
        }
        $seenIps[$ip] = true;
    }
}
