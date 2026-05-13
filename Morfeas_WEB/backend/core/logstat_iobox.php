<?php

function iobox_reserved_error_code($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $code = (int)round((float)$value);
    return in_array($code, [-901, -902, -903, -904, -905, -906, -907], true) ? $code : null;
}

function iobox_status_from_error_code(int $code, ?string $connectionStatus = null): string
{
    if ($code === -901) {
        if ($connectionStatus !== null && strcasecmp(trim($connectionStatus), 'Okay') !== 0) {
            return 'OFF-Line';
        }
        return 'Disconnected';
    }
    if ($code === -902) {
        return 'No sensor';
    }
    if ($code === -905) {
        return 'Unreachable';
    }
    if ($code === -906) {
        return 'Standby';
    }
    if ($code === -907) {
        return 'Signal Invalid';
    }
    return 'Unclassified';
}

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

        $connectionStatus = $data['Connection_status'] ?? null;
        $connections[$identifier] = $connectionStatus;

        if (!is_string($connectionStatus) || strcasecmp(trim($connectionStatus), 'Okay') !== 0) {
            continue;
        }

        foreach ($data as $key => $rxData) {
            if (!preg_match('/^RX(\d+)$/', $key, $m)) {
                continue;
            }
            if (!is_array($rxData)) {
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
                $errorCode = iobox_reserved_error_code($value);

                if ($errorCode !== null) {
                    $status = iobox_status_from_error_code($errorCode, is_string($connectionStatus) ? $connectionStatus : null);
                    $valid  = false;
                    $meas   = (float)$errorCode;
                } elseif (is_string($value) && strcasecmp($value, 'No sensor') === 0) {
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
                    'meas_unit'     => '°C',
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
