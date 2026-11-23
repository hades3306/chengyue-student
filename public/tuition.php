<?php
require_once __DIR__ . '/../lib/bootstrap.php';
require_login();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = input('action');

    if ($action === 'create') {
        $studentId = (int) input('student_id');
        $semester = input('semester', CURRENT_TERM);
        $amount = (float) input('amount', '0');
        $paidAmount = (float) input('paid_amount', '0');
        $method = input('method', '网上银行');
        $remark = input('remark');

        $studentStmt = $pdo->prepare('SELECT name FROM students WHERE id = ?');
        $studentStmt->execute([$studentId]);
        $studentName = $studentStmt->fetchColumn();

        if (!$studentName || $amount <= 0) {
            flash('error', '请选择学生并填写正确的应缴金额。');
            redirect('/tuition.php?action=new');
        }
        if ($paidAmount < 0 || $paidAmount > $amount) {
            flash('error', '实缴金额须在 0 至应缴金额之间。');
            redirect('/tuition.php?action=new');
        }

        $status = $paidAmount >= $amount ? '已缴清' : ($paidAmount > 0 ? '部分缴纳' : '待缴费');
        $maxCode = (int) $pdo->query("SELECT COALESCE(MAX(CAST(substr(receipt_no, 11) AS INTEGER)), 1000) FROM tuition WHERE receipt_no LIKE 'CYJF-2026-%'")->fetchColumn();
        $receiptNo = 'CYJF-2026-' . str_pad((string) ($maxCode + 1), 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('INSERT INTO tuition (receipt_no, student_id, semester, amount, paid_amount, status, paid_at, method, operator, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $receiptNo, $studentId, $semester, $amount, $paidAmount, $status,
            $paidAmount > 0 ? date('Y-m-d') : null,
            $paidAmount > 0 ? $method : null,
            current_operator(),
            $remark ?: null,
        ]);
        flash('success', '收费记录已创建：' . $studentName . ' · ' . $receiptNo . '。');
        redirect('/tuition.php?keyword=' . urlencode($studentName));
    }

    if ($action === 'pay') {
        $tuitionId = (int) input('tuition_id');
        $payAmount = (float) input('pay_amount', '0');
        $method = input('method', '网上银行');

        $stmt = $pdo->prepare('SELECT t.*, s.name AS student_name FROM tuition t JOIN students s ON s.id = t.student_id WHERE t.id = ?');
        $stmt->execute([$tuitionId]);
        $record = $stmt->fetch();

        if (!$record) {
            flash('error', '未找到对应的收费记录。');
            redirect('/tuition.php');
        }
        if ($payAmount <= 0) {
            flash('error', '补缴金额须大于 0。');
            redirect('/tuition.php?pay_id=' . $tuitionId);
        }

        $remaining = max(0.0, (float) $record['amount'] - (float) $record['paid_amount']);
        if ($payAmount > $remaining + 0.001) {
            flash('error', '补缴金额不能超过待缴余额 ¥ ' . money($remaining) . '。');
            redirect('/tuition.php?pay_id=' . $tuitionId);
        }

        $newPaid = (float) $record['paid_amount'] + $payAmount;
        $newStatus = $newPaid >= (float) $record['amount'] ? '已缴清' : '部分缴纳';
        $update = $pdo->prepare('UPDATE tuition SET paid_amount = ?, status = ?, paid_at = ?, method = ?, operator = ? WHERE id = ?');
        $update->execute([$newPaid, $newStatus, date('Y-m-d'), $method, current_operator(), $tuitionId]);
        flash('success', '缴费已登记：' . $record['student_name'] . ' · ' . $record['receipt_no'] . ' · ¥ ' . money($payAmount) . '。');
        redirect('/tuition.php?keyword=' . urlencode($record['student_name']));
    }
}

$showForm = q('action') === 'new';
$payId = q('pay_id');
$payRecord = null;
if ($payId !== '' && ctype_digit($payId)) {
    $payStmt = $pdo->prepare('SELECT t.*, s.name AS student_name, s.student_no, c.name AS class_name FROM tuition t JOIN students s ON s.id = t.student_id LEFT JOIN classes c ON c.id = s.class_id WHERE t.id = ?');
    $payStmt->execute([(int) $payId]);
    $payRecord = $payStmt->fetch();
}

$keyword = q('keyword');
$semesterFilter = q('semester', CURRENT_TERM);
$statusFilter = q('status');

$where = [];
$params = [];
if ($semesterFilter !== '') {
    $where[] = 't.semester = ?';
    $params[] = $semesterFilter;
}
if ($statusFilter !== '') {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.student_no LIKE ? OR t.receipt_no LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT t.*, s.name AS student_name, s.student_no, c.name AS class_name
    FROM tuition t
    JOIN students s ON s.id = t.student_id
    LEFT JOIN classes c ON c.id = s.class_id
    $whereSql
    ORDER BY t.semester DESC, t.receipt_no DESC
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$currentStmt = $pdo->prepare("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(paid_amount), 0) AS paid,
           SUM(CASE WHEN paid_amount < amount THEN 1 ELSE 0 END) AS outstanding_count
    FROM tuition WHERE semester = ?
");
$currentStmt->execute([CURRENT_TERM]);
$current = $currentStmt->fetch();
$collectionRate = (float) $current['amount'] > 0 ? (float) $current['paid'] / (float) $current['amount'] * 100 : 0.0;

$statusStmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount - paid_amount), 0) AS due FROM tuition WHERE semester = ? GROUP BY status');
$statusStmt->execute([CURRENT_TERM]);
$statusSummary = [];
foreach ($statusStmt->fetchAll() as $row) {
    $statusSummary[$row['status']] = $row;
}
$totalStatusCount = max(1, array_sum(array_map(static fn (array $row): int => (int) $row['cnt'], $statusSummary)));

$classStmt = $pdo->prepare("
    SELECT c.name, COALESCE(SUM(t.amount), 0) AS amount, COALESCE(SUM(t.paid_amount), 0) AS paid
    FROM tuition t JOIN students s ON s.id = t.student_id JOIN classes c ON c.id = s.class_id
    WHERE t.semester = ?
    GROUP BY c.id ORDER BY c.class_code
");
$classStmt->execute([CURRENT_TERM]);
$classCollection = $classStmt->fetchAll();

$studentOptions = $pdo->query("SELECT s.id, s.student_no, s.name, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.student_no")->fetchAll();

$pageTitle = '收费记录';
$active = 'tuition';
require __DIR__ . '/partials/header.php';
?>
<div class="page-head">
    <div>
        <h1>收费记录</h1>
        <p>管理学费应收、实缴与欠费情况，登记补缴并跟踪各班级收缴进度。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/reports.php">缴费报表</a>
        <a class="btn btn-primary" href="/tuition.php?action=new">新增收费记录</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card tone-indigo">
        <div class="stat-top"><span class="stat-label">本学期应收</span><span class="stat-icon"><?= icon_svg('tuition') ?></span></div>
        <div class="stat-value">¥ <?= money($current['amount']) ?></div>
        <div class="stat-foot">共 <?= (int) $current['cnt'] ?> 笔收费记录</div>
    </div>
    <div class="stat-card tone-lake">
        <div class="stat-top"><span class="stat-label">已收金额</span><span class="stat-icon"><?= icon_svg('reports') ?></span></div>
        <div class="stat-value">¥ <?= money($current['paid']) ?></div>
        <div class="stat-foot">收缴率 <strong style="color: var(--ink);"><?= number_format($collectionRate, 1) ?>%</strong></div>
    </div>
    <div class="stat-card tone-gold">
        <div class="stat-top"><span class="stat-label">待收金额</span><span class="stat-icon"><?= icon_svg('students') ?></span></div>
        <div class="stat-value">¥ <?= money((float) $current['amount'] - (float) $current['paid']) ?></div>
        <div class="stat-foot"><?= (int) $current['outstanding_count'] ?> 笔仍未缴清</div>
    </div>
    <div class="stat-card tone-rose">
        <div class="stat-top"><span class="stat-label">待缴费</span><span class="stat-icon"><?= icon_svg('bell') ?></span></div>
        <div class="stat-value"><?= (int) ($statusSummary['待缴费']['cnt'] ?? 0) ?><small>笔</small></div>
        <div class="stat-foot">缓缴 <?= (int) ($statusSummary['缓缴']['cnt'] ?? 0) ?> 笔 · 部分缴纳 <?= (int) ($statusSummary['部分缴纳']['cnt'] ?? 0) ?> 笔</div>
    </div>
</div>

<?php if ($showForm): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>新增收费记录</div>
                <div class="panel-sub">系统将根据实缴金额自动判定缴费状态</div>
            </div>
            <a class="panel-link" href="/tuition.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/tuition.php">
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
                        <label>学期</label>
                        <select name="semester">
                            <option value="2026-2027-1">2026-2027学年第一学期</option>
                            <option value="2026-2027-2">2026-2027学年第二学期</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>应缴金额<span class="required">*</span></label>
                        <input type="number" name="amount" step="0.01" min="0.01" placeholder="如 7800.00" required>
                    </div>
                    <div class="field">
                        <label>实缴金额</label>
                        <input type="number" name="paid_amount" step="0.01" min="0" value="0">
                    </div>
                    <div class="field">
                        <label>缴费方式</label>
                        <select name="method">
                            <option value="网上银行">网上银行</option>
                            <option value="手机银行">手机银行</option>
                            <option value="银行代扣">银行代扣</option>
                            <option value="线下缴费">线下缴费</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label>备注</label>
                        <input type="text" name="remark" placeholder="缴费说明、缓缴原因等（选填）">
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/tuition.php">取消</a>
                    <button class="btn btn-primary" type="submit">保存收费记录</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<?php if ($payRecord): ?>
    <section class="panel" style="margin-bottom: 18px;">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>登记补缴</div>
                <div class="panel-sub"><?= e($payRecord['student_name']) ?> · <?= e($payRecord['receipt_no']) ?> · 待缴 ¥ <?= money((float) $payRecord['amount'] - (float) $payRecord['paid_amount']) ?></div>
            </div>
            <a class="panel-link" href="/tuition.php">收起表单</a>
        </div>
        <div class="panel-body">
            <form method="post" action="/tuition.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="tuition_id" value="<?= (int) $payRecord['id'] ?>">
                <div class="form-grid">
                    <div class="field">
                        <label>学生</label>
                        <input type="text" value="<?= e($payRecord['student_name']) ?>（<?= e($payRecord['student_no']) ?>）" disabled>
                    </div>
                    <div class="field">
                        <label>班级</label>
                        <input type="text" value="<?= e($payRecord['class_name']) ?>" disabled>
                    </div>
                    <div class="field">
                        <label>补缴金额<span class="required">*</span></label>
                        <input type="number" name="pay_amount" step="0.01" min="0.01" max="<?= (float) $payRecord['amount'] - (float) $payRecord['paid_amount'] ?>" required>
                    </div>
                    <div class="field">
                        <label>缴费方式</label>
                        <select name="method">
                            <option value="网上银行">网上银行</option>
                            <option value="手机银行">手机银行</option>
                            <option value="银行代扣">银行代扣</option>
                            <option value="线下缴费">线下缴费</option>
                        </select>
                    </div>
                </div>
                <div class="form-actions">
                    <a class="btn btn-ghost" href="/tuition.php">取消</a>
                    <button class="btn btn-primary" type="submit">确认补缴</button>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<form class="filter-bar" method="get" action="/tuition.php">
    <div class="filter-field">
        <label>学期</label>
        <select name="semester">
            <?php foreach (['2026-2027-1', '2025-2026-2'] as $termOption): ?>
                <option value="<?= e($termOption) ?>"<?= $termOption === $semesterFilter ? ' selected' : '' ?>><?= e(semester_label($termOption)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label>缴费状态</label>
        <select name="status">
            <option value="">全部状态</option>
            <?php foreach (['已缴清', '部分缴纳', '待缴费', '缓缴'] as $statusOption): ?>
                <option value="<?= e($statusOption) ?>"<?= $statusOption === $statusFilter ? ' selected' : '' ?>><?= e($statusOption) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field grow">
        <label>关键字</label>
        <input type="search" name="keyword" value="<?= e($keyword) ?>" placeholder="学生姓名、学号或收据号">
    </div>
    <div class="filter-actions">
        <a class="btn btn-ghost" href="/tuition.php">重置</a>
        <button class="btn btn-primary" type="submit">查询</button>
    </div>
</form>

<div class="grid-2-1">
    <section class="panel">
        <div class="panel-head">
            <div>
                <div class="panel-title"><span class="dot"></span>收费明细</div>
                <div class="panel-sub">共 <?= count($records) ?> 笔记录</div>
            </div>
            <span class="chip"><?= e(semester_label($semesterFilter)) ?></span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>收据号</th>
                    <th>学生</th>
                    <th>班级</th>
                    <th class="t-right">应缴 / 实缴</th>
                    <th>状态</th>
                    <th>缴费日期</th>
                    <th>方式</th>
                    <th class="t-right">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <?php $due = max(0, (float) $record['amount'] - (float) $record['paid_amount']); ?>
                    <tr>
                        <td>
                            <div class="person-name"><?= e($record['receipt_no']) ?></div>
                            <span class="table-sub"><?= e(semester_label($record['semester'])) ?></span>
                        </td>
                        <td>
                            <div class="person-cell">
                                <span class="person-avatar gold"><?= e(initial_of($record['student_name'])) ?></span>
                                <div>
                                    <div class="person-name"><?= e($record['student_name']) ?></div>
                                    <span class="table-sub"><?= e($record['student_no']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($record['class_name']) ?></td>
                        <td class="t-right">
                            ¥ <?= money($record['amount']) ?>
                            <span class="table-sub">实缴 ¥ <?= money($record['paid_amount']) ?><?= $due > 0 ? ' · 待缴 ¥ ' . money($due) : '' ?></span>
                        </td>
                        <td><span class="badge <?= e(badge_class($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                        <td><?= e(fmt_date($record['paid_at'])) ?></td>
                        <td><?= e($record['method'] ?: '—') ?></td>
                        <td class="t-right">
                            <?php if ($due > 0): ?>
                                <a class="btn btn-soft btn-sm" href="/tuition.php?pay_id=<?= (int) $record['id'] ?>">补缴</a>
                            <?php else: ?>
                                <span class="muted small">已结清</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$records): ?>
                    <tr><td colspan="8" class="table-empty">没有符合条件的收费记录。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="panel-foot">
            <span>收费数据由财务处统一登记，退费与减免需另行审批</span>
            <span>当前筛选 <?= count($records) ?> 笔</span>
        </div>
    </section>

    <div>
        <section class="panel" style="margin-bottom: 18px;">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>收缴状态</div>
                    <div class="panel-sub"><?= e(semester_label(CURRENT_TERM)) ?></div>
                </div>
            </div>
            <div class="panel-body">
                <?php
                $statusTone = ['已缴清' => '', '部分缴纳' => 'gold', '待缴费' => 'rose', '缓缴' => 'indigo'];
                foreach (['已缴清', '部分缴纳', '待缴费', '缓缴'] as $statusName):
                    $row = $statusSummary[$statusName] ?? ['cnt' => 0, 'due' => 0];
                ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($statusName) ?></span>
                            <strong><?= (int) $row['cnt'] ?> 笔 · 待收 ¥ <?= money($row['due']) ?></strong>
                        </div>
                        <div class="progress"><div class="progress-fill <?= $statusTone[$statusName] ?>" style="width: <?= (int) $row['cnt'] / $totalStatusCount * 100 ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <div class="divider"></div>
                <div class="progress-row">
                    <div class="progress-meta"><span>本学期收缴率</span><strong><?= number_format($collectionRate, 1) ?>%</strong></div>
                    <div class="progress"><div class="progress-fill" style="width: <?= min(100, $collectionRate) ?>%"></div></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><span class="dot"></span>班级收缴进度</div>
                </div>
            </div>
            <div class="panel-body">
                <?php foreach ($classCollection as $row): ?>
                    <?php $rate = (float) $row['amount'] > 0 ? (float) $row['paid'] / (float) $row['amount'] * 100 : 0; ?>
                    <div class="progress-row">
                        <div class="progress-meta">
                            <span><?= e($row['name']) ?></span>
                            <strong><?= number_format($rate, 1) ?>%</strong>
                        </div>
                        <div class="progress"><div class="progress-fill indigo" style="width: <?= min(100, $rate) ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <div class="divider"></div>
                <div class="hint">缓缴学生需在 11 月 30 日前补齐证明材料，逾期按欠费流程处理。</div>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
