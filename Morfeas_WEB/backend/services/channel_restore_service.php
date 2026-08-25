<?php

require_once __DIR__ . '/../core/opcua_config.php';
require_once __DIR__ . '/../repositories/log_config_repository.php';

/*
 * Local JSON Restore accepts the historical channel-array format, including
 * valid offline definitions. Preflight reports all rows; Commit revalidates
 * current files under lock and replaces the batch atomically.
 */

const RESTORE_LEGACY_REQUIRED_FIELDS = ['ISO_CHANNEL', 'INTERFACE_TYPE', 'ANCHOR', 'DESCRIPTION', 'MIN', 'MAX'];
const RESTORE_KNOWN_INTERFACES = ['SDAQ', 'IOBOX', 'MTI', 'NOX'];

function restore_parse_legacy_json(string $rawJson): array
{
    $data = json_decode($rawJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new ChannelConfigException('File is not valid JSON: ' . json_last_error_msg(), 400, 'invalid_json');
    }
    if (!is_array($data) || !array_is_list($data)) {
        throw new ChannelConfigException(
            'Expected a JSON array of channel entries (Legacy Local JSON export format)',
            400,
            'invalid_container'
        );
    }
    return $data;
}

/* Core reads IPv4 bytes as native little-endian uint32; do not use ip2long(). */
function restore_ipv4_to_core_identifier(string $ip): ?int
{
    $bytes = @inet_pton($ip);
    if ($bytes === false || strlen($bytes) !== 4) {
        return null;
    }
    $unpacked = unpack('V', $bytes);
    return $unpacked[1] ?? null;
}

/* Restore accepts offline IOBOX/MTI only when a same-type handler is configured. */
function restore_load_device_identifiers(string $logConfigPath): array
{
    $out = ['IOBOX' => [], 'MTI' => []];
    $devices = log_config_load_manual_devices($logConfigPath);
    foreach ($devices as $dev) {
        $type = strtoupper((string)($dev['type'] ?? ''));
        if ($type !== 'IOBOX' && $type !== 'MTI') {
            continue;
        }
        $identifier = restore_ipv4_to_core_identifier((string)($dev['ip'] ?? ''));
        if ($identifier === null) {
            continue;
        }
        $out[$type][$identifier] = true;
    }
    return $out;
}

function restore_check_device_handler(string $interfaceType, array $identity, array $deviceIdentifiers): ?array
{
    if ($interfaceType !== 'IOBOX' && $interfaceType !== 'MTI') {
        return null;
    }

    $identifierStr = (string)($identity['components']['identifier'] ?? '');
    if ($identifierStr === '' || !ctype_digit($identifierStr)) {
        return ['code' => 'orphan_device_source', 'detail' => 'Could not resolve a numeric identifier from this anchor'];
    }
    $identifier = (int)$identifierStr;

    if (isset($deviceIdentifiers[$interfaceType][$identifier])) {
        return null;
    }

    $otherType = $interfaceType === 'IOBOX' ? 'MTI' : 'IOBOX';
    if (isset($deviceIdentifiers[$otherType][$identifier])) {
        return [
            'code' => 'device_source_type_mismatch',
            'detail' => "Identifier matches a configured $otherType handler, not $interfaceType",
        ];
    }

    return [
        'code' => 'orphan_device_source',
        'detail' => "No $interfaceType handler with this IP is currently configured in Morfeas_Config.xml",
    ];
}

/*
 * True if every allowed-to-restore mutable field is unchanged from the
 * existing channel -- distinguishes "No change" (idempotent skip) from
 * "Update metadata" (identity kept, mutable fields refreshed from file).
 * SDAQ Unit/Calibration are runtime-owned, never compared/restored here.
 */
function restore_entry_matches_existing(array $existing, array $entry, string $interfaceType): bool
{
    $numEq = static function ($a, $b): bool {
        $a = ($a === null || $a === '') ? '0' : $a;
        $b = ($b === null || $b === '') ? '0' : $b;
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a === (float)$b;
        }
        return (string)$a === (string)$b;
    };
    $strEq = static function ($a, $b): bool {
        return trim((string)($a ?? '')) === trim((string)($b ?? ''));
    };

    if (!$strEq($existing['description'] ?? '', $entry['description'] ?? '')) return false;
    if (!$numEq($existing['min'] ?? '', $entry['min'] ?? '')) return false;
    if (!$numEq($existing['max'] ?? '', $entry['max'] ?? '')) return false;
    if (!$strEq($existing['alarm_high'] ?? '', $entry['alarm_high'] ?? '')) return false;
    if (!$strEq($existing['alarm_low'] ?? '', $entry['alarm_low'] ?? '')) return false;
    if (!$numEq($existing['alarm_high_val'] ?? '', $entry['alarm_high_val'] ?? '')) return false;
    if (!$numEq($existing['alarm_low_val'] ?? '', $entry['alarm_low_val'] ?? '')) return false;

    if ($interfaceType !== 'SDAQ') {
        if (!$strEq($existing['unit'] ?? '', $entry['unit'] ?? '')) return false;
        if (!$strEq($existing['cal_date'] ?? '', $entry['cal_date'] ?? '')) return false;
        if (!$numEq($existing['cal_period'] ?? '', $entry['cal_period'] ?? '')) return false;
    }

    return true;
}

/*
 * Classifies every entry in $rawEntries against $existingRows (the current
 * OPC_UA_Config.xml, already loaded by the caller) and $deviceIdentifiers
 * (current Morfeas_Config.xml handlers). Read-only, deterministic, no
 * side effects -- both restore_preflight() and restore_commit() call this
 * so a report and an actual write are never generated from different logic.
 */
function restore_classify_entries(array $rawEntries, array $existingRows, array $deviceIdentifiers): array
{
    $existingByIso = [];
    $existingBySemanticKey = [];
    foreach ($existingRows as $row) {
        $isoNorm = iso_normalize_iso_channel((string)($row['iso_channel'] ?? ''));
        if ($isoNorm !== '') {
            $existingByIso[$isoNorm] = $row;
        }
        $existingIdentity = iso_parse_source_identity(
            (string)($row['interface_type'] ?? ''),
            (string)($row['anchor'] ?? '')
        );
        if ($existingIdentity !== null) {
            $existingBySemanticKey[$existingIdentity['semantic_key']] = $row;
        }
    }

    $rows = [];
    $usedIsoInFile = [];
    $usedSemanticKeyInFile = [];

    foreach ($rawEntries as $index => $raw) {
        $rowNum = $index + 1;
        $report = [
            'row' => $rowNum,
            'iso_channel' => is_array($raw) ? (string)($raw['ISO_CHANNEL'] ?? '') : '',
            'interface_type' => is_array($raw) ? strtoupper(trim((string)($raw['INTERFACE_TYPE'] ?? ''))) : '',
            'anchor' => is_array($raw) ? (string)($raw['ANCHOR'] ?? '') : '',
            'result' => 'Invalid entry',
            'code' => null,
            'reason' => null,
            'canonical_anchor' => null,
            'ignored_fields' => [],
            'payload' => null,
        ];

        if (!is_array($raw)) {
            $report['reason'] = 'Entry is not a JSON object';
            $report['code'] = 'invalid_entry';
            $rows[] = $report;
            continue;
        }

        $missing = [];
        foreach (RESTORE_LEGACY_REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $raw)) {
                $missing[] = $field;
            }
        }
        if (!$missing) {
            // Every required CHANNEL element that is written must be
            // non-empty, not just present in the source
            // JSON. All six required fields land in the document
            // unconditionally, so preflight checks all six and reports the
            // error before the final-byte gate.
            foreach (RESTORE_LEGACY_REQUIRED_FIELDS as $field) {
                if (trim((string)$raw[$field]) === '') {
                    $missing[] = $field;
                }
            }
        }
        if ($missing) {
            $report['reason'] = 'Missing field(s): ' . implode(', ', $missing);
            $report['code'] = 'missing_field';
            $rows[] = $report;
            continue;
        }

        $interfaceType = strtoupper(trim((string)$raw['INTERFACE_TYPE']));
        $report['interface_type'] = $interfaceType;
        if (!in_array($interfaceType, RESTORE_KNOWN_INTERFACES, true)) {
            $report['reason'] = "Unsupported interface: $interfaceType";
            $report['code'] = 'unsupported_interface';
            $rows[] = $report;
            continue;
        }

        // Check the value as it will be stored after the leading underscore
        // is normalised, matching the write-time gate exactly.
        $isoNormForLength = iso_normalize_iso_channel((string)$raw['ISO_CHANNEL']);
        if (strlen($isoNormForLength) >= 20) { // ISO_channel_name_size
            $report['reason'] = "ISO_CHANNEL is too long (>= 20 bytes once prefixed): $isoNormForLength";
            $report['code'] = 'invalid_iso_channel';
            $rows[] = $report;
            continue;
        }
        if (strpos($isoNormForLength, '.') !== false) {
            $report['reason'] = "ISO_CHANNEL contains an illegal '.': $isoNormForLength";
            $report['code'] = 'invalid_iso_channel';
            $rows[] = $report;
            continue;
        }

        $anchorRaw = trim((string)$raw['ANCHOR']);
        $identity = iso_parse_source_identity($interfaceType, $anchorRaw);
        if ($identity === null) {
            $report['reason'] = "ANCHOR is not a valid $interfaceType source identity: $anchorRaw";
            $report['code'] = 'invalid_anchor';
            $rows[] = $report;
            continue;
        }
        $report['canonical_anchor'] = $identity['canonical_anchor'];

        $unitRaw = trim((string)($raw['UNIT'] ?? ''));
        if ($interfaceType !== 'SDAQ' && $unitRaw === '') {
            $report['reason'] = "$interfaceType requires a non-empty UNIT (XML-owned, not SDAQ-style runtime-owned)";
            $report['code'] = 'missing_required_unit';
            $rows[] = $report;
            continue;
        }
        if ($interfaceType === 'SDAQ') {
            foreach (['UNIT', 'CAL_DATE', 'CAL_PERIOD'] as $runtimeField) {
                if (array_key_exists($runtimeField, $raw)
                    && trim((string)$raw[$runtimeField]) !== '') {
                    $report['ignored_fields'][] = $runtimeField;
                }
            }
        }

        $deviceCheck = restore_check_device_handler($interfaceType, $identity, $deviceIdentifiers);
        if ($deviceCheck !== null) {
            $report['reason'] = $deviceCheck['detail'];
            $report['code'] = $deviceCheck['code'];
            $rows[] = $report;
            continue;
        }

        $isoNorm = iso_normalize_iso_channel((string)$raw['ISO_CHANNEL']);
        $semanticKey = $identity['semantic_key'];

        if (isset($usedIsoInFile[$isoNorm])) {
            $report['reason'] = 'Duplicate ISO_CHANNEL within this file (also row ' . $usedIsoInFile[$isoNorm] . ')';
            $report['code'] = 'channel_conflict';
            $rows[] = $report;
            continue;
        }
        if (isset($usedSemanticKeyInFile[$semanticKey])) {
            $report['reason'] = 'Duplicate source within this file (also row ' . $usedSemanticKeyInFile[$semanticKey] . ')';
            $report['code'] = 'duplicate_source';
            $rows[] = $report;
            continue;
        }
        // From here on this row is syntactically valid and unique within the
        // file; mark it used regardless of how it classifies against the
        // existing config below, so a later duplicate row in the same file
        // is still caught.
        $usedIsoInFile[$isoNorm] = $rowNum;
        $usedSemanticKeyInFile[$semanticKey] = $rowNum;

        $entry = [
            'iso_channel' => $isoNorm,
            'interface_type' => $interfaceType,
            'anchor' => $identity['canonical_anchor'],
            'description' => (string)($raw['DESCRIPTION'] ?? ''),
            'min' => (string)($raw['MIN'] ?? ''),
            'max' => (string)($raw['MAX'] ?? ''),
            'unit' => $interfaceType === 'SDAQ' ? null : $unitRaw,
            'cal_date' => $interfaceType === 'SDAQ' ? null : ($raw['CAL_DATE'] ?? null),
            'cal_period' => $interfaceType === 'SDAQ' ? null : ($raw['CAL_PERIOD'] ?? null),
            'alarm_high_val' => $raw['ALARM_HIGH_VAL'] ?? null,
            'alarm_low_val' => $raw['ALARM_LOW_VAL'] ?? null,
            'alarm_high' => $raw['ALARM_HIGH'] ?? null,
            'alarm_low' => $raw['ALARM_LOW'] ?? null,
        ];

        $existingByThisIso = $existingByIso[$isoNorm] ?? null;
        $existingByThisKey = $existingBySemanticKey[$semanticKey] ?? null;

        $existingByThisIsoIdentity = $existingByThisIso !== null
            ? iso_parse_source_identity(
                (string)($existingByThisIso['interface_type'] ?? ''),
                (string)($existingByThisIso['anchor'] ?? '')
            )
            : null;

        if ($existingByThisIso !== null
            && ($existingByThisIsoIdentity === null || $existingByThisIsoIdentity['semantic_key'] !== $semanticKey)) {
            $report['result'] = 'Conflict with current config';
            $report['reason'] = 'ISO_CHANNEL already exists in the current configuration with a different identity';
            $report['code'] = 'duplicate_source';
            $rows[] = $report;
            continue;
        }
        if ($existingByThisKey !== null
            && iso_normalize_iso_channel((string)($existingByThisKey['iso_channel'] ?? '')) !== $isoNorm) {
            $report['result'] = 'Conflict with current config';
            $report['reason'] = 'This source is already used by a different ISO_CHANNEL: '
                . (string)($existingByThisKey['iso_channel'] ?? '');
            $report['code'] = 'duplicate_source';
            $rows[] = $report;
            continue;
        }

        if ($existingByThisIso !== null) {
            // Same ISO + same semantic key, guaranteed by the two checks above.
            $matches = restore_entry_matches_existing($existingByThisIso, $entry, $interfaceType);
            $report['result'] = $matches ? 'No change' : 'Update metadata';
            $report['payload'] = $entry;
            $rows[] = $report;
            continue;
        }

        $report['result'] = 'Ready to restore';
        $report['payload'] = $entry;
        $rows[] = $report;
    }

    return $rows;
}

function restore_compute_digest(string $xmlPath, string $logConfigPath): string
{
    $xmlContent = is_file($xmlPath) ? file_get_contents($xmlPath) : '';
    $logConfigContent = is_file($logConfigPath) ? file_get_contents($logConfigPath) : '';
    return hash('sha256', ($xmlContent === false ? '' : $xmlContent) . "\0" . ($logConfigContent === false ? '' : $logConfigContent));
}

function restore_summarize(array $rows): array
{
    $summary = ['ready_to_restore' => 0, 'no_change' => 0, 'update_metadata' => 0, 'invalid' => 0, 'conflict' => 0];
    foreach ($rows as $r) {
        switch ($r['result']) {
            case 'Ready to restore':
                $summary['ready_to_restore']++;
                break;
            case 'No change':
                $summary['no_change']++;
                break;
            case 'Update metadata':
                $summary['update_metadata']++;
                break;
            case 'Conflict with current config':
                $summary['conflict']++;
                break;
            default:
                $summary['invalid']++;
                break;
        }
    }
    return $summary;
}

/*
 * Read-only preflight: parses and classifies every row, never touches the
 * real files. Returns a digest of the current config so a later commit()
 * call can detect "the config changed since you reviewed this".
 */
function restore_preflight(string $xmlPath, string $logConfigPath, string $fileContent): array
{
    $rawEntries = restore_parse_legacy_json($fileContent);
    $existingRows = iso_load_channels($xmlPath);
    $deviceIdentifiers = restore_load_device_identifiers($logConfigPath);
    $rows = restore_classify_entries($rawEntries, $existingRows, $deviceIdentifiers);
    $summary = restore_summarize($rows);

    return [
        'rows' => array_map(static function ($r) {
            unset($r['payload']);
            return $r;
        }, $rows),
        'summary' => $summary,
        'can_commit' => count($rawEntries) > 0 && $summary['invalid'] === 0 && $summary['conflict'] === 0,
        'digest' => restore_compute_digest($xmlPath, $logConfigPath),
    ];
}

/*
 * Commit: re-parses and re-classifies everything fresh, inside the XML
 * lock, against the current files -- never trusts the report the browser
 * is holding. Any row that is not Ready/No-change/Update-metadata aborts
 * the whole commit with zero writes. A digest mismatch (the config changed
 * since preflight) is reported explicitly before even re-classifying.
 */
function restore_commit(string $xmlPath, string $logConfigPath, string $fileContent, string $expectedDigest): array
{
    // IOBOX/MTI handler matching reads Morfeas_Config.xml, so this must hold
    // log_config before opcua_config, in that fixed order, so the
    // digest/device-identifier snapshot and the eventual write are read
    // from a single consistent point in time -- closing the TOCTOU where a
    // device handler cannot be deleted between digest computation and the
    // handler-matching re-check below.
    return log_config_with_xml_lock($logConfigPath, function () use ($xmlPath, $logConfigPath, $fileContent, $expectedDigest) {
        return restore_commit_locked($xmlPath, $logConfigPath, $fileContent, $expectedDigest);
    });
}

function restore_commit_locked(string $xmlPath, string $logConfigPath, string $fileContent, string $expectedDigest): array
{
    return iso_with_xml_lock($xmlPath, function () use ($xmlPath, $logConfigPath, $fileContent, $expectedDigest) {
        $actualDigest = restore_compute_digest($xmlPath, $logConfigPath);
        if (!hash_equals($actualDigest, $expectedDigest)) {
            throw new ChannelConfigException(
                'The configuration changed since this file was reviewed; please re-run the preflight check',
                409,
                'restore_candidate_changed'
            );
        }

        $rawEntries = restore_parse_legacy_json($fileContent);
        if (!$rawEntries) {
            throw new ChannelConfigException('Restore file contains no channel entries', 400, 'missing_field');
        }
        $existingRows = iso_load_channels($xmlPath);
        $deviceIdentifiers = restore_load_device_identifiers($logConfigPath);
        $rows = restore_classify_entries($rawEntries, $existingRows, $deviceIdentifiers);

        foreach ($rows as $r) {
            if (!in_array($r['result'], ['Ready to restore', 'No change', 'Update metadata'], true)) {
                throw new ChannelConfigException(
                    'Row ' . $r['row'] . ' (' . $r['iso_channel'] . '): ' . $r['reason'],
                    409,
                    $r['code'] ?? 'invalid_entry'
                );
            }
        }

        if (!file_exists($xmlPath)) {
            throw new ChannelConfigException("XML not found: $xmlPath", 404, 'channel_config_missing');
        }
        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            throw new ChannelConfigException("Failed to parse XML", 500, 'channel_config_parse_failed');
        }

        $channelByIso = [];
        foreach ($xml->CHANNEL as $ch) {
            $existingIso = iso_normalize_iso_channel((string)$ch->ISO_CHANNEL);
            if ($existingIso !== '') {
                $channelByIso[$existingIso] = $ch;
            }
        }

        $added = 0;
        $updated = 0;
        $unchanged = 0;
        $now = time();

        foreach ($rows as $r) {
            if ($r['result'] === 'No change') {
                $unchanged++;
                continue;
            }

            $data = $r['payload'];

            if ($r['result'] === 'Ready to restore') {
                $new = $xml->addChild('CHANNEL');
                $newData = array_merge($data, [
                    // Legacy JSON carries no build/mod timestamp; Restore
                    // generates the current audit time for both.
                    'build_date' => $now,
                    'mod_date' => $now,
                ]);
                if ($data['interface_type'] === 'SDAQ') {
                    // SDAQ metadata is runtime-owned and is not restored to XML.
                    unset($newData['unit']);
                }
                $payload = iso_build_new_channel_payload($data['iso_channel'], $newData);
                iso_set_channel_contents($new, $payload);
                $added++;
                continue;
            }

            // Update metadata: identity (interface_type/anchor/iso_channel)
            // is never taken from the file here -- only the allowed mutable
            // fields refresh, exactly like a normal Edit's allowlist.
            $target = $channelByIso[$data['iso_channel']] ?? null;
            if ($target === null) {
                throw new ChannelConfigException(
                    'Internal error: channel disappeared mid-commit: ' . $data['iso_channel'],
                    500,
                    'channel_config_error'
                );
            }
            $existingSnapshot = iso_channel_snapshot($target);
            $payload = [
                'iso_channel'    => $existingSnapshot['iso_channel'],
                'interface_type' => $existingSnapshot['interface_type'],
                'anchor'         => $existingSnapshot['anchor'],
                'description'    => $data['description'],
                'min'            => $data['min'],
                'max'            => $data['max'],
                'unit'           => $data['interface_type'] === 'SDAQ' ? null : $data['unit'],
                'cal_date'       => $data['interface_type'] === 'SDAQ' ? null : $data['cal_date'],
                'cal_period'     => $data['interface_type'] === 'SDAQ' ? null : $data['cal_period'],
                'build_date'     => $existingSnapshot['build_date'],
                'mod_date'       => $now,
                'alarm_high_val' => $data['alarm_high_val'],
                'alarm_low_val'  => $data['alarm_low_val'],
                'alarm_high'     => $data['alarm_high'],
                'alarm_low'      => $data['alarm_low'],
            ];
            iso_set_channel_contents($target, $payload);
            $updated++;
        }

        iso_save_xml($xml, $xmlPath);

        return ['added' => $added, 'updated' => $updated, 'unchanged' => $unchanged];
    });
}
