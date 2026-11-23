<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'create') {
        $teacherNo = input('teacher_no');
        $name = input('name');
        $gender = input('gender', '男');
        $title = input('title', '讲师');
        $departmentId = (int) input('department_id');
        $phone = input('phone');
        $email = input('email');
        $specialty = input('specialty');
        $joinDate = input('join_date', date('Y-m-d'));
        $status = input('status', '在职');

        $deptCheck = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE id = ?');
        $deptCheck->execute([$departmentId]);
        $noCheck = $pdo->prepare('SELECT COUNT(*) FROM teachers WHERE teacher_no = ?');
        $noCheck->execute([$teacherNo]);

        if ($teacherNo === '' || $name === '' || $departmentId <= 0 || (int) $deptCheck->fetchColumn() === 0) {
            flash('error', '请完整填写工号、姓名与所属院系。');
            redirect('/teachers.php?action=new');
        }
        if ((int) $noCheck->fetchColumn() > 0) {
            flash('error', '教师工号已存在，请核对后重试。');
            redirect('/teachers.php?action=new');
        }

        $stmt = $pdo->prepare('INSERT INTO teachers (teacher_no, name, gender, title, department_id, phone, email, specialty, join_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$teacherNo, $name, $gender, $title, $departmentId, $phone, $email, $specialty, $joinDate, $status]);
        flash('success', '教师档案已创建：' . $name . '（' . $teacherNo . '）。');
        redirect('/teachers.php');
    }
}

$showForm = q('action') === 'new';
$keyword = q('keyword');
$deptFilter = q('department_id');
$titleFilter = q('title');

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(t.name LIKE ? OR t.teacher_no LIKE ? OR t.specialty LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
if ($deptFilter !== '' && ctype_digit($deptFilter)) {
    $where[] = 't.department_id = ?';
    $params[] = (int) $deptFilter;
}
if ($titleFilter !== '') {
    $where[] = 't.title = ?';
    $params[] = $titleFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT t.*, d.name AS department_name,
           (SELECT COUNT(*) FROM courses co WHERE co.teacher_id = t.id AND co.semester = ?) AS course_count,
           (SELECT COALESCE(SUM(co.hours), 0) FROM courses co WHERE co.teacher_id = t.id AND co.semester = ?) AS hours,
           (SELECT COUNT(*) FROM classes c WHERE c.counselor_id = t.id) AS class_count
    FROM teachers t
    LEFT JOIN departments d ON d.id = t.department_id
    $whereSql
    ORDER BY t.teacher_no
");
$stmt->execute(array_merge([CURRENT_TERM, CURRENT_TERM], $params));
$teachers = $stmt->fetchAll();

$departments = $pdo->query('SELECT id, name FROM departments ORDER BY code')->fetchAll();

$totalTeachers = (int) $pdo->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$activeTeachers = (int) $pdo->query("SELECT COUNT(*) FROM teachers WHERE status = '在职'")->fetchColumn();
$seniorTeachers = (int) $pdo->query("SELECT COUNT(*) FROM teachers WHERE title IN ('教授', '副教授')")->fetchColumn();
$teachingTeachers = (int) $pdo->query('SELECT COUNT(DISTINCT teacher_id) FROM courses WHERE semester = ' . $pdo->quote(CURRENT_TERM))->fetchColumn();
$avgHours = (float) $pdo->query('SELECT COALESCE(AVG(hours), 0) FROM courses WHERE semester = ' . $pdo->quote(CURRENT_TERM))->fetchColumn();

$titleRows = $pdo->query("SELECT title, COUNT(*) AS cnt FROM teachers GROUP BY title ORDER BY cnt DESC")->fetchAll();
$titleTotal = max(1, array_sum(array_column($titleRows, 'cnt')));
$deptRows = $pdo->query("SELECT d.name, COUNT(t.id) AS cnt FROM departments d LEFT JOIN teachers t ON t.department_id = d.id GROUP BY d.id ORDER BY d.code")->fetchAll();
$deptMax = max(1, ...array_map(static fn (array $row): int => (int) $row['cnt'], $deptRows));

$pageTitle = '教师管理';
$active = 'teachers';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>教师管理</h1>
        <p>维护教师档案、职称与院系归属，查看本学期授课课时与辅导员任职情况。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/courses.php">课程安排</a>
        <a class="btn btn-primary" href="/teachers.php?action=new">新增教师</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">教师总数</span><span class="stat-icon"><?= icon_svg('teachers') ?></span></div>
        <div class="stat-value"><?= $totalTeachers ?><small>人</small></div>
        <div class="stat-foot">在职 <?= $activeTeachers ?> 人 · 覆盖 <?= count($departments) ?> 个院系</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">高级职称</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= $seniorTeachers ?><small>人</small></div>
        <div class="stat-foot">教授与副教授合计占比 <?= percent($seniorTeachers, $totalTeachers) ?></div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top"><span class="stat-label">承担课程</span><span class="stat-icon"><?= icon_svg('courses') ?></span></div>
        <div class="stat-value"><?= $teachingTeachers ?><small>人</small></div>
        <div class="stat-foot">本学期有授课任务的教师</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">平均课时</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= number_format($avgHours, 0) ?><small>学时</small></div>
        <div class="stat-foot">按本学期课程学时计算</div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>新增教师</div>
                <div class="panel-sub">教师工号用于排课、课时统计与归档，请按人事编码规范填写</div>
            </div>
            <a class="panel-link" href="/teachers.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/teachers.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="field">
                        <label>教师工号<span class="required">*</span></label>
                        <input type="text" name="teacher_no" placeholder="如 T2026031" required>
                    </div>
                    <div class="field">
                        <label>姓名<span class="required">*</span></label>
                        <input type="text" name="name" placeholder="请输入教师姓名" required>
                    </div>
                    <div class="field">
                        <label>性别</label>
                        <select name="gender">
                            <option value="男">男</option>
                            <option value="女">女</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>职称</label>
                        <select name="title">
                            <option value="教授">教授</option>
                            <option value="副教授">副教授</option>
                            <option value="讲师" selected>讲师</option>
                            <option value="助教">助教</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>所属院系<span class="required">*</span></label>
                        <select name="department_id" required>
                            <option value="">请选择院系</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>入职日期</label>
                        <input type="date" name="join_date" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                    <div class="field">
                        <label>联系电话</label>
                        <input type="text" name="phone" placeholder="选填">
                    </div>
                    <div class="field">
                        <label>电子邮箱</label>
                        <input type="email" name="email" placeholder="选填">
                    </div>
                    <div class="field span-2">
                        <label>专业方向</label>
                        <input type="text" name="specialty" placeholder="如 软件工程与算法设计">
                    </div>
                    <div class="field">
                        <label>在职状态</label>
                        <select name="status">
                            <option value="在职">在职</option>
                            <option value="离职">离职</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/teachers.php">取消</a>
                    <button class="btn btn-primary" type="submit">保存教师档案</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<form class="filter-bar" method="get" action="/teachers.php">
    <div class="filter-field grow">
        <label>关键字</label>
        <input type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="姓名、工号或专业方向">
    </div>
    <div class="filter-field">
        <label>院系</label>
        <select name="department_id">
            <option value="">全部院系</option>
            <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>"<?= (string) $department['id'] === $deptFilter ? ' selected' : '' ?>><?= e($department['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>职称</label>
        <select name="title">
            <option value="">全部职称</option>
            <?php foreach (['教授', '副教授', '讲师', '助教'] as $titleOption): ?>
                <option value="<?= e($titleOption) ?>"<?= $titleOption === $titleFilter ? ' selected' : '' ?>><?= e($titleOption) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/teachers.php">重置</a>
        <button class="btn btn-primary" type="submit">查询</button>
    </div>
</form>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>教师名录</div>
                <div class="panel-sub">共 <?= count($teachers) ?> 位教师</div>
            </div>
            <span class="chip">2026-2027学年</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>教师</th>
                    <th>院系</th>
                    <th>职称</th>
                    <th>专业方向</th>
                    <th class="t-center">本学期课程</th>
                    <th class="t-center">课时</th>
                    <th>联系方式</th>
                    <th>状态</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($teachers as $teacher): ?>
                    <tr>
                        <td>
                            <div class="person-cell">
                                <span class="person-avatar <?= $teacher['gender'] === '女' ? 'rose' : 'lake' ?>"><?= e(initial_of($teacher['name'])) ?></span>
                                <div>
                                    <div class="person-name"><?= e($teacher['name']) ?></div>
                                    <span class="table-sub"><?= e($teacher['teacher_no']) ?> · <?= e($teacher['gender']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($teacher['department_name']) ?></td>
                        <td><span class="badge badge-primary"><?= e($teacher['title']) ?></span></td>
                        <td><?= e($teacher['specialty']) ?></td>
                        <td class="t-center">
                            <?= (int) $teacher['course_count'] ?> 门
                            <?php if ((int) $teacher['class_count'] > 0): ?>
                                <span class="table-sub">辅导员 <?= (int) $teacher['class_count'] ?> 个班</span>
                            <?php endif; ?>
                        </td>
                        <td class="t-center"><?= (int) $teacher['hours'] ?></td>
                        <td>
                            <?= e($teacher['phone']) ?>
                            <span class="table-sub"><?= e($teacher['email']) ?></span>
                        </td>
                        <td><span class="badge <?= e(badge_class($teacher['status'])) ?>"><?= e($teacher['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$teachers): ?>
                    <tr><td colspan="8" class="table-empty">没有符合条件的教师记录。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>教师信息与课程、班级模块共享</span>
            <span>在职 <?= $activeTeachers ?> 人</span>
        </div>
    </section>

    <div>
        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>职称构成</div>
                </div>
            </div>
            <div class="panel-body">
                <?php foreach ($titleRows as $row): ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($row['title']) ?></span>
                            <strong><?= (int) $row['cnt'] ?> 人</strong>
                        </div>
                        <div class="progress"><div class="progress-fill indigo" style="width: <?= (int) $row['cnt'] / $titleTotal * 100 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>院系分布</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="hbar-list">
                    <?php foreach ($deptRows as $row): ?>
                        <div class="hbar">
                            <span class="hbar-label"><?= e($row['name']) ?></span>
                            <div class="hbar-track"><div class="hbar-fill" style="width: <?= (int) $row['cnt'] / $deptMax * 100 ?>%"></div></div>
                            <span class="hbar-value"><?= (int) $row['cnt'] ?> 人</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="divider"></div>
                <div class="hint">外聘教师与兼课教师由人事处统一备案，不在本页面单独登记。</div>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
