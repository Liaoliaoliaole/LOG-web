<?php

require_once __DIR__ . '/../repositories/log_config_repository.php';
require_once __DIR__ . '/../services/device_service.php';
require_once __DIR__ . '/network_service.php';

const CAN_ROLE_NOX_BITRATE = 250000;
const CAN_ROLE_SDAQ_BITRATE = 500000;

function can_role_supported_buses(): array
{
    return defined('NETWORK_CAN_IFACES') ? NETWORK_CAN_IFACES : ['can0', 'can1'];
}

function can_role_validate_bus(string $bus): string
{
    $normalized = strtolower(trim($bus));
    if (!in_array($normalized, can_role_supported_buses(), true)) {
        throw new InvalidArgumentException('bus must be one of: ' . implode(', ', can_role_supported_buses()));
    }
    return $normalized;
}

function can_role_expected_bitrate(string $mode): ?int
{
    $normalized = strtoupper(trim($mode));
    if ($normalized === 'NOX') {
        return CAN_ROLE_NOX_BITRATE;
    }
    if ($normalized === 'SDAQ') {
        return CAN_ROLE_SDAQ_BITRATE;
    }
    return null;
}

function can_role_free_bitrates(): array
{
    return [CAN_ROLE_NOX_BITRATE, CAN_ROLE_SDAQ_BITRATE];
}

function can_role_parse_display_bitrate(?string $display): ?int
{
    $raw = trim((string) $display);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*kbps$/i', $raw, $m)) {
        return (int) round(((float) $m[1]) * 1000);
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*mbps$/i', $raw, $m)) {
        return (int) round(((float) $m[1]) * 1000000);
    }
    return null;
}

function can_role_group_handlers_by_bus(array $handlers): array
{
    $grouped = [];
    foreach ($handlers as $handler) {
        $bus = strtolower(trim((string) ($handler['bus'] ?? '')));
        if ($bus === '') {
            continue;
        }
        $grouped[$bus][] = $handler;
    }
    return $grouped;
}

function can_role_collect_runtime_summary(string $ramdisk): array
{
    $sdaqCounts = [];
    foreach (device_collect_sdaq_devices($ramdisk) as $device) {
        $bus = strtolower(trim((string) ($device['bus'] ?? '')));
        if ($bus === '') {
            continue;
        }
        $sdaqCounts[$bus] = (int) ($sdaqCounts[$bus] ?? 0) + 1;
    }

    $noxDetected = [];
    $runtimeMaps = device_build_runtime_maps($ramdisk);
    foreach (($runtimeMaps['nox_buses'] ?? []) as $bus => $runtime) {
        $noxDetected[strtolower((string) $bus)] = !empty($runtime['detected']);
    }

    return [
        'sdaq_counts' => $sdaqCounts,
        'nox_detected' => $noxDetected,
    ];
}

function can_role_resolve_mode(array $busHandlers): array
{
    $enabledModes = [];
    foreach ($busHandlers as $handler) {
        if (empty($handler['enabled'])) {
            continue;
        }
        $enabledModes[] = strtoupper((string) ($handler['mode'] ?? ''));
    }

    $enabledModes = array_values(array_unique($enabledModes));
    if (count($enabledModes) > 1) {
        return ['mode' => in_array('NOX', $enabledModes, true) ? 'NOX' : $enabledModes[0], 'issue' => 'Conflict'];
    }
    if (count($enabledModes) === 1) {
        return ['mode' => $enabledModes[0], 'issue' => null];
    }
    return ['mode' => 'FREE', 'issue' => null];
}

function can_role_resolve_state(string $mode, ?int $bitrate, int $sdaqCount, bool $noxDetected, ?string $issue): string
{
    if ($issue !== null) {
        return 'Not Detected';
    }

    if ($mode === 'FREE') {
        if (!is_int($bitrate) || $bitrate <= 0) {
            return 'Free';
        }
        return in_array($bitrate, can_role_free_bitrates(), true) ? 'Free' : 'Bitrate Mismatch';
    }

    $expected = can_role_expected_bitrate($mode);
    if (!is_int($bitrate) || $bitrate <= 0 || $expected === null || $bitrate !== $expected) {
        return 'Bitrate Mismatch';
    }

    if ($mode === 'SDAQ') {
        return $sdaqCount > 0 ? 'Connected' : 'Not Detected';
    }

    if ($mode === 'NOX') {
        return $noxDetected ? 'Connected' : 'Not Detected';
    }

    return 'Not Detected';
}

function can_role_resolve_devices_label(string $mode, int $sdaqCount, bool $noxDetected): string
{
    if ($mode === 'SDAQ' && $sdaqCount > 0) {
        return sprintf('%d SDAQ', $sdaqCount);
    }
    if ($mode === 'NOX' && $noxDetected) {
        return '1 NOX';
    }
    if ($sdaqCount > 0) {
        return sprintf('%d SDAQ', $sdaqCount);
    }
    if ($noxDetected) {
        return '1 NOX';
    }
    return 'None';
}

function can_role_resolve_action(string $mode, string $state): array
{
    if ($mode === 'FREE') {
        return ['Switch to NOX', 'Switch to SDAQ'];
    }
    if ($mode === 'SDAQ') {
        if ($state === 'Bitrate Mismatch') {
            return ['Switch to SDAQ', 'Switch to NOX'];
        }
        return ['Switch to NOX'];
    }
    if ($mode === 'NOX') {
        if ($state === 'Bitrate Mismatch') {
            return ['Switch to NOX', 'Switch to SDAQ'];
        }
        return ['Open NOX Config', 'Switch to SDAQ'];
    }
    return [];
}

function can_role_build_row(
    string $bus,
    array $busHandlers,
    array $runtimeSummary,
    array $networkState
): array {
    $resolved = can_role_resolve_mode($busHandlers);
    $mode = strtoupper((string) ($resolved['mode'] ?? 'FREE'));
    $issue = $resolved['issue'];

    $bitrate = $networkState['can'][$bus]['bitrate'] ?? null;
    $display = trim((string) ($networkState['can'][$bus]['display'] ?? '—'));
    if ((!is_numeric($bitrate) || (int) $bitrate <= 0) && $display !== '' && $display !== '—') {
        $bitrate = can_role_parse_display_bitrate($display);
    }
    $bitrate = is_numeric($bitrate) ? (int) $bitrate : null;

    $sdaqCount = (int) (($runtimeSummary['sdaq_counts'][$bus] ?? 0));
    $noxDetected = !empty($runtimeSummary['nox_detected'][$bus]);
    $state = can_role_resolve_state($mode, $bitrate, $sdaqCount, $noxDetected, $issue);

    $detail = null;
    if ($issue === 'Conflict') {
        $detail = 'Multiple handlers use this bus';
    } elseif ($mode === 'SDAQ' && $state === 'Not Detected') {
        $detail = 'No SDAQ detected';
    } elseif ($mode === 'NOX' && $state === 'Not Detected') {
        $detail = 'No NOX detected';
    } elseif ($state === 'Bitrate Mismatch') {
        $detail = 'Check CAN bitrate';
    } elseif ($mode === 'FREE') {
        $detail = 'Bus is not assigned';
    }

    return [
        'bus' => $bus,
        'mode' => $mode,
        'bitrate' => $bitrate,
        'bitrate_display' => $display !== '' ? $display : '—',
        'state' => $state,
        'devices' => can_role_resolve_devices_label($mode, $sdaqCount, $noxDetected),
        'actions' => can_role_resolve_action($mode, $state),
        'detail' => $detail,
        'issue' => $issue,
        'detected' => [
            'sdaq_count' => $sdaqCount,
            'nox_detected' => $noxDetected,
        ],
    ];
}

function can_role_build_warning_summary(array $rows): array
{
    $priority = [
        'Bitrate Mismatch' => 1,
        'Not Detected' => 2,
        'Free' => 3,
        'Connected' => 9,
    ];

    $issues = [];
    foreach ($rows as $row) {
        if (in_array(($row['state'] ?? 'Connected'), ['Connected', 'Free'], true)) {
            continue;
        }

        $label = 'Check Bus';
        if (($row['state'] ?? '') === 'Bitrate Mismatch') {
            $label = 'Bitrate Mismatch';
        } elseif (($row['mode'] ?? '') === 'SDAQ' && ($row['state'] ?? '') === 'Not Detected') {
            $label = 'SDAQ Not Detected';
        } elseif (($row['mode'] ?? '') === 'NOX' && ($row['state'] ?? '') === 'Not Detected') {
            $label = 'NOX Not Detected';
        } elseif (($row['mode'] ?? '') === 'FREE') {
            $label = 'Check Bus';
        }

        $issues[] = [
            'bus' => $row['bus'],
            'label' => $label,
            'priority' => $priority[$row['state'] ?? 'Connected'] ?? 99,
        ];
    }

    usort($issues, static function (array $a, array $b): int {
        if ($a['priority'] === $b['priority']) {
            return strcmp((string) $a['bus'], (string) $b['bus']);
        }
        return $a['priority'] <=> $b['priority'];
    });

    $chip = null;
    if (!empty($issues)) {
        $top = $issues[0];
        $chip = sprintf('%s: %s', strtoupper((string) $top['bus']), (string) $top['label']);
    }

    $ticker = array_map(static function (array $issue): string {
        return sprintf('%s %s', strtoupper((string) $issue['bus']), (string) $issue['label']);
    }, $issues);

    return [
        'chip' => $chip,
        'ticker' => $ticker,
    ];
}

function can_role_list(string $ramdisk, string $logConfig): array
{
    $xmlConfig       = log_config_read_all($logConfig);
    $handlers        = $xmlConfig['can_handlers'];
    $groupedHandlers = can_role_group_handlers_by_bus($handlers);
    $runtimeSummary  = can_role_collect_runtime_summary($ramdisk);
    $networkState    = network_get_state();
    $legacyMdaqPresent = $xmlConfig['has_legacy_mdaq'];

    $rows = [];
    foreach (can_role_supported_buses() as $bus) {
        $rows[] = can_role_build_row($bus, $groupedHandlers[$bus] ?? [], $runtimeSummary, $networkState);
    }

    return [
        'rows' => $rows,
        'warnings' => can_role_build_warning_summary($rows),
        'legacy' => [
            'mdaq_present' => $legacyMdaqPresent,
            'blocking' => $legacyMdaqPresent,
            'message' => $legacyMdaqPresent ? DEVICE_LEGACY_MDAQ_MESSAGE : null,
        ],
        'network_state' => $networkState,
    ];
}

function can_role_resolve_payload_bitrate(array $state, array $rowsByBus, string $bus): int
{
    $bitrate = $state['can'][$bus]['bitrate'] ?? null;
    if (is_numeric($bitrate) && (int) $bitrate > 0) {
        return (int) $bitrate;
    }

    $displayBitrate = can_role_parse_display_bitrate($state['can'][$bus]['display'] ?? null);
    if (is_int($displayBitrate) && $displayBitrate > 0) {
        return $displayBitrate;
    }

    $row = $rowsByBus[$bus] ?? null;
    if (is_array($row)) {
        $expected = can_role_expected_bitrate((string) ($row['mode'] ?? ''));
        if (is_int($expected) && $expected > 0) {
            return $expected;
        }
    }

    throw new RuntimeException("CAN bitrate for $bus is unavailable");
}

function can_role_build_network_payload(array $state, array $rows, array $bitrateOverrides): array
{
    $rowsByBus = [];
    foreach ($rows as $row) {
        $rowsByBus[(string) $row['bus']] = $row;
    }

    $ethState = $state['eth'] ?? [];
    $ethMode = strtolower(trim((string) ($ethState['mode'] ?? 'dhcp')));
    $ethPayload = [
        'mode' => in_array($ethMode, ['static', 'dhcp'], true) ? $ethMode : 'dhcp',
        'ipv4' => [
            'address' => $ethState['ipv4']['address'] ?? null,
            'prefix' => $ethState['ipv4']['prefix'] ?? NETWORK_DEFAULT_IPV4_PREFIX,
            'gateway' => $ethState['ipv4']['gateway'] ?? null,
            'dns' => is_array($ethState['ipv4']['dns'] ?? null) ? $ethState['ipv4']['dns'] : [],
        ],
    ];

    $canPayload = [];
    foreach (can_role_supported_buses() as $bus) {
        $canPayload[$bus] = [
            'bitrate' => array_key_exists($bus, $bitrateOverrides)
                ? (int) $bitrateOverrides[$bus]
                : can_role_resolve_payload_bitrate($state, $rowsByBus, $bus),
        ];
    }

    $ntpServer = trim((string) ($state['ntp']['server'] ?? ''));
    $ntpPayload = ['servers' => []];
    if (network_valid_ipv4($ntpServer)) {
        $ntpPayload['servers'][] = $ntpServer;
    }

    return [
        'hostname' => (string) ($state['hostname'] ?? network_get_hostname()),
        'eth' => $ethPayload,
        'can' => $canPayload,
        'ntp' => $ntpPayload,
    ];
}

function can_role_apply_bitrate(string $bus, int $bitrate, array $beforeRows): array
{
    $beforeState = network_get_state();
    $payload = can_role_build_network_payload($beforeState, $beforeRows, [$bus => $bitrate]);
    $result = network_apply_staged($payload, 0, true);

    $effectiveState = $result['state'] ?? network_get_state();
    $effective = $effectiveState['can'][$bus]['bitrate'] ?? null;
    if (!is_numeric($effective) || (int) $effective !== $bitrate) {
        throw new RuntimeException(sprintf(
            'CAN bitrate readback mismatch for %s: expected %d, got %s',
            $bus,
            $bitrate,
            var_export($effective, true)
        ));
    }

    return $result;
}

function can_role_restore_network(array $beforeState, array $beforeRows): void
{
    $payload = can_role_build_network_payload($beforeState, $beforeRows, []);
    network_apply_staged($payload, 0, true);
}

function can_role_restore_owned_xml(string $logConfig, string $beforeXml, string $committedDigest): void
{
    log_config_with_xml_lock($logConfig, static function () use ($logConfig, $beforeXml, $committedDigest): void {
        $current = @file_get_contents($logConfig);
        if (!is_string($current) || !hash_equals($committedDigest, hash('sha256', $current))) {
            throw new RuntimeException('LOG config changed after this CAN transition; refusing to overwrite the newer configuration');
        }
        backend_atomic_write_file($logConfig, $beforeXml, 0644);
    });
}

function can_role_transition(string $ramdisk, string $logConfig, string $bus, string $targetMode): array
{
    $normalizedBus = can_role_validate_bus($bus);
    $normalizedMode = strtoupper(trim($targetMode));
    if (!in_array($normalizedMode, ['NOX', 'SDAQ'], true)) {
        throw new InvalidArgumentException('target mode must be NOX or SDAQ');
    }

    $targetBitrate = can_role_expected_bitrate($normalizedMode);
    if (!is_int($targetBitrate)) {
        throw new RuntimeException('Unsupported target mode');
    }

    return backend_with_named_lock('operation:can-role-transition', function () use (
        $ramdisk, $logConfig, $normalizedBus, $normalizedMode, $targetBitrate
    ): array {
        $beforeXml = '';
        $beforeSnapshot = [];
        $beforeState = [];
        $committedDigest = '';
        $xmlCommitted = false;
        $networkAttempted = false;

        try {
            // Baseline and XML commit are one ownership step. Other config
            // writers may run after this short lock is released; the digest
            // below prevents rollback from overwriting any such newer write.
            log_config_with_xml_lock($logConfig, function () use (
                $ramdisk,
                $logConfig,
                $normalizedBus,
                $normalizedMode,
                &$beforeXml,
                &$beforeSnapshot,
                &$beforeState,
                &$committedDigest,
                &$xmlCommitted
            ): void {
                $beforeXml = @file_get_contents($logConfig);
                if (!is_string($beforeXml)) {
                    throw new RuntimeException('Failed to read current LOG config XML');
                }
                $beforeSnapshot = can_role_list($ramdisk, $logConfig);
                $beforeState = $beforeSnapshot['network_state'] ?? network_get_state();

                log_config_set_can_role_body($logConfig, $normalizedBus, $normalizedMode);
                $xmlCommitted = true;
                $committed = @file_get_contents($logConfig);
                if (!is_string($committed)) {
                    throw new RuntimeException('Failed to read committed LOG config XML');
                }
                $committedDigest = hash('sha256', $committed);
            });

            $networkAttempted = true;
            $networkResult = can_role_apply_bitrate($normalizedBus, $targetBitrate, $beforeSnapshot['rows']);
            device_restart_morfeas_core();

            return [
                'bus'     => $normalizedBus,
                'mode'    => $normalizedMode,
                'bitrate' => $targetBitrate,
                'network' => $networkResult,
                'before'  => $beforeSnapshot,
                'pending' => true,
            ];
        } catch (Throwable $e) {
            // Validation/read failures happen before either side is touched.
            if (!$xmlCommitted && !$networkAttempted) {
                throw $e;
            }

            $rollbackErrors = [];
            $stillOwnsConfig = true;
            if ($xmlCommitted) {
                try {
                    can_role_restore_owned_xml($logConfig, $beforeXml, $committedDigest);
                } catch (Throwable $rollbackXmlError) {
                    $stillOwnsConfig = false;
                    $rollbackErrors[] = 'xml rollback failed: ' . $rollbackXmlError->getMessage();
                }
            }

            if ($networkAttempted && $stillOwnsConfig) {
                try {
                    can_role_restore_network($beforeState, $beforeSnapshot['rows']);
                } catch (Throwable $rollbackNetworkError) {
                    $rollbackErrors[] = 'network rollback failed: ' . $rollbackNetworkError->getMessage();
                }
            } elseif ($networkAttempted) {
                // A newer configuration now owns both the desired CAN role
                // and any subsequent network reconciliation. Reverting only
                // the network here could corrupt that newer operation.
                $rollbackErrors[] = 'network rollback skipped because this transition no longer owns the configuration';
            }

            try {
                device_restart_morfeas_core();
            } catch (Throwable $restartError) {
                $rollbackErrors[] = 'service restart failed: ' . $restartError->getMessage();
            }

            if (!empty($rollbackErrors)) {
                throw new RuntimeException($e->getMessage() . ' | rollback: ' . implode(' ; ', $rollbackErrors), 0, $e);
            }

            throw $e;
        }
    });
}
