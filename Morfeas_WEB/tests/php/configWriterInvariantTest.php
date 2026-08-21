<?php
/*
 * Static guard for backend atomic-write call sites.
 * Run from Morfeas_WEB/: php tests/php/configWriterInvariantTest.php
 *
 * OPC_UA_Config.xml may only be replaced by iso_save_xml() or by the
 * validated two-file FTP restore transaction. Keeping a complete allowlist
 * of atomic-write calls also makes any future direct writer fail this test
 * until its destination and validation boundary are reviewed explicitly.
 */

$checks = 0;
$failures = 0;

function check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if ($condition) {
        echo "PASS: $message\n";
        return;
    }
    $failures++;
    echo "FAIL: $message\n";
}

function atomic_writer_calls(string $backend): array
{
    $calls = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend));

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = substr($path, strlen($backend) + 1);
        $tokens = token_get_all((string)file_get_contents($path));
        $braceDepth = 0;
        $functionScopes = [];
        $pendingFunction = null;
        $functionNameTokens = [];

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $name = null;
                for ($next = $index + 1; $next < count($tokens); $next++) {
                    $candidate = $tokens[$next];
                    if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                        continue;
                    }
                    if (is_array($candidate) && $candidate[0] === T_STRING) {
                        $name = $candidate[1];
                        $functionNameTokens[$next] = true;
                    }
                    break;
                }
                // Attribute calls inside a closure to its containing named
                // function; the allowlist is about production entry points.
                $pendingFunction = $name ?? ($functionScopes === [] ? '<closure>' : end($functionScopes)['name']);
                continue;
            }

            if ($token === '{') {
                $braceDepth++;
                if ($pendingFunction !== null) {
                    $functionScopes[] = ['name' => $pendingFunction, 'depth' => $braceDepth];
                    $pendingFunction = null;
                }
                continue;
            }

            if ($token === '}') {
                if ($functionScopes !== [] && end($functionScopes)['depth'] === $braceDepth) {
                    array_pop($functionScopes);
                }
                $braceDepth--;
                continue;
            }

            if (!is_array($token) || isset($functionNameTokens[$index]) || $token[0] !== T_STRING || !in_array(
                $token[1],
                ['backend_atomic_write_file', 'backend_atomic_write_file_synced'],
                true
            )) {
                continue;
            }

            $next = $index + 1;
            while ($next < count($tokens) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if (($tokens[$next] ?? null) !== '(') {
                continue;
            }

            $function = $functionScopes === [] ? '<global>' : end($functionScopes)['name'];
            $key = $relative . '|' . $function . '|' . $token[1];
            $calls[$key] = ($calls[$key] ?? 0) + 1;
        }
    }

    ksort($calls);
    return $calls;
}

$backend = realpath(__DIR__ . '/../../backend');
if ($backend === false) {
    echo "SKIPPED: backend/ not found\n";
    exit(0);
}

$expected = [
    'core/opcua_config.php|iso_save_xml|backend_atomic_write_file' => 1,
    'core/session_registry.php|backend_session_registry_write_json_file|backend_atomic_write_file' => 1,
    'repositories/log_config_repository.php|log_config_append_device|backend_atomic_write_file' => 1,
    'repositories/log_config_repository.php|log_config_delete_devices|backend_atomic_write_file' => 1,
    'repositories/log_config_repository.php|log_config_set_can_role_body|backend_atomic_write_file' => 1,
    'services/can_role_service.php|can_role_restore_owned_xml|backend_atomic_write_file' => 1,
    'services/ftp_backup_service.php|ftp_backup_apply_ordered_replace|backend_atomic_write_file_synced' => 3,
];
ksort($expected);

$actual = atomic_writer_calls($backend);
check(
    $actual === $expected,
    'Every backend atomic-write call site is explicitly reviewed'
        . ($actual === $expected ? '' : "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true))
);

$opcUaWriter = (string)file_get_contents($backend . '/core/opcua_config.php');
$ftpWriter = (string)file_get_contents($backend . '/services/ftp_backup_service.php');
check(
    substr_count($opcUaWriter, 'backend_atomic_write_file($xmlPath, $xmlString, 0644);') === 1,
    'Normal OPC_UA_Config.xml writes have exactly one sink in iso_save_xml()'
);
check(
    substr_count($ftpWriter, 'backend_atomic_write_file_synced($xmlPath, $opcUaContent, 0644);') === 1,
    'FTP Restore has exactly one OPC_UA_Config.xml sink in its ordered replace transaction'
);
check(
    substr_count($opcUaWriter, 'iso_validate_final_xml_bytes($xmlString, $dtdDir, true);') === 1,
    'The normal OPC UA sink retains its exact-final-bytes validation call'
);
check(
    substr_count($ftpWriter, 'iso_validate_final_xml_bytes($opcUa, $dtdDir, true);') === 1,
    'The FTP candidate validator retains its exact-final-bytes validation call'
);
$ftpCommit = substr($ftpWriter, (int)strpos($ftpWriter, 'function ftp_backup_restore_commit('));
$ftpValidation = strpos($ftpCommit, '$report = ftp_backup_validate_bundle_candidates(');
$ftpReplace = strpos($ftpCommit, 'ftp_backup_apply_ordered_replace(');
check(
    $ftpValidation !== false && $ftpReplace !== false && $ftpValidation < $ftpReplace,
    'FTP commit revalidates the locked candidate before its ordered replacement'
);

echo "\n{$checks} checks, " . ($checks - $failures) . " passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
