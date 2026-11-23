<?php
declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/student-status.txt') {
    $file = __DIR__ . '/student-status.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Gateway-Marker: STUDENT-STATUS-GATEWAY');
    header('X-Gateway-Service: edu-data-exchange');
    header('Cache-Control: no-store');
    readfile($file);
    return true;
}

if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Gateway-Marker: STUDENT-STATUS-GATEWAY');
    header('X-Gateway-Service: edu-data-exchange');
    echo "EDU-DATA-EXCHANGE\n";
    echo "合作方学籍数据交换服务\n";
    echo "学籍状态报文：/student-status.txt\n";
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found\n";
return true;
