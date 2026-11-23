<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$term = CURRENT_TERM;

$studentTotal = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$studentActive = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = '在读'")->fetchColumn();
$classTotal = (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$courseTotal = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$teacherTotal = (int) $pdo->query("SELECT COUNT(*) FROM teachers WHERE status = '在职'")->fetchColumn();

$tuitionStmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(paid_amount), 0) AS paid FROM tuition WHERE semester = ?');
$tuitionStmt->execute([$term]);
$tuition = $tuitionStmt->fetch();
$tuitionRate = (float) $tuition['amount'] > 0 ? (float) $tuition['paid'] / (float) $tuition['amount'] * 100 : 0.0;

$gradeStmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(AVG(score), 0) AS avg_score, COALESCE(SUM(CASE WHEN score >= 60 THEN 1 ELSE 0 END), 0) AS passed FROM grades WHERE semester = ?');
$gradeStmt->execute([$term]);
$gradeStats = $gradeStmt->fetch();
$gradeAvg = (float) $gradeStats['avg_score'];

$today = date('Y-m-d');
$attStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN status = '出勤' THEN 1 ELSE 0 END), 0) AS present FROM attendance WHERE class_date = ?");
$attStmt->execute([$today]);
$todayAtt = $attStmt->fetch();
$attendanceDate = $today;
if ((int) $todayAtt['cnt'] === 0) {
    $attendanceDate = (string) $pdo->query('SELECT MAX(class_date) FROM attendance')->fetchColumn();
    $attStmt->execute([$attendanceDate]);
    $todayAtt = $attStmt->fetch();
}
$todayAttRate = (int) $todayAtt['cnt'] > 0 ? (int) $todayAtt['present'] / (int) $todayAtt['cnt'] * 100 : 0.0;

$classRows = $pdo->prepare("
    SELECT c.id, c.name, c.class_code, c.grade_year, c.classroom, t.name AS counselor,
           COUNT(DISTINCT s.id) AS student_count,
           COALESCE(ROUND(AVG(g.score), 1), 0) AS avg_score
    FROM classes c
    LEFT JOIN teachers t ON t.id = c.counselor_id
    LEFT JOIN students s ON s.class_id = c.id AND s.status = '在读'
    LEFT JOIN grades g ON g.student_id = s.id AND g.semester = ?
    GROUP BY c.id
    ORDER BY c.grade_year DESC, c.class_code
");
$classRows->execute([$term]);
$classRows = $classRows->fetchAll();

$classAttStmt = $pdo->query("
    SELECT s.class_id, COUNT(*) AS total, SUM(CASE WHEN a.status = '出勤' THEN 1 ELSE 0 END) AS present
    FROM attendance a
    JOIN students s ON s.id = a.student_id
    GROUP BY s.class_id
");
$classAtt = [];
foreach ($classAttStmt->fetchAll() as $row) {
    $classAtt[(int) $row['class_id']] = $row;
}

$tuitionStatusRows = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM tuition WHERE semester = ? GROUP BY status');
$tuitionStatusRows->execute([$term]);
$tuitionStatus = [];
foreach ($tuitionStatusRows->fetchAll() as $row) {
    $tuitionStatus[$row['status']] = (int) $row['cnt'];
}
$tuitionTotal = array_sum($tuitionStatus);

$statusOrder = [
    ['已缴清', '#169e8d'],
    ['部分缴纳', '#c9932f'],
    ['待缴费', '#c3525a'],
    ['缓缴', '#8b95ad'],
];
$gradientParts = [];
$degreeCursor = 0.0;
foreach ($statusOrder as [$statusName, $color]) {
    $count = $tuitionStatus[$statusName] ?? 0;
    $span = $tuitionTotal > 0 ? $count / $tuitionTotal * 100 : 0;
    $next = $degreeCursor + $span;
    if ($next > $degreeCursor) {
        $gradientParts[] = $color . ' ' . round($degreeCursor, 2) . 'deg ' . round($next, 2) . 'deg';
    }
    $degreeCursor = $next;
}
$donutStyle = $gradientParts
    ? 'background: conic-gradient(' . implode(', ', $gradientParts) . ');'
    : 'background: conic-gradient(#edf0f7 0deg 360deg);';

$distStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN score >= 90 THEN 1 ELSE 0 END) AS excellent,
        SUM(CASE WHEN score >= 80 AND score < 90 THEN 1 ELSE 0 END) AS good,
        SUM(CASE WHEN score >= 70 AND score < 80 THEN 1 ELSE 0 END) AS medium,
        SUM(CASE WHEN score >= 60 AND score < 70 THEN 1 ELSE 0 END) AS pass,
        SUM(CASE WHEN score < 60 THEN 1 ELSE 0 END) AS fail,
        COUNT(*) AS total
    FROM grades WHERE semester = ?
");
$distStmt->execute([$term]);
$dist = $distStmt->fetch();
$distMax = max(1, (int) $dist['excellent'], (int) $dist['good'], (int) $dist['medium'], (int) $dist['pass'], (int) $dist['fail']);

$trendRows = $pdo->query("
    SELECT class_date, COUNT(*) AS total, SUM(CASE WHEN status = '出勤' THEN 1 ELSE 0 END) AS present
    FROM attendance
    GROUP BY class_date
    ORDER BY class_date
")->fetchAll();

$topStmt = $pdo->prepare("
    SELECT s.name, s.student_no, c.name AS class_name, ROUND(AVG(g.score), 1) AS avg_score, COUNT(g.id) AS course_count
    FROM students s
    JOIN grades g ON g.student_id = s.id AND g.semester = ?
    JOIN classes c ON c.id = s.class_id
    WHERE s.status = '在读'
    GROUP BY s.id
    ORDER BY avg_score DESC, s.student_no ASC
    LIMIT 6
");
$topStmt->execute([$term]);
$topStudents = $topStmt->fetchAll();

$notices = $pdo->query('SELECT * FROM announcements ORDER BY pinned DESC, published_at DESC LIMIT 5')->fetchAll();

$hour = (int) date('G');
$greeting = $hour < 11 ? '上午好' : ($hour < 14 ? '中午好' : ($hour < 18 ? '下午好' : '晚上好'));
$weekNumber = max(1, (int) floor((strtotime('2026-09-01') > strtotime($today) ? 0 : strtotime($today) - strtotime('2026-09-01')) / 604800) + 1);

$pageTitle = '工作台';
$active = 'dashboard';
require __DIR__ . '/partials/header.php';
?>
<div class="welcome">
    <div class="welcome-main">
        <span class="welcome-eyebrow">2026-2027 学年第一学期 · 第 <?= $weekNumber ?> 教学周</span>
        <h2><?= e($greeting) ?>，<?= e($user['real_name']) ?></h2>
        <p>今天是 <?= e(date('Y年n月j日')) ?>，校区教学运行平稳。这里汇总了学籍、成绩、考勤与缴费的关键数据。</p>
    </div>
    <div class="welcome-side">
        <a class="btn btn-ghost" href="/reports.php">查看运行报表</a>
        <a class="btn btn-lake" href="/students.php?action=new">新增学生档案</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top">
            <span class="stat-label">在籍学生</span>
            <span class="stat-icon"><?= icon_svg('students') ?></span>
        </div>
        <div class="stat-value"><?= $studentTotal ?><small>人</small></div>
        <div class="stat-foot">在读 <?= $studentActive ?> 人 · 共 <?= $classTotal ?> 个教学班</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top">
            <span class="stat-label">今日出勤率</span>
            <span class="stat-icon"><?= icon_svg('attendance') ?></span>
        </div>
        <div class="stat-value"><?= number_format($todayAttRate, 1) ?><small>%</small></div>
        <div class="stat-foot"><?= e($attendanceDate) ?> · 已记录 <?= (int) $todayAtt['cnt'] ?> 人次</div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top">
            <span class="stat-label">本学期课程</span>
            <span class="stat-icon"><?= icon_svg('courses') ?></span>
        </div>
        <div class="stat-value"><?= $courseTotal ?><small>门</small></div>
        <div class="stat-foot"><?= $teacherTotal ?> 位在职教师承担教学任务</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top">
            <span class="stat-label">学费收缴率</span>
            <span class="stat-icon"><?= icon_svg('tuition') ?></span>
        </div>
        <div class="stat-value"><?= number_format($tuitionRate, 1) ?><small>%</small></div>
        <div class="stat-foot">已收 ¥ <?= money($tuition['paid']) ?> / 应收 ¥ <?= money($tuition['amount']) ?></div>
    </div>
</div>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>班级运行概览</div>
                <div class="panel-sub">各班级学生规模、期中平均分与考勤情况</div>
            </div>
            <a class="panel-link" href="/classes.php">班级管理 →</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>班级</th>
                    <th>辅导员</th>
                    <th class="t-center">在读人数</th>
                    <th class="t-center">期中均分</th>
                    <th class="t-center">考勤率</th>
                    <th>教室</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($classRows as $row): ?>
                    <?php
                    $att = $classAtt[(int) $row['id']] ?? null;
                    $rate = $att && (int) $att['total'] > 0 ? (int) $att['present'] / (int) $att['total'] * 100 : 0;
                    ?>
                    <tr>
                        <td>
                            <div class="person-name"><?= e($row['name']) ?></div>
                            <span class="table-sub"><?= e($row['class_code']) ?> · <?= (int) $row['grade_year'] ?>级</span>
                        </td>
                        <td><?= e($row['counselor'] ?: '未设置') ?></td>
                        <td class="t-center"><?= (int) $row['student_count'] ?></td>
                        <td class="t-center"><span class="score <?= grade_tone((float) $row['avg_score']) ?>"><?= number_format((float) $row['avg_score'], 1) ?></span></td>
                        <td class="t-center"><?= number_format($rate, 1) ?>%</td>
                        <td><?= e($row['classroom']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>数据范围：<?= e(semester_label($term)) ?></span>
            <span>期中成绩已录入 <?= (int) $gradeStats['cnt'] ?> 条 · 平均分 <?= number_format($gradeAvg, 1) ?></span>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>学费收缴进度</div>
                <div class="panel-sub"><?= e(semester_label($term)) ?></div>
            </div>
            <a class="panel-link" href="/tuition.php">收费记录 →</a>
        </div>
        <div class="panel-body">
            <div class="donut-wrap">
                <div class="donut" style="<?= e($donutStyle) ?>">
                    <div class="donut-center">
                        <strong><?= number_format($tuitionRate, 0) ?>%</strong>
                        <span>收缴率</span>
                    </div>
                </div>
                <div class="donut-legend">
                    <?php foreach ($statusOrder as [$statusName, $color]): ?>
                        <div class="donut-legend-row">
                            <span><i class="legend-dot" style="background: <?= e($color) ?>"></i><?= e($statusName) ?></span>
                            <strong><?= (int) ($tuitionStatus[$statusName] ?? 0) ?> 人</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="divider"></div>
            <div class="progress-row">
                <div class="progress-meta">
                    <span>已收金额</span>
                    <strong>¥ <?= money($tuition['paid']) ?></strong>
                </div>
                <div class="progress"><div class="progress-fill" style="width: <?= min(100, $tuitionRate) ?>%"></div></div>
            </div>
            <div class="hint">本学期共 <?= (int) $tuition['cnt'] ?> 笔收费记录，待缴与部分缴纳学生已由辅导员跟进。</div>
        </div>
    </section>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>期中成绩分布</div>
                <div class="panel-sub">按成绩区间统计的本学期成绩记录</div>
            </div>
            <a class="panel-link" href="/grades.php">成绩管理 →</a>
        </div>
        <div class="panel-body">
            <div class="hbar-list">
                <?php
                $distRows = [
                    ['优秀（90-100）', (int) $dist['excellent'], 'lake'],
                    ['良好（80-89）', (int) $dist['good'], 'indigo'],
                    ['中等（70-79）', (int) $dist['medium'], 'gold'],
                    ['及格（60-69）', (int) $dist['pass'], 'rose'],
                    ['不及格（<60）', (int) $dist['fail'], 'rose'],
                ];
                foreach ($distRows as [$label, $count, $tone]):
                    $width = $distMax > 0 ? $count / $distMax * 100 : 0;
                ?>
                    <div class="hbar">
                        <span class="hbar-label"><?= e($label) ?></span>
                        <div class="hbar-track"><div class="hbar-fill <?= e($tone) ?>" style="width: <?= round($width, 1) ?>%"></div></div>
                        <span class="hbar-value"><?= $count ?> 条</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="divider"></div>
            <div class="legend">
                <span class="legend-item">平均分 <strong style="color: var(--ink); margin-left: 4px;"><?= number_format($gradeAvg, 1) ?></strong></span>
                <span class="legend-item">及格率 <strong style="color: var(--ink); margin-left: 4px;"><?= percent((int) $gradeStats['passed'], (int) $gradeStats['cnt']) ?></strong></span>
                <span class="legend-item">成绩记录 <strong style="color: var(--ink); margin-left: 4px;"><?= (int) $gradeStats['cnt'] ?> 条</strong></span>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>近期考勤走势</div>
                <div class="panel-sub">按上课日期统计的全校出勤率</div>
            </div>
            <a class="panel-link" href="/attendance.php">考勤管理 →</a>
        </div>
        <div class="panel-body">
            <div class="chart-bars">
                <?php foreach ($trendRows as $row): ?>
                    <?php
                    $rate = (int) $row['total'] > 0 ? (int) $row['present'] / (int) $row['total'] * 100 : 0;
                    $height = max(8, $rate);
                    ?>
                    <div class="chart-bar">
                        <span class="chart-bar-value"><?= number_format($rate, 0) ?>%</span>
                        <div class="chart-bar-track"><div class="chart-bar-fill" style="height: <?= $height ?>%"></div></div>
                        <span class="chart-bar-label"><?= e(date('m-d', strtotime($row['class_date']))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="divider"></div>
            <div class="att-legend">
                <span class="att-dot"><i class="ok"></i>出勤 <?= number_format($todayAttRate, 1) ?>%</span>
                <span>统计截至 <?= e($attendanceDate) ?></span>
            </div>
        </div>
    </section>
</div>

<div class="grid-1-2">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>期中表现前列</div>
                <div class="panel-sub">按已录入课程平均分排序</div>
            </div>
        </div>
        <div class="panel-body">
            <div class="rank-list">
                <?php foreach ($topStudents as $index => $student): ?>
                    <div class="rank-item">
                        <span class="rank-no<?= $index < 3 ? ' top-' . ($index + 1) : '' ?>"><?= $index + 1 ?></span>
                        <div class="rank-name">
                            <?= e($student['name']) ?>
                            <span><?= e($student['class_name']) ?> · <?= (int) $student['course_count'] ?> 门课程</span>
                        </div>
                        <span class="rank-score"><?= number_format((float) $student['avg_score'], 1) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel" id="notices">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>校园通知</div>
                <div class="panel-sub">教务处与学生工作处最新发布</div>
            </div>
            <span class="chip">共 <?= count($notices) ?> 条</span>
        </div>
        <div class="notice-list">
            <?php foreach ($notices as $notice): ?>
                <div class="notice-item">
                    <div class="notice-date">
                        <strong><?= e(date('d', strtotime($notice['published_at']))) ?></strong>
                        <span><?= e(date('m月', strtotime($notice['published_at']))) ?></span>
                    </div>
                    <div class="notice-body">
                        <div class="notice-title">
                            <?= e($notice['title']) ?>
                            <?php if ((int) $notice['pinned'] === 1): ?><span class="pin">置顶</span><?php endif; ?>
                            <span class="chip" style="padding: 1px 7px; font-size: 10.5px;"><?= e($notice['category']) ?></span>
                        </div>
                        <div class="notice-desc"><?= e($notice['content']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
