<?php
// backend/core/iso_channel_config.php

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
 *     <CAL_DATE>2020/01/01</CAL_DATE>   <!-- 可选 -->
 *     <CAL_PERIOD>12</CAL_PERIOD>       <!-- 可选，单位: 月 -->
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

    // 防止重复 ISO_CHANNEL
    foreach ($xml->CHANNEL as $ch) {
        if ((string)$ch->ISO_CHANNEL === $data['iso_channel']) {
            throw new RuntimeException("ISO_CHANNEL already exists: ".$data['iso_channel']);
        }
    }

    $new = $xml->addChild('CHANNEL');
    $new->addChild('ISO_CHANNEL',    $data['iso_channel']);
    $new->addChild('INTERFACE_TYPE', $data['interface_type']);
    $new->addChild('ANCHOR',         $data['anchor']);
    $new->addChild('DESCRIPTION',    $data['description'] ?? '');
    $new->addChild('MIN',            $data['min'] ?? '0');
    $new->addChild('MAX',            $data['max'] ?? '0');
    if (!empty($data['unit']))       $new->addChild('UNIT',       $data['unit']);
    if (!empty($data['cal_date']))   $new->addChild('CAL_DATE',   $data['cal_date']);
    if (!empty($data['cal_period'])) $new->addChild('CAL_PERIOD', $data['cal_period']);

    $xml->asXML($xmlPath);
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

    if (isset($data['interface_type'])) $target->INTERFACE_TYPE = $data['interface_type'];
    if (isset($data['anchor']))         $target->ANCHOR         = $data['anchor'];
    if (isset($data['description']))    $target->DESCRIPTION    = $data['description'];
    if (isset($data['min']))            $target->MIN            = $data['min'];
    if (isset($data['max']))            $target->MAX            = $data['max'];
    if (isset($data['unit']))           $target->UNIT           = $data['unit'];
    if (isset($data['cal_date']))       $target->CAL_DATE       = $data['cal_date'];
    if (isset($data['cal_period']))     $target->CAL_PERIOD     = $data['cal_period'];

    $xml->asXML($xmlPath);
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

    $xml->asXML($xmlPath);
}
