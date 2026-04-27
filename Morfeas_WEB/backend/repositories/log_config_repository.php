<?php

require_once __DIR__ . '/../core/concurrency.php';

function log_config_dom_text_or_empty(DOMElement $parent, string $tag): string
{
    $node = $parent->getElementsByTagName($tag)->item(0);
    return $node ? trim($node->nodeValue) : '';
}

function log_config_node_disabled(DOMElement $node): bool
{
    return strtolower((string) $node->getAttribute('Disable')) === 'true';
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
            $components->appendChild($node);
        }

        $xmlString = $doc->saveXML();
        if (!is_string($xmlString) || $xmlString === '') {
            throw new RuntimeException('Failed to serialize LOG config XML');
        }
        backend_atomic_write_file($xmlPath, $xmlString);

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

        $existing = log_config_load_manual_devices($xmlPath);
        foreach ($existing as $dev) {
            if ($dev['id'] === $id) {
                throw new RuntimeException("Device already exists: $id");
            }
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

        $components->appendChild($node);

        $xmlString = $doc->saveXML();
        if (!is_string($xmlString) || $xmlString === '') {
            throw new RuntimeException('Failed to serialize LOG config XML');
        }
        backend_atomic_write_file($xmlPath, $xmlString);

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
        backend_atomic_write_file($xmlPath, $xmlString);
    });
}
