<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) die("Некорректный ID.");
$device_id = (int)$_GET['id'];

$from = $_GET['from'] ?? $_POST['from'] ?? 'servers';
$back_url = '/adminis/switch/';
// Если открыто из кабинета — возвращаемся в него после сохранения
$_room_id_for_back = isset($_GET['id']) && is_numeric($_GET['id'])
    ? (int)(($pdo->query('SELECT room_id FROM devices WHERE id=' . (int)$_GET['id'])->fetchColumn()) ?: 0)
    : 0;
if ($from === 'room' && $_room_id_for_back) {
    $back_url = '/adminis/rooms/room.php?id=' . $_room_id_for_back;
}
if ($from === 'map') {
    $back_url = '/adminis/map/';
}

// Загружаем устройство
$stmt = $pdo->prepare("SELECT d.*, r.name AS room_name FROM devices d JOIN rooms r ON d.room_id=r.id WHERE d.id=? AND d.type='Коммутатор'");
$stmt->execute([$device_id]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$device) die("Коммутатор не найден.");

// Железо
$hw = $pdo->prepare("SELECT * FROM switch_hardware WHERE device_id=?");
$hw->execute([$device_id]);
$hw = $hw->fetch(PDO::FETCH_ASSOC) ?: [];

// Подключённые устройства (те кто воткнут в этот коммутатор)
$connectedDevices = $pdo->prepare("
    SELECT d.id, d.name, d.type, d.ip, d.status, d.icon, r.name AS room_name,
           sl.port_number
    FROM switch_links sl
    JOIN devices d ON sl.device_id = d.id
    LEFT JOIN rooms r ON d.room_id = r.id
    WHERE sl.connected_to_device_id = ?
    ORDER BY sl.port_number IS NULL, sl.port_number, d.name
");
$connectedDevices->execute([$device_id]);
$connectedDevices = $connectedDevices->fetchAll(PDO::FETCH_ASSOC);

// Открытые проблемы
$issuesByComp = [];
$stmt2 = $pdo->prepare("SELECT component FROM switch_issues WHERE device_id=? AND resolved_at IS NULL");
$stmt2->execute([$device_id]);
foreach ($stmt2->fetchAll() as $row) if ($row['component']) $issuesByComp[$row['component']] = true;

$openIssues = $pdo->prepare("SELECT * FROM switch_issues WHERE device_id=? AND resolved_at IS NULL ORDER BY reported_at DESC");
$openIssues->execute([$device_id]);
$openIssues = $openIssues->fetchAll(PDO::FETCH_ASSOC);

// История
$history = $pdo->prepare("SELECT * FROM switch_history WHERE device_id=? ORDER BY created_at DESC LIMIT 50");
$history->execute([$device_id]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll();
$statuses = ['В работе','На ремонте','Списан','На хранении','Числится за кабинетом'];
$error = '';

$componentLabels = [
    'ports'     => 'Порты',
    'power'     => 'Питание',
    'firmware'  => 'Прошивка',
    'uplink'    => 'Аплинк',
    'other'     => 'Прочее',
];
$historyActions = [
    'created'        => ['label'=>'Создана запись',      'color'=>'#4f6ef7'],
    'field_changed'  => ['label'=>'Изменено поле',       'color'=>'#f59e0b'],
    'status_changed' => ['label'=>'Изменён статус',      'color'=>'#8b5cf6'],
    'issue_opened'   => ['label'=>'Добавлена проблема',  'color'=>'#dc2626'],
    'issue_closed'   => ['label'=>'Проблема устранена',  'color'=>'#16a34a'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Добавить проблему
    if (isset($_POST['add_issue'])) {
        $comp = trim($_POST['issue_component'] ?? '');
        $desc = trim($_POST['issue_description'] ?? '');
        if ($desc !== '') {
            $pdo->prepare("INSERT INTO switch_issues (device_id,component,description) VALUES (?,?,?)")
                ->execute([$device_id, $comp ?: null, $desc]);
            $pdo->prepare("INSERT INTO switch_history (device_id,action,field_name,new_value) VALUES (?,?,?,?)")
                ->execute([$device_id, 'issue_opened', $comp ?: 'общее', $desc]);
        }
        header("Location: edit.php?id=$device_id&from=$from"); exit;
    }

    // Закрыть проблему
    if (isset($_POST['resolve_issue'])) {
        $issue_id   = (int)$_POST['issue_id'];
        $resolution = trim($_POST['resolution'] ?? '');
        $pdo->prepare("UPDATE switch_issues SET resolved_at=NOW(),resolution=? WHERE id=? AND device_id=?")
            ->execute([$resolution ?: null, $issue_id, $device_id]);
        $iss = $pdo->prepare("SELECT component,description FROM switch_issues WHERE id=?");
        $iss->execute([$issue_id]);
        $issRow = $iss->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO switch_history (device_id,action,field_name,old_value,new_value) VALUES (?,?,?,?,?)")
            ->execute([$device_id,'issue_closed',$issRow['component']??'общее',$issRow['description'],$resolution?:'Решение не указано']);
        header("Location: edit.php?id=$device_id&from=$from"); exit;
    }

    // Сохранить
    if (isset($_POST['update'])) {
        $name      = trim($_POST['name'] ?? '');
        $ip        = trim($_POST['ip'] ?? '');
        $mac       = trim($_POST['mac'] ?? '');
        $inventory = trim($_POST['inventory_number'] ?? '');
        $status    = $_POST['status'] ?? 'В работе';
        $comment   = trim($_POST['comment'] ?? '');
        $icon      = $_POST['icon'] ?? '';
        $room_id   = (int)($_POST['room_id'] ?? $device['room_id']);
        $connected_new = null; // коммутатор сам является целью подключений
        $writeoff  = isset($_POST['recommended_for_writeoff']) ? 1 : 0;
        $writeoff_date = ($writeoff && empty($device['recommended_for_writeoff']))
            ? date('Y-m-d')
            : ($writeoff ? ($device['writeoff_recommended_at'] ?? date('Y-m-d')) : null);

        if ($name === '') { $error = 'Название обязательно.'; }
        else {
            // Собираем все изменения в один список
            $changes = [];

            // Поля устройства
            $watchFields = ['name','ip','mac','inventory_number','status','comment'];
            $fieldLabels = ['name'=>'Название','ip'=>'IP','mac'=>'MAC','inventory_number'=>'Инв. номер','status'=>'Статус','comment'=>'Комментарий'];
            $newVals = ['name'=>$name,'ip'=>$ip,'mac'=>$mac,'inventory_number'=>$inventory,'status'=>$status,'comment'=>$comment];
            foreach ($watchFields as $f) {
                $old = trim($device[$f] ?? '');
                $new = trim($newVals[$f] ?? '');
                if ($old !== $new)
                    $changes[] = ['label' => $fieldLabels[$f], 'old' => $old, 'new' => $new];
            }

            if ((int)($device['recommended_for_writeoff'] ?? 0) !== $writeoff)
                $changes[] = ['label' => 'Рекомендовано к списанию', 'old' => $writeoff ? '' : '✓', 'new' => $writeoff ? '✓' : ''];

            // Поля железа (сравниваем с текущим $hw)
            $hwFields = [
                'hw_device_type'  => ['key'=>'device_type',     'label'=>'Тип устройства'],
                'hw_ports'        => ['key'=>'ports',           'label'=>'Количество портов'],
                'hw_port_speed'   => ['key'=>'port_speed',      'label'=>'Скорость портов'],
                'hw_managed'      => ['key'=>'managed',         'label'=>'Управляемый'],
                'hw_manufacturer' => ['key'=>'manufacturer',     'label'=>'Производитель'],
                'hw_model'        => ['key'=>'model',            'label'=>'Модель'],
                'hw_serial'       => ['key'=>'serial_number',    'label'=>'Серийный номер'],
                'hw_year'         => ['key'=>'year_manufactured','label'=>'Год производства'],
                'hw_commissioned' => ['key'=>'commissioned_at',  'label'=>'Ввод в эксплуатацию'],
                'hw_warranty'     => ['key'=>'warranty_until',   'label'=>'Гарантия до'],
                'hw_floor'        => ['key'=>'floor',            'label'=>'Этаж'],
            ];
            foreach ($hwFields as $post => $info) {
                $old = trim($hw[$info['key']] ?? '');
                $new = trim($_POST[$post] ?? '');
                if ($old !== $new)
                    $changes[] = ['label' => $info['label'], 'old' => $old, 'new' => $new];
            }

            // Записываем одной строкой в историю если есть изменения
            if (!empty($changes)) {
                $parts = array_map(fn($c) =>
                    $c['label'] . ($c['old'] !== '' ? ': «'.$c['old'].'» → «'.$c['new'].'»' : ': «'.$c['new'].'»'),
                    $changes
                );
                $action = count($changes) === 1 && $changes[0]['label'] === 'Статус' ? 'status_changed' : 'field_changed';
                $fieldName = count($changes) === 1 ? $changes[0]['label'] : null;
                $oldVal    = count($changes) === 1 ? $changes[0]['old'] : null;
                $newVal    = count($changes) === 1 ? $changes[0]['new'] : null;
                $note      = count($changes) > 1 ? implode('; ', $parts) : null;
                $pdo->prepare("INSERT INTO switch_history (device_id,action,field_name,old_value,new_value,note) VALUES (?,?,?,?,?,?)")
                    ->execute([$device_id, $action, $fieldName, $oldVal, $newVal, $note]);
            }

            $pdo->prepare("UPDATE devices SET name=?,ip=?,mac=?,inventory_number=?,status=?,comment=?,icon=?,room_id=?,recommended_for_writeoff=?,writeoff_recommended_at=? WHERE id=?")
                ->execute([$name,$ip,$mac,$inventory,$status,$comment,$icon,$room_id,$writeoff,$writeoff_date,$device_id]);

            // Характеристики коммутатора
            $hwData = [
                trim($_POST['hw_device_type']  ?? ''),
                trim($_POST['hw_ports']        ?? '') ?: null,
                trim($_POST['hw_port_speed']   ?? ''),
                trim($_POST['hw_managed']      ?? '0'),
                trim($_POST['hw_manufacturer'] ?? ''),
                trim($_POST['hw_model']        ?? ''),
                trim($_POST['hw_serial']       ?? ''),
                trim($_POST['hw_year']         ?? '') ?: null,
                trim($_POST['hw_commissioned'] ?? '') ?: null,
                trim($_POST['hw_warranty']     ?? '') ?: null,
                trim($_POST['hw_floor']        ?? ''),
            ];
            $pdo->prepare("INSERT INTO switch_hardware
                (device_id,device_type,ports,port_speed,managed,manufacturer,model,serial_number,year_manufactured,commissioned_at,warranty_until,floor)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                device_type=VALUES(device_type),ports=VALUES(ports),port_speed=VALUES(port_speed),managed=VALUES(managed),
                manufacturer=VALUES(manufacturer),model=VALUES(model),serial_number=VALUES(serial_number),
                year_manufactured=VALUES(year_manufactured),commissioned_at=VALUES(commissioned_at),
                warranty_until=VALUES(warranty_until),floor=VALUES(floor)")
                ->execute(array_merge([$device_id], $hwData));

            header("Location: $back_url"); exit;
        }
    }

    if (isset($_POST['delete'])) {
        $pdo->prepare("DELETE FROM devices WHERE id=?")->execute([$device_id]);
        header("Location: $back_url"); exit;
    }

    if (isset($_POST['duplicate'])) {
        $pdo->prepare("INSERT INTO devices (room_id,name,type,ip,mac,inventory_number,status,comment,icon) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$device['room_id'],$device['name'].' (копия)','Коммутатор',$device['ip'],$device['mac'],$device['inventory_number'],$device['status'],$device['comment'],$device['icon']]);
        $new_id = $pdo->lastInsertId();
        if ($hw) {
            unset($hw['id'], $hw['device_id']);
            $keys = implode(',', array_keys($hw));
            $vals = implode(',', array_fill(0, count($hw), '?'));
            $pdo->prepare("INSERT INTO switch_hardware (device_id,$keys) VALUES (?,$vals)")
                ->execute(array_merge([$new_id], array_values($hw)));
        }
        $pdo->prepare("INSERT INTO switch_history (device_id,action,note) VALUES (?,?,?)")
            ->execute([$new_id,'created','Дублировано из #'.$device_id]);
        header("Location: edit.php?id=$new_id&from=$from"); exit;
    }
}

// Проверяем гарантию
$warrantyExpired = false;
$warrantyDaysLeft = null;
if (!empty($hw['warranty_until'])) {
    $wDate = new DateTime($hw['warranty_until']);
    $now   = new DateTime();
    $diff  = $now->diff($wDate);
    $warrantyExpired = $now > $wDate;
    $warrantyDaysLeft = $warrantyExpired ? -$diff->days : $diff->days;
}
?>
<?php
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Редактирование — <?= htmlspecialchars($device['name']) ?></title>
<link href="/adminis/includes/style.css" rel="stylesheet">
<style>
.edit-layout { display:grid; grid-template-columns:1fr 280px; gap:20px; align-items:start; }
@media(max-width:900px){ .edit-layout { grid-template-columns:1fr; } }
.side-panel { display:flex; flex-direction:column; gap:16px; position:sticky; top:70px; }
.panel-box { background:#fff; border:1px solid #e5e7ef; border-radius:12px; padding:18px; }
.panel-box-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:#9ca3c4; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f0f2f5; }
.section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3c4; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #f0f2f5; }
.form-section { background:#fff; border:1px solid #e5e7ef; border-radius:12px; padding:20px 24px; margin-bottom:16px; }
.comp-list { display:flex; flex-direction:column; gap:6px; }
.comp-row { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:13px; }
.comp-label { color:#6b7499; flex:1; }
.comp-indicator { font-size:12px; font-weight:700; border-radius:5px; padding:2px 8px; }
.comp-indicator.ok   { background:#f0fdf4; color:#16a34a; }
.comp-indicator.prob { background:#fee2e2; color:#dc2626; }
.problem-badge { display:inline-flex; align-items:center; gap:5px; background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; border-radius:6px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s; }
.problem-badge:hover { background:#fecaca; }
.issue-card { background:#fff5f5; border:1px solid #fca5a5; border-radius:9px; padding:12px 14px; margin-bottom:10px; }
.issue-card-comp { font-size:11px; font-weight:700; color:#dc2626; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.issue-card-desc { font-size:13px; color:#1e2130; }
.issue-card-date { font-size:11px; color:#c4c9dd; margin-top:4px; }
.btn-resolve-small { display:inline-flex; align-items:center; gap:4px; margin-top:8px; background:#f0fdf4; border:1px solid #86efac; color:#16a34a; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; cursor:pointer; transition:background .15s; }
.btn-resolve-small:hover { background:#dcfce7; }
.history-list { display:flex; flex-direction:column; }
.history-item { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid #f0f2f5; font-size:12px; }
.history-item:last-child { border-bottom:none; }
.history-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:4px; }
.history-action { font-weight:700; color:#1e2130; }
.history-meta { color:#9ca3c4; font-size:11px; margin-top:2px; }
.history-detail { color:#6b7499; font-size:12px; margin-top:2px; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:440px; max-width:95vw; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-title { font-size:15px; font-weight:700; color:#1e2130; margin-bottom:18px; }
.passport-row { display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid #f5f6fa; font-size:13px; }
.passport-row:last-child { border-bottom:none; }
.passport-label { color:#9ca3c4; }
.passport-value { color:#1e2130; font-weight:500; text-align:right; }
.warranty-ok      { color:#16a34a; font-weight:600; }
.warranty-expired { color:#dc2626; font-weight:600; }
.warranty-soon    { color:#f59e0b; font-weight:600; }
</style>
</head>
<body>
<div class="content-wrapper">
<div class="content-container">

<form method="post" id="main-form">
<input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">

<div class="d-flex justify-content-between align-items-center mb-4" style="gap:16px;flex-wrap:wrap">
    <div style="min-width:0">
        <h1 class="mb-0" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            <?= htmlspecialchars($device['name']) ?>
            <span style="font-size:14px;font-weight:400;color:#6b7499">— <?= htmlspecialchars($device['room_name']) ?></span>
        </h1>
        <?php if (!empty($openIssues)): ?>
            <div style="margin-top:4px">
                <span style="display:inline-block;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:600">
                    ⚠ Открытых проблем: <?= count($openIssues) ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap">
        <button type="button" class="problem-badge" onclick="openIssueModal()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Проблема
        </button>
        <label style="display:inline-flex;align-items:center;gap:7px;cursor:pointer;padding:6px 12px;border:1px solid <?= $device['recommended_for_writeoff'] ? '#fca5a5' : '#dee2e6' ?>;border-radius:6px;background:<?= $device['recommended_for_writeoff'] ? '#fff5f5' : '#f9fafb' ?>;white-space:nowrap;transition:all .15s" id="writeoff-label">
            <input type="checkbox" name="recommended_for_writeoff" id="writeoff-cb" value="1"
                   <?= $device['recommended_for_writeoff'] ? 'checked' : '' ?>
                   style="width:15px;height:15px;accent-color:#dc2626;cursor:pointer;flex-shrink:0">
            <span style="font-size:13px;color:<?= $device['recommended_for_writeoff'] ? '#dc2626' : '#374151' ?>">К списанию</span>
            <?php if ($device['recommended_for_writeoff'] && $device['writeoff_recommended_at']): ?>
                <span style="font-size:11px;color:#9ca3c4"><?= date('d.m.Y', strtotime($device['writeoff_recommended_at'])) ?></span>
            <?php endif; ?>
        </label>
        <button type="submit" name="delete" class="btn btn-outline-danger" onclick="return confirm('Удалить коммутатор?')">🗑️ Удалить</button>
        <button type="submit" name="duplicate" class="btn btn-outline-success" onclick="return confirm('Дублировать коммутатор?')">📋 Дублировать</button>
        <div style="width:1px;height:28px;background:#e5e7ef;margin:0 8px;flex-shrink:0"></div>
        <button type="submit" name="update" class="btn btn-outline-success">💾 Сохранить</button>
        <a href="<?= $back_url ?>" class="btn btn-outline-danger">🚫 Отмена</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="edit-layout">
<!-- ── ЛЕВАЯ ЧАСТЬ ── -->
<div>

<!-- Паспорт -->
<div class="form-section">
    <div class="section-title">Паспорт</div>
    <div class="row">
        <!-- Строка 1 -->
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Название</label>
            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($device['name']) ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Кабинет</label>
            <select name="room_id" class="form-select">
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $r['id']==$device['room_id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Этаж</label>
            <input type="text" name="hw_floor" class="form-control" placeholder="1, 2..." value="<?= htmlspecialchars($hw['floor'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select">
                <?php foreach ($statuses as $s): ?>
                    <option <?= $s===$device['status']?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Инвентарный номер</label>
            <input type="text" name="inventory_number" class="form-control" value="<?= htmlspecialchars($device['inventory_number']) ?>">
        </div>
        <!-- Строка 2 -->
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">IP-адрес</label>
            <input type="text" name="ip" class="form-control" value="<?= htmlspecialchars($device['ip']) ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">MAC-адрес</label>
            <input type="text" name="mac" class="form-control" value="<?= htmlspecialchars($device['mac']) ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Производитель</label>
            <input type="text" name="hw_manufacturer" class="form-control" placeholder="Cisco, TP-Link, Zyxel..." value="<?= htmlspecialchars($hw['manufacturer'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Модель</label>
            <input type="text" name="hw_model" class="form-control" placeholder="SG350-28..." value="<?= htmlspecialchars($hw['model'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Серийный номер</label>
            <input type="text" name="hw_serial" class="form-control" placeholder="PF1AB2C3..." value="<?= htmlspecialchars($hw['serial_number'] ?? '') ?>">
        </div>
        <!-- Строка 3 -->
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Год производства</label>
            <input type="number" name="hw_year" class="form-control" placeholder="2021" min="1990" max="2099" value="<?= htmlspecialchars($hw['year_manufactured'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Ввод в эксплуатацию</label>
            <input type="date" name="hw_commissioned" class="form-control" value="<?= htmlspecialchars($hw['commissioned_at'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Гарантия до</label>
            <input type="date" name="hw_warranty" class="form-control" value="<?= htmlspecialchars($hw['warranty_until'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Тип устройства</label>
            <select name="hw_device_type" class="form-select">
                <option value="">— не указан —</option>
                <?php foreach (['Коммутатор','Маршрутизатор','Точка доступа','Межсетевой экран','Медиаконвертер','Прочее'] as $dt): ?>
                    <option <?= ($hw['device_type']??'')===$dt?'selected':'' ?>><?= $dt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Скорость портов</label>
            <select name="hw_port_speed" class="form-select">
                <option value="">— не указана —</option>
                <?php foreach (['10 Мбит/с','100 Мбит/с','1 Гбит/с','2.5 Гбит/с','10 Гбит/с','Смешанная'] as $ps): ?>
                    <option <?= ($hw['port_speed']??'')===$ps?'selected':'' ?>><?= $ps ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Строка 4 -->
        <div class="col-md-5" style="margin-bottom:0">
            <label class="form-label">Кол-во портов</label>
            <input type="number" name="hw_ports" class="form-control" placeholder="24" min="1" value="<?= htmlspecialchars($hw['ports'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:0;display:flex;align-items:flex-end">
            <div style="width:100%">
                <label class="form-label" style="display:block">Управляемый</label>
                <select name="hw_managed" class="form-select">
                    <option value="0" <?= empty($hw['managed'])?'selected':'' ?>>Нет</option>
                    <option value="1" <?= !empty($hw['managed'])?'selected':'' ?>>Да</option>
                </select>
            </div>
        </div>
        <div class="col-md-10" style="margin-bottom:0">
            <label class="form-label">Комментарий</label>
            <textarea name="comment" rows="1" class="form-control" style="min-height:0;height:36px;resize:vertical"><?= htmlspecialchars($device['comment']) ?></textarea>
        </div>
    </div>
</div>

<!-- Иконка -->
<div class="form-section">
    <div class="section-title">Иконка</div>
    <div id="icon-container" class="icon-picker-container"><p class="text-muted">Загрузка...</p></div>
    <input type="hidden" name="icon" id="icon-input" value="<?= htmlspecialchars($device['icon']) ?>">
</div>

<!-- Подключённые устройства -->
<div class="form-section">
    <div class="section-title">Подключённые устройства (<?= count($connectedDevices) ?>)</div>
    <?php if (empty($connectedDevices)): ?>
        <p style="font-size:13px;color:#9ca3c4">Нет подключённых устройств.</p>
    <?php else: ?>
    <table style="width:100%;font-size:13px;border-collapse:collapse">
        <thead>
            <tr style="border-bottom:2px solid #f0f2f5;color:#9ca3c4;font-size:11px;text-transform:uppercase;letter-spacing:.5px">
                <th style="padding:6px 8px;font-weight:600;width:40px">Порт</th>
                <th style="padding:6px 8px;font-weight:600"></th>
                <th style="padding:6px 8px;font-weight:600">Устройство</th>
                <th style="padding:6px 8px;font-weight:600">Тип</th>
                <th style="padding:6px 8px;font-weight:600">Кабинет</th>
                <th style="padding:6px 8px;font-weight:600">IP</th>
                <th style="padding:6px 8px;font-weight:600">Статус</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $scColors = [
                'В работе'              => ['bg'=>'#f0fdf4','color'=>'#16a34a','border'=>'#bbf7d0'],
                'На ремонте'            => ['bg'=>'#fff7ed','color'=>'#ea580c','border'=>'#fed7aa'],
                'Списан'                => ['bg'=>'#f1f5f9','color'=>'#64748b','border'=>'#cbd5e1'],
                'На хранении'           => ['bg'=>'#eff6ff','color'=>'#2563eb','border'=>'#bfdbfe'],
                'Числится за кабинетом' => ['bg'=>'#fdf4ff','color'=>'#9333ea','border'=>'#e9d5ff'],
            ];
            $typeToSection = [
                'ПК'                  => 'computers',
                'Сервер'              => 'servers',
                'Ноутбук'             => 'notebooks',
                'Принтер'             => 'printers',
                'ИБП'                 => 'ups',
                'Коммутатор'          => 'switch',
                'Интерактивная доска' => 'rooms',
            ];
            foreach ($connectedDevices as $cd):
                $sc = $scColors[$cd['status']] ?? ['bg'=>'#f0f2f5','color'=>'#6b7499','border'=>'#e5e7ef'];
                $folder = mapTypeToFolder($cd['type']);
                $iconFile = $cd['icon'] ?? '';
                $iconPath = __DIR__ . "/../assets/icons/{$folder}/{$iconFile}";
                $section = $typeToSection[$cd['type']] ?? 'rooms';
            ?>
            <tr style="border-bottom:1px solid #f5f6fa;cursor:pointer" onclick="location.href='/adminis/<?= $section ?>/edit.php?id=<?= $cd['id'] ?>'">
                <td style="padding:7px 8px;color:#9ca3c4;font-size:12px;text-align:center">
                    <?= $cd['port_number'] ? htmlspecialchars($cd['port_number']) : '—' ?>
                </td>
                <td style="padding:7px 8px;width:28px">
                    <?php if ($iconFile && file_exists($iconPath)): ?>
                        <img src="/adminis/assets/icons/<?= $folder ?>/<?= htmlspecialchars($iconFile) ?>" style="width:20px;height:20px;object-fit:contain;vertical-align:middle">
                    <?php endif; ?>
                </td>
                <td style="padding:7px 8px;font-weight:600;color:#1e2130"><?= htmlspecialchars($cd['name']) ?></td>
                <td style="padding:7px 8px;color:#6b7499"><?= htmlspecialchars($cd['type']) ?></td>
                <td style="padding:7px 8px;color:#6b7499"><?= htmlspecialchars($cd['room_name'] ?? '—') ?></td>
                <td style="padding:7px 8px;font-family:monospace;font-size:12px;color:#4f6ef7"><?= htmlspecialchars($cd['ip'] ?? '') ?></td>
                <td style="padding:7px 8px">
                    <span style="display:inline-block;border-radius:20px;padding:2px 8px;font-size:11px;font-weight:600;border:1px solid;background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border-color:<?= $sc['border'] ?>">
                        <?= htmlspecialchars($cd['status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Открытые проблемы -->
<?php if (!empty($openIssues)): ?>
<div class="form-section" style="border-color:#fca5a5">
    <div class="section-title" style="color:#dc2626">⚠ Открытые проблемы (<?= count($openIssues) ?>)</div>
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
<div class="form-section">
    <div class="section-title">История изменений</div>
    <?php if (empty($history)): ?>
        <p style="font-size:13px;color:#9ca3c4">История пуста.</p>
    <?php else: ?>
        <div class="history-list">
            <?php foreach ($history as $h):
                $act = $historyActions[$h['action']] ?? ['label'=>$h['action'],'color'=>'#9ca3c4'];
                $isMulti = !empty($h['note']) && $h['action'] === 'field_changed' && empty($h['field_name']);
            ?>
            <div class="history-item">
                <div class="history-dot" style="background:<?= $act['color'] ?>"></div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px">
                        <div class="history-action">
                            <?php if ($isMulti): ?>
                                Изменено полей: <?= count(explode(';', $h['note'])) ?>
                            <?php else: ?>
                                <?= $act['label'] ?>
                                <?php if ($h['field_name']): ?>
                                    <span style="font-weight:400;color:#6b7499"> — <?= htmlspecialchars($h['field_name']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="history-meta" style="white-space:nowrap"><?= date('d.m.Y H:i', strtotime($h['created_at'])) ?></div>
                    </div>
                    <?php if ($isMulti): ?>
                        <div style="margin-top:4px;display:flex;flex-direction:column;gap:2px">
                            <?php foreach (explode('; ', $h['note']) as $part): ?>
                                <div style="font-size:12px;color:#6b7499"><?= htmlspecialchars($part) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($h['old_value'] !== null || $h['new_value'] !== null): ?>
                        <div class="history-detail">
                            <?php if ($h['old_value'] !== null && $h['old_value'] !== ''): ?>
                                <span style="color:#dc2626;text-decoration:line-through"><?= htmlspecialchars($h['old_value']) ?></span> →
                            <?php endif; ?>
                            <span style="color:#16a34a"><?= htmlspecialchars($h['new_value'] ?? '') ?></span>
                        </div>
                    <?php elseif ($h['note']): ?>
                        <div class="history-detail" style="color:#9ca3c4"><?= htmlspecialchars($h['note']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div><!-- /left -->

<!-- ── ПРАВАЯ ПАНЕЛЬ ── -->
<div class="side-panel">

    <!-- Состояние компонентов -->
    <div class="panel-box">
        <div class="panel-box-title">Состояние</div>
        <div class="comp-list">
            <?php foreach ($componentLabels as $key => $label):
                $hasProblem = isset($issuesByComp[$label]);
            ?>
                <div class="comp-row">
                    <span class="comp-label"><?= $label ?></span>
                    <span class="comp-indicator <?= $hasProblem ? 'prob' : 'ok' ?>"><?= $hasProblem ? '⚠' : '✓' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Паспорт (только чтение — сводка) -->
    <div class="panel-box">
        <div class="panel-box-title">Паспорт</div>
        <div>
            <?php
            $passport = [
                'Кабинет'       => $device['room_name'] ?? '',
                'Этаж'          => $hw['floor'] ?? '',
                'Статус'        => $device['status'] ?? '',
                'Инв. номер'    => $device['inventory_number'] ?? '',
                'IP'            => $device['ip'] ?? '',
                'MAC'           => $device['mac'] ?? '',
                'Производитель' => $hw['manufacturer'] ?? '',
                'Модель'        => $hw['model'] ?? '',
                'Серийный №'    => $hw['serial_number'] ?? '',
                'Год выпуска'   => $hw['year_manufactured'] ?? '',
                'Тип'           => $hw['device_type'] ?? '',
                'Портов'        => $hw['ports'] ? $hw['ports'].' шт.' : '',
                'Скорость'      => $hw['port_speed'] ?? '',
                'Управляемый'   => isset($hw['managed']) ? ($hw['managed'] ? 'Да' : 'Нет') : '',
            ];
            foreach ($passport as $lbl => $val): if ($val === '' || $val === null) continue; ?>
                <div class="passport-row">
                    <span class="passport-label"><?= $lbl ?></span>
                    <span class="passport-value"><?= htmlspecialchars($val) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($hw['commissioned_at'])): ?>
                <div class="passport-row">
                    <span class="passport-label">В эксплуатации с</span>
                    <span class="passport-value"><?= date('d.m.Y', strtotime($hw['commissioned_at'])) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($hw['warranty_until'])): ?>
                <div class="passport-row">
                    <span class="passport-label">Гарантия</span>
                    <span class="passport-value">
                        <?php if ($warrantyExpired): ?>
                            <span class="warranty-expired">Истекла <?= date('d.m.Y', strtotime($hw['warranty_until'])) ?></span>
                        <?php elseif ($warrantyDaysLeft <= 30): ?>
                            <span class="warranty-soon">До <?= date('d.m.Y', strtotime($hw['warranty_until'])) ?> (<?= $warrantyDaysLeft ?> дн.)</span>
                        <?php else: ?>
                            <span class="warranty-ok">До <?= date('d.m.Y', strtotime($hw['warranty_until'])) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if (!empty($device['recommended_for_writeoff'])): ?>
                <div class="passport-row">
                    <span class="passport-label">Списание</span>
                    <span class="passport-value" style="color:#dc2626;font-weight:600">
                        ⚠ Рекомендовано<?= $device['writeoff_recommended_at'] ? ' с '.date('d.m.Y', strtotime($device['writeoff_recommended_at'])) : '' ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /side-panel -->
</div><!-- /edit-layout -->
</form>

<!-- Модалка: добавить проблему -->
<div class="modal-overlay" id="issue-modal">
    <div class="modal-box">
        <div class="modal-title">⚠ Добавить проблему</div>
        <form method="post">
            <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
            <div class="mb-3">
                <label class="form-label">Компонент</label>
                <select name="issue_component" class="form-select">
                    <option value="">— Общее —</option>
                    <?php foreach ($componentLabels as $key => $label): ?>
                        <option><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Описание <span style="color:#dc2626">*</span></label>
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
                <button type="submit" name="resolve_issue" class="btn btn-outline-success">Подтвердить</button>
            </div>
        </form>
    </div>
</div>

</div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const cb = document.getElementById('writeoff-cb');
    const lbl = document.getElementById('writeoff-label');
    if (cb && lbl) {
        cb.addEventListener('change', () => {
            lbl.style.borderColor = cb.checked ? '#fca5a5' : '#dee2e6';
            lbl.style.background  = cb.checked ? '#fff5f5' : '#f9fafb';
        });
    }
    const selected = "<?= addslashes($device['icon']) ?>";
    fetch('/adminis/load_icons.php').then(r=>r.json()).catch(()=>[]).then(files => {
        const c = document.getElementById('icon-container');
        if (!files || !files.length) { c.innerHTML = '<p class="text-muted">Иконки не найдены</p>'; return; }
        c.innerHTML = '';
        files.forEach(f => {
            const img = document.createElement('img');
            img.src = '/adminis/assets/icons/' + f;
            img.dataset.filename = f;
            img.className = 'icon-option';
            img.style.cssText = 'width:48px;height:48px;object-fit:contain;cursor:pointer;border:2px solid transparent;border-radius:6px;padding:2px';
            if (f === selected) img.style.border = '2px solid #4f6ef7';
            img.addEventListener('click', () => {
                document.getElementById('icon-input').value = f;
                document.querySelectorAll('.icon-option').forEach(i => i.style.border = '2px solid transparent');
                img.style.border = '2px solid #4f6ef7';
            });
            c.appendChild(img);
        });
    });
});
function openIssueModal()  { document.getElementById('issue-modal').classList.add('open'); }
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