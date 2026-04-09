<?php

class ChannelConfigException extends RuntimeException
{
    private int $status;
    private string $apiCode;

    public function __construct(string $message, int $status = 400, string $apiCode = 'channel_config_error')
    {
        parent::__construct($message);
        $this->status = $status;
        $this->apiCode = $apiCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

function iso_load_channels(string $xmlPath): array
{
    if (!is_file($xmlPath)) {
        throw new ChannelConfigException("OPC_UA_Config not found: $xmlPath", 404, 'channel_config_missing');
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML at $xmlPath", 500, 'channel_config_parse_failed');
    }

    $out = [];
    foreach ($xml->CHANNEL as $ch) {
        $out[] = [
            'iso_channel'    => trim((string)$ch->ISO_CHANNEL),
            'interface_type' => trim((string)$ch->INTERFACE_TYPE),
            'anchor'         => trim((string)$ch->ANCHOR),
            'description'    => trim((string)$ch->DESCRIPTION),
            'min'            => (string)$ch->MIN,
            'max'            => (string)$ch->MAX,
            'unit'           => trim((string)$ch->UNIT),
            'cal_date'       => trim((string)$ch->CAL_DATE),
            'cal_period'     => $ch->CAL_PERIOD !== null ? (int)$ch->CAL_PERIOD : null,
        ];
    }

    return $out;
}

function iso_save_xml(SimpleXMLElement $xml, string $xmlPath): void
{
    $dom = new DOMDocument('1.0');
    $dom->encoding = 'UTF-8';
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;

    if ($dom->loadXML($xml->asXML()) === false) {
        throw new ChannelConfigException('Failed to format XML output', 500, 'channel_config_format_failed');
    }

    $xmlString = $dom->saveXML();
    if ($xmlString === false) {
        throw new ChannelConfigException('Failed to serialize XML', 500, 'channel_config_serialize_failed');
    }

    $xmlString = preg_replace_callback('/<UNIT>(.*?)<\\/UNIT>/s', static function ($matches) {
        return '<UNIT>' . html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</UNIT>';
    }, $xmlString);

    if (file_put_contents($xmlPath, $xmlString) === false) {
        throw new ChannelConfigException("Failed to save XML: $xmlPath", 500, 'channel_config_save_failed');
    }
}

function iso_channel_snapshot(SimpleXMLElement $ch): array
{
    return [
        'iso_channel'    => trim((string)$ch->ISO_CHANNEL),
        'interface_type' => trim((string)$ch->INTERFACE_TYPE),
        'anchor'         => trim((string)$ch->ANCHOR),
        'description'    => trim((string)$ch->DESCRIPTION),
        'min'            => (string)$ch->MIN,
        'max'            => (string)$ch->MAX,
        'unit'           => iso_decode_xml_value(trim((string)$ch->UNIT)),
        'cal_date'       => trim((string)$ch->CAL_DATE),
        'cal_period'     => (string)$ch->CAL_PERIOD,
        'build_date'     => (string)$ch->BUILD_DATE,
        'mod_date'       => (string)$ch->MOD_DATE,
        'alarm_high_val' => (string)$ch->ALARM_HIGH_VAL,
        'alarm_low_val'  => (string)$ch->ALARM_LOW_VAL,
        'alarm_high'     => trim((string)$ch->ALARM_HIGH),
        'alarm_low'      => trim((string)$ch->ALARM_LOW),
    ];
}

function iso_normalize_iso_channel($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return $value[0] === '_' ? $value : '_' . $value;
}

function iso_decode_xml_value($value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = (string)$value;
    if ($value === '') {
        return '';
    }
    return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function iso_normalize_anchor_value($value): string
{
    $raw = strtoupper(trim((string)$value));
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^(CAN\w+)\.ADDR:(\d{1,3})\.CH:?(\d{1,3})$/i', $raw, $m)) {
        return sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
    }

    if (preg_match('/^(CAN\w+)\.(\d{1,3})\.CH:?(\d{1,3})$/i', $raw, $m)) {
        return sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
    }

    return preg_replace('/\s+/', '', $raw) ?? $raw;
}

function iso_find_anchor_conflict(SimpleXMLElement $xml, string $anchor, ?string $ignoreIso = null): ?string
{
    $anchorNorm = iso_normalize_anchor_value($anchor);
    if ($anchorNorm === '') {
        return null;
    }

    $ignoreIsoNorm = $ignoreIso !== null ? iso_normalize_iso_channel($ignoreIso) : null;

    foreach ($xml->CHANNEL as $ch) {
        $existingIso = iso_normalize_iso_channel((string)$ch->ISO_CHANNEL);
        if ($ignoreIsoNorm !== null && $existingIso === $ignoreIsoNorm) {
            continue;
        }

        if (iso_normalize_anchor_value((string)$ch->ANCHOR) === $anchorNorm) {
            return trim((string)$ch->ISO_CHANNEL);
        }
    }

    return null;
}

function iso_set_channel_contents(SimpleXMLElement $ch, array $data): void
{
    foreach ($ch->xpath('*') as $child) {
        unset($child[0]);
    }

    $ch->addChild('ISO_CHANNEL', $data['iso_channel']);
    $ch->addChild('INTERFACE_TYPE', $data['interface_type']);
    $ch->addChild('ANCHOR', $data['anchor']);
    $ch->addChild('DESCRIPTION', $data['description']);
    $ch->addChild('MIN', $data['min']);
    $ch->addChild('MAX', $data['max']);

    if ($data['unit'] !== null && $data['unit'] !== '') {
        $ch->addChild('UNIT', $data['unit']);
    }
    if ($data['cal_date'] !== null && $data['cal_date'] !== '') {
        $ch->addChild('CAL_DATE', $data['cal_date']);
    }
    if ($data['cal_period'] !== null && $data['cal_period'] !== '') {
        $ch->addChild('CAL_PERIOD', $data['cal_period']);
    }
    if ($data['build_date'] !== null && $data['build_date'] !== '') {
        $ch->addChild('BUILD_DATE', $data['build_date']);
    }
    if ($data['mod_date'] !== null && $data['mod_date'] !== '') {
        $ch->addChild('MOD_DATE', $data['mod_date']);
    }
    if ($data['alarm_high_val'] !== null && $data['alarm_high_val'] !== '') {
        $ch->addChild('ALARM_HIGH_VAL', $data['alarm_high_val']);
    }
    if ($data['alarm_low_val'] !== null && $data['alarm_low_val'] !== '') {
        $ch->addChild('ALARM_LOW_VAL', $data['alarm_low_val']);
    }
    if ($data['alarm_high'] !== null && $data['alarm_high'] !== '') {
        $ch->addChild('ALARM_HIGH', $data['alarm_high']);
    }
    if ($data['alarm_low'] !== null && $data['alarm_low'] !== '') {
        $ch->addChild('ALARM_LOW', $data['alarm_low']);
    }
}

function iso_pick_value(array $data, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }
    return null;
}

function iso_add_channel(string $xmlPath, array $data): void
{
    if (!file_exists($xmlPath)) {
        throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
    }

    $isoChannel = iso_normalize_iso_channel($data['iso_channel']);

    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $isoChannel) {
            throw new ChannelConfigException("ISO_CHANNEL already exists: " . $isoChannel, 409, 'channel_conflict');
        }
    }

    $anchorConflictIso = iso_find_anchor_conflict($xml, (string)$data['anchor']);
    if ($anchorConflictIso !== null) {
        throw new ChannelConfigException(
            "ANCHOR already exists: " . $data['anchor'] . " is already used by " . $anchorConflictIso,
            409,
            'channel_conflict'
        );
    }

    $new = $xml->addChild('CHANNEL');
    $now = time();
    $buildDate = iso_pick_value($data, ['build_date', 'build_date_unix', 'Build_date_UNIX']);
    $modDate = iso_pick_value($data, ['mod_date', 'mod_date_unix', 'Mod_date_UNIX']);

    $payload = [
        'iso_channel'    => $isoChannel,
        'interface_type' => $data['interface_type'],
        'anchor'         => $data['anchor'],
        'description'    => $data['description'] ?? '',
        'min'            => $data['min'] ?? '0',
        'max'            => $data['max'] ?? '0',
        'unit'           => iso_decode_xml_value($data['unit'] ?? null),
        'cal_date'       => $data['cal_date'] ?? null,
        'cal_period'     => $data['cal_period'] ?? null,
        'build_date'     => $buildDate ?? $now,
        'mod_date'       => $modDate ?? $now,
        'alarm_high_val' => array_key_exists('alarm_high_val', $data) ? $data['alarm_high_val'] : null,
        'alarm_low_val'  => array_key_exists('alarm_low_val', $data) ? $data['alarm_low_val'] : null,
        'alarm_high'     => array_key_exists('alarm_high', $data) ? $data['alarm_high'] : null,
        'alarm_low'      => array_key_exists('alarm_low', $data) ? $data['alarm_low'] : null,
    ];

    iso_set_channel_contents($new, $payload);
    iso_save_xml($xml, $xmlPath);
}

function iso_update_channel(string $xmlPath, string $isoChannel, array $data): void
{
    if (!file_exists($xmlPath)) {
        throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
    }

    $isoChannel = iso_normalize_iso_channel($isoChannel);
    $target = null;
    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $isoChannel) {
            $target = $ch;
            break;
        }
    }
    if (!$target) {
        throw new ChannelConfigException("ISO_CHANNEL not found: " . $isoChannel, 404, 'channel_not_found');
    }

    $existing = iso_channel_snapshot($target);
    $newIso = $existing['iso_channel'];
    if (array_key_exists('iso_channel', $data)) {
        $newIso = iso_normalize_iso_channel($data['iso_channel']);
        if ($newIso !== $isoChannel) {
            foreach ($xml->CHANNEL as $ch) {
                if ((string)$ch->ISO_CHANNEL === $newIso) {
                    throw new ChannelConfigException("ISO_CHANNEL already exists: " . $newIso, 409, 'channel_conflict');
                }
            }
        }
    }

    $buildDate = iso_pick_value($data, ['build_date', 'build_date_unix', 'Build_date_UNIX']);
    $modDate = iso_pick_value($data, ['mod_date', 'mod_date_unix', 'Mod_date_UNIX']);
    $now = time();

    $payload = [
        'iso_channel'    => $newIso,
        'interface_type' => $data['interface_type'] ?? $existing['interface_type'],
        'anchor'         => $data['anchor'] ?? $existing['anchor'],
        'description'    => array_key_exists('description', $data) ? $data['description'] : $existing['description'],
        'min'            => array_key_exists('min', $data) ? $data['min'] : $existing['min'],
        'max'            => array_key_exists('max', $data) ? $data['max'] : $existing['max'],
        'unit'           => array_key_exists('unit', $data)
            ? iso_decode_xml_value($data['unit'])
            : $existing['unit'],
        'cal_date'       => array_key_exists('cal_date', $data) ? $data['cal_date'] : $existing['cal_date'],
        'cal_period'     => array_key_exists('cal_period', $data) ? $data['cal_period'] : $existing['cal_period'],
        'build_date'     => $buildDate ?? $existing['build_date'],
        'mod_date'       => $modDate ?? $now,
        'alarm_high_val' => array_key_exists('alarm_high_val', $data) ? $data['alarm_high_val'] : $existing['alarm_high_val'],
        'alarm_low_val'  => array_key_exists('alarm_low_val', $data) ? $data['alarm_low_val'] : $existing['alarm_low_val'],
        'alarm_high'     => array_key_exists('alarm_high', $data) ? $data['alarm_high'] : $existing['alarm_high'],
        'alarm_low'      => array_key_exists('alarm_low', $data) ? $data['alarm_low'] : $existing['alarm_low'],
    ];

    $anchorConflictIso = iso_find_anchor_conflict($xml, (string)$payload['anchor'], $isoChannel);
    if ($anchorConflictIso !== null) {
        throw new ChannelConfigException(
            "ANCHOR already exists: " . $payload['anchor'] . " is already used by " . $anchorConflictIso,
            409,
            'channel_conflict'
        );
    }

    iso_set_channel_contents($target, $payload);
    iso_save_xml($xml, $xmlPath);
}

function iso_delete_channel(string $xmlPath, string $isoChannel): void
{
    if (!file_exists($xmlPath)) {
        throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
    }

    $isoChannel = iso_normalize_iso_channel($isoChannel);
    $index = 0;
    $found = false;
    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $isoChannel) {
            unset($xml->CHANNEL[$index]);
            $found = true;
            break;
        }
        $index++;
    }
    if (!$found) {
        throw new ChannelConfigException("ISO_CHANNEL not found: " . $isoChannel, 404, 'channel_not_found');
    }

    iso_save_xml($xml, $xmlPath);
}

function iso_batch_update_anchors(string $xmlPath, array $updates): void
{
    if (!file_exists($xmlPath)) {
        throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
    }

    $normalized = [];
    foreach ($updates as $iso => $anchor) {
        $isoNorm = iso_normalize_iso_channel((string)$iso);
        $anchorNorm = trim((string)$anchor);
        if ($isoNorm === '' || $anchorNorm === '') {
            throw new ChannelConfigException('Invalid batch anchor update payload', 400, 'channel_config_error');
        }
        $normalized[$isoNorm] = $anchorNorm;
    }
    if (!$normalized) {
        return;
    }

    $channelByIso = [];
    foreach ($xml->CHANNEL as $ch) {
        $existingIso = iso_normalize_iso_channel((string)$ch->ISO_CHANNEL);
        if ($existingIso !== '') {
            $channelByIso[$existingIso] = $ch;
        }
    }

    foreach ($normalized as $iso => $_anchor) {
        if (!array_key_exists($iso, $channelByIso)) {
            throw new ChannelConfigException("ISO_CHANNEL not found: " . $iso, 404, 'channel_not_found');
        }
    }

    $now = (string)time();
    foreach ($normalized as $iso => $anchor) {
        $target = $channelByIso[$iso];
        if (isset($target->ANCHOR)) {
            $target->ANCHOR = $anchor;
        } else {
            $target->addChild('ANCHOR', $anchor);
        }

        if (isset($target->MOD_DATE)) {
            $target->MOD_DATE = $now;
        } else {
            $target->addChild('MOD_DATE', $now);
        }
    }

    iso_save_xml($xml, $xmlPath);
}
