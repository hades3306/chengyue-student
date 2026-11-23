<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$majorCode = trim((string) ($_GET['major_code'] ?? ''));

if ($majorCode === '') {
    json_out(['ok' => false, 'bridge' => 'enrollment-summary', 'error' => 'parameter major_code is required'], 400);
}

try {
    $sql = "SELECT m.name AS major_name, c.grade_year, COUNT(s.id) AS student_count FROM majors m JOIN classes c ON c.major_id = m.id LEFT JOIN students s ON s.class_id = c.id WHERE m.code = '$majorCode' GROUP BY m.name, c.grade_year";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'enrollment-summary',
        'version' => 'stats-service-2020.2',
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'enrollment-summary',
        'error' => $e->getMessage(),
        'query' => $sql ?? '',
    ], 500);
}
