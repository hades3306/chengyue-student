<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'create') {
        $courseCode = input('course_code');
        $name = input('name');
        $departmentId = (int) input('department_id');
        $teacherId = (int) input('teacher_id');
        $credit = (float) input('credit', '3');
        $hours = (int) input('hours', '48');
        $courseType = input('course_type', '必修');
        $semester = input('semester', CURRENT_TERM);
        $schedule = input('schedule');
        $classroom = input('classroom');
        $status = input('status', '开课中');

        $deptCheck = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE id = ?');
        $deptCheck->execute([$departmentId]);
        $codeCheck = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE course_code = ?');
        $codeCheck->execute([$courseCode]);

        if ($courseCode === '' || $name === '' || $departmentId <= 0 || (int) $deptCheck->fetchColumn() === 0) {
            flash('error', '请完整填写课程编码、名称与开课院系。');
            redirect('/courses.php?action=new');
        }
        if ((int) $codeCheck->fetchColumn() > 0) {
            flash('error', '课程编码已存在，请更换后重试。');
            redirect('/courses.php?action=new');
        }

        $stmt = $pdo->prepare('INSERT INTO courses (course_code, name, department_id, teacher_id, credit, hours, course_type, semester, schedule, classroom, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$courseCode, $name, $departmentId, $teacherId > 0 ? $teacherId : null, max(0.5, $credit), max(8, $hours), $courseType, $semester, $schedule, $classroom, $status]);
        flash('success', '课程已创建：' . $name . '（' . $courseCode . '）。');
        redirect('/courses.php');
    }
}

$showForm = q('action') === 'new';
$keyword = q('keyword');
$typeFilter = q('course_type');
$semesterFilter = q('semester', CURRENT_TERM);

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(co.name LIKE ? OR co.course_code LIKE ? OR t.name LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
if ($typeFilter !== '') {
    $where[] = 'co.course_type = ?';
    $params[] = $typeFilter;
}
if ($semesterFilter !== '') {
    $where[] = 'co.semester = ?';
    $params[] = $semesterFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT co.*, d.name AS department_name, t.name AS teacher_name, t.title AS teacher_title,
           (SELECT COUNT(DISTINCT g.student_id) FROM grades g WHERE g.course_id = co.id AND g.semester = co.semester) AS enrolled,
           (SELECT ROUND(AVG(g.score), 1) FROM grades g WHERE g.course_id = co.id AND g.semester = co.semester) AS avg_score
    FROM courses co
    LEFT JOIN departments d ON d.id = co.department_id
    LEFT JOIN teachers t ON t.id = co.teacher_id
    $whereSql
    ORDER BY co.course_code
");
$stmt->execute($params);
$courses = $stmt->fetchAll();

$departments = $pdo->query('SELECT id, name FROM departments ORDER BY code')->fetchAll();
$teachers = $pdo->query("SELECT id, name, title FROM teachers WHERE status = '在职' ORDER BY teacher_no")->fetchAll();

$courseTotal = count($courses);
$creditTotal = 0.0;
$hoursTotal = 0;
$enrolledTotal = 0;
foreach ($courses as $course) {
    $creditTotal += (float) $course['credit'];
    $hoursTotal += (int) $course['hours'];
    $enrolledTotal += (int) $course['enrolled'];
}
$typeRows = $pdo->prepare('SELECT course_type, COUNT(*) AS cnt FROM courses WHERE semester = ? GROUP BY course_type');
$typeRows->execute([$semesterFilter ?: CURRENT_TERM]);
$typeCounts = [];
foreach ($typeRows->fetchAll() as $row) {
    $typeCounts[$row['course_type']] = (int) $row['cnt'];
}
$typeTotal = max(1, array_sum($typeCounts));

$teacherHours = $pdo->prepare("
    SELECT t.name, t.title, COUNT(co.id) AS course_count, COALESCE(SUM(co.hours), 0) AS hours
    FROM teachers t
    LEFT JOIN courses co ON co.teacher_id = t.id AND co.semester = ?
    WHERE t.status = '在职'
    GROUP BY t.id
    ORDER BY hours DESC, t.teacher_no
    LIMIT 7
");
$teacherHours->execute([CURRENT_TERM]);
$teacherHours = $teacherHours->fetchAll();
$maxHours = max(1, ...array_map(static fn (array $row): int => (int) $row['hours'], $teacherHours));

$pageTitle = '课程管理';
$active = 'courses';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>课程管理</h1>
        <p>维护学期课程、授课教师、学分学时与上课安排，掌握各课程选课规模与期中成绩。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/grades.php">成绩录入</a>
        <a class="btn btn-primary" href="/courses.php?action=new">新增课程</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">课程总数</span><span class="stat-icon"><?= icon_svg('courses') ?></span></div>
        <div class="stat-value"><?= $courseTotal ?><small>门</small></div>
        <div class="stat-foot"><?= e(semester_label($semesterFilter)) ?></div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">总学分</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= number_format($creditTotal, 1) ?><small>学分</small></div>
        <div class="stat-foot">平均每门 <?= $courseTotal > 0 ? number_format($creditTotal / $courseTotal, 1) : '0.0' ?> 学分</div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top"><span class="stat-label">总学时</span><span class="stat-icon"><?= icon_svg('attendance') ?></span></div>
        <div class="stat-value"><?= $hoursTotal ?><small>学时</small></div>
        <div class="stat-foot">理论教学与实践教学合计</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">修读人次</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= $enrolledTotal ?><small>人次</small></div>
        <div class="stat-foot">平均每门 <?= $courseTotal > 0 ? number_format($enrolledTotal / $courseTotal, 1) : '0.0' ?> 人次</div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>新增课程</div>
                <div class="panel-sub">课程编码用于成绩与考勤归档，请按教学计划规范填写</div>
            </div>
            <a class="panel-link" href="/courses.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/courses.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="field">
                        <label>课程编码<span class="required">*</span></label>
                        <input type="text" name="course_code" placeholder="如 CS401" required>
                    </div>
                    <div class="field">
                        <label>课程名称<span class="required">*</span></label>
                        <input type="text" name="name" placeholder="请输入课程名称" required>
                    </div>
                    <div class="field">
                        <label>开课院系<span class="required">*</span></label>
                        <select name="department_id" required>
                            <option value="">请选择院系</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>授课教师</label>
                        <select name="teacher_id">
                            <option value="">暂不指定</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['name']) ?> · <?= e($teacher['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>学分</label>
                        <input type="number" name="credit" value="3" step="0.5" min="0.5" max="10">
                    </div>
                    <div class="field">
                        <label>学时</label>
                        <input type="number" name="hours" value="48" step="8" min="8" max="128">
                    </div>
                    <div class="field">
                        <label>课程性质</label>
                        <select name="course_type">
                            <option value="必修">必修</option>
                            <option value="选修">选修</option>
                            <option value="实践">实践</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>学期</label>
                        <select name="semester">
                            <option value="2026-2027-1">2026-2027学年第一学期</option>
                            <option value="2026-2027-2">2026-2027学年第二学期</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>上课时间</label>
                        <input type="text" name="schedule" placeholder="如 周一 1-2节 / 周三 3-4节">
                    </div>
                    <div class="field">
                        <label>上课教室</label>
                        <input type="text" name="classroom" placeholder="如 明德楼 A201">
                    </div>
                    <div class="field">
                        <label>课程状态</label>
                        <select name="status">
                            <option value="开课中">开课中</option>
                            <option value="停开">停开</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/courses.php">取消</a>
                    <button class="btn btn-primary" type="submit">保存课程</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<form class="filter-bar" method="get" action="/courses.php">
    <div class="filter-field grow">
        <label>关键字</label>
        <input type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="课程名称、课程编码或教师">
    </div>
    <div class="filter-field">
        <label>课程性质</label>
        <select name="course_type">
            <option value="">全部性质</option>
            <?php foreach (['必修', '选修', '实践'] as $type): ?>
                <option value="<?= e($type) ?>"<?= $type === $typeFilter ? ' selected' : '' ?>><?= e($type) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>学期</label>
        <select name="semester">
            <?php foreach (['2026-2027-1', '2026-2027-2', '2025-2026-2'] as $termOption): ?>
                <option value="<?= e($termOption) ?>"<?= $termOption === $semesterFilter ? ' selected' : '' ?>><?= e(semester_label($termOption)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/courses.php">重置</a>
        <button class="btn btn-primary" type="submit">查询</button>
    </div>
</form>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>课程列表</div>
                <div class="panel-sub">共 <?= count($courses) ?> 门课程 · <?= e(semester_label($semesterFilter)) ?></div>
            </div>
            <span class="chip">按课程编码排序</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>课程</th>
                    <th>授课教师</th>
                    <th>开课院系</th>
                    <th class="t-center">学分 / 学时</th>
                    <th>上课安排</th>
                    <th class="t-center">修读</th>
                    <th class="t-center">均分</th>
                    <th>状态</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($courses as $course): ?>
                    <tr>
                        <td>
                            <div class="person-name"><?= e($course['name']) ?></div>
                            <span class="table-sub"><?= e($course['course_code']) ?> · <span class="badge <?= e(badge_class($course['course_type'])) ?>"><?= e($course['course_type']) ?></span></span>
                        </td>
                        <td>
                            <?= e($course['teacher_name'] ?: '待安排') ?>
                            <span class="table-sub"><?= e($course['teacher_title'] ?: '') ?></span>
                        </td>
                        <td><?= e($course['department_name']) ?></td>
                        <td class="t-center"><?= number_format((float) $course['credit'], 1) ?> / <?= (int) $course['hours'] ?></td>
                        <td>
                            <?= e($course['schedule'] ?: '待排课') ?>
                            <span class="table-sub"><?= e($course['classroom'] ?: '教室待定') ?></span>
                        </td>
                        <td class="t-center"><?= (int) $course['enrolled'] ?> 人</td>
                        <td class="t-center">
                            <?php if ($course['avg_score'] !== null): ?>
                                <span class="score <?= grade_tone((float) $course['avg_score']) ?>"><?= number_format((float) $course['avg_score'], 1) ?></span>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= e(badge_class($course['status'])) ?>"><?= e($course['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$courses): ?>
                    <tr><td colspan="8" class="table-empty">没有符合条件的课程。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>课程与选课、成绩、考勤数据联动</span>
            <span>学期：<?= e(semester_label($semesterFilter)) ?></span>
        </div>
    </section>

    <div>
        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>课程性质构成</div>
                    <div class="panel-sub"><?= e(semester_label($semesterFilter)) ?></div>
                </div>
            </div>
            <div class="panel-body">
                <?php foreach (['必修' => 'lake', '选修' => 'indigo', '实践' => 'gold'] as $typeName => $tone): ?>
                    <?php $count = $typeCounts[$typeName] ?? 0; ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($typeName) ?>课程</span>
                            <strong><?= $count ?> 门</strong>
                        </div>
                        <div class="progress"><div class="progress-fill <?= $tone === 'indigo' ? 'indigo' : ($tone === 'gold' ? 'gold' : '') ?>" style="width: <?= $count / $typeTotal * 100 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <div class="divider"></div>
                <div class="hint">课程调整需经开课院系审核，停开课程将保留历史成绩与考勤记录。</div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>教师课时</div>
                    <div class="panel-sub">本学期授课学时排序</div>
                </div>
                <a class="panel-link" href="/teachers.php">教师管理 →</a>
            </div>
            <div class="panel-body">
                <?php foreach ($teacherHours as $teacher): ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($teacher['name']) ?> <span class="muted small">· <?= e($teacher['title']) ?></span></span>
                            <strong><?= (int) $teacher['hours'] ?> 学时</strong>
                        </div>
                        <div class="progress"><div class="progress-fill indigo" style="width: <?= (int) $teacher['hours'] / $maxHours * 100 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
