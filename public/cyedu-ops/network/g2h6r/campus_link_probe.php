<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$host = trim((string) ($_REQUEST['host'] ?? ''));

if ($host === '') {
    json_out(['ok' => false, 'bridge' => 'campus-link-probe', 'error' => 'parameter host is required'], 400);
}

$command = PHP_OS_FAMILY === 'Windows'
    ? 'ping -n 1 -w 800 ' . $host
    : 'ping -c 1 -W 1 ' . $host;

$output = [];
$exitCode = 1;
exec($command . ' 2>&1', $output, $exitCode);

json_out([
    'ok' => true,
    'bridge' => 'campus-link-probe',
    'host' => $host,
    'command' => $command,
    'exit_code' => $exitCode,
    'output' => mb_substr(implode("\n", $output), 0, 6000),
]);
