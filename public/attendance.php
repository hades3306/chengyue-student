<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'create') {
        $studentId = (int) input('student_id');
        $courseId = (int) input('course_id');
        $classDate = input('class_date', date('Y-m-d'));
        $period = input('period', '第1-2节');
        $status = input('status', '出勤');
        $remark = input('remark');

        $studentStmt = $pdo->prepare('SELECT name FROM students WHERE id = ?');
        $studentStmt->execute([$studentId]);
        $studentName = $studentStmt->fetchColumn();
        $courseStmt = $pdo->prepare('SELECT name FROM courses WHERE id = ?');
        $courseStmt->execute([$courseId]);
        $courseName = $courseStmt->fetchColumn();

        if (!$studentName || !$courseName || $classDate === '') {
            flash('error', '请选择学生、课程并填写上课日期。');
            redirect('/attendance.php?action=new');
        }

        $dup = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE student_id = ? AND course_id = ? AND class_date = ? AND period = ?');
        $dup->execute([$studentId, $courseId, $classDate, $period]);
        if ((int) $dup->fetchColumn() > 0) {
            flash('error', '该学生在此课程与节次的考勤记录已存在。');
            redirect('/attendance.php?action=new');
        }

        $stmt = $pdo->prepare('INSERT INTO attendance (student_id, course_id, class_date, period, status, remark, recorder, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $courseId, $classDate, $period, $status, $remark ?: null, current_operator(), date('Y-m-d H:i:s')]);
        flash('success', '考勤记录已保存：' . $studentName . ' · ' . $courseName . ' · ' . $status . '。');
        redirect('/attendance.php?class_date=' . urlencode($classDate));
    }
}

$showForm = q('action') === 'new';
$classFilter = q('class_id');
$courseFilter = q('course_id');
$statusFilter = q('status');
$dateFilter = q('class_date');

$where = [];
$params = [];
if ($classFilter !== '' && ctype_digit($classFilter)) {
    $where[] = 's.class_id = ?';
    $params[] = (int) $classFilter;
}
if ($courseFilter !== '' && ctype_digit($courseFilter)) {
    $where[] = 'a.course_id = ?';
    $params[] = (int) $courseFilter;
}
if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($dateFilter !== '') {
    $where[] = 'a.class_date = ?';
    $params[] = $dateFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT a.*, s.name AS student_name, s.student_no, c.name AS class_name, co.name AS course_name, co.course_code
    FROM attendance a
    JOIN students s ON s.id = a.student_id
    JOIN classes c ON c.id = s.class_id
    JOIN courses co ON co.id = a.course_id
    $whereSql
    ORDER BY a.class_date DESC, a.period, s.student_no
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$total = count($records);
$statusCounts = ['出勤' => 0, '迟到' => 0, '请假' => 0, '缺勤' => 0];
foreach ($records as $record) {
    if (isset($statusCounts[$record['status']])) {
        $statusCounts[$record['status']]++;
    }
}
$attRate = $total > 0 ? $statusCounts['出勤'] / $total * 100 : 0.0;

$classes = $pdo->query('SELECT id, name FROM classes ORDER BY class_code')->fetchAll();
$courses = $pdo->query('SELECT id, course_code, name FROM courses ORDER BY course_code')->fetchAll();
$studentOptions = $pdo->query("SELECT s.id, s.student_no, s.name, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.student_no")->fetchAll();

$dateRows = $pdo->query("
    SELECT class_date, COUNT(*) AS total, SUM(CASE WHEN status = '出勤' THEN 1 ELSE 0 END) AS present
    FROM attendance
    GROUP BY class_date
    ORDER BY class_date
")->fetchAll();

$today = date('Y-m-d');
$todayStmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = '出勤' THEN 1 ELSE 0 END) AS present FROM attendance WHERE class_date = ?");
$todayStmt->execute([$today]);
$todayRow = $todayStmt->fetch();
if ((int) $todayRow['total'] === 0) {
    $latestDate = (string) $pdo->query('SELECT MAX(class_date) FROM attendance')->fetchColumn();
    $todayStmt->execute([$latestDate]);
    $todayRow = $todayStmt->fetch();
} else {
    $latestDate = $today;
}
$todayRate = (int) $todayRow['total'] > 0 ? (int) $todayRow['present'] / (int) $todayRow['total'] * 100 : 0;

$classAttStmt = $pdo->query("
    SELECT c.name, COUNT(*) AS total, SUM(CASE WHEN a.status = '出勤' THEN 1 ELSE 0 END) AS present
    FROM attendance a JOIN students s ON s.id = a.student_id JOIN classes c ON c.id = s.class_id
    GROUP BY c.id ORDER BY c.class_code
");
$classAtt = $classAttStmt->fetchAll();

$pageTitle = '考勤管理';
$active = 'attendance';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>考勤管理</h1>
        <p>记录课堂出勤、迟到、请假与缺勤情况，按班级与课程汇总考勤率，及时跟进异常学生。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports.php">考勤统计</a>
        <a class="btn btn-primary" href="/attendance.php?action=new">登记考勤</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">考勤记录</span><span class="stat-icon"><?= icon_svg('attendance') ?></span></div>
        <div class="stat-value"><?= $total ?><small>条</small></div>
        <div class="stat-foot">当前筛选范围 · 全校共 <?= (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn() ?> 条</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">出勤率</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= number_format($attRate, 1) ?><small>%</small></div>
        <div class="stat-foot">出勤 <?= $statusCounts['出勤'] ?> 人次 · 迟到 <?= $statusCounts['迟到'] ?> 人次</div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">请假</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= $statusCounts['请假'] ?><small>人次</small></div>
        <div class="stat-foot">均已提交请假单并记录事由</div>
    </div>
    <div class="stat-card tone-rose">
        <div class="stat-top"><span class="stat-label">缺勤</span><span class="stat-icon"><?= icon_svg('bell') ?></span></div>
        <div class="stat-value"><?= $statusCounts['缺勤'] ?><small>人次</small></div>
        <div class="stat-foot"><?= e($latestDate) ?> 出勤率 <?= number_format($todayRate, 1) ?>%</div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>登记考勤</div>
                <div class="panel-sub">同一学生、课程、日期与节次仅可登记一条记录</div>
            </div>
            <a class="panel-link" href="/attendance.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/attendance.php">
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
                        <label>上课日期<span class="required">*</span></label>
                        <input type="date" name="class_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="field">
                        <label>节次</label>
                        <select name="period">
                            <option value="第1-2节">第1-2节</option>
                            <option value="第3-4节">第3-4节</option>
                            <option value="第5-6节">第5-6节</option>
                            <option value="第7-8节">第7-8节</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>考勤状态</label>
                        <select name="status">
                            <option value="出勤">出勤</option>
                            <option value="迟到">迟到</option>
                            <option value="请假">请假</option>
                            <option value="缺勤">缺勤</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label>备注</label>
                        <input type="text" name="remark" placeholder="迟到时长、请假事由或跟进情况（选填）">
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/attendance.php">取消</a>
                    <button class="btn btn-primary" type="submit">保存考勤</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<form class="filter-bar" method="get" action="/attendance.php">
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
        <label>课程</label>
        <select name="course_id">
            <option value="">全部课程</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?= (int) $course['id'] ?>"<?= (string) $course['id'] === $courseFilter ? ' selected' : '' ?>><?= e($course['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>状态</label>
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach (['出勤', '迟到', '请假', '缺勤'] as $statusOption): ?>
                <option value="<?= e($statusOption) ?>"<?= $statusOption === $statusFilter ? ' selected' : '' ?>><?= e($statusOption) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>上课日期</label>
        <input type="date" name="class_date" value="<?= e($dateFilter) ?>">
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/attendance.php">重置</a>
        <button class="btn btn-primary" type="submit">查询</button>
    </div>
</form>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>考勤明细</div>
                <div class="panel-sub">共 <?= $total ?> 条记录，按上课日期倒序排列</div>
            </div>
            <span class="chip">出勤率 <?= number_format($attRate, 1) ?>%</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>学生</th>
                    <th>班级</th>
                    <th>课程 / 节次</th>
                    <th>上课日期</th>
                    <th>状态</th>
                    <th>备注</th>
                    <th>记录人</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td>
                            <div class="person-cell">
                                <span class="person-avatar <?= $record['status'] === '缺勤' ? 'rose' : ($record['status'] === '迟到' ? 'gold' : 'lake') ?>"><?= e(initial_of($record['student_name'])) ?></span>
                                <div>
                                    <div class="person-name"><?= e($record['student_name']) ?></div>
                                    <span class="table-sub"><?= e($record['student_no']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($record['class_name']) ?></td>
                        <td>
                            <?= e($record['course_name']) ?>
                            <span class="table-sub"><?= e($record['period']) ?> · <?= e($record['course_code']) ?></span>
                        </td>
                        <td><?= e(fmt_date($record['class_date'])) ?></td>
                        <td><span class="badge <?= e(badge_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                        <td class="small muted"><?= e($record['remark'] ?: '—') ?></td>
                        <td><?= e($record['recorder']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$records): ?>
                    <tr><td colspan="7" class="table-empty">没有符合条件的考勤记录。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>考勤记录用于学生学业预警与班级管理</span>
            <span>迟到 <?= $statusCounts['迟到'] ?> · 请假 <?= $statusCounts['请假'] ?> · 缺勤 <?= $statusCounts['缺勤'] ?></span>
        </div>
    </section>

    <div>
        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>考勤状态构成</div>
                    <div class="panel-sub">当前筛选结果</div>
                </div>
            </div>
            <div class="panel-body">
                <?php
                $statusTone = ['出勤' => '', '迟到' => 'gold', '请假' => 'indigo', '缺勤' => 'rose'];
                foreach ($statusCounts as $statusName => $count):
                    $width = $total > 0 ? $count / $total * 100 : 0;
                ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($statusName) ?></span>
                            <strong><?= $count ?> 人次</strong>
                        </div>
                        <div class="progress"><div class="progress-fill <?= $statusTone[$statusName] ?>" style="width: <?= $width ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <div class="divider"></div>
                <div class="att-legend">
                    <span class="att-dot"><i class="ok"></i>出勤</span>
                    <span class="att-dot"><i class="late"></i>迟到</span>
                    <span class="att-dot"><i class="leave"></i>请假</span>
                    <span class="att-dot"><i class="absent"></i>缺勤</span>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>按日期出勤</div>
                </div>
            </div>
            <div class="panel-body">
                <div class="chart-bars" style="height: 150px;">
                    <?php foreach ($dateRows as $row): ?>
                        <?php $rate = (int) $row['total'] > 0 ? (int) $row['present'] / (int) $row['total'] * 100 : 0; ?>
                        <div class="chart-bar">
                            <span class="chart-bar-value"><?= number_format($rate, 0) ?>%</span>
                            <div class="chart-bar-track" style="height: 95px;"><div class="chart-bar-fill lake" style="height: <?= max(8, $rate) ?>%"></div></div>
                            <span class="chart-bar-label"><?= e(date('m-d', strtotime($row['class_date']))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<section class="panel">
    <div class="panel-head">
        <div>
            <div class="panel-title"><span class="dot"></span>班级考勤汇总</div>
            <div class="panel-sub">本学期全部考勤记录</div>
        </div>
    </div>
    <div class="panel-body">
        <div class="grid-2" style="margin-bottom: 0;">
            <?php foreach (array_chunk($classAtt, 3) as $chunk): ?>
                <div>
                    <?php foreach ($chunk as $row): ?>
                        <?php $rate = (int) $row['total'] > 0 ? (int) $row['present'] / (int) $row['total'] * 100 : 0; ?>
                        <div class="progress-row">
                            <div class="progress-meta">
                                <span><?= e($row['name']) ?></span>
                                <strong><?= number_format($rate, 1) ?>%</strong>
                            </div>
                            <div class="progress"><div class="progress-fill <?= $rate < 85 ? 'gold' : '' ?>" style="width: <?= $rate ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
