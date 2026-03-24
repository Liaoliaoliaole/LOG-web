<?php

require_once __DIR__ . '/../core/opcua_config.php';
require_once __DIR__ . '/../core/logstat_sdaq.php';
require_once __DIR__ . '/../core/logstat_iobox.php';
require_once __DIR__ . '/../core/logstat_mti.php';
require_once __DIR__ . '/../core/logstat_nox.php';
require_once __DIR__ . '/../core/sdaq_type_cache.php';

function channel_status_is_offline(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['off-line', 'offline', 'disconnected'], true);
}

function channel_pick_runtime_sdaq_type(?string $fromMap, ?string $fromEntry): ?string
{
    $candidates = [$fromEntry, $fromMap];
    foreach ($candidates as $candidate) {
        $type = trim((string)$candidate);
        if ($type === '') {
            continue;
        }
        return $type;
    }
    return null;
}

function channel_sdaq_cache_keys(?string $busAddrKey, ?string $runtimeAddressAnchor, ?string $xmlAnchor): array
{
    $keys = [];
    $seen = [];

    $push = static function (?string $raw) use (&$keys, &$seen): void {
        $value = strtoupper(trim((string)$raw));
        if ($value === '' || isset($seen[$value])) {
            return;
        }
        $seen[$value] = true;
        $keys[] = $value;
    };

    $push($busAddrKey);
    $push(sdaq_cache_key_from_anchor($runtimeAddressAnchor));
    $push(sdaq_cache_key_from_anchor($xmlAnchor));

    // Fallback: keep raw anchors as cache keys so serial-style anchors
    // (e.g. SN.CHn) can still resolve subtype when runtime address is missing.
    $push($runtimeAddressAnchor);
    $push($xmlAnchor);

    return $keys;
}

function channel_pick_cached_sdaq_type(array $typeCache, array $cacheKeys): ?string
{
    foreach ($cacheKeys as $key) {
        $value = trim((string)($typeCache[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

function channel_apply_default_type_fields(array &$row): void
{
    if (!isset($row['dev_type'])) {
        $row['dev_type'] = strtoupper((string)($row['interface_type'] ?? ''));
    }

    if (!array_key_exists('dev_type_known', $row)) {
        $row['dev_type_known'] = true;
    }
    if (!array_key_exists('dev_type_stale', $row)) {
        $row['dev_type_stale'] = false;
    }
    if (!array_key_exists('dev_type_display', $row)) {
        $row['dev_type_display'] = (string)$row['dev_type'];
    }
}

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
    $typeCache = sdaq_type_cache_read();
    $typeCacheDirty = false;

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
            } elseif (preg_match('/^(CAN\w+)\.?(\d{1,2})\.CH/', $anchorUc, $m)) {
                $busAddrKey = sprintf('%s.ADDR:%02d', $m[1], (int)$m[2]);
            }
        }

        $status = 'OFF-Line';
        $meas   = '—';
        $measUnit = null;

        $runtimeSdaqType = null;
        $sdaqAddressAnchor = null;

        if ($type === 'SDAQ') {
            $row['display_anchor'] = $formatSdaqDisplayAnchor($anchor);
            if ($anchor && isset($sdaqMap[$anchor])) {
                $ls = $sdaqMap[$anchor];
                $explain = $ls['error_explanation'] ?? null;
                $status = $ls['status'] ?? 'Unknown';
                if (($explain && strcasecmp($explain, 'Unlinked') === 0) || strcasecmp($status, 'Unlinked') === 0) {
                    $status = 'Unknown';
                }

                $runtimeSdaqType = channel_pick_runtime_sdaq_type(
                    $busAddrKey ? ($sdaqDeviceTypes[$busAddrKey] ?? null) : null,
                    $ls['device_user_identifier'] ?? null
                );

                if (!empty($ls['cal_date'])) {
                    $row['cal_date'] = $ls['cal_date'];
                }
                if (!empty($ls['cal_period'])) {
                    $row['cal_period'] = $ls['cal_period'];
                }

                if (!empty($ls['address_anchor'])) {
                    $row['display_anchor'] = $ls['address_anchor'];
                    $sdaqAddressAnchor = (string)$ls['address_anchor'];
                }

                if (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = $ls['meas_value'];
                    $meas  = sprintf('%.3f', $value);
                    if (!empty($ls['meas_unit'])) {
                        $meas .= ' ' . $ls['meas_unit'];
                        $measUnit = $ls['meas_unit'];
                    }
                }
            }

            if ($runtimeSdaqType === null && $sdaqAddressAnchor) {
                $addrKeyFromRuntime = sdaq_cache_key_from_anchor($sdaqAddressAnchor);
                if ($addrKeyFromRuntime !== null && isset($sdaqDeviceTypes[$addrKeyFromRuntime])) {
                    $runtimeSdaqType = (string)$sdaqDeviceTypes[$addrKeyFromRuntime];
                }
            }

            if ($runtimeSdaqType === null && $busAddrKey && isset($sdaqDeviceTypes[$busAddrKey])) {
                $runtimeSdaqType = (string)$sdaqDeviceTypes[$busAddrKey];
            }

            // For SDAQ channels, keep edit popup in sync with the device's latest runtime unit.
            // This avoids showing stale OPC-UA-config unit after scale/calibration writes.
            if (is_string($measUnit) && $measUnit !== '') {
                $row['unit'] = $measUnit;
            }

            $cacheKeys = channel_sdaq_cache_keys($busAddrKey, $sdaqAddressAnchor, $anchor);
            $cachedSdaqType = channel_pick_cached_sdaq_type($typeCache, $cacheKeys);
            $isOffline = channel_status_is_offline($status);

            if (is_string($runtimeSdaqType) && trim($runtimeSdaqType) !== '') {
                $runtimeSdaqType = trim($runtimeSdaqType);
                $row['dev_type'] = $runtimeSdaqType;
                $row['dev_type_display'] = $runtimeSdaqType;
                $row['dev_type_known'] = true;
                $row['dev_type_stale'] = false;

                foreach ($cacheKeys as $cacheKey) {
                    if (!isset($typeCache[$cacheKey]) || $typeCache[$cacheKey] !== $runtimeSdaqType) {
                        $typeCache[$cacheKey] = $runtimeSdaqType;
                        $typeCacheDirty = true;
                    }
                }
            } elseif (is_string($cachedSdaqType) && trim($cachedSdaqType) !== '' && $isOffline) {
                $cachedSdaqType = trim($cachedSdaqType);
                $row['dev_type'] = $cachedSdaqType;
                $row['dev_type_display'] = $cachedSdaqType . ' (last known, offline)';
                $row['dev_type_known'] = true;
                $row['dev_type_stale'] = true;
            } elseif ($isOffline) {
                $row['dev_type'] = 'SDAQ';
                $row['dev_type_display'] = 'SDAQ (offline, subtype unknown)';
                $row['dev_type_known'] = false;
                $row['dev_type_stale'] = true;
            } else {
                $row['dev_type'] = 'SDAQ';
                $row['dev_type_display'] = 'SDAQ';
                $row['dev_type_known'] = false;
                $row['dev_type_stale'] = false;
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
        channel_apply_default_type_fields($row);

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
            'interface_type'   => 'SDAQ',
            'anchor'           => $anchor,
            'display_anchor'   => $formatSdaqDisplayAnchor($display),
            'serial_anchor'    => $chMeta['serial_anchor'] ?? null,
            'address_anchor'   => $chMeta['address_anchor'] ?? null,
            'aliases'          => $chMeta['aliases'] ?? [],
            'link_state'       => $chMeta['link_state'] ?? 'Linked',
            'has_sensor'       => !empty($chMeta['has_sensor']),
            'registration'     => $chMeta['registration'] ?? null,
            'unit'             => $chMeta['entry']['meas_unit'] ?? null,
            'meas_unit'        => $chMeta['entry']['meas_unit'] ?? null,
            'device_type'      => $chMeta['entry']['device_user_identifier'] ?? null,
            'device_type_known'=> !empty($chMeta['entry']['device_user_identifier']),
            'status'           => $chMeta['entry']['status'] ?? null,
            'is_meas_valid'    => $chMeta['entry']['is_meas_valid'] ?? null,
            'meas_value'       => $chMeta['entry']['meas_value'] ?? null,
            'linked_in_xml'    => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($ioboxMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['IOBOX'][] = [
            'interface_type' => 'IOBOX',
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
            'interface_type' => 'MTI',
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
            'interface_type' => 'NOX',
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
    unset($r);

    if ($typeCacheDirty) {
        sdaq_type_cache_write($typeCache);
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
