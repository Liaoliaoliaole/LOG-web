<?php
// backend/core/opcua_config.php

/**
 * 读取 OPC_UA_Config.xml，返回每个 CHANNEL 的配置
 *
 * 预期 XML 结构示例：
 * <OPC_UA_CONFIG>
 *   <CHANNEL>
 *     <ISO_CHANNEL>SDAQ_OK_1</ISO_CHANNEL>
 *     <INTERFACE_TYPE>SDAQ</INTERFACE_TYPE>
 *     <ANCHOR>CAN1.ADDR:01.CH:01</ANCHOR>
 *     <DESCRIPTION>...</DESCRIPTION>
 *     <MIN>0</MIN>
 *     <MAX>100</MAX>
 *     <UNIT>°C</UNIT>
 *     <CAL_DATE>2020/01/01</CAL_DATE>   <!-- option -->
 *     <CAL_PERIOD>12</CAL_PERIOD>       <!-- option,unit: month -->
 *   </CHANNEL>
 *   ...
 * </OPC_UA_CONFIG>
 */
function iso_load_channels(string $xmlPath): array
{
    if (!is_file($xmlPath)) {
        throw new RuntimeException("OPC_UA_Config not found: $xmlPath");
    }

    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException("Failed to parse XML at $xmlPath");
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
        throw new RuntimeException('Failed to format XML output');
    }

    $xmlString = $dom->saveXML();
    if ($xmlString === false) {
        throw new RuntimeException('Failed to serialize XML');
    }

    $xmlString = preg_replace_callback('/<UNIT>(.*?)<\\/UNIT>/s', static function ($matches) {
        return '<UNIT>' . html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</UNIT>';
    }, $xmlString);

    if (file_put_contents($xmlPath, $xmlString) === false) {
        throw new RuntimeException("Failed to save XML: $xmlPath");
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

/**
 * 新增一个 CHANNEL（写回 XML）
 * $data 至少需要: iso_channel, interface_type, anchor
 */
function iso_add_channel(string $xmlPath, array $data): void
{
    if (!file_exists($xmlPath)) {
        throw new RuntimeException("XML not found: $xmlPath");
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException("Failed to parse XML");
    }

    $isoChannel = iso_normalize_iso_channel($data['iso_channel']);

    // 防止重复 ISO_CHANNEL
    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $isoChannel) {
            throw new RuntimeException("ISO_CHANNEL already exists: ".$isoChannel);
        }
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

/**
 * 更新指定 ISO_CHANNEL（只改传入的字段）
 */
function iso_update_channel(string $xmlPath, string $isoChannel, array $data): void
{
    if (!file_exists($xmlPath)) {
        throw new RuntimeException("XML not found: $xmlPath");
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException("Failed to parse XML");
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
        throw new RuntimeException("ISO_CHANNEL not found: ".$isoChannel);
    }

    $existing = iso_channel_snapshot($target);
    $newIso = $existing['iso_channel'];
    if (array_key_exists('iso_channel', $data)) {
        $newIso = iso_normalize_iso_channel($data['iso_channel']);
        if ($newIso !== $isoChannel) {
            foreach ($xml->CHANNEL as $ch) {
                if ((string)$ch->ISO_CHANNEL === $newIso) {
                    throw new RuntimeException("ISO_CHANNEL already exists: " . $newIso);
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

    iso_set_channel_contents($target, $payload);
    iso_save_xml($xml, $xmlPath);
}

/**
 * 删除一个 CHANNEL
 */
function iso_delete_channel(string $xmlPath, string $isoChannel): void
{
    if (!file_exists($xmlPath)) {
        throw new RuntimeException("XML not found: $xmlPath");
    }
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException("Failed to parse XML");
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
        throw new RuntimeException("ISO_CHANNEL not found: ".$isoChannel);
    }

    iso_save_xml($xml, $xmlPath);
}
