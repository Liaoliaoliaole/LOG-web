<?php

function iobox_load_anchor_map(array $paths): array
{
    $anchors     = [];
    $connections = [];
    $ipv4ById    = [];

    foreach ($paths as $jsonPath) {
        if (!is_file($jsonPath)) {
            continue;
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $identifierRaw = $data['Identifier'] ?? null;
        if (is_string($identifierRaw)) {
            $identifierRaw = trim($identifierRaw);
        } elseif (is_int($identifierRaw) || is_float($identifierRaw)) {
            $identifierRaw = (string)$identifierRaw;
        } else {
            $identifierRaw = null;
        }

        if ($identifierRaw === null || $identifierRaw === '') {
            continue;
        }
        $identifier = (string)$identifierRaw;

        if (!empty($data['IPv4_address']) && is_string($data['IPv4_address'])) {
            $ipv4ById[$identifier] = $data['IPv4_address'];
        }

        $connections[$identifier] = $data['Connection_status'] ?? null;

        foreach ($data as $key => $rxData) {
            if (!preg_match('/^RX(\d+)$/', $key, $m)) {
                continue;
            }
            if (!is_array($rxData) || ($rxData === 'Disconnected')) {
                continue;
            }

            foreach ($rxData as $chKey => $value) {
                $chNum = null;
                if (ctype_digit((string)$chKey)) {
                    $chNum = (string)$chKey;
                } elseif (is_string($chKey) && preg_match('/^CH(\d+)$/i', $chKey, $cm)) {
                    $chNum = $cm[1];
                }

                if ($chNum === null) {
                    continue;
                }

                $anchor = sprintf('%s.%s.CH%s', $identifier, strtoupper($key), $chNum);

                $status = 'Okay';
                $valid  = is_numeric($value);
                $meas   = $valid ? (float)$value : null;

                if (is_string($value) && strcasecmp($value, 'No sensor') === 0) {
                    $status = 'No sensor';
                    $valid  = false;
                    $meas   = null;
                } elseif (!$valid) {
                    $status = 'Unclassified';
                }

                $anchors[$anchor] = [
                    'status'        => $status,
                    'is_meas_valid' => $valid,
                    'meas_value'    => $meas,
                    'meas_unit'     => null,
                ];
            }
        }
    }

    return [
        'anchors'     => $anchors,
        'connections' => $connections,
        'ipv4'        => $ipv4ById,
    ];
}
