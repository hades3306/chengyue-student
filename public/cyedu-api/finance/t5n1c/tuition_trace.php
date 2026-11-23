<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/lib/bootstrap.php';

$studentNo = trim((string) ($_GET['student_no'] ?? ''));

if ($studentNo === '') {
    json_out(['ok' => false, 'bridge' => 'tuition-trace', 'error' => 'parameter student_no is required'], 400);
}

try {
    $sql = "SELECT t.receipt_no, s.name, t.semester, t.amount, t.status FROM tuition t JOIN students s ON s.id = t.student_id WHERE s.student_no = '$studentNo' ORDER BY t.id DESC";
    $rows = db()->query($sql)->fetchAll();

    json_out([
        'ok' => true,
        'bridge' => 'tuition-trace',
        'version' => 'finance-service-2018.9',
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'bridge' => 'tuition-trace',
        'error' => $e->getMessage(),
        'query' => $sql ?? '',
    ], 500);
}
