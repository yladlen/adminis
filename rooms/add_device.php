<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

$room_id = isset($_GET['room_id']) && is_numeric($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
if (!$room_id && isset($_POST['room_select']) && is_numeric($_POST['room_select']))
    $room_id = (int)$_POST['room_select'];
$from = $_GET['from'] ?? $_POST['from'] ?? 'room';
$back_url = match($from) {
    'notebooks' => '/adminis/notebooks/',
    'computers' => '/adminis/computers/',
    'servers'   => '/adminis/servers/',
    'printers'  => '/adminis/printers/',
    'ups'       => '/adminis/ups/',
    default     => $room_id ? 'room.php?id='.$room_id : 'room.php',
};
$default_type = match($from) {
    'notebooks' => 'Ноутбук',
    'computers' => 'ПК',
    'servers'   => 'Сервер',
    'printers'  => 'Принтер',
    'ups'       => 'ИБП',
    default     => 'ПК',
};

$room  = null;
if ($room_id) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id=?");
    $stmt->execute([$room_id]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
}
$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = trim($_POST['name'] ?? '');
    $type         = $_POST['type'] ?? '';
    $icon         = $_POST['icon'] ?? '';
    $ip           = trim($_POST['ip'] ?? '');
    $mac          = trim($_POST['mac'] ?? '');
    $inventory    = trim($_POST['inventory_number'] ?? '');
    $status       = $_POST['status'] ?? 'В работе';
    $comment      = trim($_POST['comment'] ?? '');
    $connected_id = ($_POST['connected_to_device_id'] ?? '') !== '' ? (int)$_POST['connected_to_device_id'] : null;

    if ($name === '') {
        $error = "Название устройства обязательно.";
    } elseif (!$room_id) {
        $error = "Необходимо выбрать кабинет.";
    } else {
        $pdo->prepare("INSERT INTO devices (room_id,name,type,ip,mac,inventory_number,status,comment,icon) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$room_id,$name,$type,$ip,$mac,$inventory,$status,$comment,$icon]);
        $new_id = $pdo->lastInsertId();

        if ($connected_id !== null && !in_array($type, ['ИБП', 'Ноутбук']))
            $pdo->prepare("INSERT INTO switch_links (device_id,connected_to_device_id) VALUES (?,?)")->execute([$new_id,$connected_id]);

        // Сохраняем железо для ПК, Сервера и Ноутбука
        if (in_array($type, ['ПК', 'Сервер', 'Ноутбук'])) {
            $cpu = trim($_POST['hw_cpu'] ?? '');
            $ram = trim($_POST['hw_ram'] ?? '');
            $hdd = trim($_POST['hw_hdd'] ?? '');
            $gpu = trim($_POST['hw_gpu'] ?? '');
            $os  = trim($_POST['hw_os']  ?? '');
            if ($cpu || $ram || $hdd || $gpu || $os) {
                $pdo->prepare("INSERT INTO computer_hardware (device_id,cpu,ram_gb,hdd_gb,gpu,os) VALUES (?,?,?,?,?,?)")
                    ->execute([$new_id, $cpu, $ram, $hdd, $gpu, $os]);
            }
        }

        // История: создание (таблица может отсутствовать на старых схемах)
        try {
            $pdo->prepare("INSERT INTO computer_history (device_id,action,note) VALUES (?,?,?)")
                ->execute([$new_id,'created',"$name — $type" . ($room_id ? ", кабинет #$room_id" : '')]);
        } catch (PDOException $e) { /* таблица ещё не создана */ }

        header("Location: $back_url"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Добавить устройство<?= $room ? " — ".htmlspecialchars($room['name']) : "" ?></title>
<link href="/adminis/includes/style.css" rel="stylesheet">
<style>
.comp-list { display:flex; flex-direction:column; gap:8px; margin-top:8px; }
.comp-row  { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:13px; }
.comp-label { color:#6b7499; flex:1; }
.comp-toggle { display:flex; gap:4px; }
.comp-btn { border:none; border-radius:5px; padding:4px 11px; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; opacity:.4; }
.comp-btn.active { opacity:1; }
.comp-btn.ok   { background:#dcfce7; color:#16a34a; }
.comp-btn.ok.active   { background:#16a34a; color:#fff; }
.comp-btn.prob { background:#fee2e2; color:#dc2626; }
.comp-btn.prob.active { background:#dc2626; color:#fff; }
</style>
</head>
<body>
<div class="content-wrapper">
<div class="content-container">
<form method="post">
<input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0" style="text-align:left">
        Добавить устройство
        <?= $room ? '<span style="font-size:14px;font-weight:400;color:#6b7499">— '.htmlspecialchars($room['name']).'</span>' : '' ?>
    </h1>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-outline-success">💾 Сохранить</button>
        <a href="<?= $back_url ?>" class="btn btn-outline-danger">🚫 Отмена</a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <label class="form-label">Название устройства <span style="color:#d63031">*</span></label>
        <input type="text" name="name" class="form-control" required autofocus>
    </div>
    <div class="col-md-6">
        <label class="form-label">Тип устройства</label>
        <select name="type" id="type-select" class="form-select" required>
            <?php foreach (['ПК','Сервер','Принтер','Маршрутизатор','Свитч','Интерактивная доска','Ноутбук','ИБП','Прочее'] as $t): ?>
                <option <?= $t===$default_type?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="row" style="margin-top:16px">
    <div class="col-md-6">
        <label class="form-label">Кабинет <span style="color:#d63031">*</span></label>
        <select name="room_select" id="room-id-select" class="form-select" required>
            <option value="">— Выберите кабинет —</option>
            <?php foreach ($rooms as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $r['id']==$room_id?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="mb-3" style="margin-top:16px">
    <label class="form-label">Иконка устройства</label>
    <div id="icon-container" class="icon-picker-container"><p class="text-muted">Загрузка...</p></div>
    <input type="hidden" name="icon" id="icon-input">
</div>

<div class="row">
    <div class="col-md-6">
        <label class="form-label">IP-адрес</label>
        <input type="text" name="ip" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label">MAC-адрес</label>
        <input type="text" name="mac" class="form-control">
    </div>
</div>

<div class="row" style="margin-top:16px">
    <div class="col-md-6">
        <label class="form-label">Инвентарный номер</label>
        <input type="text" name="inventory_number" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label">Статус</label>
        <select name="status" class="form-select">
            <?php foreach (['В работе','На ремонте','Списан','На хранении','Числится за кабинетом'] as $s): ?>
                <option><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="mb-3" style="margin-top:16px">
    <label class="form-label">Комментарий</label>
    <textarea name="comment" rows="3" class="form-control"></textarea>
</div>

<!-- Состояние компонентов — только для ПК -->
<div id="hardware-block" style="margin-top:20px;display:none">
    <div style="font-size:14px;font-weight:600;color:#1e2130;margin-bottom:10px">Железо</div>
    <div style="background:#f8f9ff;border:1px solid #e5e7ef;border-radius:10px;padding:16px;max-width:600px">
        <div class="row">
            <div class="col-md-6" style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;color:#9ca3c4;text-transform:uppercase;letter-spacing:.5px">Процессор</label>
                <input type="text" name="hw_cpu" class="form-control" placeholder="Intel Core i5-10400">
            </div>
            <div class="col-md-6" style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;color:#9ca3c4;text-transform:uppercase;letter-spacing:.5px">ОЗУ (ГБ)</label>
                <input type="text" name="hw_ram" class="form-control" placeholder="8">
            </div>
            <div class="col-md-6" style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;color:#9ca3c4;text-transform:uppercase;letter-spacing:.5px">Диск (ГБ)</label>
                <input type="text" name="hw_hdd" class="form-control" placeholder="512 SSD">
            </div>
            <div class="col-md-6" id="hw-gpu-wrap" style="margin-bottom:12px">
                <label class="form-label" style="font-size:12px;color:#9ca3c4;text-transform:uppercase;letter-spacing:.5px">Видеокарта</label>
                <input type="text" name="hw_gpu" class="form-control" placeholder="Встроенная / GTX 1050">
            </div>
            <div class="col-md-6">
                <label class="form-label" style="font-size:12px;color:#9ca3c4;text-transform:uppercase;letter-spacing:.5px">Операционная система</label>
                <input type="text" name="hw_os" class="form-control" placeholder="Windows 10 Pro">
            </div>
        </div>
    </div>
</div>

<div id="connection-block" class="row" style="margin-top:16px">
    <div class="col-md-6">
        <label class="form-label">Подключено к (кабинет)</label>
        <select id="room-select" class="form-select">
            <option value="">— Не подключено —</option>
            <?php foreach ($rooms as $r): ?>
                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Устройство в кабинете</label>
        <select name="connected_to_device_id" id="device-select" class="form-select">
            <option value="">— Не подключено —</option>
        </select>
    </div>
</div>
</form>
</div>
</div>

<script>
function loadIcons(type, selected='') {
    fetch('../load_icons.php?type='+encodeURIComponent(type)).then(r=>r.text()).then(html=>{
        const c=document.getElementById('icon-container'); c.innerHTML=html;
        document.querySelectorAll('.icon-option').forEach(img=>{
            img.addEventListener('click',()=>{
                document.getElementById('icon-input').value=img.dataset.filename;
                document.querySelectorAll('.icon-option').forEach(i=>i.style.border='2px solid transparent');
                img.style.border='2px solid #4f6ef7';
            });
            if(img.dataset.filename===selected) img.style.border='2px solid #4f6ef7';
        });
    });
}
function toggleComponents(type){
    const hwBlock  = document.getElementById('hardware-block');
    const gpuWrap  = document.getElementById('hw-gpu-wrap');
    const connBlock = document.getElementById('connection-block');
    const roomSel  = document.getElementById('room-select');

    const showHw   = ['ПК','Сервер','Ноутбук'].includes(type);
    hwBlock.style.display = showHw ? 'block' : 'none';
    gpuWrap.style.display = (type === 'Сервер') ? 'none' : '';

    // ИБП и Ноутбук не подключаются к конкретному устройству — скрываем колонку выбора устройства
    // Но кабинет всё равно нужен — показываем только его
    const hideDeviceLink = ['ИБП','Ноутбук'].includes(type);
    document.getElementById('device-select').closest('.col-md-6').style.display = hideDeviceLink ? 'none' : '';
}
const typeSelect = document.getElementById('type-select');
typeSelect.addEventListener('change',function(){ loadIcons(this.value); toggleComponents(this.value); });
document.addEventListener('DOMContentLoaded',()=>{
    loadIcons(typeSelect.value);
    toggleComponents(typeSelect.value);
});
document.getElementById('room-select').addEventListener('change',function(){
    const s=document.getElementById('device-select');
    if (!this.value) { s.innerHTML='<option value="">— Не подключено —</option>'; return; }
    s.innerHTML='<option>Загрузка...</option>';
    fetch('../load_devices_by_room.php?room_id='+this.value).then(r=>r.text()).then(html=>{
        s.innerHTML='<option value="">— Не подключено —</option>'+(html||'');
    });
});
</script>
</body>
</html>