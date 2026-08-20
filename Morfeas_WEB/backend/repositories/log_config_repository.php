<?php

require_once __DIR__ . '/../core/concurrency.php';
require_once __DIR__ . '/../core/log_config_validation.php';

/*
 * F-15: until 2026-08-20 log_config_validate_document() was reachable only
 * from FTP Restore, so the ordinary Device Add and CAN role writers could
 * put a document on disk that Core refuses to start on -- and then restart
 * Core into it. Every writer below that ADDS a component now re-validates
 * the exact bytes it is about to write, inside the same lock, against the
 * same Core-equivalence rules an FTP candidate has to pass.
 *
 * Deliberately not applied to the removal paths (log_config_delete_devices(),
 * and the FREE branch of log_config_set_can_role()). Removing a component
 * cannot introduce a violation, and a config that is already invalid -- a
 * hand-edited file, or one written before these rules existed -- must not
 * become impossible to repair through the UI. Blocking the fix for the
 * problem would be worse than the problem.
 *
 * Validation runs on a re-parse of the serialized string rather than on the
 * live DOMDocument, so what is checked is byte for byte what
 * backend_atomic_write_file() then writes.
 */
function log_config_validate_before_write(string $xmlString, string $xmlPath): void
{
    $dtdDir = dirname($xmlPath);
    if (!is_file(rtrim($dtdDir, '/') . '/Morfeas.dtd')) {
        // Distinguished from a real structural failure on purpose: this is
        // a broken installation, not a bad edit, and the two need very
        // different responses from whoever reads the error.
        throw new ChannelConfigException(
            "Cannot validate the new Morfeas_Config.xml: Morfeas.dtd is missing from $dtdDir",
            500,
            'dtd_unavailable'
        );
    }

    $check = new DOMDocument('1.0');
    libxml_use_internal_errors(true);
    $loaded = $check->loadXML($xmlString);
    libxml_clear_errors();
    if (!$loaded) {
        throw new ChannelConfigException(
            'The updated Morfeas_Config.xml is not well-formed XML; nothing was written',
            500,
            'invalid_document_structure'
        );
    }

    log_config_validate_document($check, $dtdDir);
}

/*
 * Uniqueness for a device being CREATED, evaluated against the DOM already
 * loaded inside the log_config lock.
 *
 * This used to live in api_devices.php, outside the lock, while the in-lock
 * check compared only the fully composed id ("IOBOX:-:Name") -- so two
 * requests could both pass the outside check and the second would still
 * satisfy the in-lock one whenever the ids differed (same name, different
 * IP, or vice versa), writing a config Core rejects and then restarting
 * Core into it (F-15). There is now exactly one implementation of the rule
 * and it runs where it can actually hold.
 *
 * Names are compared case-INSENSITIVELY, which is stricter than Core's
 * strcmp(). That is deliberate and specific to creation: the Web's own
 * runtime map keys handlers by strtoupper(identifier)
 * (device_build_runtime_maps()), so "Box" and "box" would be one entry
 * there and the two devices would shadow each other's connection status.
 * log_config_validate_document(), which judges documents that already
 * exist, stays case-sensitive like Core.
 */
function log_config_find_device_conflict(DOMElement $components, string $name, string $ip): ?string
{
    foreach ($components->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if (!in_array(strtoupper($node->tagName), ['IOBOX_HANDLER', 'MTI_HANDLER'], true)) {
            continue;
        }

        $existingName = log_config_dom_text_or_empty($node, 'DEV_NAME');
        $existingIp = log_config_dom_text_or_empty($node, 'IPv4_ADDR');

        if ($existingName !== '' && strcasecmp($existingName, $name) === 0) {
            return "Device name already exists: $name";
        }
        if ($existingIp !== '' && $existingIp === $ip) {
            return "Device IPv4 already exists: $ip";
        }
    }

    return null;
}

function log_config_dom_text_or_empty(DOMElement $parent, string $tag): string
{
    $node = $parent->getElementsByTagName($tag)->item(0);
    return $node ? trim($node->nodeValue) : '';
}

/**
 * Parse the XML once and return all derived views together.
 *
 * Returns:
 *   manual_devices   — same shape as log_config_load_manual_devices()
 *   can_handlers     — same shape as log_config_load_can_handlers()
 *   component_count  — same value as log_config_count_components()
 *   has_legacy_mdaq  — same value as log_config_has_legacy_mdaq()
 */
function log_config_read_all(string $xmlPath): array
{
    $empty = [
        'manual_devices'  => [],
        'can_handlers'    => [],
        'component_count' => 0,
        'has_legacy_mdaq' => false,
    ];

    if (!is_file($xmlPath)) {
        return $empty;
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return $empty;
    }

    $manualDevices  = [];
    $canHandlers    = [];
    $componentCount = 0;
    $hasLegacyMdaq  = false;

    foreach ($components->children() as $comp) {
        $tag     = strtoupper($comp->getName());
        $disable = strtolower((string) $comp['Disable']) === 'true';
        $status  = $disable ? 'Disabled' : 'Okay';
        $componentCount++;

        switch ($tag) {
            case 'IOBOX_HANDLER':
            case 'MTI_HANDLER':
                $type = str_replace('_HANDLER', '', $tag);
                $name = trim((string) $comp->DEV_NAME);
                $ip   = trim((string) $comp->IPv4_ADDR);
                $bus  = '-';
                $manualDevices[] = [
                    'id'     => log_config_build_manual_id($type, $bus, $name, $ip),
                    'type'   => $type,
                    'bus'    => $bus,
                    'ip'     => $ip,
                    'name'   => $name,
                    'status' => $status,
                    'origin' => 'xml',
                ];
                break;

            case 'NOX_HANDLER':
                $bus  = trim((string) $comp->CANBUS_IF);
                $name = $bus;
                $manualDevices[] = [
                    'id'     => log_config_build_manual_id('NOX', $bus, $name, ''),
                    'type'   => 'NOX',
                    'bus'    => $bus,
                    'ip'     => '',
                    'name'   => $name,
                    'status' => $status,
                    'origin' => 'xml',
                ];
                $busKey = strtolower(trim($bus));
                if ($busKey !== '') {
                    $canHandlers[] = [
                        'tag'     => $tag,
                        'mode'    => 'NOX',
                        'bus'     => $busKey,
                        'enabled' => !$disable,
                        'status'  => $status,
                    ];
                }
                break;

            case 'SDAQ_HANDLER':
                $busKey = strtolower(trim((string) $comp->CANBUS_IF));
                if ($busKey !== '') {
                    $canHandlers[] = [
                        'tag'     => $tag,
                        'mode'    => 'SDAQ',
                        'bus'     => $busKey,
                        'enabled' => !$disable,
                        'status'  => $status,
                    ];
                }
                break;

            case 'MDAQ_HANDLER':
                $hasLegacyMdaq = true;
                break;
        }
    }

    return [
        'manual_devices'  => $manualDevices,
        'can_handlers'    => $canHandlers,
        'component_count' => $componentCount,
        'has_legacy_mdaq' => $hasLegacyMdaq,
    ];
}

function log_config_has_component_tag(string $xmlPath, string $tagName): bool
{
    if (!is_file($xmlPath)) {
        return false;
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return false;
    }

    $wanted = strtoupper(trim($tagName));
    foreach ($components->children() as $comp) {
        if (strtoupper($comp->getName()) === $wanted) {
            return true;
        }
    }

    return false;
}

function log_config_has_legacy_mdaq(string $xmlPath): bool
{
    return log_config_has_component_tag($xmlPath, 'MDAQ_HANDLER');
}

function log_config_load_manual_devices(string $xmlPath): array
{
    if (!is_file($xmlPath)) {
        return [];
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return [];
    }

    $out = [];
    foreach ($components->children() as $comp) {
        $tag = strtoupper($comp->getName());
        $disable = strtolower((string)$comp['Disable']) === 'true';
        $status = $disable ? 'Disabled' : 'Okay';

        switch ($tag) {
            case 'IOBOX_HANDLER':
            case 'MTI_HANDLER':
                $type = str_replace('_HANDLER', '', $tag);
                $name = trim((string)$comp->DEV_NAME);
                $ip   = trim((string)$comp->IPv4_ADDR);
                $bus  = '-';
                $out[] = [
                    'id'     => log_config_build_manual_id($type, $bus, $name, $ip),
                    'type'   => $type,
                    'bus'    => $bus,
                    'ip'     => $ip,
                    'name'   => $name,
                    'status' => $status,
                    'origin' => 'xml',
                ];
                break;
            case 'NOX_HANDLER':
                $type = 'NOX';
                $bus  = trim((string)$comp->CANBUS_IF);
                $name = $bus;
                $ip   = '';
                $out[] = [
                    'id'     => log_config_build_manual_id($type, $bus, $name, $ip),
                    'type'   => $type,
                    'bus'    => $bus,
                    'ip'     => $ip,
                    'name'   => $name,
                    'status' => $status,
                    'origin' => 'xml',
                ];
                break;
            default:
                break;
        }
    }

    return $out;
}

function log_config_load_can_handlers(string $xmlPath): array
{
    if (!is_file($xmlPath)) {
        return [];
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException('Failed to parse LOG config XML');
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return [];
    }

    $out = [];
    foreach ($components->children() as $comp) {
        $tag = strtoupper($comp->getName());
        if (!in_array($tag, ['SDAQ_HANDLER', 'NOX_HANDLER'], true)) {
            continue;
        }

        $bus = strtolower(trim((string) $comp->CANBUS_IF));
        if ($bus === '') {
            continue;
        }

        $disabled = strtolower((string) $comp['Disable']) === 'true';
        $out[] = [
            'tag' => $tag,
            'mode' => str_replace('_HANDLER', '', $tag),
            'bus' => $bus,
            'enabled' => !$disabled,
            'status' => $disabled ? 'Disabled' : 'Okay',
        ];
    }

    return $out;
}

function log_config_count_components(string $xmlPath): int
{
    if (!is_file($xmlPath)) {
        return 0;
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        return 0;
    }

    $components = $xml->COMPONENTS ?? null;
    if ($components === null) {
        return 0;
    }

    $count = 0;
    foreach ($components->children() as $_) {
        $count++;
    }

    return $count;
}

function log_config_build_manual_id(string $type, string $bus, string $name, string $ip): string
{
    $type = strtoupper($type);
    $bus  = $bus !== '' ? $bus : '-';
    $label = $name !== '' ? $name : ($ip !== '' ? $ip : uniqid('DEV'));

    return implode(':', [$type, $bus, $label]);
}

function log_config_with_xml_lock(string $xmlPath, callable $fn)
{
    return backend_with_resource_file_lock('log_config', $xmlPath, $fn);
}

function log_config_component_order(string $tag): int
{
    static $order = [
        'OPC_UA_SERVER' => 0,
        'SDAQ_HANDLER' => 1,
        'MDAQ_HANDLER' => 2,
        'IOBOX_HANDLER' => 3,
        'MTI_HANDLER' => 4,
        'NOX_HANDLER' => 5,
    ];

    return $order[strtoupper($tag)] ?? 99;
}

function log_config_insert_component_ordered(DOMElement $components, DOMElement $node): void
{
    $newOrder = log_config_component_order($node->tagName);
    foreach ($components->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        if (log_config_component_order($child->tagName) > $newOrder) {
            $components->insertBefore($node, $child);
            return;
        }
    }

    $components->appendChild($node);
}

function log_config_remove_can_role_nodes(DOMElement $components, string $bus): int
{
    $removed = 0;
    $normalizedBus = strtolower(trim($bus));
    $children = $components->childNodes;

    for ($i = $children->length - 1; $i >= 0; $i--) {
        $node = $children->item($i);
        if (!$node instanceof DOMElement) {
            continue;
        }

        $tag = strtoupper($node->tagName);
        if (!in_array($tag, ['SDAQ_HANDLER', 'NOX_HANDLER'], true)) {
            continue;
        }

        if (strtolower(log_config_dom_text_or_empty($node, 'CANBUS_IF')) !== $normalizedBus) {
            continue;
        }

        $components->removeChild($node);
        $removed++;
    }

    return $removed;
}

function log_config_set_can_role(string $xmlPath, string $bus, string $role): array
{
    return log_config_with_xml_lock($xmlPath, function () use ($xmlPath, $bus, $role) {
        if (!is_file($xmlPath)) {
            throw new RuntimeException("XML not found: $xmlPath");
        }

        $normalizedBus = strtolower(trim($bus));
        $normalizedRole = strtoupper(trim($role));
        if ($normalizedBus === '') {
            throw new RuntimeException('bus is required');
        }
        if (!in_array($normalizedRole, ['SDAQ', 'NOX', 'FREE'], true)) {
            throw new RuntimeException('role must be SDAQ, NOX, or FREE');
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (!$doc->load($xmlPath)) {
            throw new RuntimeException('Failed to parse LOG config XML');
        }

        $components = $doc->getElementsByTagName('COMPONENTS')->item(0);
        if (!$components instanceof DOMElement) {
            throw new RuntimeException('Invalid LOG config XML');
        }

        log_config_remove_can_role_nodes($components, $normalizedBus);

        if ($normalizedRole !== 'FREE') {
            $node = $doc->createElement($normalizedRole . '_HANDLER');
            $node->setAttribute('Disable', 'false');
            $node->appendChild($doc->createElement('CANBUS_IF', $normalizedBus));
            log_config_insert_component_ordered($components, $node);
        }

        $xmlString = $doc->saveXML();
        if (!is_string($xmlString) || $xmlString === '') {
            throw new RuntimeException('Failed to serialize LOG config XML');
        }
        if ($normalizedRole !== 'FREE') {
            log_config_validate_before_write($xmlString, $xmlPath);
        }
        backend_atomic_write_file($xmlPath, $xmlString, 0644);

        return [
            'bus' => $normalizedBus,
            'mode' => $normalizedRole,
            'enabled' => $normalizedRole !== 'FREE',
        ];
    });
}

function log_config_append_device(string $xmlPath, array $data): array
{
    return log_config_with_xml_lock($xmlPath, function () use ($xmlPath, $data) {
        if (!is_file($xmlPath)) {
            throw new RuntimeException("XML not found: $xmlPath");
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (!$doc->load($xmlPath)) {
            throw new RuntimeException('Failed to parse LOG config XML');
        }

        $components = $doc->getElementsByTagName('COMPONENTS')->item(0);
        if ($components === null) {
            throw new RuntimeException('Invalid LOG config XML');
        }

        $type = strtoupper(str_replace('-', '', $data['type']));
        $id   = log_config_build_manual_id($type, '-', $data['name'], $data['ip']);

        $conflict = log_config_find_device_conflict($components, (string)$data['name'], (string)$data['ip']);
        if ($conflict !== null) {
            throw new ChannelConfigException($conflict, 409, 'duplicate_device');
        }

        switch ($type) {
            case 'IOBOX':
            case 'MTI':
                $node = $doc->createElement($type . '_HANDLER');
                $node->setAttribute('Disable', 'false');
                $node->appendChild($doc->createElement('DEV_NAME', $data['name']));
                $node->appendChild($doc->createElement('IPv4_ADDR', $data['ip']));
                break;
            default:
                throw new RuntimeException('Unsupported type: ' . $type);
        }

        log_config_insert_component_ordered($components, $node);

        $xmlString = $doc->saveXML();
        if (!is_string($xmlString) || $xmlString === '') {
            throw new RuntimeException('Failed to serialize LOG config XML');
        }
        log_config_validate_before_write($xmlString, $xmlPath);
        backend_atomic_write_file($xmlPath, $xmlString, 0644);

        return [
            'id'     => $id,
            'type'   => $type,
            'bus'    => '-',
            'ip'     => $data['ip'],
            'name'   => $data['name'],
            'status' => 'Okay',
            'origin' => 'xml',
        ];
    });
}

function log_config_delete_devices(string $xmlPath, array $ids): void
{
    log_config_with_xml_lock($xmlPath, function () use ($xmlPath, $ids) {
        if (!is_file($xmlPath)) {
            throw new RuntimeException("XML not found: $xmlPath");
        }
        if (empty($ids)) {
            throw new RuntimeException('Missing ids');
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (!$doc->load($xmlPath)) {
            throw new RuntimeException('Failed to parse LOG config XML');
        }

        $components = $doc->getElementsByTagName('COMPONENTS')->item(0);
        if ($components === null) {
            throw new RuntimeException('Invalid LOG config XML');
        }

        $set = array_fill_keys($ids, true);
        $children = $components->childNodes;
        for ($i = $children->length - 1; $i >= 0; $i--) {
            $node = $children->item($i);
            if (!$node instanceof DOMElement) continue;

            $tag = strtoupper($node->tagName);
            $bus = '';
            $name = '';
            $ip = '';

            switch ($tag) {
                case 'IOBOX_HANDLER':
                case 'MTI_HANDLER':
                    $type = str_replace('_HANDLER', '', $tag);
                    $name = log_config_dom_text_or_empty($node, 'DEV_NAME');
                    $ip   = log_config_dom_text_or_empty($node, 'IPv4_ADDR');
                    $bus  = '-';
                    break;
                case 'NOX_HANDLER':
                    $type = 'NOX';
                    $bus  = log_config_dom_text_or_empty($node, 'CANBUS_IF');
                    $name = $bus;
                    break;
                default:
                    continue 2;
            }

            $currId = log_config_build_manual_id($type, $bus, $name, $ip);
            if (isset($set[$currId])) {
                $components->removeChild($node);
            }
        }

        $xmlString = $doc->saveXML();
        if (!is_string($xmlString) || $xmlString === '') {
            throw new RuntimeException('Failed to serialize LOG config XML');
        }
        backend_atomic_write_file($xmlPath, $xmlString, 0644);
    });
}
