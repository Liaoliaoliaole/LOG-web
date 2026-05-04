<?php

function mti_reserved_error_code($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $code = (int)round((float)$value);
    return in_array($code, [-901, -902, -903, -904], true) ? $code : null;
}

function mti_status_from_error_code(int $code, ?string $connectionStatus = null): string
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
    return 'Unclassified';
}

function mti_load_anchor_map(array $paths): array
{
    $anchors     = [];
    $connections = [];
    $ipv4ById    = [];

    foreach ($paths as $jsonPath) {
        if (!is_file($jsonPath)) continue;

        $raw = file_get_contents($jsonPath);
        if ($raw === false || $raw === '') continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $identifier = $data['Identifier'] ?? null;
        if (is_numeric($identifier)) {
            $connections[$identifier] = $data['Connection_status'] ?? null;
        }

        if ($identifier !== null && !empty($data['IPv4_address']) && is_string($data['IPv4_address'])) {
            $ipv4ById[(string)$identifier] = $data['IPv4_address'];
        }

        $connectionStatus = is_string($data['Connection_status'] ?? null) ? $data['Connection_status'] : null;

        $tele     = $data['Tele_data'] ?? null;
        $teleType = $data['MTI_status']['Tele_Device_type'] ?? null;

        if (!$teleType || !is_array($tele) || !isset($tele['CHs']) || !is_array($tele['CHs']) || $identifier === null) {
            continue;
        }

        $typeSlug = is_string($teleType) ? $teleType : '';
        $typeSlug = (substr($typeSlug, 0, 5) === 'Tele_') ? substr($typeSlug, 5) : $typeSlug;

        foreach ($tele['CHs'] as $idx => $value) {
            $chNum = $idx + 1;
            $anchor = sprintf('%s.%s.CH%d', $identifier, $typeSlug, $chNum);

	            $errorCode = mti_reserved_error_code($value);
	            if ($errorCode !== null) {
	                $status = mti_status_from_error_code($errorCode, $connectionStatus);
	                $valid  = false;
	                $meas   = (float)$errorCode;
	            } elseif (is_numeric($value) && ($tele['IsValid'] ?? true)) {
	                $status = 'Okay';
	                $valid  = true;
	                $meas   = (float)$value;
	            } else {
	                $status = 'Unclassified';
	                $valid  = false;
	                $meas   = null;
	            }

            $anchors[$anchor] = [
                'status'        => $status,
                'is_meas_valid' => $valid,
                'meas_value'    => $meas,
                'meas_unit'     => '°C',
            ];

            if ($typeSlug !== $teleType) {
                $anchors[sprintf('%s.%s.CH%d', $identifier, $teleType, $chNum)] = $anchors[$anchor];
            }
        }
    }

    return [
        'anchors'     => $anchors,
        'connections' => $connections,
        'ipv4'        => $ipv4ById,
    ];
}
