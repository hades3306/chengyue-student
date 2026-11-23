<?php
require_once __DIR__ . '/../lib/bootstrap.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = (string) ($_POST['_token'] ?? '');

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        $error = '页面已过期，请刷新后重新登录。';
    } elseif ($username === '' || $password === '') {
        $error = '请输入账号和密码。';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND status = ?');
        $stmt->execute([$username, '启用']);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            login_user($user);
            db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), $user['id']]);
            redirect('/dashboard.php');
        }
        $error = '账号或密码不正确，请重试。';
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
<main class="login-shell">
    <section class="login-aside">
        <div class="login-aside-top">
            <div class="brand">
                <div class="brand-mark">澄</div>
                <div class="brand-text">
                    <strong>澄岳学务</strong>
                    <span>CHENGYUE CAMPUS</span>
                </div>
            </div>

            <div style="margin-top: 48px;">
                <span class="login-eyebrow">Campus Academic Platform</span>
                <h1>让每一次成长<br>都有迹可循</h1>
                <p class="login-slogan">面向校区教务与学生工作的综合管理平台，连接学籍、班级、课程、成绩、考勤与收费，让教学运行数据清晰、及时、可追溯。</p>
                <ul class="login-features">
                    <li>学籍档案与班级信息一体化维护</li>
                    <li>成绩、考勤与教学运行实时汇总</li>
                    <li>学费收缴进度与校区报表统一查看</li>
                </ul>
            </div>
        </div>

        <div class="login-aside-foot">
            <div class="login-stats">
                <strong>6</strong>
                <span>教学班级</span>
            </div>
            <div class="login-stats">
                <strong>30</strong>
                <span>在籍学生</span>
            </div>
            <div class="login-stats">
                <strong>10</strong>
                <span>在修课程</span>
            </div>
        </div>
    </section>

    <section class="login-main">
        <div class="login-main-head">
            <span class="login-eyebrow">澄岳学生管理系统</span>
            <h2>登录教务工作台</h2>
            <p>使用教务处分配的账号访问校区数据中心</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="login-form" autocomplete="off" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="username">账号</label>
                <input id="username" name="username" type="text" value="<?= e($username) ?>" placeholder="请输入账号" required autofocus>
            </div>
            <div class="field">
                <label for="password">密码</label>
                <input id="password" name="password" type="password" placeholder="请输入密码" required>
            </div>
            <div class="login-meta">
                <span>账号由教务处统一开通</span>
                <span>忘记密码请联系信息中心</span>
            </div>
            <button class="btn btn-primary btn-block" type="submit">登录系统</button>
        </form>

        <div class="login-tip">本系统为校区内部教务工作台，登录与业务操作均记录在系统日志中。请妥善保管账号凭证，离开公共设备前及时退出。</div>
        <p class="login-copy">© 2026 澄岳教育科技有限公司 · v<?= APP_VERSION ?></p>
    </section>
</main>
</body>
</html>
