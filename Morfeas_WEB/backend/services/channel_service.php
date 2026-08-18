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

function channel_reserved_error_code(?float $value): ?int
{
    if ($value === null) {
        return null;
    }

    // Reserved measurement error codes are produced by core/logstat/OPC-UA.
    // The web may display these values, but it must not infer or invent them
    // from status text alone.
    $intValue = (int)round($value);
    return in_array($intValue, [-901, -902, -903, -904, -905, -906, -907], true) ? $intValue : null;
}

function channel_assign_error_code_display(array &$row, int $code, string &$meas, ?string &$measUnit): void
{
    $row['meas_error_code'] = $code;
    $row['meas_is_error_code'] = true;
    $meas = (string)$code;
    $measUnit = null;
}

function channel_assign_numeric_display(array &$row, float $value, ?string $unit, string &$meas, ?string &$measUnit): void
{
    $row['meas_error_code'] = null;
    $row['meas_is_error_code'] = false;
    $meas = sprintf('%.3f', $value);
    if (is_string($unit) && $unit !== '') {
        $meas .= ' ' . $unit;
        $measUnit = $unit;
    } else {
        $measUnit = null;
    }
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

function channel_nox_canonical_search_entry(?string $anchor): ?array
{
    $raw = trim((string)$anchor);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/^(can\w+)\.(?:sensor|addr[:_]?)(\d+)\.(NOx|O2)$/i', $raw, $m)) {
        return null;
    }

    $bus = strtolower($m[1]);
    $addr = (int)$m[2];
    $meas = strcasecmp($m[3], 'O2') === 0 ? 'O2' : 'NOx';

    return [
        'anchor' => sprintf('%s.addr_%d.%s', $bus, $addr, $meas),
        'display_anchor' => sprintf('%s.ADDR:%d.%s', strtoupper($bus), $addr, $meas),
    ];
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
        if (preg_match('/^(RX\d+)\.(Status|Success)$/i', $rest, $m) === 1) {
            return sprintf('%s.%s.%s', $prefix, strtoupper($m[1]), ucfirst(strtolower($m[2])));
        }
        return $prefix . '.' . strtoupper($rest);
    };

    $formatNoxDisplayAnchor = static function (?string $anchor): string {
        $canonical = channel_nox_canonical_search_entry($anchor);
        if ($canonical !== null) {
            return $canonical['display_anchor'];
        }

        return strtoupper(trim((string)$anchor));
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
    $mtiTeleTypes = $mtiData['tele_types'] ?? [];

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
        $row['meas_error_code'] = null;
        $row['meas_is_error_code'] = false;

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

                $errorCode = channel_reserved_error_code(isset($ls['meas_value']) ? (float)$ls['meas_value'] : null);
                if ($errorCode !== null) {
                    channel_assign_error_code_display($row, $errorCode, $meas, $measUnit);
                } elseif (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = (float)$ls['meas_value'];
                    channel_assign_numeric_display($row, $value, $ls['meas_unit'] ?? null, $meas, $measUnit);
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
            if ($isOffline) {
                $row['display_anchor'] = $formatSdaqDisplayAnchor($anchor);
            }

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
                $row['dev_type_display'] = $cachedSdaqType . ' (last known)';
                $row['dev_type_known'] = true;
                $row['dev_type_stale'] = true;
            } elseif ($isOffline) {
                $row['dev_type'] = 'SDAQ';
                $row['dev_type_display'] = 'SDAQ (unknown)';
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

                $errorCode = channel_reserved_error_code(isset($ls['meas_value']) ? (float)$ls['meas_value'] : null);
                if ($errorCode !== null) {
                    channel_assign_error_code_display($row, $errorCode, $meas, $measUnit);
                } elseif (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    $value = (float)$ls['meas_value'];
                    channel_assign_numeric_display($row, $value, $ls['meas_unit'] ?? null, $meas, $measUnit);
                }
            }

            if ($anchor && !isset($ioboxMap[$anchor])) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null && isset($ioboxConn[$deviceId]) && strcasecmp((string)$ioboxConn[$deviceId], 'Okay') === 0) {
                    $status = 'Unconfigured';
                } else {
                    $status = 'OFF-Line';
                }
            }


        } elseif ($type === 'MTI') {
            $row['display_anchor'] = $formatNetworkAnchor($anchor, $mtiIPv4);

            if ($anchor && isset($mtiMap[$anchor])) {
                $ls = $mtiMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                $errorCode = channel_reserved_error_code(isset($ls['meas_value']) ? (float)$ls['meas_value'] : null);
                if ($errorCode !== null) {
                    channel_assign_error_code_display($row, $errorCode, $meas, $measUnit);
                } elseif (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    channel_assign_numeric_display($row, (float)$ls['meas_value'], $ls['meas_unit'] ?? null, $meas, $measUnit);
                }
            } elseif ($anchor) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null &&
                    isset($mtiConn[$deviceId], $mtiTeleTypes[$deviceId]) &&
                    strcasecmp((string)$mtiConn[$deviceId], 'Okay') === 0 &&
                    strcasecmp((string)$mtiTeleTypes[$deviceId], 'Disabled') === 0) {
                    // MTI Disabled has no Tele_data in logstat, so $mtiMap is
                    // intentionally absent. If core starts emitting -901 here,
                    // handle it through channel_assign_error_code_display().
                    $status = 'Disabled';
                }
            }

            if ($status === 'OFF-Line' && $anchor) {
                $deviceId = explode('.', $anchor, 2)[0] ?? null;
                if ($deviceId !== null && isset($mtiConn[$deviceId]) && strcasecmp($mtiConn[$deviceId], 'Okay') === 0) {
                    $status = 'Disconnected';
                }
            }

        } elseif ($type === 'NOX' || $type === 'NOx') {
            // Keep the legacy/core anchor for XML, but show CAN-bus NOX paths in
            // the same user-facing style as other CAN devices.
            $row['display_anchor'] = $formatNoxDisplayAnchor($anchor);

            if ($anchor && isset($noxMap[$anchor])) {
                $ls = $noxMap[$anchor];
                $status = $ls['status'] ?? 'Unknown';

                $errorCode = channel_reserved_error_code(isset($ls['meas_value']) ? (float)$ls['meas_value'] : null);
                if ($errorCode !== null) {
                    channel_assign_error_code_display($row, $errorCode, $meas, $measUnit);
                } elseif (!empty($ls['is_meas_valid']) && $ls['meas_value'] !== null) {
                    channel_assign_numeric_display($row, (float)$ls['meas_value'], $ls['meas_unit'] ?? null, $meas, $measUnit);
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
        // Add/Replace candidates must be keyed by the stable serial anchor and a
        // completed registration; a detected-but-not-yet-registered SDAQ (or one
        // whose serial hasn't arrived yet) never becomes selectable here. It can
        // still appear elsewhere as a diagnostic row via $anchorsInXmlUpper/$rows.
        $serialAnchor = $chMeta['serial_anchor'] ?? null;
        $regStatus    = $chMeta['registration'] ?? null;
        $regDone      = is_string($regStatus) && strcasecmp($regStatus, 'Done') === 0;
        if (!$serialAnchor || !$regDone) {
            continue;
        }

        $anchor = $serialAnchor;
        $display = $chMeta['display_anchor'] ?? $anchor;

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
            'error_code'       => channel_reserved_error_code(isset($chMeta['entry']['meas_value']) ? (float)$chMeta['entry']['meas_value'] : null),
            'meas_is_error_code' => channel_reserved_error_code(isset($chMeta['entry']['meas_value']) ? (float)$chMeta['entry']['meas_value'] : null) !== null,
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
            'error_code'     => channel_reserved_error_code(isset($entry['meas_value']) ? (float)$entry['meas_value'] : null),
            'meas_is_error_code' => channel_reserved_error_code(isset($entry['meas_value']) ? (float)$entry['meas_value'] : null) !== null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    foreach ($mtiMap as $anchor => $entry) {
        $upper = strtoupper($anchor);
        $searchPool['MTI'][] = [
            'interface_type' => 'MTI',
            'anchor'         => $anchor,
            'display_anchor' => $formatNetworkAnchor($anchor, $mtiIPv4),
            'link_state'     => 'Unlinked',
            'status'         => $entry['status'] ?? null,
            'is_meas_valid'  => $entry['is_meas_valid'] ?? null,
            'meas_value'     => $entry['meas_value'] ?? null,
            'meas_unit'      => $entry['meas_unit'] ?? null,
            'error_code'     => channel_reserved_error_code(isset($entry['meas_value']) ? (float)$entry['meas_value'] : null),
            'meas_is_error_code' => channel_reserved_error_code(isset($entry['meas_value']) ? (float)$entry['meas_value'] : null) !== null,
            'linked_in_xml'  => isset($anchorsInXmlUpper[$upper]),
        ];
    }

    $noxSearch = [];
    foreach ($noxMap as $anchor => $entry) {
        $canonical = channel_nox_canonical_search_entry($anchor);
        if ($canonical === null) {
            continue;
        }

        $key = strtoupper($canonical['anchor']);
        if (!isset($noxSearch[$key])) {
            $isOfflineOnly = !empty($entry['is_offline_only']);
            $noxSearch[$key] = [
                'interface_type' => 'NOX',
                'anchor' => $canonical['anchor'],
                'display_anchor' => $canonical['display_anchor'] . ($isOfflineOnly ? ' (OFF-Line)' : ''),
                'link_state' => 'Unlinked',
                'status' => $entry['status'] ?? null,
                'is_meas_valid' => $entry['is_meas_valid'] ?? null,
                'meas_value' => $entry['meas_value'] ?? null,
                'meas_unit' => $entry['meas_unit'] ?? null,
                'error_code' => channel_reserved_error_code(isset($entry['meas_value']) ? (float)$entry['meas_value'] : null),
                'meas_is_error_code' => channel_reserved_error_code(isset($entry['meas_value']) ? (float)$entry['meas_value'] : null) !== null,
                'is_offline_only' => $isOfflineOnly,
                'aliases' => [],
                'linked_in_xml' => false,
            ];
        }

        $noxSearch[$key]['aliases'][] = $anchor;
        if (isset($anchorsInXmlUpper[strtoupper($anchor)]) || isset($anchorsInXmlUpper[$key])) {
            $noxSearch[$key]['linked_in_xml'] = true;
        }
    }

    foreach ($noxSearch as $item) {
        $item['aliases'] = array_values(array_unique($item['aliases']));
        $searchPool['NOX'][] = $item;
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

function channel_collect_rows_and_extras(
    string $xmlPath,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): array {
    $extras = [];
    $rows = channel_build_rows_with_logstat(
        $xmlPath,
        $sdaqLogFiles,
        $ioboxLogFiles,
        $mtiLogFiles,
        $noxLogFiles,
        $sdaqDeviceTypes,
        $extras
    );

    if (!is_array($extras)) {
        $extras = [];
    }


    return [$rows, $extras];
}

function channel_normalize_family(?string $raw): string
{
    return strtoupper(trim((string)$raw));
}

function channel_normalize_subtype(?string $raw): string
{
    return strtoupper(trim((string)$raw));
}

function channel_anchor_tokens(?string $anchor): array
{
    $raw = trim((string)$anchor);
    if ($raw === '') {
        return [];
    }

    $tokens = [];
    $tokens[] = strtoupper($raw);
    $tokens[] = strtoupper(preg_replace('/\s+/', '', $raw) ?? $raw);

    if (preg_match('/^(CAN\w+)\.(\d+)\.CH(\d+)$/i', $raw, $m)) {
        $tokens[] = sprintf('%s.ADDR:%02d.CH:%02d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
        $tokens[] = sprintf('%s.ADDR:%d.CH:%d', strtoupper($m[1]), (int)$m[2], (int)$m[3]);
    }

    return array_values(array_unique(array_filter($tokens)));
}

function channel_search_pool_all_candidates(array $searchPool): array
{
    $all = [];
    foreach ($searchPool as $family => $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['interface_type'])) {
                $item['interface_type'] = strtoupper((string)$family);
            }
            $all[] = $item;
        }
    }
    return $all;
}

function channel_candidate_family(array $candidate): string
{
    $family = channel_normalize_family($candidate['interface_type'] ?? '');
    if ($family !== '') {
        return $family;
    }

    $deviceType = channel_normalize_subtype($candidate['device_type'] ?? '');
    if (str_starts_with($deviceType, 'SDAQ')) {
        return 'SDAQ';
    }
    if (str_starts_with($deviceType, 'IOBOX')) {
        return 'IOBOX';
    }
    if (str_starts_with($deviceType, 'MTI')) {
        return 'MTI';
    }
    if (str_starts_with($deviceType, 'NOX')) {
        return 'NOX';
    }
    return '';
}

function channel_find_candidate_by_anchor(array $searchPool, string $anchor): ?array
{
    $tokens = channel_anchor_tokens($anchor);
    if (empty($tokens)) {
        return null;
    }

    $candidates = channel_search_pool_all_candidates($searchPool);
    foreach ($candidates as $candidate) {
        $keys = [];
        foreach (['anchor', 'display_anchor', 'address_anchor', 'serial_anchor'] as $field) {
            if (!empty($candidate[$field])) {
                $keys = array_merge($keys, channel_anchor_tokens((string)$candidate[$field]));
            }
        }

        if (!empty($candidate['aliases']) && is_array($candidate['aliases'])) {
            foreach ($candidate['aliases'] as $alias) {
                $keys = array_merge($keys, channel_anchor_tokens((string)$alias));
            }
        }

        if (empty($keys)) {
            continue;
        }

        $keys = array_values(array_unique($keys));
        foreach ($tokens as $needle) {
            if (in_array($needle, $keys, true)) {
                return $candidate;
            }
        }
    }

    return null;
}

/*
 * Server-side re-derivation for SDAQ Add: the client-submitted `anchor` is
 * never trusted as the identity to persist. It is only used to locate a
 * currently-detected, registered, not-yet-linked SDAQ candidate in a search
 * pool rebuilt inside the XML lock; the anchor actually written is always
 * the candidate's own canonical serial anchor. This closes the incident
 * entry point: a display/CAN-address string can no longer be written to
 * OPC_UA_Config.xml as if it were the device identity, even via a direct
 * API call.
 */
function channel_add_sdaq_from_pool(
    string $xmlPath,
    array $data,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): void {
    iso_with_xml_lock($xmlPath, function () use (
        $xmlPath, $data, $sdaqLogFiles, $ioboxLogFiles, $mtiLogFiles, $noxLogFiles, $sdaqDeviceTypes
    ) {
        $anchorInput = trim((string)($data['anchor'] ?? ''));
        if ($anchorInput === '') {
            throw new ChannelConfigException('Missing field: anchor', 400, 'missing_field');
        }

        [, $extras] = channel_collect_rows_and_extras(
            $xmlPath,
            $sdaqLogFiles,
            $ioboxLogFiles,
            $mtiLogFiles,
            $noxLogFiles,
            $sdaqDeviceTypes
        );
        $searchPool = is_array($extras['search_pool'] ?? null) ? $extras['search_pool'] : [];
        $candidate = channel_find_candidate_by_anchor($searchPool, $anchorInput);

        if ($candidate === null || channel_candidate_family($candidate) !== 'SDAQ') {
            throw new ChannelConfigException(
                'SDAQ candidate is not currently available: ' . $anchorInput,
                409,
                'candidate_not_available'
            );
        }
        if (!empty($candidate['linked_in_xml'])) {
            throw new ChannelConfigException(
                'SDAQ candidate is already linked: ' . $anchorInput,
                409,
                'candidate_not_available'
            );
        }

        $serialAnchor = trim((string)($candidate['serial_anchor'] ?? ''));
        if (!iso_sdaq_anchor_is_valid($serialAnchor)) {
            throw new ChannelConfigException(
                'SDAQ candidate does not have a valid serial anchor yet: ' . $anchorInput,
                409,
                'candidate_not_available'
            );
        }

        $serverData = $data;
        $serverData['anchor'] = $serialAnchor;
        iso_add_channel_body($xmlPath, $serverData);
    });
}

/*
 * Server-side re-derivation for Replace: same principle as
 * channel_add_sdaq_from_pool(), applied to the PATCH replace_mode path. The
 * client-submitted `anchor` is only used to locate a currently-detected,
 * compatible, not-already-linked candidate in a search pool rebuilt inside
 * the XML lock; the value actually written is always the candidate's own
 * canonical identity (serial anchor for SDAQ, pool anchor otherwise), never
 * the client-submitted display/address text. This also removes the previous
 * silent pass-through for "source SDAQ subtype unknown and no candidate
 * found": that case is now blocked with a stable error, matching the rule
 * that an unverifiable target must never be written.
 */
function channel_replace_channel_from_pool(
    string $xmlPath,
    string $iso,
    array $data,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): void {
    iso_with_xml_lock($xmlPath, function () use (
        $xmlPath, $iso, $data, $sdaqLogFiles, $ioboxLogFiles, $mtiLogFiles, $noxLogFiles, $sdaqDeviceTypes
    ) {
        $targetAnchorInput = trim((string)($data['anchor'] ?? ''));
        if ($targetAnchorInput === '') {
            throw new ChannelConfigException('Missing replacement anchor', 400, 'replace_target_missing');
        }

        $extras = [];
        $rows = channel_build_rows_with_logstat(
            $xmlPath,
            $sdaqLogFiles,
            $ioboxLogFiles,
            $mtiLogFiles,
            $noxLogFiles,
            $sdaqDeviceTypes,
            $extras
        );
        if (!is_array($extras)) {
            $extras = [];
        }

        $source = channel_find_by_iso($rows, $iso);
        if ($source === null) {
            throw new ChannelConfigException('Source channel not found for replace', 404, 'replace_source_not_found');
        }

        // Replace is an identity migration, not a metadata edit: the backend
        // must re-confirm the source is actually offline at write time, using
        // the same freshly rebuilt runtime rows as the candidate pool below.
        // The frontend only ever shows Replace for an offline source, but that
        // is not a security boundary; a direct API call must be re-checked
        // here, inside the same lock as the write, or a live/connected
        // channel's identity could be silently reassigned.
        if (!channel_status_is_offline((string)($source['status'] ?? ''))) {
            throw new ChannelConfigException(
                'Source channel must be offline for Replace: ' . $iso,
                409,
                'replace_source_not_offline'
            );
        }

        $sourceFamily = channel_normalize_family($source['interface_type'] ?? ($source['dev_type'] ?? ''));
        if ($sourceFamily === '') {
            // An unresolvable source family must never silently skip the
            // cross-family check below; it must be rejected outright.
            throw new ChannelConfigException(
                'Source channel interface type is unknown; cannot verify Replace compatibility: ' . $iso,
                409,
                'replace_source_family_unknown'
            );
        }
        $sourceSubtype = trim((string)($source['dev_type'] ?? ''));
        $sourceKnown = !empty($source['dev_type_known']);

        $searchPool = is_array($extras['search_pool'] ?? null) ? $extras['search_pool'] : [];
        $candidate = channel_find_candidate_by_anchor($searchPool, $targetAnchorInput);

        // Unlike the pre-fix behaviour, an unresolved target is always
        // rejected, even when the source subtype was never known: syntax
        // matching a pool token is not authorization, and a target that
        // cannot be verified must never be written, silently or otherwise.
        if ($candidate === null) {
            throw new ChannelConfigException(
                'Replacement target type cannot be verified from current device pool',
                409,
                'replace_target_not_detected'
            );
        }

        // $sourceFamily is already known non-empty (checked above). An
        // unresolvable candidate family must be rejected, not silently
        // treated as a match: family compatibility can only be waived when
        // there is nothing to compare, and here there always is.
        $candidateFamily = channel_candidate_family($candidate);
        if ($candidateFamily === '' || $candidateFamily !== $sourceFamily) {
            throw new ChannelConfigException(
                sprintf('Replace type mismatch: expected %s, got %s', $sourceFamily, $candidateFamily !== '' ? $candidateFamily : 'unknown'),
                409,
                'replace_type_mismatch'
            );
        }

        if ($sourceFamily === 'SDAQ') {
            if (!$sourceKnown) {
                throw new ChannelConfigException(
                    'Source SDAQ subtype is unknown; cannot enforce compatibility',
                    409,
                    'replace_sdaq_subtype_unknown'
                );
            }

            $sourceSubtypeNorm = channel_normalize_subtype($sourceSubtype);
            $candidateSubtypeRaw = trim((string)($candidate['device_type'] ?? ''));
            $candidateSubtypeNorm = channel_normalize_subtype($candidateSubtypeRaw);

            if ($candidateSubtypeNorm === '') {
                throw new ChannelConfigException(
                    'Replacement SDAQ subtype is unknown; choose a detected SDAQ device',
                    409,
                    'replace_sdaq_subtype_unknown'
                );
            }

            if ($sourceSubtypeNorm !== $candidateSubtypeNorm) {
                throw new ChannelConfigException(
                    sprintf('Replace SDAQ subtype mismatch: expected %s, got %s', $sourceSubtype, $candidateSubtypeRaw),
                    409,
                    'replace_sdaq_subtype_mismatch'
                );
            }
        }

        // Never persist the client-submitted display/address text: always
        // resolve to the candidate's own canonical identity, exactly like Add.
        $canonicalAnchor = $candidateFamily === 'SDAQ'
            ? trim((string)($candidate['serial_anchor'] ?? ''))
            : trim((string)($candidate['anchor'] ?? ''));

        if ($candidateFamily === 'SDAQ' && !iso_sdaq_anchor_is_valid($canonicalAnchor)) {
            throw new ChannelConfigException(
                'Replacement SDAQ candidate does not have a valid serial anchor: ' . $targetAnchorInput,
                409,
                'replace_target_not_detected'
            );
        }
        if ($canonicalAnchor === '') {
            throw new ChannelConfigException(
                'Replacement candidate does not have a resolvable anchor: ' . $targetAnchorInput,
                409,
                'replace_target_not_detected'
            );
        }

        // Replace only ever changes identity (anchor) and mod_date; per plan
        // 5.4 it must preserve ISO/description/min/max/unit/alarms/build_date
        // unconditionally. Do not forward the raw client PATCH body here: a
        // replace_mode request that also carries iso_channel/description/etc.
        // must not be able to smuggle an Edit through the Replace path.
        // iso_update_channel_body() keeps every field it does not receive.
        iso_update_channel_body($xmlPath, $iso, ['anchor' => $canonicalAnchor]);
    });
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
