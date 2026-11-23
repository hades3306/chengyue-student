<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'create') {
        $studentId = (int) input('student_id');
        $courseId = (int) input('course_id');
        $score = (float) input('score', '-1');
        $examType = input('exam_type', '期中');
        $examDate = input('exam_date', date('Y-m-d'));

        $studentStmt = $pdo->prepare('SELECT name FROM students WHERE id = ?');
        $studentStmt->execute([$studentId]);
        $studentName = $studentStmt->fetchColumn();
        $courseStmt = $pdo->prepare('SELECT name, teacher_id FROM courses WHERE id = ?');
        $courseStmt->execute([$courseId]);
        $courseRow = $courseStmt->fetch();

        if (!$studentName || !$courseRow) {
            flash('error', '请选择学生与课程。');
            redirect('/grades.php?action=new');
        }
        if ($score < 0 || $score > 100) {
            flash('error', '成绩须在 0 至 100 之间。');
            redirect('/grades.php?action=new');
        }

        $dup = $pdo->prepare('SELECT COUNT(*) FROM grades WHERE student_id = ? AND course_id = ? AND semester = ? AND exam_type = ?');
        $dup->execute([$studentId, $courseId, CURRENT_TERM, $examType]);
        if ((int) $dup->fetchColumn() > 0) {
            flash('error', '该学生本课程已存在同类型成绩，请勿重复录入。');
            redirect('/grades.php?action=new');
        }

        $recorder = current_operator();
        $stmt = $pdo->prepare('INSERT INTO grades (student_id, course_id, semester, score, exam_type, exam_date, recorder, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $courseId, CURRENT_TERM, $score, $examType, $examDate, $recorder, date('Y-m-d H:i:s')]);
        flash('success', '成绩已录入：' . $studentName . ' · ' . $courseRow['name'] . ' · ' . number_format($score, 1) . ' 分。');
        redirect('/grades.php?course_id=' . $courseId);
    }
}

$showForm = q('action') === 'new';
$semesterFilter = q('semester', CURRENT_TERM);
$courseFilter = q('course_id');
$classFilter = q('class_id');
$studentFilter = q('student_id');
$keyword = q('keyword');
$examFilter = q('exam_type');

$where = ['g.semester = ?'];
$params = [$semesterFilter];
if ($courseFilter !== '' && ctype_digit($courseFilter)) {
    $where[] = 'g.course_id = ?';
    $params[] = (int) $courseFilter;
}
if ($classFilter !== '' && ctype_digit($classFilter)) {
    $where[] = 's.class_id = ?';
    $params[] = (int) $classFilter;
}
if ($studentFilter !== '' && ctype_digit($studentFilter)) {
    $where[] = 's.id = ?';
    $params[] = (int) $studentFilter;
}
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.student_no LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
if ($examFilter !== '') {
    $where[] = 'g.exam_type = ?';
    $params[] = $examFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT g.*, s.name AS student_name, s.student_no, c.name AS class_name, co.name AS course_name, co.course_code, co.credit
    FROM grades g
    JOIN students s ON s.id = g.student_id
    JOIN classes c ON c.id = s.class_id
    JOIN courses co ON co.id = g.course_id
    $whereSql
    ORDER BY g.exam_date DESC, s.student_no, co.course_code
");
$stmt->execute($params);
$grades = $stmt->fetchAll();

$count = count($grades);
$scores = array_map(static fn (array $row): float => (float) $row['score'], $grades);
$avgScore = $count > 0 ? array_sum($scores) / $count : 0.0;
$maxScore = $count > 0 ? max($scores) : 0.0;
$minScore = $count > 0 ? min($scores) : 0.0;
$passCount = count(array_filter($scores, static fn (float $score): bool => $score >= 60));

$bands = [
    ['优秀', 90, 100, 'lake'],
    ['良好', 80, 89.99, 'indigo'],
    ['中等', 70, 79.99, 'gold'],
    ['及格', 60, 69.99, 'rose'],
    ['不及格', 0, 59.99, 'rose'],
];
$bandCounts = [];
foreach ($bands as [$label, $low, $high, $tone]) {
    $bandCounts[$label] = count(array_filter($scores, static fn (float $score): bool => $score >= $low && $score <= $high));
}
$bandMax = max(1, ...array_values($bandCounts));

$courses = $pdo->query('SELECT id, course_code, name FROM courses ORDER BY course_code')->fetchAll();
$classes = $pdo->query('SELECT id, name FROM classes ORDER BY class_code')->fetchAll();
$studentOptions = $pdo->query("SELECT s.id, s.student_no, s.name, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.student_no")->fetchAll();
$selectedStudent = null;
if ($studentFilter !== '' && ctype_digit($studentFilter)) {
    foreach ($studentOptions as $option) {
        if ((int) $option['id'] === (int) $studentFilter) {
            $selectedStudent = $option;
        }
    }
}

$courseAvgStmt = $pdo->prepare("
    SELECT co.name, co.course_code, ROUND(AVG(g.score), 1) AS avg_score, COUNT(g.id) AS cnt
    FROM grades g JOIN courses co ON co.id = g.course_id
    WHERE g.semester = ?
    GROUP BY co.id
    ORDER BY avg_score DESC
");
$courseAvgStmt->execute([$semesterFilter]);
$courseAvg = $courseAvgStmt->fetchAll();

$classAvgStmt = $pdo->prepare("
    SELECT c.name, ROUND(AVG(g.score), 1) AS avg_score, COUNT(g.id) AS cnt
    FROM grades g JOIN students s ON s.id = g.student_id JOIN classes c ON c.id = s.class_id
    WHERE g.semester = ?
    GROUP BY c.id
    ORDER BY avg_score DESC
");
$classAvgStmt->execute([$semesterFilter]);
$classAvg = $classAvgStmt->fetchAll();

$pageTitle = '成绩管理';
$active = 'grades';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>成绩管理</h1>
        <p>按学期、课程与班级查看成绩记录，录入期中成绩并跟踪班级与课程成绩分布。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports.php">成绩分析</a>
        <a class="btn btn-primary" href="/grades.php?action=new">录入成绩</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">成绩记录</span><span class="stat-icon"><?= icon_svg('grades') ?></span></div>
        <div class="stat-value"><?= $count ?><small>条</small></div>
        <div class="stat-foot"><?= e(semester_label($semesterFilter)) ?></div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">平均分</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= number_format($avgScore, 1) ?></div>
        <div class="stat-foot">最高 <?= number_format($maxScore, 1) ?> · 最低 <?= number_format($minScore, 1) ?></div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top"><span class="stat-label">及格率</span><span class="stat-icon"><?= icon_svg('attendance') ?></span></div>
        <div class="stat-value"><?= $count > 0 ? number_format($passCount / $count * 100, 1) : '0.0' ?><small>%</small></div>
        <div class="stat-foot"><?= $passCount ?> 条及格 · <?= $count - $passCount ?> 条不及格</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">优秀记录</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= $bandCounts['优秀'] ?><small>条</small></div>
        <div class="stat-foot">90 分及以上 · 占比 <?= percent($bandCounts['优秀'], $count) ?></div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>录入成绩</div>
                <div class="panel-sub">同一学生、课程与考核类型仅可录入一次，如需更正请联系教务处</div>
            </div>
            <a class="panel-link" href="/grades.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/grades.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="field">
                        <label>学生<span class="required">*</span></label>
                        <select name="student_id" required>
                            <option value="">请选择学生</option>
                            <?php foreach ($studentOptions as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"><?= e($option['student_no']) ?> · <?= e($option['name']) ?> · <?= e($option['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>课程<span class="required">*</span></label>
                        <select name="course_id" required>
                            <option value="">请选择课程</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= (int) $course['id'] ?>"><?= e($course['course_code']) ?> · <?= e($course['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>考核类型</label>
                        <select name="exam_type">
                            <option value="期中">期中</option>
                            <option value="期末">期末</option>
                            <option value="补考">补考</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>成绩<span class="required">*</span></label>
                        <input type="number" name="score" min="0" max="100" step="0.5" placeholder="0 - 100" required>
                    </div>
                    <div class="field">
                        <label>考试日期</label>
                        <input type="date" name="exam_date" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/grades.php">取消</a>
                    <button class="btn btn-primary" type="submit">保存成绩</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($selectedStudent): ?>
    <div class="alert alert-info">当前仅显示 <strong><?= e($selectedStudent['name']) ?></strong>（<?= e($selectedStudent['student_no']) ?>）的成绩记录。<a href="/grades.php">查看全部记录</a></div>
<?php endif; ?>

<form class="filter-bar" method="get" action="/grades.php">
    <div class="filter-field">
        <label>学期</label>
        <select name="semester">
            <?php foreach (['2026-2027-1', '2026-2027-2', '2025-2026-2'] as $termOption): ?>
                <option value="<?= e($termOption) ?>"<?= $termOption === $semesterFilter ? ' selected' : '' ?>><?= e(semester_label($termOption)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>课程</label>
        <select name="course_id">
            <option value="">全部课程</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?= (int) $course['id'] ?>"<?= (string) $course['id'] === $courseFilter ? ' selected' : '' ?>><?= e($course['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>班级</label>
        <select name="class_id">
            <option value="">全部班级</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= (int) $class['id'] ?>"<?= (string) $class['id'] === $classFilter ? ' selected' : '' ?>><?= e($class['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>考核类型</label>
        <select name="exam_type">
            <option value="">全部类型</option>
            <?php foreach (['期中', '期末', '补考'] as $examType): ?>
                <option value="<?= e($examType) ?>"<?= $examType === $examFilter ? ' selected' : '' ?>><?= e($examType) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field grow">
        <label>学生</label>
        <input type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="姓名或学号">
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/grades.php">重置</a>
        <button class="btn btn-primary" type="submit">查询</button>
    </div>
</form>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>成绩记录</div>
                <div class="panel-sub">共 <?= $count ?> 条记录</div>
            </div>
            <span class="chip">平均分 <?= number_format($avgScore, 1) ?></span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>学生</th>
                    <th>班级</th>
                    <th>课程</th>
                    <th>考核</th>
                    <th class="t-center">成绩</th>
                    <th>等级</th>
                    <th>考试日期</th>
                    <th>录入人</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($grades as $grade): ?>
                    <tr>
                        <td>
                            <div class="person-cell">
                                <span class="person-avatar"><?= e(initial_of($grade['student_name'])) ?></span>
                                <div>
                                    <div class="person-name"><?= e($grade['student_name']) ?></div>
                                    <span class="table-sub"><?= e($grade['student_no']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($grade['class_name']) ?></td>
                        <td>
                            <?= e($grade['course_name']) ?>
                            <span class="table-sub"><?= e($grade['course_code']) ?> · <?= number_format((float) $grade['credit'], 1) ?> 学分</span>
                        </td>
                        <td><span class="badge <?= e(badge_class($grade['exam_type'])) ?>"><?= e($grade['exam_type']) ?></span></td>
                        <td class="t-center"><span class="score <?= grade_tone((float) $grade['score']) ?>"><?= number_format((float) $grade['score'], 1) ?></span></td>
                        <td><span class="badge badge-muted"><?= e(grade_level((float) $grade['score'])) ?></span></td>
                        <td><?= e(fmt_date($grade['exam_date'])) ?></td>
                        <td><?= e($grade['recorder']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$grades): ?>
                    <tr><td colspan="8" class="table-empty">没有符合条件的成绩记录。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>成绩数据仅用于教务统计，请按教学管理规定妥善使用</span>
            <span>及格率 <?= $count > 0 ? number_format($passCount / $count * 100, 1) : '0.0' ?>%</span>
        </div>
    </section>

    <div>
        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>成绩区间分布</div>
                    <div class="panel-sub">当前筛选结果</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="hbar-list">
                    <?php foreach ($bands as [$label, $low, $high, $tone]): ?>
                        <div class="hbar">
                            <span class="hbar-label"><?= e($label) ?></span>
                            <div class="hbar-track"><div class="hbar-fill <?= $tone === 'indigo' ? 'indigo' : ($tone === 'gold' ? 'gold' : ($tone === 'rose' ? 'rose' : '')) ?>" style="width: <?= $bandCounts[$label] / $bandMax * 100 ?>%"></div></div>
                            <span class="hbar-value"><?= $bandCounts[$label] ?> 条</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>课程平均分</div>
                    <div class="panel-sub"><?= e(semester_label($semesterFilter)) ?></div>
                </div>
            </div>
            <div class="panel-body">
                <div class="hbar-list">
                    <?php foreach ($courseAvg as $row): ?>
                        <div class="hbar">
                            <span class="hbar-label" title="<?= e($row['name']) ?>"><?= e($row['name']) ?></span>
                            <div class="hbar-track"><div class="hbar-fill" style="width: <?= (float) $row['avg_score'] ?>%"></div></div>
                            <span class="hbar-value"><?= number_format((float) $row['avg_score'], 1) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>班级平均分</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="hbar-list">
                    <?php foreach ($classAvg as $row): ?>
                        <div class="hbar">
                            <span class="hbar-label"><?= e(mb_substr($row['name'], 0, 7)) ?></span>
                            <div class="hbar-track"><div class="hbar-fill indigo" style="width: <?= (float) $row['avg_score'] ?>%"></div></div>
                            <span class="hbar-value"><?= number_format((float) $row['avg_score'], 1) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="divider"></div>
                <div class="hint">成绩录入后即时计入工作台与报表中心统计。</div>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
