<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();
$term = q('semester', CURRENT_TERM);
if (!in_array($term, ['2026-2027-1', '2026-2027-2', '2025-2026-2'], true)) {
    $term = CURRENT_TERM;
}

$classStmt = $pdo->prepare("
    SELECT c.id, c.name, c.class_code, c.grade_year, m.name AS major_name,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = '在读') AS student_count,
           (SELECT ROUND(AVG(g.score), 1) FROM grades g JOIN students s ON s.id = g.student_id WHERE s.class_id = c.id AND g.semester = ?) AS avg_score,
           (SELECT COUNT(*) FROM attendance a JOIN students s ON s.id = a.student_id WHERE s.class_id = c.id) AS att_total,
           (SELECT COALESCE(SUM(CASE WHEN a.status = '出勤' THEN 1 ELSE 0 END), 0) FROM attendance a JOIN students s ON s.id = a.student_id WHERE s.class_id = c.id) AS att_present,
           (SELECT COALESCE(SUM(t.amount), 0) FROM tuition t JOIN students s ON s.id = t.student_id WHERE s.class_id = c.id AND t.semester = ?) AS tuition_amount,
           (SELECT COALESCE(SUM(t.paid_amount), 0) FROM tuition t JOIN students s ON s.id = t.student_id WHERE s.class_id = c.id AND t.semester = ?) AS tuition_paid
    FROM classes c
    JOIN majors m ON m.id = c.major_id
    ORDER BY c.grade_year DESC, c.class_code
");
$classStmt->execute([$term, $term, $term]);
$classSummary = $classStmt->fetchAll();

if (q('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campus-report-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['班级', '班级编码', '专业', '年级', '在读学生', '期中均分', '考勤率', '应收学费', '实收学费', '收缴率']);
    foreach ($classSummary as $row) {
        $attRate = (int) $row['att_total'] > 0 ? (int) $row['att_present'] / (int) $row['att_total'] * 100 : 0;
        $tuitionRate = (float) $row['tuition_amount'] > 0 ? (float) $row['tuition_paid'] / (float) $row['tuition_amount'] * 100 : 0;
        fputcsv($out, [
            $row['name'], $row['class_code'], $row['major_name'], $row['grade_year'] . '级',
            (int) $row['student_count'], number_format((float) $row['avg_score'], 1),
            number_format($attRate, 1) . '%', money($row['tuition_amount']), money($row['tuition_paid']), number_format($tuitionRate, 1) . '%',
        ]);
    }
    fclose($out);
    exit;
}

$studentTotal = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$activeTotal = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = '在读'")->fetchColumn();
$gradeStmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(AVG(score), 0) AS avg_score, COALESCE(SUM(CASE WHEN score >= 60 THEN 1 ELSE 0 END), 0) AS passed FROM grades WHERE semester = ?');
$gradeStmt->execute([$term]);
$gradeStats = $gradeStmt->fetch();
$attStats = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = '出勤' THEN 1 ELSE 0 END), 0) AS present FROM attendance")->fetch();
$attRate = (int) $attStats['total'] > 0 ? (int) $attStats['present'] / (int) $attStats['total'] * 100 : 0;
$tuitionStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(paid_amount), 0) AS paid FROM tuition WHERE semester = ?');
$tuitionStmt->execute([$term]);
$tuitionStats = $tuitionStmt->fetch();
$tuitionRate = (float) $tuitionStats['amount'] > 0 ? (float) $tuitionStats['paid'] / (float) $tuitionStats['amount'] * 100 : 0;

$majorRows = $pdo->query("
    SELECT m.name, COUNT(s.id) AS cnt, SUM(CASE WHEN s.status = '在读' THEN 1 ELSE 0 END) AS active
    FROM majors m LEFT JOIN students s ON s.major_id = m.id
    GROUP BY m.id ORDER BY m.code
")->fetchAll();
$majorMax = max(1, ...array_map(static fn (array $row): int => (int) $row['cnt'], $majorRows));

$distStmt = $pdo->prepare("
    SELECT SUM(CASE WHEN score >= 90 THEN 1 ELSE 0 END) AS excellent,
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

$topStmt = $pdo->prepare("
    SELECT s.name, s.student_no, c.name AS class_name, ROUND(AVG(g.score), 1) AS avg_score, COUNT(g.id) AS course_count
    FROM students s
    JOIN grades g ON g.student_id = s.id AND g.semester = ?
    JOIN classes c ON c.id = s.class_id
    WHERE s.status = '在读'
    GROUP BY s.id ORDER BY avg_score DESC, s.student_no LIMIT 8
");
$topStmt->execute([$term]);
$topStudents = $topStmt->fetchAll();

$teacherStmt = $pdo->prepare("
    SELECT t.name, t.title, d.name AS department_name,
           COUNT(co.id) AS course_count, COALESCE(SUM(co.hours), 0) AS hours
    FROM teachers t
    LEFT JOIN departments d ON d.id = t.department_id
    LEFT JOIN courses co ON co.teacher_id = t.id AND co.semester = ?
    WHERE t.status = '在职'
    GROUP BY t.id ORDER BY hours DESC, t.teacher_no
");
$teacherStmt->execute([$term]);
$teacherWorkload = $teacherStmt->fetchAll();
$maxHours = max(1, ...array_map(static fn (array $row): int => (int) $row['hours'], $teacherWorkload));

$pageTitle = '报表中心';
$active = 'reports';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>报表中心</h1>
        <p>汇总学籍规模、成绩分布、考勤表现与学费收缴情况，为校区教学管理提供数据参考。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports.php?semester=<?= e($term) ?>&export=csv">导出汇总 CSV</a>
        <button class="btn btn-primary" type="button" onclick="window.print()">打印报表</button>
    </div>
</div>

<form class="filter-bar" method="get" action="/reports.php">
    <div class="filter-field">
        <label>统计学期</label>
        <select name="semester">
            <?php foreach (['2026-2027-1', '2026-2027-2', '2025-2026-2'] as $termOption): ?>
                <option value="<?= e($termOption) ?>"<?= $termOption === $term ? ' selected' : '' ?>><?= e(semester_label($termOption)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <span class="chip">制表日期 <?= e(date('Y-m-d')) ?></span>
        <button class="btn btn-primary" type="submit">生成报表</button>
    </div>
</form>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">在籍学生</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= $studentTotal ?><small>人</small></div>
        <div class="stat-foot">在读 <?= $activeTotal ?> 人 · 休学 <?= $studentTotal - $activeTotal ?> 人</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">期中平均分</span><span class="stat-icon"><?= icon_svg('grades') ?></span></div>
        <div class="stat-value"><?= number_format((float) $gradeStats['avg_score'], 1) ?></div>
        <div class="stat-foot">及格率 <?= percent((int) $gradeStats['passed'], (int) $gradeStats['cnt']) ?> · <?= (int) $gradeStats['cnt'] ?> 条成绩</div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top"><span class="stat-label">全校考勤率</span><span class="stat-icon"><?= icon_svg('attendance') ?></span></div>
        <div class="stat-value"><?= number_format($attRate, 1) ?><small>%</small></div>
        <div class="stat-foot"><?= (int) $attStats['total'] ?> 条考勤记录 · 出勤 <?= (int) $attStats['present'] ?> 人次</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">学费收缴率</span><span class="stat-icon"><?= icon_svg('tuition') ?></span></div>
        <div class="stat-value"><?= number_format($tuitionRate, 1) ?><small>%</small></div>
        <div class="stat-foot">应收 ¥ <?= money($tuitionStats['amount']) ?> · 已收 ¥ <?= money($tuitionStats['paid']) ?></div>
    </div>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>专业学生分布</div>
                <div class="panel-sub">各专业在籍学生规模</div>
            </div>
            <span class="chip">共 <?= count($majorRows) ?> 个专业</span>
        </div>
        <div class="panel-body">
            <div class="hbar-list">
                <?php foreach ($majorRows as $row): ?>
                    <div class="hbar">
                        <span class="hbar-label" title="<?= e($row['name']) ?>"><?= e($row['name']) ?></span>
                        <div class="hbar-track"><div class="hbar-fill indigo" style="width: <?= (int) $row['cnt'] / $majorMax * 100 ?>%"></div></div>
                        <span class="hbar-value"><?= (int) $row['cnt'] ?> 人</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>成绩区间分布</div>
                <div class="panel-sub"><?= e(semester_label($term)) ?></div>
            </div>
            <span class="chip">及格率 <?= percent((int) $gradeStats['passed'], (int) $gradeStats['cnt']) ?></span>
        </div>
        <div class="panel-body">
            <div class="hbar-list">
                <?php
                $distRows = [
                    ['优秀', (int) $dist['excellent'], ''],
                    ['良好', (int) $dist['good'], 'indigo'],
                    ['中等', (int) $dist['medium'], 'gold'],
                    ['及格', (int) $dist['pass'], 'rose'],
                    ['不及格', (int) $dist['fail'], 'rose'],
                ];
                foreach ($distRows as [$label, $count, $tone]):
                ?>
                    <div class="hbar">
                        <span class="hbar-label"><?= e($label) ?></span>
                        <div class="hbar-track"><div class="hbar-fill <?= $tone ?>" style="width: <?= $count / $distMax * 100 ?>%"></div></div>
                        <span class="hbar-value"><?= $count ?> 条</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>

<section class="panel" style="margin-bottom: 18px;">
    <div class="panel-head">
        <div>
            <div class="panel-title"><span class="dot"></span>班级运行汇总</div>
            <div class="panel-sub">学生规模、成绩、考勤与学费收缴对比</div>
        </div>
        <span class="chip"><?= e(semester_label($term)) ?></span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>班级</th>
                <th>专业</th>
                <th class="t-center">在读</th>
                <th class="t-center">期中均分</th>
                <th>考勤率</th>
                <th class="t-right">应收学费</th>
                <th class="t-right">实收学费</th>
                <th>收缴率</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($classSummary as $row): ?>
                <?php
                $cAttRate = (int) $row['att_total'] > 0 ? (int) $row['att_present'] / (int) $row['att_total'] * 100 : 0;
                $cTuitionRate = (float) $row['tuition_amount'] > 0 ? (float) $row['tuition_paid'] / (float) $row['tuition_amount'] * 100 : 0;
                ?>
                <tr>
                    <td>
                        <div class="person-name"><?= e($row['name']) ?></div>
                        <span class="table-sub"><?= e($row['class_code']) ?> · <?= (int) $row['grade_year'] ?>级</span>
                    </td>
                    <td><?= e($row['major_name']) ?></td>
                    <td class="t-center"><?= (int) $row['student_count'] ?></td>
                    <td class="t-center"><span class="score <?= grade_tone((float) $row['avg_score']) ?>"><?= number_format((float) $row['avg_score'], 1) ?></span></td>
                    <td>
                        <div class="score-bar"><div class="progress"><div class="progress-fill" style="width: <?= $cAttRate ?>%"></div></div><span class="small muted"><?= number_format($cAttRate, 1) ?>%</span></div>
                    </td>
                    <td class="t-right">¥ <?= money($row['tuition_amount']) ?></td>
                    <td class="t-right">¥ <?= money($row['tuition_paid']) ?></td>
                    <td>
                        <div class="score-bar"><div class="progress"><div class="progress-fill indigo" style="width: <?= min(100, $cTuitionRate) ?>%"></div></div><span class="small muted"><?= number_format($cTuitionRate, 1) ?>%</span></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-foot">
        <span>统计口径：成绩取本学期已录入记录，考勤取全部已登记记录</span>
        <span>报表生成时间 <?= e(date('Y-m-d H:i')) ?></span>
    </div>
</section>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>学业表现前列</div>
                <div class="panel-sub">本学期平均分前 8 名</div>
            </div>
        </div>
        <div class="panel-body">
            <div class="rank-list">
                <?php foreach ($topStudents as $index => $student): ?>
                    <div class="rank-item">
                        <span class="rank-no<?= $index < 3 ? ' top-' . ($index + 1) : '' ?>"><?= $index + 1 ?></span>
                        <div class="rank-name">
                            <?= e($student['name']) ?> <span class="muted small"><?= e($student['student_no']) ?></span>
                            <span><?= e($student['class_name']) ?> · <?= (int) $student['course_count'] ?> 门课程</span>
                        </div>
                        <span class="rank-score"><?= number_format((float) $student['avg_score'], 1) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>教师课时汇总</div>
                <div class="panel-sub">本学期在职教师授课情况</div>
            </div>
            <a class="panel-link" href="/teachers.php">教师管理 →</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>教师</th>
                    <th>院系</th>
                    <th>职称</th>
                    <th class="t-center">课程</th>
                    <th class="t-center">学时</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($teacherWorkload, 0, 8) as $row): ?>
                    <tr>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['department_name']) ?></td>
                        <td><span class="badge badge-muted"><?= e($row['title']) ?></span></td>
                        <td class="t-center"><?= (int) $row['course_count'] ?></td>
                        <td class="t-center"><?= (int) $row['hours'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>课时按课程计划学时汇总</span>
            <span>最高 <?= $maxHours ?> 学时</span>
        </div>
    </section>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <div class="panel-title"><span class="dot"></span>报表说明</div>
        </div>
    </div>
    <div class="panel-body">
        <div class="grid-3" style="margin-bottom: 0;">
            <div>
                <div class="person-name">学籍口径</div>
                <div class="hint" style="margin-top: 6px;">在籍学生包含在读与休学状态，按教务系统学籍库实时汇总。</div>
            </div>
            <div>
                <div class="person-name">成绩口径</div>
                <div class="hint" style="margin-top: 6px;">期中成绩以教务处审核后的记录为准，补考成绩另行归档。</div>
            </div>
            <div>
                <div class="person-name">收费口径</div>
                <div class="hint" style="margin-top: 6px;">应收金额按学期收费标准计算，实收金额以财务处到账记录为准。</div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
