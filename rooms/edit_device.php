<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) die("Некорректный ID устройства.");
$device_id = (int)$_GET['id'];

$from = $_GET['from'] ?? $_POST['from'] ?? 'room';
$back_url = match($from) {
    'notebooks' => '/adminis/notebooks/',
    'computers' => '/adminis/computers/',
    'servers'   => '/adminis/servers/',
    'printers'  => '/adminis/printers/',
    'ups'       => '/adminis/ups/',
    default     => null,
};

$stmt = $pdo->prepare("SELECT d.*, r.name AS room_name, r.id AS room_id FROM devices d JOIN rooms r ON d.room_id = r.id WHERE d.id = ?");
$stmt->execute([$device_id]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device) die("Устройство не найдено.");

// Состояние компонентов — вычисляется из открытых проблем
$issuesByComp = [];
$compIssuesStmt = $pdo->prepare("SELECT component FROM computer_issues WHERE device_id=? AND resolved_at IS NULL");
$compIssuesStmt->execute([$device_id]);
foreach ($compIssuesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['component']) $issuesByComp[$row['component']] = true;
}
$hasAnyOpenIssue = !empty($openIssues);

// Железо ПК
$hwStmt = $pdo->prepare("SELECT * FROM computer_hardware WHERE device_id=?");
$hwStmt->execute([$device_id]);
$hw = $hwStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// Открытые проблемы
$openIssues = $pdo->prepare("SELECT * FROM computer_issues WHERE device_id=? AND resolved_at IS NULL ORDER BY reported_at DESC");
$openIssues->execute([$device_id]);
$openIssues = $openIssues->fetchAll(PDO::FETCH_ASSOC);

// История
$history = $pdo->prepare("SELECT * FROM computer_history WHERE device_id=? ORDER BY created_at DESC LIMIT 50");
$history->execute([$device_id]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

// Switch link
$link = $pdo->prepare("SELECT s.connected_to_device_id, r.id AS connected_room_id FROM switch_links s JOIN devices d2 ON s.connected_to_device_id=d2.id JOIN rooms r ON d2.room_id=r.id WHERE s.device_id=?");
$link->execute([$device_id]);
$link_data         = $link->fetch(PDO::FETCH_ASSOC);
$connected_id      = $link_data['connected_to_device_id'] ?? null;
$connected_room_id = $link_data['connected_room_id'] ?? null;

$componentLabels = [
    'fan'         => 'Вентилятор',
    'hdd'         => 'Жёсткий диск',
    'psu'         => 'Блок питания',
    'ram'         => 'ОЗУ',
    'motherboard' => 'Материнская плата',
    'maintenance' => 'Необходимо ТО',
    'os_errors'   => 'Ошибки ОС',
    'sw_errors'   => 'Ошибки ПО',
    'other'       => 'Прочее',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // === Добавление проблемы ===
    if (isset($_POST['add_issue'])) {
        $comp = trim($_POST['issue_component'] ?? '');
        $desc = trim($_POST['issue_description'] ?? '');
        if ($desc !== '') {
            $pdo->prepare("INSERT INTO computer_issues (device_id, component, description) VALUES (?,?,?)")
                ->execute([$device_id, $comp ?: null, $desc]);
            $pdo->prepare("INSERT INTO computer_history (device_id, action, field_name, new_value) VALUES (?,?,?,?)")
                ->execute([$device_id, 'issue_opened', $comp ?: 'общее', $desc]);
        }
        header("Location: edit_device.php?id=$device_id&from=$from"); exit;
    }

    // === Закрытие проблемы ===
    if (isset($_POST['resolve_issue'])) {
        $issue_id  = (int)$_POST['issue_id'];
        $resolution = trim($_POST['resolution'] ?? '');
        $pdo->prepare("UPDATE computer_issues SET resolved_at=NOW(), resolution=? WHERE id=? AND device_id=?")
            ->execute([$resolution ?: null, $issue_id, $device_id]);
        // get description for history
        $iss = $pdo->prepare("SELECT component, description FROM computer_issues WHERE id=?");
        $iss->execute([$issue_id]);
        $issRow = $iss->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO computer_history (device_id, action, field_name, old_value, new_value) VALUES (?,?,?,?,?)")
            ->execute([$device_id, 'issue_closed', $issRow['component'] ?? 'общее', $issRow['description'], $resolution ?: 'Решение не указано']);
        header("Location: edit_device.php?id=$device_id&from=$from"); exit;
    }



    // === Основное сохранение ===
    if (isset($_POST['update'])) {
        $watchFields = ['name','type','ip','mac','inventory_number','status','comment'];
        $fieldLabels = ['name'=>'Название','type'=>'Тип','ip'=>'IP','mac'=>'MAC','inventory_number'=>'Инв. номер','status'=>'Статус','comment'=>'Комментарий'];

        $name      = trim($_POST['name'] ?? '');
        $type      = $_POST['type'] ?? '';
        $ip        = trim($_POST['ip'] ?? '');
        $mac       = trim($_POST['mac'] ?? '');
        $inventory = trim($_POST['inventory_number'] ?? '');
        $status    = $_POST['status'] ?? 'В работе';
        $comment   = trim($_POST['comment'] ?? '');
        $icon      = $_POST['icon'] ?? '';
        $new_connected_id = ($_POST['connected_to_device_id'] ?? '') !== '' ? (int)$_POST['connected_to_device_id'] : null;

        if ($name === '') { $error = "Название обязательно."; }
        else {
            $newVals = ['name'=>$name,'type'=>$type,'ip'=>$ip,'mac'=>$mac,'inventory_number'=>$inventory,'status'=>$status,'comment'=>$comment];
            foreach ($watchFields as $f) {
                $old = trim($device[$f] ?? '');
                $new = trim($newVals[$f] ?? '');
                if ($old !== $new) {
                    $action = ($f === 'status') ? 'status_changed' : 'field_changed';
                    $pdo->prepare("INSERT INTO computer_history (device_id, action, field_name, old_value, new_value) VALUES (?,?,?,?,?)")
                        ->execute([$device_id, $action, $fieldLabels[$f], $old, $new]);
                }
            }
            $pdo->prepare("UPDATE devices SET name=?,type=?,ip=?,mac=?,inventory_number=?,status=?,comment=?,icon=? WHERE id=?")
                ->execute([$name,$type,$ip,$mac,$inventory,$status,$comment,$icon,$device_id]);
            $pdo->prepare("DELETE FROM switch_links WHERE device_id=?")->execute([$device_id]);
            if ($new_connected_id !== null && !in_array($type, ['ИБП', 'Ноутбук']))
                $pdo->prepare("INSERT INTO switch_links (device_id,connected_to_device_id) VALUES (?,?)")->execute([$device_id,$new_connected_id]);
            // Сохраняем железо для ПК, Сервера и Ноутбука
            if (in_array($type, ['ПК', 'Сервер', 'Ноутбук'])) {
                $cpu = trim($_POST['hw_cpu'] ?? '');
                $ram = trim($_POST['hw_ram'] ?? '');
                $hdd = trim($_POST['hw_hdd'] ?? '');
                $gpu = trim($_POST['hw_gpu'] ?? '');
                $os  = trim($_POST['hw_os']  ?? '');
                $pdo->prepare("INSERT INTO computer_hardware (device_id,cpu,ram_gb,hdd_gb,gpu,os)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE cpu=VALUES(cpu),ram_gb=VALUES(ram_gb),hdd_gb=VALUES(hdd_gb),gpu=VALUES(gpu),os=VALUES(os)")
                    ->execute([$device_id,$cpu,$ram,$hdd,$gpu,$os]);
            }
            $_back = $back_url ?? ('room.php?id='.$device['room_id']);
            header("Location: $_back"); exit;
        }
    }

    if (isset($_POST['delete'])) {
        $pdo->prepare("DELETE FROM devices WHERE id=?")->execute([$device_id]);
        $_back = $back_url ?? ('room.php?id='.$device['room_id']);
        header("Location: $_back"); exit;
    }

    if (isset($_POST['duplicate'])) {
        $pdo->prepare("INSERT INTO devices (room_id,name,type,ip,mac,inventory_number,status,comment,icon) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$device['room_id'],$device['name'].' (копия)',$device['type'],$device['ip'],$device['mac'],$device['inventory_number'],$device['status'],$device['comment'],$device['icon']]);
        $new_id = $pdo->lastInsertId();
        if ($connected_id && !in_array($device['type'], ['ИБП', 'Ноутбук']))
            $pdo->prepare("INSERT INTO switch_links (device_id,connected_to_device_id) VALUES (?,?)")->execute([$new_id,$connected_id]);
        $pdo->prepare("INSERT INTO computer_history (device_id, action, note) VALUES (?,?,?)")
            ->execute([$new_id, 'created', 'Дублировано из устройства #'.$device_id]);
        header("Location: edit_device.php?id=$new_id&from=$from"); exit;
    }
}

$types    = ['ПК','Сервер','Принтер','Маршрутизатор','Свитч','Интерактивная доска','Ноутбук','ИБП','Прочее'];
$statuses = ['В работе','На ремонте','Списан','На хранении','Числится за кабинетом'];
$rooms    = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll();

$hasProblems = !empty($openIssues);

$historyActions = [
    'created'        => ['label'=>'Создана запись',      'color'=>'#4f6ef7'],
    'field_changed'  => ['label'=>'Изменено поле',       'color'=>'#f59e0b'],
    'status_changed' => ['label'=>'Изменён статус',      'color'=>'#8b5cf6'],
    'issue_opened'   => ['label'=>'Добавлена проблема',  'color'=>'#dc2626'],
    'issue_closed'   => ['label'=>'Проблема устранена',  'color'=>'#16a34a'],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Редактирование — <?= htmlspecialchars($device['name']) ?></title>
<link href="/adminis/includes/style.css" rel="stylesheet">
<style>
.edit-layout { display:grid; grid-template-columns:1fr 260px; gap:20px; align-items:start; }
@media(max-width:900px){ .edit-layout { grid-template-columns:1fr; } }

.side-panel { display:flex; flex-direction:column; gap:16px; }
.panel-box { background:#fff; border:1px solid #e5e7ef; border-radius:12px; padding:18px; }
.panel-box-title { font-size:13px; font-weight:700; color:#1e2130; margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; }

/* Компоненты */
.comp-list { display:flex; flex-direction:column; gap:6px; }
.comp-row { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:13px; }
.comp-label { color:#6b7499; flex:1; }
.comp-indicator {
    font-size:12px; font-weight:700; border-radius:5px; padding:2px 8px;
}
.comp-indicator.ok   { background:#f0fdf4; color:#16a34a; }
.comp-indicator.prob { background:#fee2e2; color:#dc2626; }

/* Проблема-кнопка */
.problem-badge {
    display:inline-flex; align-items:center; gap:5px;
    background:#fee2e2; color:#dc2626; border:1px solid #fca5a5;
    border-radius:6px; padding:4px 12px; font-size:12px; font-weight:700;
    cursor:pointer; transition:background .15s;
}
.problem-badge:hover { background:#fecaca; }

/* Открытые проблемы */
.issue-card { background:#fff5f5; border:1px solid #fca5a5; border-radius:9px; padding:12px 14px; margin-bottom:10px; }
.issue-card-comp { font-size:11px; font-weight:700; color:#dc2626; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.issue-card-desc { font-size:13px; color:#1e2130; }
.issue-card-date { font-size:11px; color:#c4c9dd; margin-top:4px; }
.btn-resolve-small {
    display:inline-flex; align-items:center; gap:4px; margin-top:8px;
    background:#f0fdf4; border:1px solid #86efac; color:#16a34a;
    border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; cursor:pointer; transition:background .15s;
}
.btn-resolve-small:hover { background:#dcfce7; }

/* История */
.history-list { display:flex; flex-direction:column; gap:0; }
.history-item { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid #f0f2f5; font-size:12px; }
.history-item:last-child { border-bottom:none; }
.history-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:4px; }
.history-action { font-weight:700; color:#1e2130; }
.history-meta { color:#9ca3c4; font-size:11px; margin-top:2px; }
.history-detail { color:#6b7499; font-size:12px; margin-top:2px; }

/* Статус-бейдж */
.status-badge { display:inline-block; border-radius:20px; padding:2px 10px; font-size:12px; font-weight:600; }

/* Модальное окно */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:440px; max-width:95vw; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-title { font-size:15px; font-weight:700; color:#1e2130; margin-bottom:18px; }
</style>
</head>
<body>
<div class="content-wrapper">
<div class="content-container">

<form method="post" id="main-form">
<input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-0" style="text-align:left">
            <?= htmlspecialchars($device['name']) ?>
            <span style="font-size:14px;font-weight:400;color:#6b7499">— <?= htmlspecialchars($device['room_name']) ?></span>
        </h1>
        <?php if ($hasProblems): ?>
            <div style="margin-top:6px">
                <span class="status-badge" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5">
                    ⚠ Есть проблемы
                </span>
            </div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2" style="flex-wrap:wrap">
        <!-- Кнопка проблема -->
        <button type="button" class="problem-badge" onclick="openIssueModal()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Проблема
        </button>
        <button type="submit" name="update" class="btn btn-outline-success">💾 Сохранить</button>
        <button type="submit" name="duplicate" class="btn btn-outline-success">📋 Дублировать</button>
        <button type="submit" name="delete" class="btn btn-outline-danger"
                onclick="return confirm('Удалить это устройство?')">🗑️ Удалить</button>
        <a href="<?= $back_url ?? ('room.php?id='.$device['room_id']) ?>" class="btn btn-outline-danger">🚫 Отмена</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php $simpleDevice = in_array($device['type'], ['Принтер','ИБП']); ?>
<div class="edit-layout" id="edit-layout" style="<?= $simpleDevice ? 'grid-template-columns:1fr' : '' ?>">
    <!-- Левая часть: основные поля -->
    <div>
        <div class="row">
            <div class="col-md-6">
                <label class="form-label">Название устройства</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($device['name']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Тип устройства</label>
                <select name="type" id="type-select" class="form-select" required>
                    <?php foreach ($types as $t): ?>
                        <option <?= $t===$device['type']?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-3" style="margin-top:16px">
            <label class="form-label">Иконка устройства</label>
            <div id="icon-container" class="icon-picker-container"><p class="text-muted">Загрузка...</p></div>
            <input type="hidden" name="icon" id="icon-input" value="<?= htmlspecialchars($device['icon']) ?>">
        </div>

        <div class="row">
            <div class="col-md-6">
                <label class="form-label">IP-адрес</label>
                <input type="text" name="ip" class="form-control" value="<?= htmlspecialchars($device['ip']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">MAC-адрес</label>
                <input type="text" name="mac" class="form-control" value="<?= htmlspecialchars($device['mac']) ?>">
            </div>
        </div>

        <div class="row" style="margin-top:16px">
            <div class="col-md-6">
                <label class="form-label">Инвентарный номер</label>
                <input type="text" name="inventory_number" class="form-control" value="<?= htmlspecialchars($device['inventory_number']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <?php foreach ($statuses as $s): ?>
                        <option <?= $s===$device['status']?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-3" style="margin-top:16px">
            <label class="form-label">Комментарий</label>
            <textarea name="comment" rows="3" class="form-control"><?= htmlspecialchars($device['comment']) ?></textarea>
        </div>

        <div class="row">
            <div class="col-md-6">
                <label class="form-label">Подключено к (кабинет)</label>
                <select id="room-select" name="room_select" class="form-select">
                    <option value="">— Не подключено —</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $r['id']==$connected_room_id?'selected':'' ?>>
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Устройство в кабинете</label>
                <select name="connected_to_device_id" id="device-select" class="form-select">
                    <option value="">— Сначала выберите кабинет —</option>
                </select>
            </div>
        </div>

        <!-- Открытые проблемы (под основными полями) -->
        <?php if (!empty($openIssues)): ?>
        <div style="margin-top:24px">
            <div style="font-size:14px;font-weight:700;color:#dc2626;margin-bottom:12px;display:flex;align-items:center;gap:6px">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Открытые проблемы (<?= count($openIssues) ?>)
            </div>
            <?php foreach ($openIssues as $iss): ?>
                <div class="issue-card">
                    <?php if ($iss['component']): ?>
                        <div class="issue-card-comp"><?= htmlspecialchars($iss['component']) ?></div>
                    <?php endif; ?>
                    <div class="issue-card-desc"><?= nl2br(htmlspecialchars($iss['description'])) ?></div>
                    <div class="issue-card-date"><?= date('d.m.Y H:i', strtotime($iss['reported_at'])) ?></div>
                    <button type="button" class="btn-resolve-small"
                            onclick="openResolveModal(<?= $iss['id'] ?>, '<?= addslashes(htmlspecialchars($iss['description'])) ?>')">
                        <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="#16a34a" stroke-width="2"><polyline points="1,6 4.5,9.5 11,2.5"/></svg>
                        Решение
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- История -->
        <div style="margin-top:24px">
            <div style="font-size:14px;font-weight:700;color:#1e2130;margin-bottom:12px">История изменений</div>
            <?php if (empty($history)): ?>
                <p style="font-size:13px;color:#9ca3c4">История пуста.</p>
            <?php else: ?>
                <div class="history-list">
                    <?php foreach ($history as $h):
                        $act = $historyActions[$h['action']] ?? ['label'=>$h['action'],'color'=>'#9ca3c4'];
                    ?>
                    <div class="history-item">
                        <div class="history-dot" style="background:<?= $act['color'] ?>"></div>
                        <div style="flex:1">
                            <div class="history-action"><?= $act['label'] ?>
                                <?php if ($h['field_name']): ?>
                                    <span style="font-weight:400;color:#6b7499"> — <?= htmlspecialchars($h['field_name']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($h['old_value'] !== null || $h['new_value'] !== null): ?>
                                <div class="history-detail">
                                    <?php if ($h['old_value'] !== null && $h['old_value'] !== ''): ?>
                                        <span style="color:#dc2626;text-decoration:line-through"><?= htmlspecialchars($h['old_value']) ?></span>
                                        →
                                    <?php endif; ?>
                                    <span style="color:#16a34a"><?= htmlspecialchars($h['new_value'] ?? '') ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if ($h['note']): ?>
                                <div class="history-detail" style="color:#9ca3c4"><?= htmlspecialchars($h['note']) ?></div>
                            <?php endif; ?>
                            <div class="history-meta"><?= date('d.m.Y H:i', strtotime($h['created_at'])) ?> — Администратор</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Правая панель: Состояние -->
    <div class="side-panel" id="side-panel" style="<?= $simpleDevice ? 'display:none' : '' ?>">
        <div class="panel-box" id="state-panel">
            <div class="panel-box-title">СОСТОЯНИЕ</div>
            <div class="comp-list">
                <?php foreach ($componentLabels as $key => $label):
                    $hasProblem = isset($issuesByComp[$label]);
                ?>
                    <div class="comp-row">
                        <span class="comp-label"><?= $label ?></span>
                        <?php if ($hasProblem): ?>
                            <span class="comp-indicator prob">⚠</span>
                        <?php else: ?>
                            <span class="comp-indicator ok">✓</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!empty($openIssues)): ?>
                    <div style="margin-top:8px;padding-top:8px;border-top:1px solid #f0f2f5;font-size:11px;color:#dc2626;font-weight:600">
                        Открытых проблем: <?= count($openIssues) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Железо ПК / Сервер / Ноутбук -->
        <?php $showHw = in_array($device['type'], ['ПК','Сервер','Ноутбук']); ?>
        <div class="panel-box" id="hw-panel" style="<?= $showHw ? '' : 'display:none' ?>">
            <div class="panel-box-title">ЖЕЛЕЗО</div>
                <div style="display:flex;flex-direction:column;gap:10px">
                    <div>
                        <label style="font-size:11px;color:#9ca3c4;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Процессор</label>
                        <input type="text" name="hw_cpu" class="form-control" style="margin-top:4px;font-size:13px"
                               value="<?= htmlspecialchars($hw['cpu'] ?? '') ?>" placeholder="Intel Core i5-10400">
                    </div>
                    <div>
                        <label style="font-size:11px;color:#9ca3c4;font-weight:600;text-transform:uppercase;letter-spacing:.5px">ОЗУ (ГБ)</label>
                        <input type="text" name="hw_ram" class="form-control" style="margin-top:4px;font-size:13px"
                               value="<?= htmlspecialchars($hw['ram_gb'] ?? '') ?>" placeholder="8">
                    </div>
                    <div>
                        <label style="font-size:11px;color:#9ca3c4;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Диск (ГБ)</label>
                        <input type="text" name="hw_hdd" class="form-control" style="margin-top:4px;font-size:13px"
                               value="<?= htmlspecialchars($hw['hdd_gb'] ?? '') ?>" placeholder="512 SSD">
                    </div>
                    <div id="hw-gpu-wrap-edit" style="<?= $device['type']==='Сервер' ? 'display:none' : '' ?>">
                        <label style="font-size:11px;color:#9ca3c4;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Видеокарта</label>
                        <input type="text" name="hw_gpu" class="form-control" style="margin-top:4px;font-size:13px"
                               value="<?= htmlspecialchars($hw['gpu'] ?? '') ?>" placeholder="Встроенная / GTX 1050">
                    </div>
                    <div>
                        <label style="font-size:11px;color:#9ca3c4;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Операционная система</label>
                        <input type="text" name="hw_os" class="form-control" style="margin-top:4px;font-size:13px"
                               value="<?= htmlspecialchars($hw['os'] ?? '') ?>" placeholder="Windows 10 Pro">
                    </div>
                </div>
        </div>

    </div>
</div>
</form>

<!-- Модалка: добавить проблему -->
<div class="modal-overlay" id="issue-modal">
    <div class="modal-box">
        <div class="modal-title">⚠ Добавить проблему</div>
        <form method="post">
            <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
            <?php if (!$simpleDevice): ?>
            <div class="mb-3">
                <label class="form-label">Компонент</label>
                <select name="issue_component" class="form-select">
                    <option value="">— Общее —</option>
                    <?php foreach ($componentLabels as $key => $label): ?>
                        <option><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="issue_component" value="Прочее">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">Описание проблемы <span style="color:#dc2626">*</span></label>
                <textarea name="issue_description" class="form-control" rows="3" required placeholder="Опишите проблему..."></textarea>
            </div>
            <div class="d-flex gap-2" style="justify-content:flex-end">
                <button type="button" class="btn btn-outline-danger" onclick="closeModal('issue-modal')">Отмена</button>
                <button type="submit" name="add_issue" class="btn btn-outline-success">Добавить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модалка: решить проблему -->
<div class="modal-overlay" id="resolve-modal">
    <div class="modal-box">
        <div class="modal-title">✓ Закрыть проблему</div>
        <div id="resolve-desc" style="font-size:13px;color:#6b7499;margin-bottom:16px;background:#f8f9ff;border-radius:8px;padding:10px 12px"></div>
        <form method="post">
            <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
            <input type="hidden" name="issue_id" id="resolve-issue-id">
            <div class="mb-3">
                <label class="form-label">Как решили (опционально)</label>
                <textarea name="resolution" class="form-control" rows="2" placeholder="Описание решения..."></textarea>
            </div>
            <div class="d-flex gap-2" style="justify-content:flex-end">
                <button type="button" class="btn btn-outline-danger" onclick="closeModal('resolve-modal')">Отмена</button>
                <button type="submit" name="resolve_issue" class="btn btn-outline-success">Подтвердить решение</button>
            </div>
        </form>
    </div>
</div>

</div>
</div>

<script>
// Icons
function loadIcons(type, selected='') {
    fetch('../load_icons.php?type=' + encodeURIComponent(type))
        .then(r=>r.text()).then(html=>{
            const c = document.getElementById('icon-container');
            c.innerHTML = html;
            document.querySelectorAll('.icon-option').forEach(img=>{
                img.addEventListener('click',()=>{
                    document.getElementById('icon-input').value = img.dataset.filename;
                    document.querySelectorAll('.icon-option').forEach(i=>i.style.border='2px solid transparent');
                    img.style.border='2px solid #4f6ef7';
                });
                if (img.dataset.filename === selected) img.style.border='2px solid #4f6ef7';
            });
        });
}
document.addEventListener('DOMContentLoaded', ()=>{
    loadIcons(document.getElementById('type-select').value, "<?= addslashes($device['icon']) ?>");
    const roomId = "<?= $connected_room_id ?>", selDev = "<?= $connected_id ?>";
    if (roomId) fetch('../load_devices_by_room.php?room_id='+roomId).then(r=>r.text()).then(html=>{
        const s = document.getElementById('device-select'); s.innerHTML=html; s.value=selDev;
    });
    toggleDeviceLink(document.getElementById('type-select').value);
});
function toggleDeviceLink(type) {
    const hideDeviceLink = ['ИБП','Ноутбук'].includes(type);
    document.getElementById('device-select').closest('.col-md-6').style.display = hideDeviceLink ? 'none' : '';
    const simpleDevice = ['Принтер','ИБП'].includes(type);
    const sidePanel  = document.getElementById('side-panel');
    const editLayout = document.getElementById('edit-layout');
    if (sidePanel)  sidePanel.style.display = simpleDevice ? 'none' : '';
    if (editLayout) editLayout.style.gridTemplateColumns = simpleDevice ? '1fr' : '';
}
document.getElementById('type-select').addEventListener('change', function(){
    loadIcons(this.value);
    const hw = document.getElementById('hw-panel');
    const gpu = document.getElementById('hw-gpu-wrap-edit');
    const show = ['ПК','Сервер','Ноутбук'].includes(this.value);
    hw.style.display  = show ? '' : 'none';
    gpu.style.display = (this.value === 'Сервер') ? 'none' : '';
    toggleDeviceLink(this.value);
});
document.getElementById('room-select').addEventListener('change', function(){
    const s = document.getElementById('device-select');
    if (!this.value) { s.innerHTML='<option value="">— Не подключено —</option>'; return; }
    s.innerHTML='<option>Загрузка...</option>';
    fetch('../load_devices_by_room.php?room_id='+this.value).then(r=>r.text()).then(html=>{
        s.innerHTML = '<option value="">— Не подключено —</option>' + (html || '');
    });
});

// Модалки
function openIssueModal() { document.getElementById('issue-modal').classList.add('open'); }
function openResolveModal(id, desc) {
    document.getElementById('resolve-issue-id').value = id;
    document.getElementById('resolve-desc').textContent = desc;
    document.getElementById('resolve-modal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m=>{
    m.addEventListener('click', e=>{ if(e.target===m) m.classList.remove('open'); });
});
</script>
</body>
</html>