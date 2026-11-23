<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$bizDate = trim((string) ($_REQUEST['biz_date'] ?? ''));
if ($bizDate === '') {
    $bizDate = date('Ymd');
}

$archiveDir = STORAGE_PATH . '/archives';
$sourceDir = STORAGE_PATH . '/reports';
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0777, true);
}
if (!is_dir($sourceDir)) {
    mkdir($sourceDir, 0777, true);
}
if (count(glob($sourceDir . '/*') ?: []) === 0) {
    file_put_contents($sourceDir . '/campus-summary.txt', "澄岳学生管理系统教务报表汇总\n生成日期：" . date('Y-m-d') . "\n");
}

$destination = $archiveDir . '/' . $bizDate . '.tar';
$tar = 'tar';
if (PHP_OS_FAMILY === 'Windows' && is_file('C:\\Windows\\System32\\tar.exe')) {
    $tar = 'C:\\Windows\\System32\\tar.exe';
}
$command = $tar . ' -cf '
    . str_replace('\\', '/', $destination)
    . ' -C '
    . str_replace('\\', '/', $sourceDir)
    . ' . 2>&1';

$output = [];
$exitCode = 1;
exec($command, $output, $exitCode);

json_out([
    'ok' => true,
    'bridge' => 'campus-archive',
    'biz_date' => $bizDate,
    'archive' => $destination,
    'archive_exists' => is_file($destination),
    'command' => $command,
    'exit_code' => $exitCode,
    'output' => mb_substr(implode("\n", $output), 0, 6000),
]);
