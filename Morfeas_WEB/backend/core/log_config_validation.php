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

function log_config_validate_document(DOMDocument $dom, string $dtdDir): void
{
    if ($dom->doctype === null || $dom->doctype->systemId === null || trim((string)$dom->doctype->systemId) === '') {
        throw new ChannelConfigException(
            'Morfeas_Config.xml is missing its DOCTYPE declaration',
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
            "Morfeas_Config.xml failed DTD structural validation: $detail",
            409,
            'invalid_document_structure'
        );
    }

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

    $maxNameLen = log_config_dev_name_max_length();
    foreach ($xpath->query('//DEV_NAME') as $node) {
        $name = trim($node->textContent);
        if (strlen($name) >= $maxNameLen) {
            throw new ChannelConfigException(
                "DEV_NAME is too long (>= $maxNameLen bytes): $name",
                409,
                'invalid_device_name'
            );
        }
    }

    $seenNames = [];
    $seenIps = [];
    foreach ($xpath->query('//IOBOX_HANDLER | //MTI_HANDLER') as $handler) {
        /** @var DOMElement $handler */
        $nameNode = $handler->getElementsByTagName('DEV_NAME')->item(0);
        $ipNode = $handler->getElementsByTagName('IPv4_ADDR')->item(0);
        $name = $nameNode ? trim($nameNode->textContent) : '';
        $ip = $ipNode ? trim($ipNode->textContent) : '';

        $nameKey = strtolower($name);
        if ($name !== '') {
            if (isset($seenNames[$nameKey])) {
                throw new ChannelConfigException("Duplicate device DEV_NAME: $name", 409, 'duplicate_device_name');
            }
            $seenNames[$nameKey] = true;
        }
        if ($ip !== '') {
            if (isset($seenIps[$ip])) {
                throw new ChannelConfigException("Duplicate device IPv4_ADDR: $ip", 409, 'duplicate_device_ipv4');
            }
            $seenIps[$ip] = true;
        }
    }
}
