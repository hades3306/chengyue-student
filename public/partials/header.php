<?php
if (!defined('APP_NAME')) {
    http_response_code(404);
    exit;
}

$user = current_user();
$flashes = take_flashes();
$today = date('Y年n月j日');
$weekdayMap = ['日', '一', '二', '三', '四', '五', '六'];
$weekday = '星期' . $weekdayMap[(int) date('w')];
$active = $active ?? '';
$pageTitle = $pageTitle ?? '工作台';

$navGroups = [
    '概览' => [
        ['dashboard.php', '工作台', 'dashboard', 'dashboard'],
    ],
    '学籍管理' => [
        ['students.php', '学生档案', 'students', 'students'],
        ['classes.php', '班级管理', 'classes', 'classes'],
    ],
    '教学运行' => [
        ['courses.php', '课程管理', 'courses', 'courses'],
        ['grades.php', '成绩管理', 'grades', 'grades'],
        ['attendance.php', '考勤管理', 'attendance', 'attendance'],
    ],
    '财务与师资' => [
        ['tuition.php', '收费记录', 'tuition', 'tuition'],
        ['teachers.php', '教师管理', 'teachers', 'teachers'],
    ],
    '数据中心' => [
        ['reports.php', '报表中心', 'reports', 'reports'],
    ],
];
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-mark">澄</div>
            <div class="brand-text">
                <strong>澄岳学务</strong>
                <span>CHENGYUE CAMPUS</span>
            </div>
        </div>
        <nav class="side-nav">
            <?php foreach ($navGroups as $group => $items): ?>
                <div class="nav-group">
                    <div class="nav-group-label"><?= e($group) ?></div>
                    <?php foreach ($items as [$href, $label, $icon, $key]): ?>
                        <a class="nav-link<?= $active === $key ? ' is-active' : '' ?>" href="/<?= e($href) ?>">
                            <span class="nav-icon" aria-hidden="true"><?= icon_svg($icon) ?></span>
                            <span><?= e($label) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="side-badge">滨江校区 · 2026-2027学年</div>
            <span>教务处 · 内线 8602</span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="nav-toggle" id="navToggle" type="button" aria-label="打开导航">☰</button>
                <div class="crumb">教务中心 <span>/</span> <strong><?= e($pageTitle) ?></strong></div>
                <form class="topbar-search" method="get" action="/students.php">
                    <?= icon_svg('search', 16) ?>
                    <input type="search" name="keyword" placeholder="搜索学生姓名 / 学号" aria-label="搜索学生">
                </form>
            </div>
            <div class="topbar-right">
                <span class="topbar-date"><?= e($today) ?> <?= e($weekday) ?></span>
                <span class="topbar-sep"></span>
                <span class="topbar-user">
                    <span class="avatar"><?= e(initial_of($user['real_name'] ?? '澄')) ?></span>
                    <span class="user-meta">
                        <strong><?= e($user['real_name'] ?? '') ?></strong>
                        <small><?= e($user['role'] ?? '') ?></small>
                    </span>
                </span>
                <a class="logout-link" href="/logout.php">退出</a>
            </div>
        </header>

        <div class="content">
            <?php foreach ($flashes as $flash): ?>
                <div class="flash flash-<?= e($flash['type']) ?>">
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" class="flash-close" aria-label="关闭">×</button>
                </div>
            <?php endforeach; ?>
