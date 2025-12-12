<?php

// Utilities for reading system-level data on Pi or sandbox environments.

function format_bitrate_kbps($bps)
{
    if (!is_numeric($bps) || $bps <= 0) {
        return null;
    }

    $kbps = round($bps / 1000);
    if ($kbps >= 1000) {
        $mbps = $kbps / 1000;
        return sprintf('%g Mbps', $mbps);
    }

    return sprintf('%d kbps', (int) $kbps);
}

function read_can_bitrate(string $iface): ?string
{
    // Prefer iproute2 output if available.
    $cmd = sprintf('ip -details link show %s 2>/dev/null', escapeshellarg($iface));
    $output = shell_exec($cmd);
    if ($output && preg_match('/bitrate\s+(\d+)/i', $output, $m)) {
        return format_bitrate_kbps((int) $m[1]);
    }

    // Fallback: check sysfs if bitrate was configured via ip link set.
    $sysfs = sprintf('/sys/class/net/%s/can_bittiming/bitrate', $iface);
    if (is_file($sysfs)) {
        $raw = trim((string) @file_get_contents($sysfs));
        if ($raw !== '' && is_numeric($raw)) {
            return format_bitrate_kbps((int) $raw);
        }
    }

    return null;
}

function system_can_bitrates(): array
{
    $map = [];
    foreach (['can0', 'can1'] as $iface) {
        $map[$iface] = read_can_bitrate($iface) ?? '—';
    }
    return $map;
}

function primary_mac_address(): ?string
{
    $preferred = ['eth0', 'en0', 'wlan0'];
    foreach ($preferred as $iface) {
        $mac = mac_for_interface($iface);
        if ($mac) return $mac;
    }

    // Fallback: first non-loopback interface.
    foreach (glob('/sys/class/net/*') ?: [] as $path) {
        $iface = basename($path);
        if ($iface === 'lo') continue;
        $mac = mac_for_interface($iface);
        if ($mac) return $mac;
    }

    return null;
}

function mac_for_interface(string $iface): ?string
{
    $addrPath = sprintf('/sys/class/net/%s/address', $iface);
    if (!is_file($addrPath)) return null;

    $mac = strtoupper(trim((string) @file_get_contents($addrPath)));
    if ($mac === '' || $mac === '00:00:00:00:00:00') return null;
    return $mac;
}