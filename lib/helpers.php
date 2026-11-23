<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function money($amount): string
{
    return number_format((float) $amount, 2);
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : '—';
}

function fmt_month_day(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('m月d日', $ts) : '—';
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('页面已过期，请返回后重新提交。');
    }
}

function input(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function q(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

function semester_label(?string $code): string
{
    $map = [
        '2026-2027-1' => '2026-2027学年第一学期',
        '2026-2027-2' => '2026-2027学年第二学期',
        '2025-2026-1' => '2025-2026学年第一学期',
        '2025-2026-2' => '2025-2026学年第二学期',
    ];
    return $map[(string) $code] ?? (string) $code;
}

function grade_level(float $score): string
{
    if ($score >= 90) {
        return '优秀';
    }
    if ($score >= 80) {
        return '良好';
    }
    if ($score >= 70) {
        return '中等';
    }
    if ($score >= 60) {
        return '及格';
    }
    return '不及格';
}

function grade_tone(float $score): string
{
    if ($score >= 90) {
        return 'excellent';
    }
    if ($score >= 80) {
        return 'good';
    }
    if ($score >= 60) {
        return 'pass';
    }
    return 'fail';
}

function percent($part, $total, int $decimals = 1): string
{
    $total = (float) $total;
    if ($total <= 0) {
        return '0.0%';
    }
    return number_format((float) $part / $total * 100, $decimals) . '%';
}

function badge_class(string $status): string
{
    $map = [
        '在读' => 'badge-success',
        '开课中' => 'badge-success',
        '在职' => 'badge-success',
        '已缴清' => 'badge-success',
        '已归档' => 'badge-success',
        '启用' => 'badge-success',
        '出勤' => 'badge-success',
        '正常' => 'badge-success',
        '休学' => 'badge-warn',
        '请假' => 'badge-warn',
        '迟到' => 'badge-warn',
        '部分缴纳' => 'badge-warn',
        '待缴费' => 'badge-warn',
        '缓缴' => 'badge-warn',
        '待审核' => 'badge-warn',
        '已毕业' => 'badge-muted',
        '停开' => 'badge-muted',
        '离职' => 'badge-muted',
        '缺勤' => 'badge-danger',
        '退学' => 'badge-danger',
        '欠费' => 'badge-danger',
        '已停用' => 'badge-danger',
        '必修' => 'badge-primary',
        '选修' => 'badge-info',
        '实践' => 'badge-info',
        '期中' => 'badge-info',
        '期末' => 'badge-primary',
    ];
    return $map[$status] ?? 'badge-muted';
}

function current_operator(): string
{
    $user = current_user();
    return $user['real_name'] ?? '系统';
}

function initial_of(?string $name): string
{
    $name = (string) $name;
    return $name === '' ? '澄' : mb_substr($name, 0, 1);
}

function icon_svg(string $name, int $size = 18): string
{
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
        'students' => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20c.6-3.4 3.4-5.4 7-5.4s6.4 2 7 5.4"/>',
        'classes' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 19c.5-3 2.8-4.8 6-4.8s5.5 1.8 6 4.8"/><path d="M16 8.4a3 3 0 0 1 0 5.8"/><path d="M17.6 14.9c2 .6 3.3 2.1 3.7 4.1"/>',
        'courses' => '<path d="M4 5.5c2.8-.9 5.5-.9 8 0v14c-2.5-.9-5.2-.9-8 0z"/><path d="M20 5.5c-2.8-.9-5.5-.9-8 0v14c2.5-.9 5.2-.9 8 0z"/>',
        'grades' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
        'attendance' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="m8 12 3 3 5-6"/>',
        'tuition' => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        'teachers' => '<circle cx="12" cy="7.5" r="3.3"/><path d="M5 20c.6-3.3 3.3-5.2 7-5.2s6.4 1.9 7 5.2"/><path d="m9 14.5 3 2.5 3-2.5"/>',
        'reports' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
    ];
    $body = $icons[$name] ?? $icons['dashboard'];
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return $bytes . ' B';
}
