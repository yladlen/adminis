<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/navbar.php';
require_once '../../includes/top_navbar.php';

$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$servers = $pdo->query("SELECT id, name, inventory_number FROM devices WHERE type='Сервер' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Загружаем нерешённые проблемы — они автоматически попадают в новое посещение
$openIssues = $pdo->query("
    SELECT * FROM server_visit_issues WHERE resolved_at IS NULL ORDER BY reported_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$error = '';

$checks = [
    'check_servers'  => 'Серверы',
    'check_ups'      => 'ИБП',
    'check_switches' => 'Свитчи',
    'check_temp'     => 'Температура (19–24°C)',
    'check_cooling'  => 'Охлаждение/кондиционер',
    'check_power'    => 'Электропитание',
    'check_access'   => 'Доступ/замки',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if (!$room_id) {
        $error = "Выберите помещение.";
    } else {
        // Вставляем посещение
        $checkVals = [];
        $insertCols = ['room_id', 'comment'];
        $insertParams = [$room_id, $comment ?: null];

        foreach ($checks as $key => $_) {
            $insertCols[] = $key;
            $insertParams[] = isset($_POST[$key]) ? 1 : 0;
        }

        $placeholders = implode(',', array_fill(0, count($insertParams), '?'));
        $cols = implode(',', $insertCols);
        $pdo->prepare("INSERT INTO server_visits ($cols) VALUES ($placeholders)")
            ->execute($insertParams);
        $visitId = $pdo->lastInsertId();

        // Привязываем все открытые проблемы к этому посещению
        foreach ($openIssues as $issue) {
            $pdo->prepare("INSERT IGNORE INTO server_visit_issue_links (visit_id, issue_id) VALUES (?,?)")
                ->execute([$visitId, $issue['id']]);
        }

        // Добавляем новые проблемы из формы
        $newDevices  = $_POST['new_device']   ?? [];
        $newProblems = $_POST['new_problem']  ?? [];
        $newNotified = $_POST['new_notified'] ?? [];

        foreach ($newDevices as $i => $dev) {
            $dev = trim($dev);
            $prob = trim($newProblems[$i] ?? '');
            if ($dev === '' || $prob === '') continue;
            $notif = trim($newNotified[$i] ?? '');
            $pdo->prepare("INSERT INTO server_visit_issues (device_name, problem, notified) VALUES (?,?,?)")
                ->execute([$dev, $prob, $notif ?: null]);
            $newIssueId = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO server_visit_issue_links (visit_id, issue_id) VALUES (?,?)")
                ->execute([$visitId, $newIssueId]);
        }

        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавить посещение серверной</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        .check-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; margin-bottom:8px; }
        .check-item {
            display:flex; align-items:center; gap:10px;
            background:#f8f9ff; border:1px solid #e5e7ef; border-radius:8px;
            padding:10px 14px; cursor:pointer; transition:border-color .15s, background .15s;
            user-select:none;
        }
        .check-item:hover { border-color:#4f6ef7; }
        .check-item.checked { background:#f0fdf4; border-color:#86efac; }
        .check-item input[type=checkbox] { width:16px; height:16px; accent-color:#16a34a; flex-shrink:0; }
        .check-item label { font-size:13px; color:#1e2130; cursor:pointer; margin:0; }

        .issues-inherited { background:#fff5f5; border:1px solid #fca5a5; border-radius:10px; padding:16px 18px; margin-bottom:20px; }
        .issues-inherited-title { font-size:13px; font-weight:700; color:#dc2626; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
        .inherited-row { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #fde8e8; font-size:13px; }
        .inherited-row:last-child { border-bottom:none; padding-bottom:0; }

        .new-issues-block { margin-top:20px; }
        .issue-entry { background:#f8f9ff; border:1px solid #e5e7ef; border-radius:10px; padding:16px; margin-bottom:12px; position:relative; }
        .issue-entry .remove-btn {
            position:absolute; top:10px; right:12px;
            background:none; border:none; color:#dc2626; cursor:pointer; font-size:18px; line-height:1; padding:0;
        }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">
        <form method="post" id="main-form">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <div style="font-size:12px;color:#9ca3c4;margin-bottom:4px">
                        <a href="index.php" style="color:#9ca3c4;text-decoration:none">← Журнал серверной</a>
                    </div>
                    <h1 class="mb-0" style="text-align:left">Добавить посещение</h1>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-success">💾 Сохранить</button>
                    <a href="index.php" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Помещение -->
            <div class="row mb-3">
                <div class="col-md-5">
                    <label class="form-label">Помещение (серверная) <span style="color:#d63031">*</span></label>
                    <select name="room_id" class="form-select" required>
                        <option value="">— Выберите кабинет —</option>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= ($_POST['room_id']??'')==$r['id']?'selected':'' ?>>
                                <?= htmlspecialchars($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3" style="display:flex;align-items:flex-end">
                    <div style="background:#f0f2f5;border-radius:8px;padding:10px 16px;font-size:13px;color:#6b7499;width:100%">
                        🕐 Дата и время проставятся автоматически
                    </div>
                </div>
            </div>

            <!-- Чекбоксы проверок -->
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;margin-bottom:10px;display:block">Проверки — отметьте что в порядке</label>
                <div class="check-grid">
                    <?php foreach ($checks as $key => $label):
                        $checked = isset($_POST[$key]) || $_POST[$key]; // по умолчанию включены
                    ?>
                        <div class="check-item <?= $checked ? 'checked' : '' ?>" onclick="toggleCheck(this)">
                            <input type="checkbox" name="<?= $key ?>" id="<?= $key ?>" value="1" <?= $checked ? 'checked' : '' ?>>
                            <label for="<?= $key ?>"><?= $label ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Унаследованные проблемы -->
            <?php if (!empty($openIssues)): ?>
                <div class="issues-inherited">
                    <div class="issues-inherited-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Нерешённые проблемы (<?= count($openIssues) ?>) — автоматически перенесены в это посещение
                    </div>
                    <?php foreach ($openIssues as $oi): ?>
                        <div class="inherited-row">
                            <div style="flex:1">
                                <strong><?= htmlspecialchars($oi['device_name']) ?></strong>
                                <span style="color:#6b7499;margin-left:8px"><?= htmlspecialchars($oi['problem']) ?></span>
                                <?php if ($oi['notified']): ?>
                                    <span style="color:#9ca3c4;font-size:12px;margin-left:6px">— Сообщено: <?= htmlspecialchars($oi['notified']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:11px;color:#c4c9dd;white-space:nowrap"><?= date('d.m.Y', strtotime($oi['reported_at'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Новые проблемы -->
            <div class="new-issues-block">
                <label class="form-label" style="font-weight:600;margin-bottom:10px;display:block">
                    Новые проблемы
                    <span style="font-size:12px;font-weight:400;color:#9ca3c4"> — добавьте если что-то не в порядке</span>
                </label>
                <div id="issues-container"></div>
                <button type="button" class="btn btn-outline-success" style="font-size:13px" onclick="addIssue()">
                    ➕ Добавить проблему
                </button>
            </div>

            <!-- Комментарий -->
            <div class="mb-3" style="margin-top:20px">
                <label class="form-label">Общий комментарий</label>
                <textarea name="comment" class="form-control" rows="3"><?= htmlspecialchars($_POST['comment'] ?? '') ?></textarea>
            </div>
        </form>
    </div>
</div>
<script>
// Toggle check-item visual state
function toggleCheck(el) {
    const cb = el.querySelector('input[type=checkbox]');
    cb.checked = !cb.checked;
    el.classList.toggle('checked', cb.checked);
}
// Prevent double-toggle when clicking label/checkbox directly
document.querySelectorAll('.check-item input, .check-item label').forEach(el => {
    el.addEventListener('click', e => e.stopPropagation());
});
document.querySelectorAll('.check-item input[type=checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
        cb.closest('.check-item').classList.toggle('checked', cb.checked);
    });
});

let issueCount = 0;
const serverOptions = <?= json_encode(array_map(fn($s) => ['id' => $s['id'], 'label' => $s['name'] . ($s['inventory_number'] ? ' (' . $s['inventory_number'] . ')' : '')], $servers)) ?>;

function addIssue() {
    const i = issueCount++;
    const div = document.createElement('div');
    div.className = 'issue-entry';
    div.innerHTML = `
        <button type="button" class="remove-btn" onclick="this.closest('.issue-entry').remove()">×</button>
        <div class="row">
            <div class="col-md-4" style="margin-bottom:10px">
                <label class="form-label" style="font-size:13px">Устройство <span style="color:#d63031">*</span></label>
                <select name="new_device[]" class="form-select">
                    <option value="">— Выберите устройство —</option>
                    ${serverOptions.map(s => `<option value="${s.label}">${s.label}</option>`).join('')}
                </select>
            </div>
            <div class="col-md-8" style="margin-bottom:10px">
                <label class="form-label" style="font-size:13px">Описание проблемы <span style="color:#d63031">*</span></label>
                <input type="text" name="new_problem[]" class="form-control" placeholder="Что не так?">
            </div>
        </div>
        <div class="row">
            <div class="col-md-5">
                <label class="form-label" style="font-size:13px">Кому сообщили</label>
                <input type="text" name="new_notified[]" class="form-control" placeholder="ФИО или должность">
            </div>
        </div>
    `;
    document.getElementById('issues-container').appendChild(div);
}
</script>
</body>
</html>