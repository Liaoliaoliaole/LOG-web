<?php

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

function read_timesyncd_ntp_server(): ?string
{
    $conf = '/etc/systemd/timesyncd.conf';
    if (is_file($conf)) {
        $lines = @file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            if (preg_match('/^NTP\s*=\s*(.+)$/i', $trim, $m)) {
                $raw = trim((string) $m[1]);
                if ($raw === '') {
                    continue;
                }

                // Keep first token only (timesyncd allows multiple NTP servers).
                $parts = preg_split('/\s+/', $raw) ?: [];
                $first = trim((string) ($parts[0] ?? ''));
                if ($first !== '') {
                    return $first;
                }
            }
        }
    }

    $server = trim((string) @shell_exec('timedatectl show-timesync --property=ServerName --value 2>/dev/null'));
    return $server !== '' ? $server : null;
}

function parse_timesync_status_text(string $text): array
{
    $values = [
        'server' => null,
        'poll_interval' => null,
        'packet_count' => null,
    ];

    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        if (!preg_match('/^\s*([^:]+):\s*(.*?)\s*$/', $line, $match)) {
            continue;
        }
        $key = strtolower(trim($match[1]));
        $value = trim($match[2]);
        if ($key === 'server') {
            $values['server'] = $value !== '' ? $value : null;
        } elseif ($key === 'poll interval') {
            $values['poll_interval'] = $value !== '' ? $value : null;
        } elseif ($key === 'packet count') {
            $values['packet_count'] = ctype_digit($value) ? (int)$value : null;
        }
    }

    return $values;
}

function read_timesync_status(): array
{
    $status = parse_timesync_status_text((string)@shell_exec('LC_ALL=C timedatectl timesync-status 2>/dev/null'));
    $show = static function (string $property): ?string {
        $value = trim((string)@shell_exec(
            'timedatectl show --property=' . escapeshellarg($property) . ' --value 2>/dev/null'
        ));
        return $value !== '' ? $value : null;
    };
    $showTimesync = static function (string $property): ?string {
        $value = trim((string)@shell_exec(
            'timedatectl show-timesync --property=' . escapeshellarg($property) . ' --value 2>/dev/null'
        ));
        return $value !== '' ? $value : null;
    };

    $rtcName = trim((string)@file_get_contents('/sys/class/rtc/rtc0/name'));
    $rtcEpoch = trim((string)@file_get_contents('/sys/class/rtc/rtc0/since_epoch'));
    $systemEpoch = time();

    return [
        'configured_server' => read_timesyncd_ntp_server(),
        'selected_server' => $showTimesync('ServerName') ?? $status['server'],
        'selected_address' => $showTimesync('ServerAddress'),
        'ntp_enabled' => $show('NTP'),
        'ntp_synchronized' => $show('NTPSynchronized'),
        'poll_interval' => $status['poll_interval'],
        'packet_count' => $status['packet_count'],
        'timezone' => $show('Timezone'),
        'system_time' => date('c', $systemEpoch),
        'system_epoch' => $systemEpoch,
        'rtc_name' => $rtcName !== '' ? $rtcName : null,
        'rtc_hctosys' => ($rtcHctosys = trim((string)@file_get_contents('/sys/class/rtc/rtc0/hctosys'))) !== '' ? $rtcHctosys : null,
        'rtc_delta_sec' => ctype_digit($rtcEpoch) ? ($systemEpoch - (int)$rtcEpoch) : null,
    ];
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
