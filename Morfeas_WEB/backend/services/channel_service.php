<?php

require_once __DIR__ . '/../core/opcua_config.php';
require_once __DIR__ . '/../core/logstat_sdaq.php';
require_once __DIR__ . '/../core/logstat_iobox.php';
require_once __DIR__ . '/../core/logstat_mti.php';
require_once __DIR__ . '/../core/logstat_nox.php';
require_once __DIR__ . '/../core/sdaq_type_cache.php';
// Add and Local JSON Restore use the same static handler identity rules.
require_once __DIR__ . '/channel_restore_service.php';

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

    // Error codes come from Core; status text must not be converted into one.
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

            // SDAQ Unit is runtime-owned; keep Edit in sync with live metadata.
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
                    // IOBOX Unit is XML-owned; logstat's °C is only a placeholder.
                    channel_assign_numeric_display($row, $value, $row['unit'] ?? null, $meas, $measUnit);
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
                    // MTI Unit is XML-owned; do not display a logstat placeholder.
                    channel_assign_numeric_display($row, (float)$ls['meas_value'], $row['unit'] ?? null, $meas, $measUnit);
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
                    // NOX Unit is XML-owned after Add/Edit; ppm/% is candidate-only.
                    channel_assign_numeric_display($row, (float)$ls['meas_value'], $row['unit'] ?? null, $meas, $measUnit);
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
        // Only registered SDAQs with a stable serial become Add/Replace candidates.
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
            // No device-reported IOBOX Unit exists; keep Add's required Unit empty.
            'meas_unit'      => null,
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
            // MTI's logstat Unit is a placeholder, not an Add default.
            'meas_unit'      => null,
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

/* Add and Replace persist the same canonical identity for a candidate. */
function channel_candidate_canonical_anchor(array $candidate): string
{
    if (channel_candidate_family($candidate) === 'SDAQ') {
        return trim((string)($candidate['serial_anchor'] ?? ''));
    }
    return trim((string)($candidate['anchor'] ?? ''));
}

/*
 * Rebuild the live candidate pool inside the XML lock. Client anchor text
 * selects a candidate; only that candidate's canonical identity is written.
 */
function channel_add_channel_from_pool(
    string $xmlPath,
    string $logConfigPath,
    array $data,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): void {
    // Resolved before any lock is taken: a malformed request should not
    // queue behind a write, and the requested family decides which locks
    // this call needs. The family is only a *request* here -- it is checked
    // against the resolved candidate's real family below, so an IOBOX
    // candidate can never be reached through a path that declared SDAQ and
    // skipped the log_config lock.
    $anchorInput = trim((string)($data['anchor'] ?? ''));
    if ($anchorInput === '') {
        throw new ChannelConfigException('Missing field: anchor', 400, 'missing_field');
    }
    $requestedFamily = channel_normalize_family($data['interface_type'] ?? '');
    if ($requestedFamily === '') {
        throw new ChannelConfigException('Missing field: interface_type', 400, 'missing_field');
    }
    if ($requestedFamily === 'SDAQ' && array_key_exists('unit', $data)) {
        throw new ChannelConfigException(
            'SDAQ Unit is supplied by live runtime metadata and must not be stored in OPC_UA_Config.xml',
            400,
            'sdaq_unit_not_allowed'
        );
    }
    $needsLogConfig = in_array($requestedFamily, ['IOBOX', 'MTI'], true);

    $body = function () use (
        $xmlPath, $logConfigPath, $data, $anchorInput, $requestedFamily, $needsLogConfig,
        $sdaqLogFiles, $ioboxLogFiles, $mtiLogFiles, $noxLogFiles, $sdaqDeviceTypes
    ) {
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

        if ($candidate === null || channel_candidate_family($candidate) !== $requestedFamily) {
            throw new ChannelConfigException(
                "$requestedFamily candidate is not currently available: " . $anchorInput,
                409,
                'candidate_not_available'
            );
        }
        if (!empty($candidate['linked_in_xml'])) {
            throw new ChannelConfigException(
                "$requestedFamily candidate is already linked: " . $anchorInput,
                409,
                'candidate_not_available'
            );
        }

        $canonicalAnchor = channel_candidate_canonical_anchor($candidate);
        if ($requestedFamily === 'SDAQ' && !iso_sdaq_anchor_is_valid($canonicalAnchor)) {
            throw new ChannelConfigException(
                'SDAQ candidate does not have a valid serial anchor yet: ' . $anchorInput,
                409,
                'candidate_not_available'
            );
        }
        if ($canonicalAnchor === '') {
            throw new ChannelConfigException(
                "$requestedFamily candidate does not have a resolvable anchor: " . $anchorInput,
                409,
                'candidate_not_available'
            );
        }

        // The candidate pool above is rebuilt from ramdisk logstat only.
        // Deleting an IOBOX/MTI device handler does not remove the
        // logstat file it left behind, so between the delete and the next
        // Core restart a stale logstat still presents that device as an
        // available candidate -- and Add would write an ISO channel
        // anchored to a handler that no longer exists, permanently offline
        // and invisible to the delete that caused it.
        //
        // The static side is therefore re-read here, under the log_config
        // lock held around this whole closure, with the same rule Local
        // JSON Restore applies (restore_check_device_handler(), shared by
        // both). That rule asks whether a handler is CONFIGURED and
        // ENABLED (P2: a Disable="true" handler cannot make a new channel
        // work, so it is not treated as satisfying this gate either); the
        // liveness half is already covered, because the candidate had to
        // appear in the freshly rebuilt runtime pool to get this far. The
        // conditions compose into "configured, enabled, AND currently
        // detected", which is what Add has always meant.
        if ($needsLogConfig) {
            $identity = iso_parse_source_identity($requestedFamily, $canonicalAnchor);
            if ($identity === null) {
                throw new ChannelConfigException(
                    "$requestedFamily candidate anchor could not be parsed: $canonicalAnchor",
                    409,
                    'candidate_not_available'
                );
            }
            $problem = restore_check_device_handler(
                $requestedFamily,
                $identity,
                restore_load_device_identifiers($logConfigPath)
            );
            if ($problem !== null) {
                // "no matching handler" would be a wrong (self-contradicting)
                // prefix for device_handler_disabled: a handler DOES match,
                // it just cannot make the channel work while disabled.
                $prefix = $problem['code'] === 'device_handler_disabled'
                    ? "$requestedFamily candidate's handler is not usable"
                    : "$requestedFamily candidate has no matching handler in Morfeas_Config.xml";
                throw new ChannelConfigException(
                    "$prefix: " . $problem['detail'],
                    409,
                    $problem['code']
                );
            }
        }

        $serverData = $data;
        $serverData['interface_type'] = $requestedFamily;
        $serverData['anchor'] = $canonicalAnchor;
        iso_add_channel_body($xmlPath, $serverData);
    };

    // Fixed lock order prevents deadlock with FTP Restore. SDAQ/NOX need no
    // log-config lock because their identities do not depend on manual handlers.
    if ($needsLogConfig) {
        log_config_with_xml_lock($logConfigPath, function () use ($xmlPath, $body) {
            iso_with_xml_lock($xmlPath, $body);
        });
        return;
    }
    iso_with_xml_lock($xmlPath, $body);
}

/* Re-resolve and validate every Range Add item under one lock; commit all or none. */
function channel_add_sdaq_range_from_pool(
    string $xmlPath,
    array $items,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): void {
    iso_with_xml_lock($xmlPath, function () use (
        $xmlPath, $items, $sdaqLogFiles, $ioboxLogFiles, $mtiLogFiles, $noxLogFiles, $sdaqDeviceTypes
    ) {
        if (empty($items)) {
            throw new ChannelConfigException('Batch must contain at least one item', 400, 'missing_field');
        }

        if (!file_exists($xmlPath)) {
            throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
        }
        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
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

        // Pass 1: resolve every item against the live pool. No writes yet.
        $usedSerialAnchors = [];
        $usedIsoChannels = [];
        $resolved = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ChannelConfigException("Invalid batch item at index $idx", 400, 'missing_field');
            }
            if (array_key_exists('unit', $item)) {
                throw new ChannelConfigException(
                    "SDAQ Unit is runtime-owned and is not accepted for batch item #$idx",
                    400,
                    'sdaq_unit_not_allowed'
                );
            }
            $anchorInput = trim((string)($item['anchor'] ?? ''));
            $isoChannelRaw = (string)($item['iso_channel'] ?? '');
            if ($anchorInput === '' || trim($isoChannelRaw) === '') {
                throw new ChannelConfigException("Missing anchor or iso_channel for batch item #$idx", 400, 'missing_field');
            }

            $candidate = channel_find_candidate_by_anchor($searchPool, $anchorInput);
            if ($candidate === null || channel_candidate_family($candidate) !== 'SDAQ') {
                throw new ChannelConfigException(
                    "SDAQ candidate is not currently available for batch item #$idx: " . $anchorInput,
                    409,
                    'candidate_not_available'
                );
            }
            if (!empty($candidate['linked_in_xml'])) {
                throw new ChannelConfigException(
                    "SDAQ candidate is already linked for batch item #$idx: " . $anchorInput,
                    409,
                    'candidate_not_available'
                );
            }

            $serialAnchor = trim((string)($candidate['serial_anchor'] ?? ''));
            if (!iso_sdaq_anchor_is_valid($serialAnchor)) {
                throw new ChannelConfigException(
                    "SDAQ candidate does not have a valid serial anchor yet for batch item #$idx: " . $anchorInput,
                    409,
                    'candidate_not_available'
                );
            }

            // Two batch items resolving to the same physical channel (e.g. a
            // client bug submitting the same anchor twice) must be rejected
            // as a batch-internal duplicate, not silently create one channel
            // then conflict-reject the other.
            if (isset($usedSerialAnchors[$serialAnchor])) {
                throw new ChannelConfigException(
                    "Batch requests the same SDAQ candidate more than once: " . $serialAnchor,
                    409,
                    'duplicate_source'
                );
            }
            $usedSerialAnchors[$serialAnchor] = true;

            $isoNorm = iso_normalize_iso_channel($isoChannelRaw);
            if (isset($usedIsoChannels[$isoNorm])) {
                throw new ChannelConfigException(
                    "Batch requests the same ISO_CHANNEL more than once: " . $isoNorm,
                    409,
                    'channel_conflict'
                );
            }
            $usedIsoChannels[$isoNorm] = true;

            $resolved[] = array_merge($item, ['anchor' => $serialAnchor]);
        }

        // Pass 2: validate every resolved item against the file as it stood
        // when the lock was acquired. Still no writes.
        $payloads = [];
        foreach ($resolved as $data) {
            $isoChannel = iso_normalize_iso_channel((string)$data['iso_channel']);
            foreach ($xml->CHANNEL as $ch) {
                if ((string)$ch->ISO_CHANNEL === $isoChannel) {
                    throw new ChannelConfigException("ISO_CHANNEL already exists: " . $isoChannel, 409, 'channel_conflict');
                }
            }

            $identity = iso_require_valid_source_identity('SDAQ', (string)$data['anchor']);
            $conflict = iso_find_anchor_conflict($xml, $identity['semantic_key']);
            if ($conflict !== null) {
                throw new ChannelConfigException(
                    "ANCHOR already exists: " . $identity['canonical_anchor'] . " is already used by " . $conflict,
                    409,
                    'duplicate_source'
                );
            }

            $data['interface_type'] = 'SDAQ';
            $data['anchor'] = $identity['canonical_anchor'];
            $payloads[] = iso_build_new_channel_payload($isoChannel, $data);
        }

        // Mutate only after the full batch has passed validation.
        foreach ($payloads as $payload) {
            $new = $xml->addChild('CHANNEL');
            iso_set_channel_contents($new, $payload);
        }
        iso_save_xml($xml, $xmlPath);
    });
}

/* Replace resolves a live compatible candidate under lock, then writes its identity. */
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
    if (array_key_exists('unit', $data)) {
        throw new ChannelConfigException(
            'SDAQ Unit is runtime-owned and cannot be supplied during Replace',
            400,
            'sdaq_unit_not_allowed'
        );
    }

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

        // Replace is only offered by the UI for SDAQ (the device-relocation
        // scenario this operation exists for); IOBOX/MTI/NOX identity moves
        // go through Devices + Add/Delete instead. Re-enforce that here, and
        // ahead of the offline check below, so a direct API call cannot reach
        // a code path the UI never exposes, and so an online IOBOX/MTI/NOX
        // channel is rejected for the real reason ("not SDAQ") rather than a
        // misleading "must be offline" that has nothing to do with why
        // Replace refuses it.
        if ($sourceFamily !== 'SDAQ') {
            throw new ChannelConfigException(
                'Replace is only available for SDAQ channels: ' . $iso,
                409,
                'replace_source_not_sdaq'
            );
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
        $canonicalAnchor = channel_candidate_canonical_anchor($candidate);

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

/* TC16 Replace All is kept here so the write operation can be unit-tested. */

class ChannelRuleException extends RuntimeException
{
    private int $status;
    private string $apiCode;

    public function __construct(string $message, int $status = 409, string $apiCode = 'channel_rule_violation')
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

function channel_normalize_tc16_subtype(?string $raw): string
{
    $text = strtoupper(trim((string)$raw));
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s*\(.*$/', '', $text) ?? $text;
    return trim($text);
}

function channel_is_tc16_compatible(?string $raw): bool
{
    return channel_normalize_tc16_subtype($raw) === 'SDAQ-TC16';
}

function channel_row_has_known_non_tc16_subtype(array $row): bool
{
    $subtype = channel_normalize_tc16_subtype((string)($row['dev_type_display'] ?? ($row['dev_type'] ?? '')));
    if ($subtype === 'SDAQ-TC16') {
        return false;
    }

    $known = !empty($row['dev_type_known']);
    return $known && $subtype !== '' && $subtype !== 'SDAQ';
}

function channel_parse_sn_channel(?string $anchor): ?array
{
    $raw = trim((string)$anchor);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/^(\d+)\.CH:?(\d{1,3})$/i', $raw, $m)) {
        return [
            'sn' => (string)$m[1],
            'ch' => (int)$m[2],
        ];
    }

    return null;
}

function channel_row_is_sdaq(array $row): bool
{
    $family = channel_normalize_family($row['interface_type'] ?? ($row['dev_type'] ?? ''));
    if ($family === 'SDAQ') {
        return true;
    }
    return str_starts_with(channel_normalize_subtype($row['dev_type'] ?? ''), 'SDAQ');
}

function channel_row_sn_info(array $row): ?array
{
    $raw = channel_parse_sn_channel((string)($row['anchor'] ?? ''));
    if ($raw !== null) {
        return $raw;
    }
    return channel_parse_sn_channel((string)($row['display_anchor'] ?? ''));
}

function channel_group_is_full16(array $group): bool
{
    for ($ch = 1; $ch <= 16; $ch++) {
        if (!isset($group[$ch])) {
            return false;
        }
    }
    return true;
}

function channel_group_by_sn(array $rows, string $sn): array
{
    $group = [];
    foreach ($rows as $row) {
        if (!channel_row_is_sdaq($row)) {
            continue;
        }
        $snInfo = channel_row_sn_info($row);
        if ($snInfo === null || (string)$snInfo['sn'] !== (string)$sn) {
            continue;
        }
        $ch = (int)($snInfo['ch'] ?? 0);
        if ($ch < 1 || $ch > 16) {
            continue;
        }
        if (!isset($group[$ch])) {
            $group[$ch] = $row;
        }
    }
    return $group;
}

function channel_group_to_source_map(array $group, string $mode, string $sourceKey): array
{
    ksort($group, SORT_NUMERIC);
    $items = [];
    foreach ($group as $ch => $row) {
        $items[] = [
            'ch_no' => (int)$ch,
            'iso_channel' => (string)($row['iso_channel'] ?? ''),
            'anchor' => (string)($row['anchor'] ?? ''),
            'display_anchor' => (string)($row['display_anchor'] ?? ($row['anchor'] ?? '')),
            'source_mode' => $mode,
            'source_key' => $sourceKey,
        ];
    }
    return $items;
}

function channel_tc16_source_serial(array $sourceGroup): string
{
    $sourceKey = trim((string)($sourceGroup['source_key'] ?? ''));
    if (preg_match('/^SN:(.+)$/i', $sourceKey, $m)) {
        return strtoupper(trim((string)$m[1]));
    }
    return '';
}

function channel_collect_sdaq_capabilities(array $sdaqLogFiles): array
{
    $devices = [];

    foreach ($sdaqLogFiles as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $bus = strtoupper(sdaq_detect_bus($data, $path));
        $sdaqs = $data['SDAQs_data'] ?? null;
        if (!is_array($sdaqs)) {
            continue;
        }

        foreach ($sdaqs as $dev) {
            if (!is_array($dev)) {
                continue;
            }
            $addr = $dev['Address'] ?? null;
            if ($addr === null) {
                continue;
            }

            $addrInt = (int)$addr;
            $type = trim((string)($dev['SDAQ_type'] ?? ''));
            $serial = trim((string)($dev['Serial_number'] ?? ''));
            $numChannels = (int)($dev['SDAQ_info']['Number_of_channels'] ?? 0);
            $regStatus = $dev['SDAQ_Status']['Registration_status'] ?? null;
            $regDone = is_string($regStatus) && strcasecmp($regStatus, 'Done') === 0;

            // Which channel numbers are actually reported in this reading's
            // Meas array -- "declared 16-channel capable" is not the same as
            // "all 16 channels are currently, really detected".
            $detectedChannels = [];
            $measArr = $dev['Meas'] ?? [];
            if (is_array($measArr)) {
                foreach ($measArr as $meas) {
                    $ch = $meas['Channel'] ?? null;
                    if ($ch !== null) {
                        $detectedChannels[(int)$ch] = true;
                    }
                }
            }

            $deviceKey = sprintf('%s.ADDR:%02d', $bus, $addrInt);

            $devices[$deviceKey] = [
                'device_key' => $deviceKey,
                'bus' => $bus,
                'address' => $addrInt,
                'serial' => $serial,
                'sdaq_type' => $type,
                'number_of_channels' => $numChannels,
                'registration_done' => $regDone,
                'detected_channels' => $detectedChannels,
            ];
        }
    }

    ksort($devices, SORT_STRING);
    return $devices;
}

function channel_target_aliases_for_channel(array $device, int $ch): array
{
    $bus = strtoupper((string)($device['bus'] ?? ''));
    $addr = (int)($device['address'] ?? 0);
    $serial = trim((string)($device['serial'] ?? ''));

    $aliases = [
        sprintf('%s.ADDR:%02d.CH:%02d', $bus, $addr, $ch),
        sprintf('%s.ADDR:%d.CH:%d', $bus, $addr, $ch),
        sprintf('%s.ADDR:%d.CH%d', $bus, $addr, $ch),
        sprintf('%s.%d.CH%d', $bus, $addr, $ch),
        sprintf('%s.%d.CH:%d', $bus, $addr, $ch),
    ];

    if ($serial !== '') {
        $aliases[] = sprintf('%s.CH%d', $serial, $ch);
    }

    return array_values(array_unique(array_filter($aliases)));
}

function channel_target_anchor_for_channel(array $device, int $ch): string
{
    // No canX.address.CHn fallback: a target without a stable serial can
    // never be assigned a canonical anchor. Callers must have already
    // rejected such a target via channel_validate_tc16_target() before
    // reaching here; this is a defense-in-depth guard, not the primary check.
    $serial = trim((string)($device['serial'] ?? ''));
    if ($serial === '') {
        throw new ChannelRuleException('Target device does not have a valid serial', 409, 'tc16_target_serial_missing');
    }
    return sprintf('%s.CH%d', $serial, $ch);
}

function channel_anchor_usage_from_rows(array $rows): array
{
    $usage = [];
    foreach ($rows as $row) {
        $iso = strtoupper(trim((string)($row['iso_channel'] ?? '')));
        if ($iso === '') {
            continue;
        }

        $tokens = channel_anchor_tokens((string)($row['anchor'] ?? ''));
        foreach ($tokens as $token) {
            if (!isset($usage[$token])) {
                $usage[$token] = [];
            }
            $usage[$token][$iso] = true;
        }
    }

    return $usage;
}

function channel_target_channel_is_unlinked(array $usage, array $device, int $ch, array $ignoreIsoSet = []): bool
{
    $aliases = channel_target_aliases_for_channel($device, $ch);
    foreach ($aliases as $alias) {
        $tokens = channel_anchor_tokens($alias);
        foreach ($tokens as $token) {
            if (empty($usage[$token])) {
                continue;
            }

            foreach (array_keys($usage[$token]) as $iso) {
                if (!isset($ignoreIsoSet[$iso])) {
                    return false;
                }
            }
        }
    }

    return true;
}

function channel_resolve_tc16_source_group(array $rows, string $sourceIso): array
{
    $sourceIsoNorm = iso_normalize_iso_channel($sourceIso);
    if ($sourceIsoNorm === null || $sourceIsoNorm === '') {
        throw new ChannelRuleException('Missing source ISO', 400, 'missing_source_iso');
    }

    $source = channel_find_by_iso($rows, $sourceIsoNorm);
    if ($source === null) {
        throw new ChannelRuleException('Source channel not found', 404, 'tc16_source_unresolvable');
    }

    if (!channel_status_is_offline((string)($source['status'] ?? ''))) {
        throw new ChannelRuleException('Source channel must be offline for Replace TC16', 409, 'tc16_source_not_offline');
    }

    if (!channel_row_is_sdaq($source)) {
        throw new ChannelRuleException('Source channel must be SDAQ for Replace TC16', 409, 'tc16_source_not_sdaq');
    }

    if (channel_row_has_known_non_tc16_subtype($source)) {
        throw new ChannelRuleException('Source channel subtype is not SDAQ-TC16', 409, 'tc16_subtype_mismatch');
    }

    $snInfo = channel_row_sn_info($source);
    if ($snInfo !== null) {
        $snGroup = channel_group_by_sn($rows, (string)$snInfo['sn']);
        if (channel_group_is_full16($snGroup)) {
            foreach ($snGroup as $row) {
                if (channel_row_has_known_non_tc16_subtype($row)) {
                    throw new ChannelRuleException('SN group contains non TC16-compatible channels', 409, 'tc16_subtype_mismatch');
                }
            }

            return [
                'source' => $source,
                'mode' => 'sn',
                'source_key' => 'SN:' . (string)$snInfo['sn'],
                'channels' => $snGroup,
            ];
        }
    }

    throw new ChannelRuleException('Source TC16 serial anchor group is not full CH1..CH16', 409, 'tc16_source_not_full');
}

function channel_collect_tc16_target_candidates(array $rows, array $devices, array $sourceGroup): array
{
    $usage = channel_anchor_usage_from_rows($rows);
    $sourceKey = strtoupper((string)($sourceGroup['source_key'] ?? ''));
    $sourceSerial = channel_tc16_source_serial($sourceGroup);

    $items = [];
    foreach ($devices as $deviceKey => $device) {
        $key = strtoupper((string)$deviceKey);
        if ($key === $sourceKey) {
            continue;
        }

        $deviceSerial = strtoupper(trim((string)($device['serial'] ?? '')));
        if ($deviceSerial === '') {
            continue; // no stable serial yet -- never offered as a target
        }
        if ($sourceSerial !== '' && $deviceSerial === $sourceSerial) {
            continue;
        }

        if (!channel_is_tc16_compatible((string)($device['sdaq_type'] ?? ''))) {
            continue;
        }

        if ((int)($device['number_of_channels'] ?? 0) !== 16) {
            continue;
        }

        if (empty($device['registration_done'])) {
            continue;
        }

        $detected = is_array($device['detected_channels'] ?? null) ? $device['detected_channels'] : [];
        $allDetected = true;
        for ($ch = 1; $ch <= 16; $ch++) {
            if (empty($detected[$ch])) {
                $allDetected = false;
                break;
            }
        }
        if (!$allDetected) {
            continue;
        }

        $allFree = true;
        for ($ch = 1; $ch <= 16; $ch++) {
            if (!channel_target_channel_is_unlinked($usage, $device, $ch)) {
                $allFree = false;
                break;
            }
        }

        if (!$allFree) {
            continue;
        }

        $items[] = [
            'device_key' => (string)$device['device_key'],
            'bus' => (string)$device['bus'],
            'address' => (int)$device['address'],
            'serial' => (string)($device['serial'] ?? ''),
            'sdaq_type' => (string)$device['sdaq_type'],
            'number_of_channels' => (int)$device['number_of_channels'],
            'available_channels' => range(1, 16),
        ];
    }

    usort($items, static function ($a, $b) {
        return strcmp((string)$a['device_key'], (string)$b['device_key']);
    });

    return $items;
}

function channel_validate_tc16_target(array $rows, array $device, array $sourceGroup): void
{
    $sourceSerial = channel_tc16_source_serial($sourceGroup);
    $targetSerial = strtoupper(trim((string)($device['serial'] ?? '')));

    // No canX.address.CHn fallback: a target without a stable serial can
    // never be assigned a canonical anchor.
    if ($targetSerial === '') {
        throw new ChannelRuleException('Target device does not have a valid serial yet', 409, 'tc16_target_serial_missing');
    }
    if ($sourceSerial !== '' && $targetSerial === $sourceSerial) {
        throw new ChannelRuleException('Target device matches source TC16 serial', 409, 'tc16_target_is_source');
    }

    if (!channel_is_tc16_compatible((string)($device['sdaq_type'] ?? ''))) {
        throw new ChannelRuleException('Target device subtype is not SDAQ-TC16', 409, 'tc16_subtype_mismatch');
    }

    if ((int)($device['number_of_channels'] ?? 0) !== 16) {
        throw new ChannelRuleException('Target device is not full 16-channel capable', 409, 'tc16_target_not_full');
    }

    if (empty($device['registration_done'])) {
        throw new ChannelRuleException('Target device registration is not Done', 409, 'tc16_target_not_registered');
    }

    // Declared 16-channel capable is not the same as all 16 channels being
    // physically detected right now; No_Sensor is fine (it's still a real
    // physical channel), a channel simply absent from this reading is not.
    $detected = is_array($device['detected_channels'] ?? null) ? $device['detected_channels'] : [];
    for ($ch = 1; $ch <= 16; $ch++) {
        if (empty($detected[$ch])) {
            throw new ChannelRuleException("Target device channel CH$ch is not currently detected", 409, 'tc16_target_channel_not_detected');
        }
    }

    $usage = channel_anchor_usage_from_rows($rows);
    $ignoreIsoSet = [];
    foreach ($sourceGroup['channels'] as $row) {
        $iso = strtoupper(trim((string)($row['iso_channel'] ?? '')));
        if ($iso !== '') {
            $ignoreIsoSet[$iso] = true;
        }
    }

    for ($ch = 1; $ch <= 16; $ch++) {
        if (!channel_target_channel_is_unlinked($usage, $device, $ch, $ignoreIsoSet)) {
            throw new ChannelRuleException('Target device has linked channels in CH1..CH16', 409, 'tc16_target_not_unlinked');
        }
    }
}

function channel_build_tc16_anchor_updates(array $sourceGroup, array $targetDevice): array
{
    if (!channel_group_is_full16($sourceGroup['channels'] ?? [])) {
        throw new ChannelRuleException('Source group is incomplete', 409, 'tc16_source_not_full');
    }

    $updates = [];
    for ($ch = 1; $ch <= 16; $ch++) {
        $row = $sourceGroup['channels'][$ch] ?? null;
        if (!is_array($row)) {
            throw new ChannelRuleException('Source group is incomplete', 409, 'tc16_source_not_full');
        }
        $iso = (string)($row['iso_channel'] ?? '');
        if ($iso === '') {
            throw new ChannelRuleException('Invalid source ISO in TC16 group', 409, 'tc16_source_unresolvable');
        }
        $updates[$iso] = channel_target_anchor_for_channel($targetDevice, $ch);
    }

    return $updates;
}

/* Resolve, validate and replace all 16 channels under one lock; commit all or none. */
function channel_replace_tc16_from_pool(
    string $xmlPath,
    string $sourceIso,
    string $targetKey,
    array $sdaqLogFiles,
    array $ioboxLogFiles,
    array $mtiLogFiles,
    array $noxLogFiles,
    array $sdaqDeviceTypes
): array {
    return iso_with_xml_lock($xmlPath, function () use (
        $xmlPath, $sourceIso, $targetKey, $sdaqLogFiles, $ioboxLogFiles, $mtiLogFiles, $noxLogFiles, $sdaqDeviceTypes
    ) {
        [$rows, ] = channel_collect_rows_and_extras(
            $xmlPath,
            $sdaqLogFiles,
            $ioboxLogFiles,
            $mtiLogFiles,
            $noxLogFiles,
            $sdaqDeviceTypes
        );

        $sourceGroup = channel_resolve_tc16_source_group($rows, $sourceIso);
        $devices = channel_collect_sdaq_capabilities($sdaqLogFiles);

        if (!isset($devices[$targetKey])) {
            throw new ChannelRuleException('Target device not found', 409, 'tc16_target_not_found');
        }
        $targetDevice = $devices[$targetKey];
        channel_validate_tc16_target($rows, $targetDevice, $sourceGroup);

        $updates = channel_build_tc16_anchor_updates($sourceGroup, $targetDevice);
        if (count($updates) !== 16) {
            throw new ChannelRuleException('TC16 replace payload must contain 16 channels', 409, 'tc16_apply_conflict');
        }

        if (!file_exists($xmlPath)) {
            throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
        }
        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
        }

        // Pass 1: grammar + semantic-source duplicate check for all 16
        // generated anchors against the file as it stands right now. No
        // writes yet.
        $canonicalByIso = [];
        foreach ($updates as $iso => $anchor) {
            $isoNorm = iso_normalize_iso_channel((string)$iso);
            if ($isoNorm === '') {
                throw new ChannelConfigException('Invalid TC16 batch payload', 400, 'channel_config_error');
            }

            $identity = iso_require_valid_source_identity('SDAQ', (string)$anchor);
            $conflict = iso_find_anchor_conflict($xml, $identity['semantic_key'], (string)$iso);
            if ($conflict !== null) {
                throw new ChannelConfigException(
                    "ANCHOR already exists: " . $identity['canonical_anchor'] . " is already used by " . $conflict,
                    409,
                    'duplicate_source'
                );
            }

            $canonicalByIso[$isoNorm] = $identity['canonical_anchor'];
        }

        $channelByIso = [];
        foreach ($xml->CHANNEL as $ch) {
            $existingIso = iso_normalize_iso_channel((string)$ch->ISO_CHANNEL);
            if ($existingIso !== '') {
                $channelByIso[$existingIso] = $ch;
            }
        }
        foreach ($canonicalByIso as $isoNorm => $_anchor) {
            if (!array_key_exists($isoNorm, $channelByIso)) {
                throw new ChannelConfigException("ISO_CHANNEL not found: " . $isoNorm, 404, 'channel_not_found');
            }
        }

        // Pass 2: every one of the 16 targets is proven valid and
        // non-conflicting. Only now do we mutate the document, and save
        // exactly once.
        $now = (string)time();
        foreach ($canonicalByIso as $isoNorm => $canonicalAnchor) {
            $target = $channelByIso[$isoNorm];
            if (isset($target->ANCHOR)) {
                $target->ANCHOR = $canonicalAnchor;
            } else {
                $target->addChild('ANCHOR', $canonicalAnchor);
            }
            if (isset($target->MOD_DATE)) {
                $target->MOD_DATE = $now;
            } else {
                $target->addChild('MOD_DATE', $now);
            }
        }

        iso_save_xml($xml, $xmlPath);

        return [
            'replaced_count' => count($updates),
            'source_key' => (string)$sourceGroup['source_key'],
            'target_key' => (string)$targetDevice['device_key'],
        ];
    });
}
