<?php

require_once __DIR__ . '/opcua_config.php'; // ChannelConfigException

/*
 * Final-byte validator for Morfeas_Config.xml. It mirrors deterministic Core
 * validation rules; restore compatibility requires equivalence, not stricter
 * Web-only rules. Runtime hardware state is intentionally out of scope.
 */

/* Validate in-memory candidates against the installed DTD; libxml state is global. */
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
    // IFNAMSIZ / Dev_or_Bus_name_str_size from the Core IPC contract.
    return 16;
}

/* Core accepts 16 bytes despite its message; Restore warns instead of rejecting it. */
function log_config_dev_name_is_too_long(string $name): bool
{
    return strlen($name) > log_config_dev_name_max_length();
}

/* New names are capped at 15 bytes: 16 breaks IOBOX or truncates MTI IPC identity. */
function log_config_dev_name_safe_max_length(): int
{
    return log_config_dev_name_max_length() - 1;
}

/* Match Core: first direct child only, with raw untrimmed content. */
function log_config_child_text(DOMElement $parent, string $tag): ?string
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $tag) {
            return $child->textContent;
        }
    }
    return null;
}

/* Core requires a valid DTD, matching root and matching DOCTYPE. */
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

/* This PHP IPv4 parser was verified equivalent to Core's inet_pton(AF_INET). */
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

/* Match Core exactly: only literal Disable="true" disables a component. */
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

/* DTD permits any Disable text; Core accepts only literal true or false. */
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
 * Core has three distinct raw, case-sensitive CANBUS_IF duplicate passes:
 * SDAQ-only, NOX-only, then all enabled handlers.
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

/* Core validates MDAQ/IOBOX/MTI handler IP and name as raw text. */
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

/* Core-valid but unsafe legacy conditions require acknowledgement at Restore. */
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
