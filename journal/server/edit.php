<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/navbar.php';
require_once '../../includes/top_navbar.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT sv.*, r.name AS room_name FROM server_visits sv JOIN rooms r ON r.id=sv.room_id WHERE sv.id=?");
$stmt->execute([$id]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$visit) die("Посещение не найдено.");

$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$servers = $pdo->query("SELECT id, name, inventory_number FROM devices WHERE type='Сервер' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Проблемы привязанные к этому посещению
$linkedIssues = $pdo->prepare("
    SELECT i.*, lnk.visit_id
    FROM server_visit_issues i
    JOIN server_visit_issue_links lnk ON lnk.issue_id = i.id
    WHERE lnk.visit_id = ?
    ORDER BY i.reported_at ASC
");
$linkedIssues->execute([$id]);
$linkedIssues = $linkedIssues->fetchAll(PDO::FETCH_ASSOC);

$checks = [
    'check_servers'  => 'Серверы',
    'check_ups'      => 'ИБП',
    'check_switches' => 'Свитчи',
    'check_temp'     => 'Температура (19–24°C)',
    'check_cooling'  => 'Охлаждение/кондиционер',
    'check_power'    => 'Электропитание',
    'check_access'   => 'Доступ/замки',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete'])) {
        $pdo->prepare("DELETE FROM server_visits WHERE id=?")->execute([$id]);
        header("Location: index.php"); exit;
    }

    $room_id = (int)($_POST['room_id'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if (!$room_id) { $error = "Выберите помещение."; }
    else {
        $sets = ['room_id=?', 'comment=?'];
        $params = [$room_id, $comment ?: null];
        foreach ($checks as $key => $_) {
            $sets[] = "$key=?";
            $params[] = isset($_POST[$key]) ? 1 : 0;
        }
        $params[] = $id;
        $pdo->prepare("UPDATE server_visits SET " . implode(',', $sets) . " WHERE id=?")->execute($params);

        // Новые проблемы
        $newDevices  = $_POST['new_device']   ?? [];
        $newProblems = $_POST['new_problem']  ?? [];
        $newNotified = $_POST['new_notified'] ?? [];
        foreach ($newDevices as $i => $dev) {
            $dev  = trim($dev);
            $prob = trim($newProblems[$i] ?? '');
            if ($dev===''||$prob==='') continue;
            $notif = trim($newNotified[$i] ?? '');
            $pdo->prepare("INSERT INTO server_visit_issues (device_name,problem,notified) VALUES (?,?,?)")
                ->execute([$dev, $prob, $notif ?: null]);
            $newIssueId = $pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO server_visit_issue_links (visit_id,issue_id) VALUES (?,?)")
                ->execute([$id, $newIssueId]);
        }
        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Посещение серверной — <?= date('d.m.Y H:i', strtotime($visit['visited_at'])) ?></title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        .check-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; margin-bottom:8px; }
        .check-item { display:flex;align-items:center;gap:10px;background:#f8f9ff;border:1px solid #e5e7ef;border-radius:8px;padding:10px 14px;cursor:pointer;transition:border-color .15s,background .15s;user-select:none; }
        .check-item:hover { border-color:#4f6ef7; }
        .check-item.checked { background:#f0fdf4;border-color:#86efac; }
        .check-item input[type=checkbox] { width:16px;height:16px;accent-color:#16a34a;flex-shrink:0; }
        .check-item label { font-size:13px;color:#1e2130;cursor:pointer;margin:0; }

        .issue-row-edit { display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-radius:8px;margin-bottom:8px;font-size:13px; }
        .issue-row-edit.open   { background:#fff5f5;border:1px solid #fca5a5; }
        .issue-row-edit.closed { background:#f0fdf4;border:1px solid #bbf7d0;opacity:.75; }

        .issue-entry { background:#f8f9ff;border:1px solid #e5e7ef;border-radius:10px;padding:16px;margin-bottom:12px;position:relative; }
        .issue-entry .remove-btn { position:absolute;top:10px;right:12px;background:none;border:none;color:#dc2626;cursor:pointer;font-size:18px;line-height:1;padding:0; }
        .btn-resolve { display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #86efac;color:#16a34a;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap;cursor:pointer;transition:background .15s; }
        .btn-resolve:hover { background:#dcfce7; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">
        <form method="post">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <div style="font-size:12px;color:#9ca3c4;margin-bottom:4px">
                        <a href="index.php" style="color:#9ca3c4;text-decoration:none">← Журнал серверной</a>
                    </div>
                    <h1 class="mb-0" style="text-align:left">
                        <?= htmlspecialchars($visit['room_name']) ?>
                        <span style="font-size:14px;font-weight:400;color:#6b7499">— <?= date('d.m.Y H:i', strtotime($visit['visited_at'])) ?></span>
                    </h1>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-success">💾 Сохранить</button>
                    <button type="submit" name="delete" class="btn btn-outline-danger"
                            onclick="return confirm('Удалить это посещение?')">🗑️ Удалить</button>
                    <a href="index.php" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="row mb-3">
                <div class="col-md-5">
                    <label class="form-label">Помещение <span style="color:#d63031">*</span></label>
                    <select name="room_id" class="form-select" required>
                        <?php foreach ($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= $visit['room_id']==$r['id']?'selected':'' ?>>
                                <?= htmlspecialchars($r['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Чекбоксы -->
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;margin-bottom:10px;display:block">Проверки</label>
                <div class="check-grid">
                    <?php foreach ($checks as $key => $label): ?>
                        <div class="check-item <?= $visit[$key] ? 'checked' : '' ?>" onclick="toggleCheck(this)">
                            <input type="checkbox" name="<?= $key ?>" id="<?= $key ?>" value="1" <?= $visit[$key]?'checked':'' ?>>
                            <label for="<?= $key ?>"><?= $label ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Проблемы привязанные к посещению -->
            <?php if (!empty($linkedIssues)): ?>
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;margin-bottom:10px;display:block">Проблемы в этом посещении</label>
                <?php foreach ($linkedIssues as $iss): ?>
                    <div class="issue-row-edit <?= $iss['resolved_at'] ? 'closed' : 'open' ?>">
                        <div style="flex:1">
                            <div style="font-weight:700"><?= htmlspecialchars($iss['device_name']) ?></div>
                            <div style="color:#6b7499;margin-top:2px"><?= nl2br(htmlspecialchars($iss['problem'])) ?></div>
                            <?php if ($iss['notified']): ?>
                                <div style="font-size:12px;color:#9ca3c4;margin-top:3px">Сообщено: <?= htmlspecialchars($iss['notified']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:120px">
                            <div style="font-size:11px;color:#c4c9dd"><?= date('d.m.Y', strtotime($iss['reported_at'])) ?></div>
                            <?php if ($iss['resolved_at']): ?>
                                <span style="font-size:12px;color:#16a34a;font-weight:600">✓ Устранено <?= date('d.m.Y', strtotime($iss['resolved_at'])) ?></span>
                            <?php else: ?>
                                <form method="post" action="resolve_issue.php" style="margin:0">
                                    <input type="hidden" name="id" value="<?= $iss['id'] ?>">
                                    <input type="hidden" name="redirect" value="edit.php?id=<?= $id ?>">
                                    <button type="submit" class="btn-resolve"
                                            onclick="return confirm('Подтвердить устранение проблемы?')">
                                        <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="#16a34a" stroke-width="2"><polyline points="1,6 4.5,9.5 11,2.5"/></svg>
                                        Устранено
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Новые проблемы -->
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;margin-bottom:10px;display:block">Добавить новую проблему</label>
                <div id="issues-container"></div>
                <button type="button" class="btn btn-outline-success" style="font-size:13px" onclick="addIssue()">
                    ➕ Добавить проблему
                </button>
            </div>

            <!-- Комментарий -->
            <div class="mb-3" style="margin-top:8px">
                <label class="form-label">Комментарий</label>
                <textarea name="comment" class="form-control" rows="3"><?= htmlspecialchars($visit['comment'] ?? '') ?></textarea>
            </div>
        </form>
    </div>
</div>
<script>
function toggleCheck(el) {
    const cb = el.querySelector('input[type=checkbox]');
    cb.checked = !cb.checked;
    el.classList.toggle('checked', cb.checked);
}
document.querySelectorAll('.check-item input, .check-item label').forEach(el => {
    el.addEventListener('click', e => e.stopPropagation());
});
document.querySelectorAll('.check-item input[type=checkbox]').forEach(cb => {
    cb.addEventListener('change', () => cb.closest('.check-item').classList.toggle('checked', cb.checked));
});

const serverOptions = <?= json_encode(array_map(fn($s) => ['id' => $s['id'], 'label' => $s['name'] . ($s['inventory_number'] ? ' (' . $s['inventory_number'] . ')' : '')], $servers)) ?>;

function addIssue() {
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