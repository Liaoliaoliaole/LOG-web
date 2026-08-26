<?php

require_once __DIR__ . '/../core/system_info.php';

const NETWORK_ETH_IFACE = 'eth0';
const NETWORK_CAN_IFACES = ['can0', 'can1'];
const NETWORK_DEFAULT_IPV4_PREFIX = 24;
const NETWORK_PENDING_FILE = '/tmp/morfeas_network_pending.json';
const NETWORK_LOCK_FILE = '/tmp/morfeas_network_apply.lock';
const NETWORK_DEFAULT_TIMEOUT_SEC = 90;
const NETWORK_FILE_HELPER = '/usr/local/sbin/morfeas-network-files';

function network_now(): int
{
    return time();
}

function network_uuid(): string
{
    return bin2hex(random_bytes(8));
}

function network_exec(string $command, bool $sudo = false): array
{
    $full = $sudo ? ('sudo -n ' . $command) : $command;
    $out = [];
    $code = 0;
    exec($full . ' 2>&1', $out, $code);

    return [
        'code' => $code,
        'output' => implode("\n", $out),
        'command' => $full,
    ];
}

function network_exec_ok(string $command, bool $sudo = false, string $context = 'Command failed'): string
{
    $ret = network_exec($command, $sudo);
    if ($ret['code'] !== 0) {
        throw new RuntimeException($context . ': ' . trim((string) $ret['output']));
    }
    return trim((string) $ret['output']);
}

function network_with_lock(callable $fn)
{
    $fp = fopen(NETWORK_LOCK_FILE, 'c+');
    if (!$fp) {
        throw new RuntimeException('Unable to open network lock file');
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('Unable to acquire network lock');
    }

    try {
        return $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function network_valid_ipv4(?string $ip): bool
{
    if (!is_string($ip) || $ip === '') {
        return false;
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function network_read_pending(): ?array
{
    if (!is_file(NETWORK_PENDING_FILE)) {
        return null;
    }
    $raw = @file_get_contents(NETWORK_PENDING_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function network_write_pending(array $pending): void
{
    $json = json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode pending network state');
    }
    if (@file_put_contents(NETWORK_PENDING_FILE, $json) === false) {
        throw new RuntimeException('Unable to write pending network state');
    }
}

function network_pending_summary(?array $pending): ?array
{
    if (!$pending) {
        return null;
    }

    $now = network_now();
    $expiresAt = (int) ($pending['expires_at'] ?? 0);
    $remaining = max(0, $expiresAt - $now);

    return [
        'pending_id' => (string) ($pending['pending_id'] ?? ''),
        'state' => (string) ($pending['state'] ?? 'unknown'),
        'created_at' => (int) ($pending['created_at'] ?? 0),
        'expires_at' => $expiresAt,
        'timeout_sec' => (int) ($pending['timeout_sec'] ?? 0),
        'remaining_sec' => $remaining,
        'confirmed_at' => isset($pending['confirmed_at']) ? (int) $pending['confirmed_at'] : null,
        'rolled_back_at' => isset($pending['rolled_back_at']) ? (int) $pending['rolled_back_at'] : null,
        'reason' => isset($pending['reason']) ? (string) $pending['reason'] : null,
    ];
}

function network_get_hostname(): string
{
    $name = trim((string) @shell_exec('hostnamectl --static 2>/dev/null'));
    if ($name !== '') {
        return $name;
    }
    $fallback = gethostname();
    return is_string($fallback) && $fallback !== '' ? $fallback : 'unknown';
}

function network_get_operator_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return network_valid_ipv4($ip) ? $ip : null;
}

function network_eth_ip_prefix(): array
{
    $ret = network_exec('ip -4 -o addr show dev ' . escapeshellarg(NETWORK_ETH_IFACE));
    if ($ret['code'] !== 0 || trim($ret['output']) === '') {
        return ['address' => null, 'prefix' => null];
    }

    if (preg_match('/\binet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $ret['output'], $m)) {
        return [
            'address' => $m[1],
            'prefix' => (int) $m[2],
        ];
    }

    return ['address' => null, 'prefix' => null];
}

function network_eth_gateway(): ?string
{
    $ret = network_exec('ip route show default dev ' . escapeshellarg(NETWORK_ETH_IFACE));
    if ($ret['code'] !== 0 || trim($ret['output']) === '') {
        return null;
    }
    if (preg_match('/\bvia\s+(\d+\.\d+\.\d+\.\d+)\b/', $ret['output'], $m)) {
        return $m[1];
    }
    return null;
}

function network_dns_servers(): array
{
    $dns = [];

    $resolv = '/etc/resolv.conf';
    if (is_file($resolv)) {
        $lines = @file($resolv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\d+\.\d+\.\d+\.\d+)\s*$/i', $line, $m)) {
                if (network_valid_ipv4($m[1])) {
                    $dns[] = $m[1];
                }
            }
        }
    }

    $dns = array_values(array_unique($dns));
    return $dns;
}

function network_nm_connection_for_device(string $device): ?string
{
    $ret = network_exec('nmcli -t -f NAME,DEVICE connection show');
    if ($ret['code'] !== 0) {
        return null;
    }

    foreach (explode("\n", $ret['output']) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$name, $dev] = $parts;
        if (trim($dev) === $device && trim($name) !== '') {
            return trim($name);
        }
    }

    return null;
}

function network_nm_eth_mode(): ?string
{
    $conn = network_nm_connection_for_device(NETWORK_ETH_IFACE);
    if (!$conn) {
        return null;
    }

    $ret = network_exec('nmcli -g ipv4.method connection show ' . escapeshellarg($conn));
    if ($ret['code'] !== 0) {
        return null;
    }

    $method = strtolower(trim($ret['output']));
    if ($method === 'manual') {
        return 'static';
    }
    if ($method === 'auto') {
        return 'dhcp';
    }

    return null;
}

function network_nm_can_supported(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $ret = network_exec('nmcli connection add type __probe__ ifname __probe__ con-name __probe__');
    if ($ret['code'] === 0) {
        // Highly unlikely, but if it somehow succeeded, cleanup and return true.
        network_exec('nmcli connection delete ' . escapeshellarg('__probe__'), true);
        $cached = true;
        return $cached;
    }

    // nmcli prints allowed connection types in this error path.
    $text = strtolower((string) $ret['output']);
    $cached = preg_match('/\bcan\b/', $text) === 1;
    return $cached;
}

function network_system_file_key(string $path): string
{
    $keys = [
        '/etc/NetworkManager/NetworkManager.conf' => 'networkmanager',
        '/etc/hosts' => 'hosts',
        '/etc/network/interfaces.d/can0' => 'can0',
        '/etc/network/interfaces.d/can1' => 'can1',
        '/etc/systemd/timesyncd.conf' => 'timesyncd',
    ];
    if (!array_key_exists($path, $keys)) {
        throw new RuntimeException("Unsupported system configuration path: $path");
    }
    return $keys[$path];
}

function network_file_helper_command(string ...$args): string
{
    return escapeshellarg(NETWORK_FILE_HELPER) . ' '
        . implode(' ', array_map('escapeshellarg', $args));
}

/*
 * The new contents go to the helper on stdin, not as a path argument.
 * The shell performs this redirection as www-data before exec'ing sudo, so
 * the helper receives a descriptor that was already open and cannot be
 * swapped for a symlink between validation and use -- see the comment on
 * write_file() in deploy/morfeas-network-files for why passing the path
 * instead would let a compromised web process read root-only files into a
 * world-readable target.
 */
function network_write_system_file_via_helper(string $path, string $contents, string $context): void
{
    $key = network_system_file_key($path);

    $tmp = '/tmp/morfeas_net_' . basename($path) . '_' . network_uuid();
    if (@file_put_contents($tmp, $contents) === false) {
        throw new RuntimeException("$context: unable to create temp file");
    }

    try {
        network_exec_ok(
            network_file_helper_command('write', $key) . ' < ' . escapeshellarg($tmp),
            true,
            $context
        );
    } finally {
        @unlink($tmp);
    }
}

function network_ensure_nm_ifupdown_managed_true(string $backupDir): bool
{
    $nmConf = '/etc/NetworkManager/NetworkManager.conf';
    if (!is_file($nmConf)) {
        return false;
    }

    network_exec_ok(
        network_file_helper_command('backup-networkmanager', $backupDir),
        true,
        'Unable to backup NetworkManager.conf'
    );

    $raw = @file_get_contents($nmConf);
    if (!is_string($raw)) {
        throw new RuntimeException('Unable to read NetworkManager.conf');
    }

    $updated = $raw;
    if (preg_match('/^\[ifupdown\]\s*$/mi', $updated) === 1) {
        if (preg_match('/^\s*managed\s*=.*$/mi', $updated) === 1) {
            $updated = preg_replace('/^\s*managed\s*=.*$/mi', 'managed=true', $updated) ?? $updated;
        } else {
            $updated = preg_replace('/^\[ifupdown\]\s*$/mi', "[ifupdown]\nmanaged=true", $updated, 1) ?? $updated;
        }
    } else {
        $updated = rtrim($updated, "\n") . "\n\n[ifupdown]\nmanaged=true\n";
    }

    if ($updated === $raw) {
        return false;
    }

    network_write_system_file_via_helper($nmConf, $updated, 'Unable to enable NM ifupdown managed=true');
    return true;
}

function network_update_hosts_hostname_map(string $hostname): void
{
    $hostsPath = '/etc/hosts';
    $raw = @file_get_contents($hostsPath);
    if (!is_string($raw)) {
        throw new RuntimeException('Unable to read /etc/hosts');
    }

    $line = "127.0.1.1\t$hostname";
    if (preg_match('/^127\.0\.1\.1\s+.+$/mi', $raw) === 1) {
        $updated = preg_replace('/^127\.0\.1\.1\s+.+$/mi', $line, $raw, 1) ?? $raw;
    } else {
        $updated = rtrim($raw, "\n") . "\n$line\n";
    }

    if ($updated === $raw) {
        return;
    }

    network_write_system_file_via_helper($hostsPath, $updated, 'Unable to update /etc/hosts');
}

function network_get_can_bitrate_bps(string $iface): ?int
{
    $sysfs = sprintf('/sys/class/net/%s/can_bittiming/bitrate', $iface);
    if (is_file($sysfs)) {
        $raw = trim((string) @file_get_contents($sysfs));
        if ($raw !== '' && is_numeric($raw)) {
            $bps = (int) $raw;
            if ($bps > 0) {
                return $bps;
            }
        }
    }

    $ret = network_exec('ip -details link show ' . escapeshellarg($iface));
    if ($ret['code'] === 0 && preg_match('/bitrate\s+(\d+)/i', $ret['output'], $m)) {
        $bps = (int) $m[1];
        if ($bps > 0) {
            return $bps;
        }
    }

    return null;
}

function network_effective_eth_mode(): string
{
    $mode = network_nm_eth_mode();
    if ($mode !== null) {
        return $mode;
    }

    $ip = network_eth_ip_prefix();
    return $ip['address'] ? 'static' : 'dhcp';
}

function network_get_state(): array
{
    $ip = network_eth_ip_prefix();

    $can = [];
    foreach (NETWORK_CAN_IFACES as $iface) {
        $can[$iface] = [
            'bitrate' => network_get_can_bitrate_bps($iface),
            'display' => read_can_bitrate($iface) ?? '—',
        ];
    }

    $pending = network_pending_summary(network_read_pending());

    return [
        'hostname' => network_get_hostname(),
        'eth' => [
            'iface' => NETWORK_ETH_IFACE,
            'mode' => network_effective_eth_mode(),
            'ipv4' => [
                'address' => $ip['address'],
                'prefix' => $ip['prefix'],
                'gateway' => network_eth_gateway(),
                'dns' => network_dns_servers(),
            ],
            'current_ip' => $ip['address'],
            'operator_ip' => network_get_operator_ip(),
        ],
        'can' => $can,
        'can_backend' => network_nm_can_supported() ? 'nm' : 'legacy',
        'ntp' => [
            'server' => read_timesyncd_ntp_server() ?? '—',
            'readonly' => false,
        ],
        'pending' => $pending,
    ];
}

function network_require_hostname(string $hostname): string
{
    $hostname = trim($hostname);
    if ($hostname === '') {
        throw new InvalidArgumentException('hostname is required');
    }
    if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $hostname)) {
        throw new InvalidArgumentException('hostname contains invalid characters');
    }
    return $hostname;
}

function network_require_ipv4_field(array $arr, string $field): string
{
    $val = trim((string) ($arr[$field] ?? ''));
    if (!network_valid_ipv4($val)) {
        throw new InvalidArgumentException($field . ' must be a valid IPv4 address');
    }
    return $val;
}

function network_normalize_dns($dnsRaw): array
{
    if (!is_array($dnsRaw)) {
        return [];
    }
    $dns = [];
    foreach ($dnsRaw as $item) {
        $ip = trim((string) $item);
        if ($ip === '') {
            continue;
        }
        if (!network_valid_ipv4($ip)) {
            throw new InvalidArgumentException('dns contains invalid IPv4 value');
        }
        $dns[] = $ip;
    }
    return array_values(array_unique($dns));
}

function network_normalize_bitrate($value): int
{
    if ($value === null || $value === '') {
        throw new InvalidArgumentException('CAN bitrate is required');
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('CAN bitrate must be numeric');
    }
    $bitrate = (int) $value;
    if ($bitrate < 10000 || $bitrate > 1000000) {
        throw new InvalidArgumentException('CAN bitrate out of allowed range (10000..1000000)');
    }
    return $bitrate;
}

function network_normalize_payload(array $payload, array $state): array
{
    $hostname = network_require_hostname((string) ($payload['hostname'] ?? ''));

    $eth = $payload['eth'] ?? [];
    if (!is_array($eth)) {
        throw new InvalidArgumentException('eth payload is required');
    }

    $mode = strtolower(trim((string) ($eth['mode'] ?? '')));
    if (!in_array($mode, ['static', 'dhcp'], true)) {
        throw new InvalidArgumentException('eth.mode must be static or dhcp');
    }

    $ipv4 = [
        'address' => null,
        'prefix' => null,
        'gateway' => null,
        'dns' => [],
    ];

    if ($mode === 'static') {
        $ipv4Raw = $eth['ipv4'] ?? [];
        if (!is_array($ipv4Raw)) {
            throw new InvalidArgumentException('eth.ipv4 payload is required for static mode');
        }

        $address = network_require_ipv4_field($ipv4Raw, 'address');
        $prefixRaw = $ipv4Raw['prefix'] ?? null;
        if (!is_numeric($prefixRaw)) {
            throw new InvalidArgumentException('prefix must be a number between 0 and 32');
        }
        $prefix = (int) $prefixRaw;
        if ($prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('prefix must be in range 0..32');
        }

        $gateway = network_require_ipv4_field($ipv4Raw, 'gateway');

        $dns = network_normalize_dns($ipv4Raw['dns'] ?? []);

        $ipv4 = [
            'address' => $address,
            'prefix' => $prefix,
            'gateway' => $gateway,
            'dns' => $dns,
        ];
    }

    $canPayload = $payload['can'] ?? [];
    if (!is_array($canPayload)) {
        $canPayload = [];
    }

    $can = [];
    foreach (NETWORK_CAN_IFACES as $iface) {
        $ifaceData = $canPayload[$iface] ?? null;
        if (is_array($ifaceData) && array_key_exists('bitrate', $ifaceData)) {
            $can[$iface] = ['bitrate' => network_normalize_bitrate($ifaceData['bitrate'])];
            continue;
        }

        $fallback = $state['can'][$iface]['bitrate'] ?? null;
        if (!is_numeric($fallback) || (int) $fallback <= 0) {
            throw new InvalidArgumentException("CAN bitrate for $iface is unavailable; provide explicit value");
        }
        $can[$iface] = ['bitrate' => (int) $fallback];
    }

    $ntpServer = null;
    $ntpPayload = $payload['ntp'] ?? null;
    if (is_array($ntpPayload)) {
        $servers = $ntpPayload['servers'] ?? [];
        if (!is_array($servers)) {
            throw new InvalidArgumentException('ntp.servers must be an array');
        }
        foreach ($servers as $serverRaw) {
            $server = trim((string) $serverRaw);
            if ($server === '') {
                continue;
            }
            if (!network_valid_ipv4($server)) {
                throw new InvalidArgumentException('NTP server must be a valid IPv4 address');
            }
            $ntpServer = $server;
            break;
        }
    } else {
        $stateNtp = trim((string) ($state['ntp']['server'] ?? ''));
        if (network_valid_ipv4($stateNtp)) {
            $ntpServer = $stateNtp;
        }
    }

    return [
        'hostname' => $hostname,
        'eth' => [
            'mode' => $mode,
            'ipv4' => $ipv4,
        ],
        'can' => $can,
        'ntp' => [
            'server' => $ntpServer,
        ],
    ];
}

function network_restore_payload_from_state(array $state): array
{
    $stateNtp = trim((string) ($state['ntp']['server'] ?? ''));
    if (!network_valid_ipv4($stateNtp)) {
        $stateNtp = null;
    }

    return [
        'hostname' => (string) ($state['hostname'] ?? network_get_hostname()),
        'eth' => [
            'mode' => (string) ($state['eth']['mode'] ?? 'dhcp'),
            'ipv4' => [
                'address' => $state['eth']['ipv4']['address'] ?? null,
                'prefix' => $state['eth']['ipv4']['prefix'] ?? NETWORK_DEFAULT_IPV4_PREFIX,
                'gateway' => $state['eth']['ipv4']['gateway'] ?? null,
                'dns' => is_array($state['eth']['ipv4']['dns'] ?? null) ? $state['eth']['ipv4']['dns'] : [],
            ],
        ],
        'can' => [
            'can0' => ['bitrate' => is_numeric($state['can']['can0']['bitrate'] ?? null) ? (int) $state['can']['can0']['bitrate'] : null],
            'can1' => ['bitrate' => is_numeric($state['can']['can1']['bitrate'] ?? null) ? (int) $state['can']['can1']['bitrate'] : null],
        ],
        'ntp' => [
            'server' => $stateNtp,
        ],
    ];
}

function network_backup_dir(string $pendingId): string
{
    // The root helper, not www-data, creates this exact /run path with
    // root:root 0700.  Do not change this to /tmp or pre-create it here:
    // backup child paths must be outside the caller's control.
    return '/run/morfeas_network_backup_' . preg_replace('/[^a-f0-9]/', '', strtolower($pendingId));
}

function network_prepare_cutover(string $backupDir): void
{
    $nmCan = network_nm_can_supported();
    $ifacesForCutover = [NETWORK_ETH_IFACE];
    if ($nmCan) {
        $ifacesForCutover = array_merge($ifacesForCutover, NETWORK_CAN_IFACES);
    }

    $cutoverTouched = false;
    foreach ($ifacesForCutover as $iface) {
        $path = '/etc/network/interfaces.d/' . $iface;

        if (is_file($path)) {
            network_exec_ok(
                network_file_helper_command('backup-disable-ifupdown', $iface, $backupDir),
                true,
                "Unable to backup and disable $path"
            );
            $cutoverTouched = true;
        }
    }

    $nmConfChanged = network_ensure_nm_ifupdown_managed_true($backupDir);

    if ($cutoverTouched || $nmConfChanged) {
        network_exec_ok('systemctl restart NetworkManager', true, 'Unable to restart NetworkManager');
    }

    foreach ($ifacesForCutover as $iface) {
        network_exec('nmcli device set ' . escapeshellarg($iface) . ' managed yes', true);
    }
}

function network_default_connection_name(string $iface): string
{
    return 'morfeas-' . $iface;
}

function network_connection_exists(string $name): bool
{
    $ret = network_exec('nmcli -t -f NAME connection show ' . escapeshellarg($name));
    return $ret['code'] === 0;
}

function network_ensure_eth_connection(string $connName): void
{
    if (!network_connection_exists($connName)) {
        network_exec_ok(
            'nmcli connection add type ethernet ifname ' . escapeshellarg(NETWORK_ETH_IFACE)
            . ' con-name ' . escapeshellarg($connName) . ' autoconnect yes',
            true,
            'Unable to create NM ethernet connection'
        );
    }

    network_exec_ok('nmcli connection modify ' . escapeshellarg($connName) . ' connection.interface-name ' . escapeshellarg(NETWORK_ETH_IFACE), true, 'Unable to bind NM ethernet connection to eth0');
    network_exec_ok('nmcli connection modify ' . escapeshellarg($connName) . ' connection.autoconnect yes ipv6.method ignore', true, 'Unable to configure NM ethernet autoconnect/ipv6');
}

function network_apply_eth(array $eth): void
{
    $connName = network_nm_connection_for_device(NETWORK_ETH_IFACE) ?: network_default_connection_name(NETWORK_ETH_IFACE);
    network_ensure_eth_connection($connName);

    if ($eth['mode'] === 'dhcp') {
        network_exec_ok('nmcli connection modify ' . escapeshellarg($connName) . ' ipv4.method auto ipv4.addresses "" ipv4.gateway "" ipv4.dns ""', true, 'Unable to configure DHCP mode');
    } else {
        $ip = $eth['ipv4']['address'];
        $prefix = (int) $eth['ipv4']['prefix'];
        $gateway = $eth['ipv4']['gateway'];
        $dns = $eth['ipv4']['dns'];
        $dnsCsv = implode(',', $dns);

        network_exec_ok(
            'nmcli connection modify ' . escapeshellarg($connName)
            . ' ipv4.method manual'
            . ' ipv4.addresses ' . escapeshellarg($ip . '/' . $prefix)
            . ' ipv4.gateway ' . escapeshellarg($gateway)
            . ' ipv4.dns ' . escapeshellarg($dnsCsv),
            true,
            'Unable to configure static IPv4 settings'
        );
    }

    network_exec_ok('nmcli connection up ' . escapeshellarg($connName) . ' ifname ' . escapeshellarg(NETWORK_ETH_IFACE), true, 'Unable to bring up ethernet connection');
}

function network_ensure_can_connection(string $iface, string $connName): void
{
    if (!network_connection_exists($connName)) {
        network_exec_ok(
            'nmcli connection add type can ifname ' . escapeshellarg($iface)
            . ' con-name ' . escapeshellarg($connName)
            . ' autoconnect yes',
            true,
            "Unable to create NM CAN connection for $iface"
        );
    }

    network_exec_ok('nmcli connection modify ' . escapeshellarg($connName) . ' connection.interface-name ' . escapeshellarg($iface), true, "Unable to bind NM CAN connection for $iface");
    network_exec_ok('nmcli connection modify ' . escapeshellarg($connName) . ' connection.autoconnect yes', true, "Unable to set autoconnect for $iface");
}

function network_update_can_interfaces_file(string $iface, int $bitrate): void
{
    $path = '/etc/network/interfaces.d/' . $iface;

    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException("Unable to read $path");
        }

        $updated = $raw;

        // Handle legacy ifupdown CAN format: "iface canX can static".
        if (preg_match('/^\s*iface\s+' . preg_quote($iface, '/') . '\s+can\s+static\s*$/mi', $raw) === 1) {
            if (preg_match('/^\s*bitrate\s+\d+\s*$/mi', $updated) === 1) {
                $updated = preg_replace('/^\s*bitrate\s+\d+\s*$/mi', "\tbitrate $bitrate", $updated, 1) ?? $updated;
            } else {
                $updated = preg_replace(
                    '/^\s*iface\s+' . preg_quote($iface, '/') . '\s+can\s+static\s*$/mi',
                    "iface $iface can static\n\tbitrate $bitrate",
                    $updated,
                    1
                ) ?? $updated;
            }

            // Keep pre-up line in sync when it exists.
            if (preg_match('/^\s*pre-up\s+\/sbin\/ip\s+link\s+set\s+\\$IFACE\s+type\s+can\s+bitrate\s+\d+/mi', $updated) === 1) {
                $updated = preg_replace(
                    '/(^\s*pre-up\s+\/sbin\/ip\s+link\s+set\s+\\$IFACE\s+type\s+can\s+bitrate\s+)\d+/mi',
                    '$1' . $bitrate,
                    $updated,
                    1
                ) ?? $updated;
            }
        } else {
            // Fallback to canonical legacy style that worked in old deployments.
            $updated = "auto $iface\n"
                . "allow-hotplug $iface\n"
                . "iface $iface inet manual\n"
                . "\tpre-up /sbin/ip link set $iface type can bitrate $bitrate triple-sampling on restart-ms 100\n"
                . "\tup /sbin/ifconfig $iface up\n"
                . "\tdown /sbin/ifconfig $iface down\n";
        }

        if ($updated !== $raw) {
            network_write_system_file_via_helper($path, $updated, "Unable to persist CAN bitrate for $iface");
        }
        return;
    }

    $template = "auto $iface\n"
        . "allow-hotplug $iface\n"
        . "iface $iface can static\n"
        . "\tbitrate $bitrate\n"
        . "\tup /sbin/ip link set \$IFACE up\n"
        . "\tdown /sbin/ip link set \$IFACE down\n";

    network_write_system_file_via_helper($path, $template, "Unable to create CAN interface config for $iface");
}

function network_apply_can_legacy(array $can): void
{
    foreach (NETWORK_CAN_IFACES as $iface) {
        $bitrate = (int) ($can[$iface]['bitrate'] ?? 0);
        if ($bitrate <= 0) {
            continue;
        }

        network_update_can_interfaces_file($iface, $bitrate);
        network_exec('/sbin/ip link set ' . escapeshellarg($iface) . ' down', true);
        network_exec_ok(
            '/sbin/ip link set ' . escapeshellarg($iface)
            . ' up type can bitrate ' . escapeshellarg((string) $bitrate),
            true,
            "Unable to bring up CAN interface $iface with bitrate $bitrate"
        );
    }
}

function network_apply_can_nm(array $can): void
{
    foreach (NETWORK_CAN_IFACES as $iface) {
        $bitrate = (int) ($can[$iface]['bitrate'] ?? 0);
        if ($bitrate <= 0) {
            continue;
        }

        $connName = network_nm_connection_for_device($iface) ?: network_default_connection_name($iface);
        network_ensure_can_connection($iface, $connName);

        network_exec_ok(
            'nmcli connection modify ' . escapeshellarg($connName)
            . ' can.bitrate ' . escapeshellarg((string) $bitrate),
            true,
            "Unable to set CAN bitrate for $iface"
        );
        network_exec_ok('nmcli connection up ' . escapeshellarg($connName) . ' ifname ' . escapeshellarg($iface), true, "Unable to bring up CAN interface $iface");
    }
}

function network_apply_can(array $can): void
{
    if (network_nm_can_supported()) {
        network_apply_can_nm($can);
        return;
    }

    network_apply_can_legacy($can);
}

function network_apply_hostname(string $hostname): void
{
    network_update_hosts_hostname_map($hostname);
    network_exec_ok('hostnamectl set-hostname ' . escapeshellarg($hostname), true, 'Unable to apply hostname');
}

function network_apply_ntp_server(?string $server): void
{
    if (!is_string($server) || !network_valid_ipv4($server)) {
        return;
    }

    $confPath = '/etc/systemd/timesyncd.conf';
    $raw = @file_get_contents($confPath);
    if (!is_string($raw)) {
        throw new RuntimeException('Unable to read timesyncd configuration');
    }

    $line = 'NTP=' . $server;
    $updated = $raw;

    if (preg_match('/^\s*#?\s*NTP\s*=.*$/mi', $updated) === 1) {
        $updated = preg_replace('/^\s*#?\s*NTP\s*=.*$/mi', $line, $updated, 1) ?? $updated;
    } elseif (preg_match('/^\s*\[Time\]\s*$/mi', $updated) === 1) {
        $updated = preg_replace('/^\s*\[Time\]\s*$/mi', "[Time]\n$line", $updated, 1) ?? $updated;
    } else {
        $updated = rtrim($updated, "\n") . "\n\n[Time]\n$line\n";
    }

    if ($updated !== $raw) {
        network_write_system_file_via_helper($confPath, $updated, 'Unable to update NTP server in timesyncd.conf');
    }

    network_exec_ok('systemctl restart systemd-timesyncd', true, 'Unable to restart systemd-timesyncd');
}

function network_apply_payload(array $normalized): void
{
    network_apply_hostname($normalized['hostname']);
    network_apply_eth($normalized['eth']);
    network_apply_can($normalized['can']);
    network_apply_ntp_server($normalized['ntp']['server'] ?? null);
}

function network_apply_payload_no_rollback(array $normalized): array
{
    $warnings = [];

    network_apply_hostname($normalized['hostname']);
    network_apply_eth($normalized['eth']);

    try {
        network_apply_can($normalized['can']);
    } catch (Throwable $e) {
        $warnings[] = 'CAN apply warning: ' . $e->getMessage();
    }

    try {
        network_apply_ntp_server($normalized['ntp']['server'] ?? null);
    } catch (Throwable $e) {
        $warnings[] = 'NTP apply warning: ' . $e->getMessage();
    }

    return $warnings;
}

function network_start_rollback_watcher(string $pendingId, int $timeoutSec): void
{
    $phpCli = '/usr/bin/php';
    if (!is_executable($phpCli)) {
        $phpCli = PHP_BINARY ?: 'php';
    }

    $script = realpath(__DIR__ . '/../cli/network_pending_watcher.php');
    if (!$script) {
        throw new RuntimeException('Pending watcher script not found');
    }

    $sleep = max(1, $timeoutSec + 2);
    $cmd = sprintf(
        'nohup %s %s %s %d >/dev/null 2>&1 &',
        escapeshellarg($phpCli),
        escapeshellarg($script),
        escapeshellarg($pendingId),
        $sleep
    );
    @shell_exec($cmd);
}

function network_pending_is_active(array $pending): bool
{
    return ($pending['state'] ?? '') === 'pending' && network_now() < (int) ($pending['expires_at'] ?? 0);
}

function network_rollback_locked(array $pending, string $reason): array
{
    $restore = $pending['before_payload'] ?? null;
    if (!is_array($restore)) {
        throw new RuntimeException('Rollback payload is missing');
    }

    network_apply_payload($restore);

    $pending['state'] = 'rolled_back';
    $pending['rolled_back_at'] = network_now();
    $pending['reason'] = $reason;
    network_write_pending($pending);

    return $pending;
}

function network_apply_staged(array $payload, int $timeoutSec = NETWORK_DEFAULT_TIMEOUT_SEC, bool $autoConfirm = false): array
{
    if (!$autoConfirm) {
        $timeoutSec = max(60, min(120, $timeoutSec));
    } else {
        $timeoutSec = 0;
    }

    return network_with_lock(function () use ($payload, $timeoutSec, $autoConfirm) {
        $existing = network_read_pending();
        if ($existing && ($existing['state'] ?? '') === 'pending' && network_now() >= (int) ($existing['expires_at'] ?? 0)) {
            $existing = network_rollback_locked($existing, 'expired_before_new_apply');
        }
        if ($existing && network_pending_is_active($existing)) {
            throw new RuntimeException('Another network apply is still pending confirmation');
        }

        $beforeState = network_get_state();
        $normalized = network_normalize_payload($payload, $beforeState);
        $beforePayload = network_restore_payload_from_state($beforeState);

        $pendingId = network_uuid();
        $backupDir = network_backup_dir($pendingId);

        network_prepare_cutover($backupDir);

        $warnings = [];
        if ($autoConfirm) {
            // Future production mode: no rollback loop, keep applied ETH/hostname;
            // CAN failures are reported as warnings.
            $warnings = network_apply_payload_no_rollback($normalized);
        } else {
            try {
                network_apply_payload($normalized);
            } catch (Throwable $e) {
                try {
                    network_apply_payload($beforePayload);
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException('Apply failed and rollback failed: ' . $rollbackError->getMessage() . '; original error: ' . $e->getMessage());
                }
                throw new RuntimeException('Apply failed; restored previous network state. Error: ' . $e->getMessage());
            }
        }

        $now = network_now();
        $pending = [
            'pending_id' => $pendingId,
            'state' => $autoConfirm ? 'confirmed' : 'pending',
            'created_at' => $now,
            'expires_at' => $autoConfirm ? $now : ($now + $timeoutSec),
            'timeout_sec' => $timeoutSec,
            'before_payload' => $beforePayload,
            'after_payload' => $normalized,
            'backup_dir' => $backupDir,
            'requested_by' => network_get_operator_ip(),
        ];
        if ($autoConfirm) {
            $pending['confirmed_at'] = $now;
        }
        if (!empty($warnings)) {
            $pending['warnings'] = $warnings;
        }
        network_write_pending($pending);

        if (!$autoConfirm) {
            network_start_rollback_watcher($pendingId, $timeoutSec);
        }

        $result = [
            'pending' => network_pending_summary($pending),
            'state' => network_get_state(),
        ];
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }
        return $result;
    });
}

function network_confirm_pending(string $pendingId): array
{
    return network_with_lock(function () use ($pendingId) {
        $pending = network_read_pending();
        if (!$pending) {
            throw new RuntimeException('No pending network apply found');
        }

        if (($pending['pending_id'] ?? '') !== $pendingId) {
            throw new RuntimeException('pending_id mismatch');
        }

        if (($pending['state'] ?? '') !== 'pending') {
            throw new RuntimeException('Pending state is already finalized');
        }

        if (network_now() >= (int) ($pending['expires_at'] ?? 0)) {
            $pending = network_rollback_locked($pending, 'expired_before_confirm');
            return [
                'pending' => network_pending_summary($pending),
                'state' => network_get_state(),
            ];
        }

        $pending['state'] = 'confirmed';
        $pending['confirmed_at'] = network_now();
        network_write_pending($pending);

        return [
            'pending' => network_pending_summary($pending),
            'state' => network_get_state(),
        ];
    });
}

function network_manual_rollback(?string $pendingId = null): array
{
    return network_with_lock(function () use ($pendingId) {
        $pending = network_read_pending();
        if (!$pending) {
            throw new RuntimeException('No pending network apply found');
        }

        if ($pendingId !== null && $pendingId !== '' && ($pending['pending_id'] ?? '') !== $pendingId) {
            throw new RuntimeException('pending_id mismatch');
        }

        if (($pending['state'] ?? '') !== 'pending') {
            throw new RuntimeException('Pending state is already finalized');
        }

        $pending = network_rollback_locked($pending, 'manual_rollback');

        return [
            'pending' => network_pending_summary($pending),
            'state' => network_get_state(),
        ];
    });
}

function network_auto_rollback_if_expired(string $pendingId): array
{
    return network_with_lock(function () use ($pendingId) {
        $pending = network_read_pending();
        if (!$pending) {
            return ['handled' => false, 'reason' => 'no_pending'];
        }

        if (($pending['pending_id'] ?? '') !== $pendingId) {
            return ['handled' => false, 'reason' => 'different_pending'];
        }

        if (($pending['state'] ?? '') !== 'pending') {
            return ['handled' => false, 'reason' => 'already_finalized'];
        }

        if (network_now() < (int) ($pending['expires_at'] ?? 0)) {
            return ['handled' => false, 'reason' => 'not_expired_yet'];
        }

        $pending = network_rollback_locked($pending, 'timeout_auto_rollback');

        return [
            'handled' => true,
            'pending' => network_pending_summary($pending),
        ];
    });
}
