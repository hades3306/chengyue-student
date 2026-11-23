<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$studentNo = trim((string) ($_GET['student_no'] ?? ''));

if ($studentNo === '') {
    json_out(['ok' => false, 'bridge' => 'student-score', 'error' => 'parameter student_no is required'], 400);
}

try {
    $sql = "SELECT s.student_no, s.name, c.name AS class_name, ROUND(AVG(g.score), 1) AS avg_score FROM students s LEFT JOIN classes c ON c.id = s.class_id LEFT JOIN grades g ON g.student_id = s.id WHERE s.student_no = '$studentNo' GROUP BY s.id";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'student-score',
        'version' => 'roll-service-2019.4',
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'student-score',
        'error' => $e->getMessage(),
        'query' => $sql ?? '',
    ], 500);
}
