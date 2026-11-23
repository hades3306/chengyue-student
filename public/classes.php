<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (input('action') === 'create') {
        $classCode = input('class_code');
        $name = input('name');
        $majorId = (int) input('major_id');
        $gradeYear = (int) input('grade_year');
        $counselorId = (int) input('counselor_id');
        $classroom = input('classroom');
        $capacity = (int) input('capacity', '45');
        $status = input('status', '在读');

        $majorCheck = $pdo->prepare('SELECT COUNT(*) FROM majors WHERE id = ?');
        $majorCheck->execute([$majorId]);
        $codeCheck = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE class_code = ?');
        $codeCheck->execute([$classCode]);

        if ($classCode === '' || $name === '' || $majorId <= 0 || $gradeYear < 2000 || (int) $majorCheck->fetchColumn() === 0) {
            flash('error', '请完整填写班级编码、名称、专业与年级。');
            redirect('/classes.php?action=new');
        }
        if ((int) $codeCheck->fetchColumn() > 0) {
            flash('error', '班级编码已存在，请更换后重试。');
            redirect('/classes.php?action=new');
        }

        $stmt = $pdo->prepare('INSERT INTO classes (class_code, name, major_id, grade_year, counselor_id, classroom, capacity, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$classCode, $name, $majorId, $gradeYear, $counselorId > 0 ? $counselorId : null, $classroom, max(1, $capacity), $status]);
        flash('success', '班级已创建：' . $name . '。');
        redirect('/classes.php');
    }
}

$showForm = q('action') === 'new';
$majorFilter = q('major_id');
$gradeFilter = q('grade_year');

$where = [];
$params = [];
if ($majorFilter !== '' && ctype_digit($majorFilter)) {
    $where[] = 'c.major_id = ?';
    $params[] = (int) $majorFilter;
}
if ($gradeFilter !== '' && ctype_digit($gradeFilter)) {
    $where[] = 'c.grade_year = ?';
    $params[] = (int) $gradeFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT c.*, m.name AS major_name, m.degree, d.name AS department_name, t.name AS counselor,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = '在读') AS active_count,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS total_count
    FROM classes c
    LEFT JOIN majors m ON m.id = c.major_id
    LEFT JOIN departments d ON d.id = m.department_id
    LEFT JOIN teachers t ON t.id = c.counselor_id
    $whereSql
    ORDER BY c.grade_year DESC, c.class_code
");
$stmt->execute($params);
$classes = $stmt->fetchAll();

$avgStmt = $pdo->prepare('SELECT s.class_id, ROUND(AVG(g.score), 1) AS avg_score FROM grades g JOIN students s ON s.id = g.student_id WHERE g.semester = ? GROUP BY s.class_id');
$avgStmt->execute([CURRENT_TERM]);
$avgMap = [];
foreach ($avgStmt->fetchAll() as $row) {
    $avgMap[(int) $row['class_id']] = (float) $row['avg_score'];
}
$attStmt = $pdo->query("SELECT s.class_id, COUNT(*) AS total, SUM(CASE WHEN a.status = '出勤' THEN 1 ELSE 0 END) AS present FROM attendance a JOIN students s ON s.id = a.student_id GROUP BY s.class_id");
$attMap = [];
foreach ($attStmt->fetchAll() as $row) {
    $attMap[(int) $row['class_id']] = $row;
}

$majors = $pdo->query('SELECT id, name FROM majors ORDER BY code')->fetchAll();
$teachers = $pdo->query("SELECT id, name, title FROM teachers WHERE status = '在职' ORDER BY teacher_no")->fetchAll();

$totalClasses = (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$totalActive = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE status = '在读'")->fetchColumn();
$totalCapacity = (int) $pdo->query('SELECT COALESCE(SUM(capacity), 0) FROM classes')->fetchColumn();
$avgClassSize = $totalClasses > 0 ? $totalActive / $totalClasses : 0.0;
$coveredMajors = (int) $pdo->query('SELECT COUNT(DISTINCT major_id) FROM classes')->fetchColumn();

$pageTitle = '班级管理';
$active = 'classes';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>班级管理</h1>
        <p>维护行政班级、专业归属、辅导员与固定教室，掌握各班学生规模与教学运行情况。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/students.php">学生名册</a>
        <a class="btn btn-primary" href="/classes.php?action=new">新建班级</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">教学班级</span><span class="stat-icon"><?= icon_svg('classes') ?></span></div>
        <div class="stat-value"><?= $totalClasses ?><small>个</small></div>
        <div class="stat-foot">覆盖 <?= $coveredMajors ?> 个专业方向</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">在读学生</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value"><?= $totalActive ?><small>人</small></div>
        <div class="stat-foot">平均班额 <?= number_format($avgClassSize, 1) ?> 人</div>
    </div>
    <div class="stat-card tone-sky">
        <div class="stat-top"><span class="stat-label">核定容量</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value"><?= $totalCapacity ?><small>人</small></div>
        <div class="stat-foot">当前占用 <strong style="color: var(--ink);"><?= percent($totalActive, $totalCapacity) ?></strong></div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">辅导员配置</span><span class="stat-icon"><?= icon_svg('teachers') ?></span></div>
        <div class="stat-value"><?= (int) $pdo->query('SELECT COUNT(DISTINCT counselor_id) FROM classes WHERE counselor_id IS NOT NULL')->fetchColumn() ?><small>人</small></div>
        <div class="stat-foot">每班均配备专职辅导员</div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>新建班级</div>
                <div class="panel-sub">班级编码用于排课与成绩归档，建议按“专业简称 + 年级 + 序号”编制</div>
            </div>
            <a class="panel-link" href="/classes.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/classes.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-grid">
                    <div class="field">
                        <label>班级编码<span class="required">*</span></label>
                        <input type="text" name="class_code" placeholder="如 XK2601" required>
                    </div>
                    <div class="field">
                        <label>班级名称<span class="required">*</span></label>
                        <input type="text" name="name" placeholder="如 2026级计算机科学与技术1班" required>
                    </div>
                    <div class="field">
                        <label>所属专业<span class="required">*</span></label>
                        <select name="major_id" required>
                            <option value="">请选择专业</option>
                            <?php foreach ($majors as $major): ?>
                                <option value="<?= (int) $major['id'] ?>"><?= e($major['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>年级<span class="required">*</span></label>
                        <select name="grade_year" required>
                            <?php foreach ([2026, 2025, 2024, 2023] as $year): ?>
                                <option value="<?= $year ?>"<?= $year === 2026 ? ' selected' : '' ?>><?= $year ?>级</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>辅导员</label>
                        <select name="counselor_id">
                            <option value="">暂不指定</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int) $teacher['id'] ?>"><?= e($teacher['name']) ?> · <?= e($teacher['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>固定教室</label>
                        <input type="text" name="classroom" placeholder="如 明德楼 A201">
                    </div>
                    <div class="field">
                        <label>核定容量</label>
                        <input type="number" name="capacity" value="45" min="1" max="120">
                    </div>
                    <div class="field">
                        <label>班级状态</label>
                        <select name="status">
                            <option value="在读">在读</option>
                            <option value="已毕业">已毕业</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/classes.php">取消</a>
                    <button class="btn btn-primary" type="submit">创建班级</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<form class="filter-bar" method="get" action="/classes.php">
    <div class="filter-field">
        <label>年级</label>
        <select name="grade_year">
            <option value="">全部年级</option>
            <?php foreach ([2026, 2025, 2024, 2023] as $year): ?>
                <option value="<?= $year ?>"<?= (string) $year === $gradeFilter ? ' selected' : '' ?>><?= $year ?>级</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>专业</label>
        <select name="major_id">
            <option value="">全部专业</option>
            <?php foreach ($majors as $major): ?>
                <option value="<?= (int) $major['id'] ?>"<?= (string) $major['id'] === $majorFilter ? ' selected' : '' ?>><?= e($major['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/classes.php">重置</a>
        <button class="btn btn-primary" type="submit">筛选</button>
    </div>
</form>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>班级列表</div>
                <div class="panel-sub">共 <?= count($classes) ?> 个班级</div>
            </div>
            <span class="chip">按年级排序</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>班级</th>
                    <th>专业 / 院系</th>
                    <th>辅导员</th>
                    <th class="t-center">学生</th>
                    <th class="t-center">期中均分</th>
                    <th class="t-center">考勤率</th>
                    <th>教室</th>
                    <th class="t-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($classes as $class): ?>
                    <?php
                    $att = $attMap[(int) $class['id']] ?? null;
                    $attRate = $att && (int) $att['total'] > 0 ? (int) $att['present'] / (int) $att['total'] * 100 : 0;
                    $avg = $avgMap[(int) $class['id']] ?? 0;
                    ?>
                    <tr>
                        <td>
                            <div class="person-name"><?= e($class['name']) ?></div>
                            <span class="table-sub"><?= e($class['class_code']) ?> · <?= (int) $class['grade_year'] ?>级 · <span class="badge <?= e(badge_class($class['status'])) ?>"><?= e($class['status']) ?></span></span>
                        </td>
                        <td>
                            <?= e($class['major_name']) ?>
                            <span class="table-sub"><?= e($class['department_name']) ?> · <?= e($class['degree']) ?></span>
                        </td>
                        <td><?= e($class['counselor'] ?: '未指定') ?></td>
                        <td class="t-center">
                            <?= (int) $class['active_count'] ?>
                            <span class="table-sub">共 <?= (int) $class['total_count'] ?> 人</span>
                        </td>
                        <td class="t-center"><span class="score <?= grade_tone((float) $avg) ?>"><?= number_format((float) $avg, 1) ?></span></td>
                        <td class="t-center"><?= number_format($attRate, 1) ?>%</td>
                        <td><?= e($class['classroom'] ?: '待安排') ?></td>
                        <td class="t-right">
                            <a class="btn btn-soft btn-sm" href="/students.php?class_id=<?= (int) $class['id'] ?>">学生名单</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$classes): ?>
                    <tr><td colspan="8" class="table-empty">没有符合筛选条件的班级。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>班级数据与学籍、课程、成绩模块实时联动</span>
            <span>核定容量合计 <?= $totalCapacity ?> 人</span>
        </div>
    </section>

    <div>
        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>班级规模</div>
                    <div class="panel-sub">在读人数 / 核定容量</div>
                </div>
            </div>
            <div class="panel-body">
                <?php foreach ($classes as $class): ?>
                    <?php $usage = (int) $class['capacity'] > 0 ? (int) $class['active_count'] / (int) $class['capacity'] * 100 : 0; ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($class['name']) ?></span>
                            <strong><?= (int) $class['active_count'] ?> / <?= (int) $class['capacity'] ?></strong>
                        </div>
                        <div class="progress"><div class="progress-fill <?= $usage >= 90 ? 'gold' : '' ?>" style="width: <?= min(100, $usage) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>年级构成</div>
                </div>
            </div>
            <div class="panel-body">
                <?php
                $gradeCounts = $pdo->query('SELECT c.grade_year, COUNT(s.id) AS cnt FROM classes c LEFT JOIN students s ON s.class_id = c.id GROUP BY c.grade_year ORDER BY c.grade_year DESC')->fetchAll();
                $gradeTotal = max(1, (int) array_sum(array_column($gradeCounts, 'cnt')));
                foreach ($gradeCounts as $row):
                    $year = (int) $row['grade_year'];
                    $cnt = (int) $row['cnt'];
                ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= $year ?>级</span>
                            <strong><?= $cnt ?> 人</strong>
                        </div>
                        <div class="progress"><div class="progress-fill indigo" style="width: <?= $cnt / $gradeTotal * 100 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <div class="divider"></div>
                <div class="hint">班级变动请同步联系教务处学籍科，涉及专业调整需提交学籍异动申请。</div>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
