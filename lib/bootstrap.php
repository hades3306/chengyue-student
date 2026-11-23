<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('APP_NAME', '澄岳学生管理系统');
define('APP_SHORT', '澄岳学务');
define('APP_VERSION', '3.2.0');
define('APP_CAMPUS', '滨江校区');
define('APP_ACADEMIC_YEAR', '2026-2027');
define('CURRENT_TERM', '2026-2027-1');
define('DB_PATH', ROOT_PATH . '/database/chengyue-campus.sqlite');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('UPLOAD_BASE', ROOT_PATH . '/public/cyedu-uploads');

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(STORAGE_PATH)) {
    ini_set('error_log', STORAGE_PATH . '/php-error.log');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CYDUS_SESSION');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
