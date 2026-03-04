<?php

require_once __DIR__ . '/../core/opcua_config.php';
require_once __DIR__ . '/../core/logstat_sdaq.php';
require_once __DIR__ . '/../core/logstat_iobox.php';
require_once __DIR__ . '/../core/logstat_mti.php';
require_once __DIR__ . '/../core/logstat_nox.php';

function channel_build_rows_with_logstat(
    string $xmlPath,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes,
    ?array &$extras = null
): array {
    $channels = iso_load_channels($xmlPath);

    $formatSdaqDisplayAnchor = static function (?string $anchor): string {
        $anchor = trim((string)$anchor);
        if ($anchor === '') return '';

        if (preg_match('/^(CAN\w+)\.ADDR:(\d+)\.CH:?([\d]+)/i', $anchor, $m)) {
            return sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
        }
        if (preg_match('/^(CAN\w+)\.?(\d+)\.CH:?([\d]+)/i', $anchor, $m)) {
            return sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
        }

        return strtoupper($anchor);
    };

    $formatNetworkAnchor = static function (?string $anchor, array $ipv4Map): string {
        $anchor = trim((string)$anchor);
        if ($anchor === '') return '';

        $parts = explode('.', $anchor, 2);
        if (count($parts) !== 2) {
            return strtoupper($anchor);
        }

        [$deviceId, $rest] = $parts;
        $deviceKey = (string)$deviceId;

        $prefix = $ipv4Map[$deviceKey] ?? $deviceId;
        return $prefix . '.' . strtoupper($rest);
    };

    $sdaqAnchorsFromXml = [];
    foreach ($channels as $ch) {
        if (strcasecmp($ch['interface_type'] ?? '', 'SDAQ') === 0 && !empty($ch['anchor'])) {
            $sdaqAnchorsFromXml[strtoupper($ch['anchor'])] = true;
        }
    }

    $sdaqMap = [];
    $sdaqChannels = [];

    foreach ($sdaqLogFiles as $path) {
        $dataset = sdaq_load_anchor_map($path, $sdaqAnchorsFromXml);
        if (is_array($dataset)) {
            $anchors = $dataset['anchors'] ?? [];
            foreach ($anchors as $anchor => $entry) {
                $sdaqMap[$anchor] = $entry;
            }

            if (!empty($dataset['channels']) && is_array($dataset['channels'])) {
                $sdaqChannels = array_merge($sdaqChannels, $dataset['channels']);
            }
        }
    }

    $ioboxData = iobox_load_anchor_map($ioboxLogFiles);
    $ioboxMap  = $ioboxData['anchors'] ?? [];
    $ioboxConn = $ioboxData['connections'] ?? [];
    $ioboxIPv4 = $ioboxData['ipv4'] ?? [];

    $mtiData = mti_load_anchor_map($mtiLogFiles);
    $mtiMap  = $mtiData['anchors'] ?? [];
    $mtiConn = $mtiData['connections'] ?? [];
    $mtiIPv4 = $mtiData['ipv4'] ?? [];

    $noxMap = [];
    foreach ($noxLogFiles as $path) {
        $newMap = nox_load_anchor_map($path);
        if (is_array($newMap)) {
            foreach ($newMap as $anchor => $entry) {
                $noxMap[$anchor] = $entry;
            }
        }
    }

    $rows = [];
    $anchorsInXmlUpper = [];
    $idx = 0;

    $searchPool = [
        'SDAQ'  => [],
        'IOBOX' => [],
        'MTI'   => [],
        'NOX'   => [],
    ];

    foreach ($channels as $ch) {
        $row    = $ch;
        $anchor = $ch['anchor'] ?? '';
        $type   = strtoupper($ch['interface_type'] ?? '');
        $row['dev_type'] = $type;
        $row['display_anchor'] = $anchor;
        $row['_order'] = $idx++;
        $busAddrKey = null;

        if ($type === 'SDAQ' && $anchor) {
            $anchorUc = strtoupper($anchor);

            if (preg_match('/^(CAN\w+\.ADDR:\d{2})/i', $anchorUc, $m)) {
                $busAddrKey = strtoupper($m[1]);
            } elseif (preg_match('/^(CAN\w+)\.?(\d{1,2})\.CH/',$anchorUc,$m)) {
                $busAddrKey = sprintf('%s.ADDR:%02d', $m[1], (int)$m[2]);
            }

            if ($busAddrKey && isset($sdaqDeviceTypes[$busAddrKey])) {
                $row['dev_type'] = $sdaqDeviceTypes[$busAddrKey];
            }
        }

        $status = 'OFF-Line';
        $meas   = '—';
        $measUnit = null;

        if ($type === 'SDAQ') {
            $row['display_anchor'] = $formatSdaqDisplayAnchor($anchor);
            if ($anchor && isset($sdaqMap[$anchor])) {
                $ls = $sdaqMap[$anchor];
                $explain = $ls['error_explanation'] ?? null;
                $status = $ls['status'] ?? 'Unknown';
                if (($explain && strcasecmp($explain, 'Unlinked') === 0) || strcasecmp($status, 'Unlinked') === 0) {
                    $status = 'Unknown';
                }

                if (!empty($ls['device_user_identifier'])) {
                    $row['dev_type'] = $ls['device_user_identifier'];
                }

                if (!empty($ls['cal_date'])) {
                    $row['cal_date'] = $ls['cal_date'];
                }
                if (!empty($ls['cal_period'])) {
                    $row['cal_period'] = $ls['cal_period'];
                }

                if (!empty($ls['address_anchor'])) {
                    $row['display_anchor'] = $ls['address_anchor'];
                }

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                        $measUnit = $ls['meas_unit'];
                    }
                }
            } elseif ($busAddrKey && isset($sdaqDeviceTypes[$busAddrKey])) {
                $row['dev_type'] = $sdaqDeviceTypes[$busAddrKey];
            }

            // For SDAQ channels, keep edit popup in sync with the device's latest runtime unit.
            // This avoids showing stale OPC-UA-config unit after scale/calibration writes.
            if (is_string($measUnit) && $measUnit !== '') {
                $row['unit'] = $measUnit;
            }

        } elseif ($type === 'IOBOX') {
            $row['display_anchor'] = $formatNetworkAnchor($anchor, $ioboxIPv4);

            if ($anchor && isset($ioboxMap[$anchor])) {
                $ls = $ioboxMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                        $measUnit = $ls['meas_unit'];
                    }
                }
            }

            if ($status === 'OFF-Line' && $anchor) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null && isset($ioboxConn[$deviceId]) && strcasecmp($ioboxConn[$deviceId], 'Okay') === 0) {
                    $status = 'Disconnected';
                }
            }

        } elseif ($type === 'MTI') {
            $row['display_anchor'] = $formatNetworkAnchor($anchor, $mtiIPv4);

            if ($anchor && isset($mtiMap[$anchor])) {
                $ls = $mtiMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                        $measUnit = $ls['meas_unit'];
                    }
                }
            }

            if ($status === 'OFF-Line' && $anchor) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null && isset($mtiConn[$deviceId]) && strcasecmp($mtiConn[$deviceId], 'Okay') === 0) {
                    $status = 'Disconnected';
                }
            }

        } elseif ($type === 'NOX' || $type === 'NOx') {
            if ($anchor && isset($noxMap[$anchor])) {
                $ls = $noxMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                        $measUnit = $ls['meas_unit'];
                    }
                }
            }

        } else {
            $status = 'Okay';
        }

        $row['status'] = $status;
        $row['meas']   = $meas;
        $row['meas_unit'] = $measUnit;

        $rows[] = $row;
        if ($anchor) {
            $upper = strtoupper($anchor);
            $anchorsInXmlUpper[$upper] = true;
        }
    }

    foreach ($sdaqChannels as $chMeta) {
        $anchor = $chMeta['connection_anchor'] ?? ($chMeta['preferred_anchor'] ?? ($chMeta['aliases'][0] ?? null));
        $display = $chMeta['display_anchor'] ?? $anchor;
        if (!$anchor || !$display) {
            continue;
        }

        $upper = strtoupper($anchor);
        $searchPool['SDAQ'][] = [
            'anchor'          => $anchor,
            'display_anchor'  => $formatSdaqDisplayAnchor($display),
            'serial_anchor'   => $chMeta['serial_anchor'] ?? null,
            'address_anchor'  => $chMeta['address_anchor'] ?? null,
            'link_state'      => $chMeta['link_state'] ?? 'Linked',
            'has_sensor'      => !empty($chMeta['has_sensor']),
            'registration'    => $chMeta['registration'] ?? null,
            'unit'            => $chMeta['entry']['meas_unit'] ?? null,
            'meas_unit'       => $chMeta['entry']['meas_unit'] ?? null,
            'device_type'     => $chMeta['entry']['device_user_identifier'] ?? null,
            'status'          => $chMeta['entry']['status'] ?? null,
            'is_meas_valid'   => $chMeta['entry']['is_meas_valid'] ?? null,
            'meas_value'      => $chMeta['entry']['meas_value'] ?? null,
            'linked_in_xml'   => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($ioboxMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['IOBOX'][] = [
            'anchor'         => $anchor,
            'display_anchor' => $formatNetworkAnchor($anchor, $ioboxIPv4),
            'link_state'     => 'Unlinked',
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($mtiMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['MTI'][] = [
            'anchor'         => $anchor,
            'display_anchor' => $formatNetworkAnchor($anchor, $mtiIPv4),
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($noxMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['NOX'][] = [
            'anchor'         => $anchor,
            'display_anchor' => $anchor,
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    $priority = [
        'SDAQ'  => 0,
        'NOX'   => 1,
        'NOx'   => 1,
        'MTI'   => 2,
        'IOBOX' => 3,
    ];

    usort($rows, static function ($a, $b) use ($priority) {
        $pa = $priority[$a['interface_type'] ?? $a['dev_type'] ?? ''] ?? 99;
        $pb = $priority[$b['interface_type'] ?? $b['dev_type'] ?? ''] ?? 99;

        if ($pa === $pb) {
            return ($a['_order'] ?? 0) <=> ($b['_order'] ?? 0);
        }
        return $pa <=> $pb;
    });

    foreach ($rows as &$r) {
        unset($r['_order']);
    }

    if (is_array($extras)) {
        $extras = [
            'search_pool' => $searchPool,
        ];
    }

    return $rows;
}

function channel_find_by_iso(array $rows, ?string $iso): ?array
{
    if ($iso === null) {
        return null;
    }

    foreach ($rows as $row) {
        if (($row['iso_channel'] ?? '') === $iso) {
            return $row;
        }
    }

    return null;
}
