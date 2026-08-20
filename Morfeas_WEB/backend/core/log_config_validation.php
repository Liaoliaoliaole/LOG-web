<?php

require_once __DIR__ . '/opcua_config.php'; // ChannelConfigException

/*
 * Whole-document validator for a Morfeas_Config.xml candidate, built for
 * FTP Restore (plan §10.0.3) and, since F-15, also run over the result of
 * every write that ADDS a component (Device Add, CAN role change): the
 * document must be provably safe to write before it is ever committed to
 * disk, the same standard iso_validate_document() already holds
 * OPC_UA_Config.xml to.
 *
 * Scope (plan §10.0.9 F-14, completed 2026-08-20): this now covers every
 * DETERMINISTIC rule in Core's Morfeas_daemon_config_valid()
 * (Morfeas_XML.c:973-1210), rule for rule:
 *
 *   Core                                    | here
 *   ----------------------------------------|--------------------------------
 *   XML_PARSE_DTDVALID at parse (XML.c:176) | log_config_validate_dtd_structure()
 *   scaning_XML_nodes_for_empty()  (:978)   | empty-leaf pass
 *   Disable attribute range        (:1013)  | log_config_validate_disable_attributes()
 *   APP_NAME whitespace            (:1037)  | APP_NAME pass
 *   SDAQ_HANDLER dup CANBUS_IF     (:1052)  | log_config_validate_can_bus_usage() pass 1
 *   NOX_HANDLER dup CANBUS_IF      (:1080)  | log_config_validate_can_bus_usage() pass 2
 *   cross-handler dup CANBUS_IF    (:1110)  | log_config_validate_can_bus_usage() pass 3
 *   MDAQ/IOBOX/MTI IPv4+DEV_NAME   (:1139)  | handler pass
 *
 * The passes run in Core's own order and each pass mirrors Core's own loop
 * shape (nested "compare against every LATER sibling" rather than a
 * single-pass seen-map), so the FIRST error reported here is the same one
 * Core would print -- which matters, because that string is what the
 * operator sees in the preflight report and then has to reconcile against
 * Core's journal if they restore anyway.
 *
 * The remaining §10.0.1 items -- per-component supported/unsupported
 * enforcement and the runtime meaning of a Disable combination -- are NOT
 * deterministic document rules; they depend on what hardware answers, and
 * Core itself does not check them at config-validation time. They stay out
 * of scope here on purpose.
 *
 * The governing principle throughout is EQUIVALENCE, not strictness: this
 * validator gates the restore of an existing configuration, so a rule
 * stricter than Core's would false-reject a backup Core itself loads
 * happily. Every deliberate deviation is called out at its own site, and
 * there are exactly two (the whitespace-only leaf, and the 16-byte
 * DEV_NAME reported as a warning by
 * log_config_collect_document_warnings()).
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
 * Core's DEV_NAME length rule, transcribed from the loop that implements it
 * rather than from the error string it prints (Morfeas_XML.c:1160-1180):
 *
 *     for(int i=0; dev_name[i]!='\0'; i++) {
 *         if(dev_name[i]==' '||...) { reject }
 *         if(i>=Dev_or_Bus_name_str_size) { reject "too long (>=16)" }
 *     }
 *
 * The bound is tested against the INDEX, so the largest index ever reached
 * for a 16-byte name is 15 and the check never fires: Core's daemon-config
 * validator ACCEPTS 16 bytes and first rejects at 17. The message it prints
 * (">=16") describes a rule the code does not implement. Verified by
 * compiling the loop verbatim against the real net/if.h.
 *
 * So 16 bytes must not be a hard error here -- rejecting it would
 * false-reject a document Core loads. It is not harmless either (see
 * log_config_collect_document_warnings()), which is why it is a warning,
 * and why the Device Add writer applies its own stricter rule to names it
 * is CREATING (log_config_dev_name_safe_max_length()).
 */
function log_config_dev_name_is_too_long(string $name): bool
{
    return strlen($name) > log_config_dev_name_max_length();
}

/*
 * The limit the Web applies to a DEV_NAME it is about to CREATE, as opposed
 * to one it is being asked to accept from an existing document. 15 bytes,
 * i.e. IFNAMSIZ-1, because at exactly 16 the two handler binaries disagree
 * with each other and with the daemon-config validator:
 *
 *   Morfeas_IOBOX_if.c:110   strlen(dev_name) >= 16  -> handler EXITS
 *   Morfeas_MTI_if.c:169     strlen(dev_name) >  16  -> handler starts,
 *                            then memccpy(..., 16) + [15]='\0' silently
 *                            TRUNCATES the name to 15 bytes in every IPC
 *                            message, so the device reports itself under a
 *                            different name than the config gives it
 *   Morfeas_XML.c:1160       index-based             -> config ACCEPTED
 *
 * A 16-byte name therefore produces a config that loads cleanly and a
 * device that either never comes up (IOBOX) or comes up under the wrong
 * identity (MTI). Nothing should be creating one; existing ones are
 * surfaced as a warning instead of being rejected outright.
 */
function log_config_dev_name_safe_max_length(): int
{
    return log_config_dev_name_max_length() - 1;
}

/*
 * Core reads element content with XML_node_get_content() (Morfeas_XML.c:114),
 * which returns the content of the FIRST DIRECT CHILD element with that
 * name -- not a recursive descendant search, and with no trimming of any
 * kind. Both properties matter, so both are reproduced here rather than
 * using getElementsByTagName() (recursive) + trim() (see the call sites for
 * what trimming would cost).
 */
function log_config_child_text(DOMElement $parent, string $tag): ?string
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $tag) {
            return $child->textContent;
        }
    }
    return null;
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
 * Core's is_valid_IPv4() (Morfeas_run_check.c:102) is a bare
 * inet_pton(AF_INET). filter_var(FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) was
 * checked against it case for case -- including the ones that usually
 * separate two IPv4 parsers: leading zeros ("01.2.3.4", "192.168.000.1"),
 * short forms ("127.1"), hex ("0x7f.0.0.1"), a bare integer
 * ("2130706433"), and surrounding whitespace (" 1.2.3.4", "1.2.3.4\n").
 * Both reject all of them, both accept "0.0.0.0" and "255.255.255.255".
 * The whitespace cases are why this is called with untrimmed content.
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

/*
 * Whether a COMPONENTS child is switched off, mirroring Core's
 * getprop_disable() (Morfeas_XML.c:141): only the exact string "true"
 * disables. Note what this deliberately does NOT do: no strtolower(), no
 * trim(). "TRUE" and " true" are not "true" to strcmp(), so to Core they
 * are out-of-range values that
 * log_config_validate_disable_attributes() has already rejected -- they
 * must never be silently read as "disabled" here.
 *
 * An ABSENT attribute means enabled, and not because of a guess: the DTD
 * declares `Disable CDATA "false"` as a default value for all five handler
 * types, and xmlGetProp() falls back to the DTD default when the attribute
 * is not on the node. Confirmed by running Core's exact parse
 * (XML_PARSE_DTDVALID|XML_PARSE_NOBLANKS) over a config with the attribute
 * omitted: xmlGetProp() returns "false". This is also why Core's
 * "Unknown Attribute found" branch (Morfeas_XML.c:1024) is unreachable for
 * a DTD-valid document and has no counterpart here.
 */
function log_config_component_is_disabled(DOMElement $node): bool
{
    return $node->getAttribute('Disable') === 'true';
}

function log_config_components_element(DOMDocument $dom): ?DOMElement
{
    $root = $dom->documentElement;
    if ($root === null) {
        return null;
    }
    foreach ($root->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === 'COMPONENTS') {
            return $child;
        }
    }
    return null;
}

/**
 * The direct element children of COMPONENTS, in document order -- exactly
 * what Core walks with `xml_node = components_head_node->children` while
 * skipping non-element nodes.
 *
 * @return DOMElement[]
 */
function log_config_component_nodes(DOMDocument $dom): array
{
    $components = log_config_components_element($dom);
    if ($components === null) {
        return [];
    }

    $out = [];
    foreach ($components->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $out[] = $child;
        }
    }
    return $out;
}

/*
 * Core (Morfeas_XML.c:1013-1030) walks every element child of COMPONENTS and
 * rejects the whole config if its Disable attribute is neither "true" nor
 * "false". The DTD cannot catch this: it declares Disable as CDATA, so
 * Disable="maybe" is structurally valid and passes DTD validation -- verified
 * against the real DTD. Without this pass a bundle carrying Disable="yes"
 * is written happily and then Core refuses to start.
 *
 * Only a PRESENT attribute is checked. Absent means the DTD default
 * "false", which Core sees through xmlGetProp() -- see
 * log_config_component_is_disabled().
 */
function log_config_validate_disable_attributes(DOMDocument $dom): void
{
    foreach (log_config_component_nodes($dom) as $node) {
        if (!$node->hasAttribute('Disable')) {
            continue;
        }
        $value = $node->getAttribute('Disable');
        if ($value !== 'true' && $value !== 'false') {
            throw new ChannelConfigException(
                'Attribute Disable="' . $value . '" on ' . $node->nodeName
                    . ' is out of range (must be exactly "true" or "false")',
                409,
                'invalid_disable_attribute'
            );
        }
    }
}

/*
 * Core's three separate CANBUS_IF duplicate scans (Morfeas_XML.c:1052-1136),
 * kept separate here because they do NOT have the same rule:
 *
 *   1. SDAQ_HANDLER vs SDAQ_HANDLER -- Disable is IGNORED.
 *   2. NOX_HANDLER  vs NOX_HANDLER  -- Disable is IGNORED.
 *   3. any handler  vs any handler  -- ENABLED nodes only (getprop_disable).
 *
 * Pass 3 alone would not be equivalent: two SDAQ_HANDLERs on can0 with one
 * of them Disable="true" are skipped by pass 3 and still rejected by Core
 * in pass 1. That combination is not hypothetical -- the field config on
 * the two production LOGs carries a Disable="true" SDAQ_HANDLER (vcan0)
 * alongside its live can0/can1 ones, so a backup taken there is exactly one
 * "duplicate the disabled bus" edit away from the case pass 3 misses.
 *
 * Comparison is strcmp() on raw content: case-sensitive, untrimmed. "can0"
 * and "CAN0" are two different buses to Core, and normalizing them here
 * (as the repository layer's read path does for display purposes) would
 * invent a duplicate Core does not see.
 */
function log_config_validate_can_bus_usage(DOMDocument $dom): void
{
    $nodes = log_config_component_nodes($dom);
    $count = count($nodes);

    foreach (['SDAQ_HANDLER', 'NOX_HANDLER'] as $tag) {
        for ($i = 0; $i < $count; $i++) {
            if ($nodes[$i]->nodeName !== $tag) {
                continue;
            }
            $bus = log_config_child_text($nodes[$i], 'CANBUS_IF');
            if ($bus === null) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if ($nodes[$j]->nodeName !== $tag) {
                    continue;
                }
                if (log_config_child_text($nodes[$j], 'CANBUS_IF') === $bus) {
                    throw new ChannelConfigException(
                        "CANBUS_IF \"$bus\" is used by more than one $tag",
                        409,
                        'duplicate_can_bus'
                    );
                }
            }
        }
    }

    for ($i = 0; $i < $count; $i++) {
        if (log_config_component_is_disabled($nodes[$i])) {
            continue;
        }
        $bus = log_config_child_text($nodes[$i], 'CANBUS_IF');
        if ($bus === null) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if (log_config_component_is_disabled($nodes[$j])) {
                continue;
            }
            if (log_config_child_text($nodes[$j], 'CANBUS_IF') === $bus) {
                throw new ChannelConfigException(
                    "CANBUS_IF \"$bus\" is used by multiple enabled handlers ("
                        . $nodes[$i]->nodeName . ' and ' . $nodes[$j]->nodeName . ')',
                    409,
                    'duplicate_can_bus'
                );
            }
        }
    }
}

/*
 * Core's MDAQ/IOBOX/MTI pass (Morfeas_XML.c:1139-1205). MDAQ is included
 * here for the same reason Core includes it: the DTD still permits an
 * MDAQ_HANDLER element, and a historical .mbl may well carry one --
 * retiring MDAQ removed it as a *channel anchor* interface, not as a
 * daemon-config handler element.
 *
 * Content is read RAW. An earlier revision trimmed DEV_NAME and IPv4_ADDR
 * before checking them, which broke equivalence in both directions at once:
 *
 *   - too loose: "IOBOX1 " (trailing space) trimmed to "IOBOX1" passes the
 *     illegal-character scan, while Core scans the raw bytes, finds the
 *     space, and refuses to start -- the exact class of divergence F-14 was
 *     opened for. Same for " 10.0.0.1", which trims into a valid address
 *     but is not one to inet_pton().
 *   - too strict: "Box" and "Box " are one trimmed string and two distinct
 *     strings to strcmp(), so trimming invented a duplicate Core does not
 *     see and false-rejected a restorable backup.
 *
 * Duplicate detection uses Core's nested "compare against every later
 * sibling" shape rather than a seen-map so that the first error reported is
 * the one Core reports, IPv4 before DEV_NAME, on a document that violates
 * several rules at once.
 */
function log_config_validate_ip_handlers(DOMDocument $dom): void
{
    $ipTags = ['MDAQ_HANDLER' => true, 'IOBOX_HANDLER' => true, 'MTI_HANDLER' => true];
    $nodes = log_config_component_nodes($dom);
    $count = count($nodes);
    $maxNameLen = log_config_dev_name_max_length();

    for ($i = 0; $i < $count; $i++) {
        if (!isset($ipTags[$nodes[$i]->nodeName])) {
            continue;
        }
        $ip = (string)log_config_child_text($nodes[$i], 'IPv4_ADDR');
        $name = (string)log_config_child_text($nodes[$i], 'DEV_NAME');

        if (!log_config_ipv4_is_valid($ip)) {
            throw new ChannelConfigException(
                'IPv4_ADDR is not a valid IPv4 address: ' . ($ip === '' ? '(empty)' : $ip),
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
        if (log_config_dev_name_is_too_long($name)) {
            throw new ChannelConfigException(
                "DEV_NAME is too long (> $maxNameLen bytes): $name",
                409,
                'invalid_device_name'
            );
        }

        for ($j = $i + 1; $j < $count; $j++) {
            if (!isset($ipTags[$nodes[$j]->nodeName])) {
                continue;
            }
            if ((string)log_config_child_text($nodes[$j], 'IPv4_ADDR') === $ip) {
                throw new ChannelConfigException("Duplicate device IPv4_ADDR: $ip", 409, 'duplicate_device_ipv4');
            }
            if ((string)log_config_child_text($nodes[$j], 'DEV_NAME') === $name) {
                throw new ChannelConfigException("Duplicate device DEV_NAME: $name", 409, 'duplicate_device_name');
            }
        }
    }
}

/*
 * Non-blocking findings: things Core's daemon-config validator accepts, so
 * they must not stop a restore, but that produce a configuration which does
 * not do what its author wrote down. Same treatment as F-13's orphan
 * channels -- reported, and ftp_backup_restore_commit() requires explicit
 * acknowledgement before it will proceed.
 *
 * Currently one rule: a DEV_NAME of exactly IFNAMSIZ bytes. See
 * log_config_dev_name_safe_max_length() for why 16 is the trap value and
 * why it cannot be a hard error.
 */
function log_config_collect_document_warnings(DOMDocument $dom): array
{
    $ipTags = ['MDAQ_HANDLER' => true, 'IOBOX_HANDLER' => true, 'MTI_HANDLER' => true];
    $warnings = [];

    foreach (log_config_component_nodes($dom) as $node) {
        if (!isset($ipTags[$node->nodeName])) {
            continue;
        }
        $name = (string)log_config_child_text($node, 'DEV_NAME');
        if (strlen($name) !== log_config_dev_name_max_length()) {
            continue;
        }
        $warnings[] = [
            'code' => 'dev_name_at_ifnamsiz',
            'message' => $node->nodeName . " DEV_NAME \"$name\" is exactly "
                . log_config_dev_name_max_length() . ' bytes. Core loads this config, but the'
                . ' IOBOX handler refuses to start at this length and the MTI handler truncates'
                . ' the name to ' . log_config_dev_name_safe_max_length()
                . ' bytes in every IPC message, so the device will not appear under this name.',
        ];
    }

    return $warnings;
}

function log_config_validate_document(DOMDocument $dom, string $dtdDir): void
{
    log_config_validate_dtd_structure($dom, $dtdDir, 'CONFIG', 'Morfeas_Config.xml');

    // Core's scaning_XML_nodes_for_empty() (Morfeas_XML.c:71) returns the
    // first element that has NO child nodes at all; DTD validation cannot
    // catch it because #PCDATA permits empty content.
    //
    // The one deliberate deviation: a whitespace-only leaf ("<CANBUS_IF>
    // </CANBUS_IF>") has a text child, so Core walks past it, and this
    // rejects it. That is stricter than Core by exactly four elements'
    // worth -- CANBUS_IF and the three *_DIR nodes, since a whitespace-only
    // DEV_NAME is caught by the illegal-character scan and a whitespace-only
    // IPv4_ADDR/APP_NAME by their own rules -- and in each of those four a
    // whitespace-only value yields a handler or a path Core cannot use. No
    // writer in this codebase can emit one, so the false-rejection risk the
    // rest of this file is shaped around does not apply.
    $xpath = new DOMXPath($dom);
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

    log_config_validate_disable_attributes($dom);

    // Core rejects an APP_NAME containing a space (Morfeas_XML.c:1037),
    // using strstr() on the raw content -- so, as everywhere else in this
    // file, no trim() before the check.
    $components = log_config_components_element($dom);
    if ($components !== null) {
        foreach ($components->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->nodeName !== 'OPC_UA_SERVER') {
                continue;
            }
            $appName = (string)log_config_child_text($child, 'APP_NAME');
            if (strpos($appName, ' ') !== false) {
                throw new ChannelConfigException(
                    'APP_NAME must not contain whitespace: ' . $appName,
                    409,
                    'invalid_app_name'
                );
            }
        }
    }

    log_config_validate_can_bus_usage($dom);
    log_config_validate_ip_handlers($dom);
}
