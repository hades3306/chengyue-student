<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    if ($action === 'create') {
        $studentNo = input('student_no');
        $name = input('name');
        $gender = input('gender', '男');
        $classId = (int) input('class_id');
        $birthDate = input('birth_date');
        $idCard = input('id_card');
        $phone = input('phone');
        $email = input('email');
        $address = input('address');
        $guardianName = input('guardian_name');
        $guardianPhone = input('guardian_phone');
        $political = input('political_status', '共青团员');
        $status = input('status', '在读');

        $classStmt = $pdo->prepare('SELECT * FROM classes WHERE id = ?');
        $classStmt->execute([$classId]);
        $classRow = $classStmt->fetch();

        if ($studentNo === '' || $name === '' || !$classRow) {
            flash('error', '请填写学号、姓名并选择班级。');
            redirect('/students.php?action=new');
        }

        $exists = $pdo->prepare('SELECT COUNT(*) FROM students WHERE student_no = ?');
        $exists->execute([$studentNo]);
        if ((int) $exists->fetchColumn() > 0) {
            flash('error', '学号已存在，请核对后重新提交。');
            redirect('/students.php?action=new');
        }

        $stmt = $pdo->prepare('
            INSERT INTO students (student_no, name, gender, birth_date, id_card, class_id, major_id, enrollment_date,
                phone, email, address, guardian_name, guardian_phone, status, political_status, avatar, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
        ');
        $stmt->execute([
            $studentNo, $name, $gender, $birthDate ?: null, $idCard ?: null, $classId, (int) $classRow['major_id'],
            ((int) $classRow['grade_year']) . '-09-01', $phone ?: null, $email ?: null, $address ?: null,
            $guardianName ?: null, $guardianPhone ?: null, $status, $political, date('Y-m-d H:i:s'),
        ]);
        $newId = (int) $pdo->lastInsertId();
        flash('success', '学生档案已创建：' . $name . '（' . $studentNo . '）。');
        redirect('/students.php?id=' . $newId);
    }
}

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name AS class_name, c.class_code, c.grade_year, c.classroom,
               m.name AS major_name, m.degree, d.name AS department_name,
               t.name AS counselor
        FROM students s
        LEFT JOIN classes c ON c.id = s.class_id
        LEFT JOIN majors m ON m.id = s.major_id
        LEFT JOIN departments d ON d.id = m.department_id
        LEFT JOIN teachers t ON t.id = c.counselor_id
        WHERE s.id = ?
    ");
    $stmt->execute([(int) $_GET['id']]);
    $student = $stmt->fetch();

    if (!$student) {
        flash('error', '未找到该学生档案。');
        redirect('/students.php');
    }

    $gradeStmt = $pdo->prepare("
        SELECT g.*, co.name AS course_name, co.course_code, co.credit
        FROM grades g
        JOIN courses co ON co.id = g.course_id
        WHERE g.student_id = ? AND g.semester = ?
        ORDER BY g.score DESC
    ");
    $gradeStmt->execute([$student['id'], CURRENT_TERM]);
    $studentGrades = $gradeStmt->fetchAll();
    $avgScore = $studentGrades ? array_sum(array_column($studentGrades, 'score')) / count($studentGrades) : 0.0;
    $passedCourses = count(array_filter($studentGrades, static fn (array $row): bool => (float) $row['score'] >= 60));

    $tuitionStmt = $pdo->prepare('SELECT * FROM tuition WHERE student_id = ? ORDER BY semester DESC, id DESC');
    $tuitionStmt->execute([$student['id']]);
    $studentTuition = $tuitionStmt->fetchAll();

    $attStmt = $pdo->prepare("
        SELECT a.*, co.name AS course_name
        FROM attendance a
        JOIN courses co ON co.id = a.course_id
        WHERE a.student_id = ?
        ORDER BY a.class_date DESC, a.id DESC
        LIMIT 8
    ");
    $attStmt->execute([$student['id']]);
    $recentAttendance = $attStmt->fetchAll();

    $attStatsStmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = '出勤' THEN 1 ELSE 0 END) AS present,
               SUM(CASE WHEN status = '请假' THEN 1 ELSE 0 END) AS leave_count,
               SUM(CASE WHEN status = '缺勤' THEN 1 ELSE 0 END) AS absent_count
        FROM attendance WHERE student_id = ?
    ");
    $attStatsStmt->execute([$student['id']]);
    $attStats = $attStatsStmt->fetch();
    $attRate = (int) $attStats['total'] > 0 ? (int) $attStats['present'] / (int) $attStats['total'] * 100 : 0;
    $currentTuition = null;
    foreach ($studentTuition as $row) {
        if ($row['semester'] === CURRENT_TERM) {
            $currentTuition = $row;
            break;
        }
    }

    $pageTitle = '学生档案';
    $active = 'students';
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-head">
        <div>
            <h1>学生档案</h1>
            <p>查看学生基本信息、学籍状态、本学期成绩、考勤与缴费记录。</p>
        </div>
        <div class="page-actions">
            <a class="btn btn-ghost" href="/students.php">返回学生列表</a>
            <a class="btn btn-primary" href="/grades.php?student_id=<?= (int) $student['id'] ?>">查看成绩记录</a>
        </div>
    </div>

    <div class="detail-layout">
        <div>
            <section class="profile-card">
                <div class="profile-head">
                    <div class="profile-avatar"><?= e(initial_of($student['name'])) ?></div>
                    <div>
                        <div class="profile-name">
                            <h2><?= e($student['name']) ?></h2>
                            <span class="badge <?= e(badge_class($student['status'])) ?>"><?= e($student['status']) ?></span>
                            <span class="chip"><?= e($student['student_no']) ?></span>
                        </div>
                        <div class="profile-meta">
                            <span><?= e($student['class_name']) ?></span>
                            <span class="sep">·</span>
                            <span><?= e($student['major_name']) ?></span>
                            <span class="sep">·</span>
                            <span><?= e($student['department_name']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="info-grid">
                    <div class="info-item"><label>性别</label><div><?= e($student['gender']) ?></div></div>
                    <div class="info-item"><label>出生日期</label><div><?= e(fmt_date($student['birth_date'])) ?></div></div>
                    <div class="info-item"><label>政治面貌</label><div><?= e($student['political_status']) ?></div></div>
                    <div class="info-item"><label>入学日期</label><div><?= e(fmt_date($student['enrollment_date'])) ?></div></div>
                    <div class="info-item"><label>身份证号</label><div><?= e($student['id_card'] ?: '未登记') ?></div></div>
                    <div class="info-item"><label>辅导员</label><div><?= e($student['counselor'] ?: '未设置') ?></div></div>
                    <div class="info-item"><label>行政班级</label><div><?= e($student['class_name']) ?></div></div>
                    <div class="info-item"><label>固定教室</label><div><?= e($student['classroom']) ?></div></div>
                    <div class="info-item"><label>联系电话</label><div><?= e($student['phone'] ?: '未登记') ?></div></div>
                    <div class="info-item"><label>电子邮箱</label><div><?= e($student['email'] ?: '未登记') ?></div></div>
                    <div class="info-item"><label>监护人</label><div><?= e($student['guardian_name'] ?: '未登记') ?></div></div>
                    <div class="info-item"><label>监护人电话</label><div><?= e($student['guardian_phone'] ?: '未登记') ?></div></div>
                    <div class="info-item" style="grid-column: span 2;"><label>家庭住址</label><div><?= e($student['address'] ?: '未登记') ?></div></div>
                    <div class="info-item"><label>学历层次</label><div>本科 · <?= e($student['degree']) ?></div></div>
                    <div class="info-item"><label>建档时间</label><div><?= e(fmt_date($student['created_at'])) ?></div></div>
                </div>

                <div class="section-title">
                    <h3>本学期成绩</h3>
                    <span class="chip">共 <?= count($studentGrades) ?> 门课程</span>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>课程</th>
                            <th>学分</th>
                            <th>考核类型</th>
                            <th>考试日期</th>
                            <th class="t-right">成绩</th>
                            <th>等级</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$studentGrades): ?>
                            <tr><td colspan="6" class="table-empty">本学期暂无成绩记录。</td></tr>
                        <?php endif; ?>
                        <?php foreach ($studentGrades as $grade): ?>
                            <tr>
                                <td>
                                    <div class="person-name"><?= e($grade['course_name']) ?></div>
                                    <span class="table-sub"><?= e($grade['course_code']) ?></span>
                                </td>
                                <td><?= number_format((float) $grade['credit'], 1) ?></td>
                                <td><span class="badge <?= e(badge_class($grade['exam_type'])) ?>"><?= e($grade['exam_type']) ?></span></td>
                                <td><?= e(fmt_date($grade['exam_date'])) ?></td>
                                <td class="t-right"><span class="score <?= grade_tone((float) $grade['score']) ?>"><?= number_format((float) $grade['score'], 1) ?></span></td>
                                <td><span class="badge badge-muted"><?= e(grade_level((float) $grade['score'])) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section-title">
                    <h3>收费记录</h3>
                    <span class="chip">共 <?= count($studentTuition) ?> 笔</span>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>收据号</th>
                            <th>学期</th>
                            <th class="t-right">应缴</th>
                            <th class="t-right">实缴</th>
                            <th>状态</th>
                            <th>缴费日期</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($studentTuition as $row): ?>
                            <tr>
                                <td><?= e($row['receipt_no']) ?></td>
                                <td><?= e(semester_label($row['semester'])) ?></td>
                                <td class="t-right">¥ <?= money($row['amount']) ?></td>
                                <td class="t-right">¥ <?= money($row['paid_amount']) ?></td>
                                <td><span class="badge <?= e(badge_class($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                                <td><?= e(fmt_date($row['paid_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div>
            <section class="panel" style="margin-bottom: 18px;">
                <div class="panel-head">
                    <div>
                        <div class="panel-title"><span class="dot"></span>学业概览</div>
                        <div class="panel-sub"><?= e(semester_label(CURRENT_TERM)) ?></div>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="progress-row">
                        <div class="progress-meta"><span>期中平均分</span><strong><?= number_format($avgScore, 1) ?></strong></div>
                        <div class="progress"><div class="progress-fill indigo" style="width: <?= min(100, $avgScore) ?>%"></div></div>
                    </div>
                    <div class="progress-row">
                        <div class="progress-meta"><span>课程通过</span><strong><?= $passedCourses ?> / <?= count($studentGrades) ?></strong></div>
                        <div class="progress"><div class="progress-fill" style="width: <?= count($studentGrades) > 0 ? $passedCourses / count($studentGrades) * 100 : 0 ?>%"></div></div>
                    </div>
                    <div class="progress-row">
                        <div class="progress-meta"><span>考勤率</span><strong><?= number_format($attRate, 1) ?>%</strong></div>
                        <div class="progress"><div class="progress-fill gold" style="width: <?= $attRate ?>%"></div></div>
                    </div>
                    <div class="divider"></div>
                    <div class="legend" style="gap: 10px;">
                        <span class="att-dot"><i class="ok"></i>出勤 <?= (int) $attStats['present'] ?></span>
                        <span class="att-dot"><i class="leave"></i>请假 <?= (int) $attStats['leave_count'] ?></span>
                        <span class="att-dot"><i class="absent"></i>缺勤 <?= (int) $attStats['absent_count'] ?></span>
                    </div>
                </div>
            </section>

            <section class="panel" style="margin-bottom: 18px;">
                <div class="panel-head">
                    <div>
                        <div class="panel-title"><span class="dot"></span>本学期缴费</div>
                    </div>
                    <a class="panel-link" href="/tuition.php?keyword=<?= e($student['student_no']) ?>">明细 →</a>
                </div>
                <div class="panel-body">
                    <?php if ($currentTuition): ?>
                        <div class="stat-value" style="font-size: 24px; margin-top: 0;">¥ <?= money($currentTuition['paid_amount']) ?></div>
                        <div class="hint" style="margin-bottom: 14px;">应缴 ¥ <?= money($currentTuition['amount']) ?> · <?= e($currentTuition['status']) ?></div>
                        <div class="progress"><div class="progress-fill" style="width: <?= (float) $currentTuition['amount'] > 0 ? min(100, (float) $currentTuition['paid_amount'] / (float) $currentTuition['amount'] * 100) : 0 ?>%"></div></div>
                        <div class="hint" style="margin-top: 10px;"><?= e($currentTuition['remark'] ?: '缴费状态正常。') ?></div>
                    <?php else: ?>
                        <div class="empty" style="padding: 12px 0;">本学期暂无收费记录。</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div>
                        <div class="panel-title"><span class="dot"></span>近期考勤</div>
                        <div class="panel-sub">最近 8 条课堂记录</div>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="timeline">
                        <?php foreach ($recentAttendance as $record): ?>
                            <div class="timeline-item">
                                <span class="timeline-dot"></span>
                                <div class="timeline-body">
                                    <div class="timeline-title"><?= e($record['course_name']) ?> · <?= e($record['period']) ?></div>
                                    <div class="timeline-meta"><?= e(fmt_date($record['class_date'])) ?> · <span class="badge <?= e(badge_class($record['status'])) ?>"><?= e($record['status']) ?></span></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$recentAttendance): ?>
                            <div class="empty" style="padding: 14px 0;">暂无考勤记录。</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <?php
    require __DIR__ . '/partials/footer.php';
    exit;
}

$keyword = q('keyword');
$classFilter = q('class_id');
$statusFilter = q('status');
$showForm = q('action') === 'new';

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.student_no LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
if ($classFilter !== '' && ctype_digit($classFilter)) {
    $where[] = 's.class_id = ?';
    $params[] = (int) $classFilter;
}
if ($statusFilter !== '') {
    $where[] = 's.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$listStmt = $pdo->prepare("
    SELECT s.*, c.name AS class_name, c.class_code, c.grade_year, m.name AS major_name, d.name AS department_name,
           (SELECT ROUND(AVG(g.score), 1) FROM grades g WHERE g.student_id = s.id AND g.semester = ?) AS avg_score
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id
    LEFT JOIN majors m ON m.id = s.major_id
    LEFT JOIN departments d ON d.id = m.department_id
    $whereSql
    ORDER BY s.student_no
");
$listStmt->execute(array_merge([CURRENT_TERM], $params));
$students = $listStmt->fetchAll();

$classes = $pdo->query('SELECT id, name, grade_year FROM classes ORDER BY class_code')->fetchAll();

$genderCounts = $pdo->query("SELECT gender, COUNT(*) AS cnt FROM students GROUP BY gender")->fetchAll();
$genderMap = [];
foreach ($genderCounts as $row) {
    $genderMap[$row['gender']] = (int) $row['cnt'];
}
$activeCount = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = '在读'")->fetchColumn();
$suspendedCount = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = '休学'")->fetchColumn();
$grade2024 = (int) $pdo->query('SELECT COUNT(*) FROM students s JOIN classes c ON c.id = s.class_id WHERE c.grade_year = 2024')->fetchColumn();
$grade2025 = (int) $pdo->query('SELECT COUNT(*) FROM students s JOIN classes c ON c.id = s.class_id WHERE c.grade_year = 2025')->fetchColumn();

$pageTitle = '学生档案';
$active = 'students';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>学生档案</h1>
        <p>维护在籍学生基础信息与学籍状态，支持按姓名、学号、班级快速检索。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports.php">学籍统计</a>
        <a class="btn btn-primary" href="/students.php?action=new">新增学生档案</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">学籍总数</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= count($students) ?><small>人</small></div>
        <div class="stat-foot">当前筛选结果 · 全校 <?= $activeCount + $suspendedCount ?> 人</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">在读学生</span><span class="stat-icon"><?= icon_svg('classes') ?></span></div>
        <div class="stat-value"><?= $activeCount ?><small>人</small></div>
        <div class="stat-foot">休学 <?= $suspendedCount ?> 人 · 男女比 <?= $genderMap['男'] ?? 0 ?> : <?= $genderMap['女'] ?? 0 ?></div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top"><span class="stat-label">2024级</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= $grade2024 ?><small>人</small></div>
        <div class="stat-foot">计科、数媒、信管三个教学班</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">2025级</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= $grade2025 ?><small>人</small></div>
        <div class="stat-foot">大数据、人工智能、学前三个教学班</div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>新增学生档案</div>
                <div class="panel-sub">录入基础信息后，系统将按班级自动关联专业与入学年份</div>
            </div>
            <a class="panel-link" href="/students.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/students.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="field">
                        <label>学号<span class="required">*</span></label>
                        <input type="text" name="student_no" placeholder="如 2026010101" required>
                    </div>
                    <div class="field">
                        <label>姓名<span class="required">*</span></label>
                        <input type="text" name="name" placeholder="请输入学生姓名" required>
                    </div>
                    <div class="field">
                        <label>性别</label>
                        <select name="gender">
                            <option value="男">男</option>
                            <option value="女">女</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>班级<span class="required">*</span></label>
                        <select name="class_id" required>
                            <option value="">请选择班级</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>"><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>出生日期</label>
                        <input type="date" name="birth_date">
                    </div>
                    <div class="field">
                        <label>身份证号</label>
                        <input type="text" name="id_card" placeholder="选填">
                    </div>
                    <div class="field">
                        <label>联系电话</label>
                        <input type="text" name="phone" placeholder="学生本人手机号">
                    </div>
                    <div class="field">
                        <label>电子邮箱</label>
                        <input type="email" name="email" placeholder="选填">
                    </div>
                    <div class="field">
                        <label>监护人姓名</label>
                        <input type="text" name="guardian_name" placeholder="选填">
                    </div>
                    <div class="field">
                        <label>监护人电话</label>
                        <input type="text" name="guardian_phone" placeholder="选填">
                    </div>
                    <div class="field">
                        <label>政治面貌</label>
                        <select name="political_status">
                            <option value="共青团员">共青团员</option>
                            <option value="群众">群众</option>
                            <option value="中共预备党员">中共预备党员</option>
                            <option value="中共党员">中共党员</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>学籍状态</label>
                        <select name="status">
                            <option value="在读">在读</option>
                            <option value="休学">休学</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label>家庭住址</label>
                        <input type="text" name="address" placeholder="请输入家庭住址">
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/students.php">取消</a>
                    <button class="btn btn-primary" type="submit">保存学生档案</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<form class="filter-bar" method="get" action="/students.php">
    <div class="filter-field grow">
        <label>关键字</label>
        <input type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="姓名或学号">
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
        <label>学籍状态</label>
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach (['在读', '休学', '已毕业', '退学'] as $status): ?>
                <option value="<?= e($status) ?>"<?= $status === $statusFilter ? ' selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/students.php">重置</a>
        <button class="btn btn-primary" type="submit">查询</button>
    </div>
</form>

<section class="panel">
    <div class="panel-head">
        <div>
            <div class="panel-title"><span class="dot"></span>学生名册</div>
            <div class="panel-sub">共 <?= count($students) ?> 条记录，点击行可查看完整档案</div>
        </div>
        <span class="chip">2026-2027学年</span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>学生</th>
                <th>学号</th>
                <th>班级</th>
                <th>专业</th>
                <th>联系方式</th>
                <th class="t-center">期中均分</th>
                <th>学籍状态</th>
                <th class="t-right">操作</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$students): ?>
                <tr><td colspan="8" class="table-empty">没有符合条件的学生记录。</td></tr>
            <?php endif; ?>
            <?php foreach ($students as $student): ?>
                <tr data-href="/students.php?id=<?= (int) $student['id'] ?>" style="cursor: pointer;">
                    <td>
                        <div class="person-cell">
                            <span class="person-avatar<?= $student['gender'] === '女' ? ' rose' : '' ?>"><?= e(initial_of($student['name'])) ?></span>
                            <div>
                                <div class="person-name"><?= e($student['name']) ?></div>
                                <span class="table-sub"><?= e($student['gender']) ?> · <?= e($student['political_status']) ?></span>
                            </div>
                        </div>
                    </td>
                    <td><?= e($student['student_no']) ?></td>
                    <td>
                        <?= e($student['class_name']) ?>
                        <span class="table-sub"><?= e($student['class_code']) ?> · <?= (int) $student['grade_year'] ?>级</span>
                    </td>
                    <td>
                        <?= e($student['major_name']) ?>
                        <span class="table-sub"><?= e($student['department_name']) ?></span>
                    </td>
                    <td>
                        <?= e($student['phone']) ?>
                        <span class="table-sub"><?= e($student['email']) ?></span>
                    </td>
                    <td class="t-center">
                        <?php if ($student['avg_score'] !== null): ?>
                            <span class="score <?= grade_tone((float) $student['avg_score']) ?>"><?= number_format((float) $student['avg_score'], 1) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= e(badge_class($student['status'])) ?>"><?= e($student['status']) ?></span></td>
                    <td class="t-right"><a class="btn btn-soft btn-sm" href="/students.php?id=<?= (int) $student['id'] ?>">查看档案</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="panel-foot">
        <span>学生档案由教务处统一维护，学号与身份证号请谨慎变更</span>
        <span>在籍 <?= $activeCount ?> 人 · 休学 <?= $suspendedCount ?> 人</span>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
