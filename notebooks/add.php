<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

$from = $_GET['from'] ?? 'computers';
$back_url = '/adminis/notebooks/';

$room_id = isset($_GET['room_id']) && is_numeric($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
if (!$room_id && isset($_POST['room_id']) && is_numeric($_POST['room_id']))
    $room_id = (int)$_POST['room_id'];

$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $name      = trim($_POST['name'] ?? '');
    $ip        = trim($_POST['ip'] ?? '');
    $mac       = trim($_POST['mac'] ?? '');
    $inventory = trim($_POST['inventory_number'] ?? '');
    $status    = $_POST['status'] ?? 'В работе';
    $comment   = trim($_POST['comment'] ?? '');
    $icon      = $_POST['icon'] ?? '';
    $room_id   = (int)($_POST['room_id'] ?? 0);
    $connected_id = ($_POST['connected_to_device_id'] ?? '') !== '' ? (int)$_POST['connected_to_device_id'] : null;

    if ($name === '')    $error = 'Название устройства обязательно.';
    elseif (!$room_id)  $error = 'Необходимо выбрать кабинет.';
    else {
        $pdo->prepare("INSERT INTO devices (room_id,name,type,ip,mac,inventory_number,status,comment,icon) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$room_id, $name, 'Ноутбук', $ip, $mac, $inventory, $status, $comment, $icon]);
        $new_id = $pdo->lastInsertId();

        if ($connected_id)
            $pdo->prepare("INSERT INTO switch_links (device_id,connected_to_device_id) VALUES (?,?)")->execute([$new_id, $connected_id]);

        // Железо + новые поля
        $hw = [
            trim($_POST['hw_cpu']          ?? ''),
            trim($_POST['hw_ram']          ?? ''),
            trim($_POST['hw_hdd']          ?? ''),
            trim($_POST['hw_gpu']          ?? ''),
            trim($_POST['hw_os']           ?? ''),
            trim($_POST['hw_manufacturer'] ?? ''),
            trim($_POST['hw_model']        ?? ''),
            trim($_POST['hw_serial']       ?? ''),
            trim($_POST['hw_year']         ?? '') ?: null,
            trim($_POST['hw_commissioned'] ?? '') ?: null,
            trim($_POST['hw_warranty']     ?? '') ?: null,
            trim($_POST['hw_floor']        ?? ''),
        ];
        $pdo->prepare("INSERT INTO notebook_hardware
            (device_id,cpu,ram_gb,hdd_gb,gpu,os,manufacturer,model,serial_number,year_manufactured,commissioned_at,warranty_until,floor)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array_merge([$new_id], $hw));

        try {
            $pdo->prepare("INSERT INTO notebook_history (device_id,action,note) VALUES (?,?,?)")
                ->execute([$new_id, 'created', "$name — Ноутбук, кабинет #$room_id"]);
        } catch (PDOException $e) {}

        header("Location: $back_url"); exit;
    }
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
<title>Добавить ноутбук</title>
<link href="/adminis/includes/style.css" rel="stylesheet">
<style>
.section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#9ca3c4; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #f0f2f5; }
.form-section { background:#fff; border:1px solid #e5e7ef; border-radius:12px; padding:20px 24px; margin-bottom:16px; }
</style>
</head>
<body>
<div class="content-wrapper">
<div class="content-container">
<form method="post">
<input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Добавить ноутбук</h1>
    <div class="d-flex gap-2">
        <button type="submit" name="save" class="btn btn-outline-success">💾 Сохранить</button>
        <a href="<?= $back_url ?>" class="btn btn-outline-danger">🚫 Отмена</a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Паспорт -->
<div class="form-section">
    <div class="section-title">Паспорт</div>
    <div class="row">

        <!-- Строка 1 -->
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Название <span style="color:#d63031">*</span></label>
            <input type="text" name="name" class="form-control" required autofocus value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Кабинет <span style="color:#d63031">*</span></label>
            <select name="room_id" id="room-id-select" class="form-select" required>
                <option value="">— Выберите кабинет —</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= $r['id']==$room_id?'selected':'' ?>><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Этаж</label>
            <input type="text" name="hw_floor" class="form-control" placeholder="1, 2..." value="<?= htmlspecialchars($_POST['hw_floor'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select">
                <?php foreach (['В работе','На ремонте','Списан','На хранении','Числится за кабинетом'] as $s): ?>
                    <option <?= (($_POST['status'] ?? 'В работе')===$s)?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Инвентарный номер</label>
            <input type="text" name="inventory_number" class="form-control" value="<?= htmlspecialchars($_POST['inventory_number'] ?? '') ?>">
        </div>

        <!-- Строка 2 -->
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">IP-адрес</label>
            <input type="text" name="ip" class="form-control" value="<?= htmlspecialchars($_POST['ip'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">MAC-адрес</label>
            <input type="text" name="mac" class="form-control" value="<?= htmlspecialchars($_POST['mac'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Производитель</label>
            <input type="text" name="hw_manufacturer" class="form-control" placeholder="Lenovo, HP, Dell..." value="<?= htmlspecialchars($_POST['hw_manufacturer'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Модель</label>
            <input type="text" name="hw_model" class="form-control" placeholder="ThinkCentre M720..." value="<?= htmlspecialchars($_POST['hw_model'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:14px">
            <label class="form-label">Серийный номер</label>
            <input type="text" name="hw_serial" class="form-control" placeholder="PF1AB2C3..." value="<?= htmlspecialchars($_POST['hw_serial'] ?? '') ?>">
        </div>

        <!-- Строка 3 -->
        <div class="col-md-5" style="margin-bottom:0">
            <label class="form-label">Год производства</label>
            <input type="number" name="hw_year" class="form-control" placeholder="2021" min="1990" max="2099" value="<?= htmlspecialchars($_POST['hw_year'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:0">
            <label class="form-label">Ввод в эксплуатацию</label>
            <input type="date" name="hw_commissioned" class="form-control" value="<?= htmlspecialchars($_POST['hw_commissioned'] ?? '') ?>">
        </div>
        <div class="col-md-5" style="margin-bottom:0">
            <label class="form-label">Гарантия до</label>
            <input type="date" name="hw_warranty" class="form-control" value="<?= htmlspecialchars($_POST['hw_warranty'] ?? '') ?>">
        </div>
        <div class="col-md-10" style="margin-bottom:0">
            <label class="form-label">Комментарий</label>
            <textarea name="comment" rows="1" class="form-control" style="min-height:0;height:36px;resize:vertical"><?= htmlspecialchars($_POST['comment'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- Иконка -->
<div class="form-section">
    <div class="section-title">Иконка</div>
    <div id="icon-container" class="icon-picker-container"><p class="text-muted">Загрузка...</p></div>
    <input type="hidden" name="icon" id="icon-input">
</div>

<!-- Железо -->
<div class="form-section">
    <div class="section-title">Железо</div>
    <div class="row">
        <div class="col-md-6" style="margin-bottom:14px">
            <label class="form-label">Процессор</label>
            <input type="text" name="hw_cpu" class="form-control" placeholder="Intel Core i5-10400" value="<?= htmlspecialchars($_POST['hw_cpu'] ?? '') ?>">
        </div>
        <div class="col-md-3" style="margin-bottom:14px">
            <label class="form-label">ОЗУ (ГБ)</label>
            <input type="text" name="hw_ram" class="form-control" placeholder="8" value="<?= htmlspecialchars($_POST['hw_ram'] ?? '') ?>">
        </div>
        <div class="col-md-3" style="margin-bottom:14px">
            <label class="form-label">Диск</label>
            <input type="text" name="hw_hdd" class="form-control" placeholder="512 SSD" value="<?= htmlspecialchars($_POST['hw_hdd'] ?? '') ?>">
        </div>
        <div class="col-md-6" style="margin-bottom:14px">
            <label class="form-label">Видеокарта</label>
            <input type="text" name="hw_gpu" class="form-control" placeholder="Встроенная / GTX 1050" value="<?= htmlspecialchars($_POST['hw_gpu'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Операционная система</label>
            <input type="text" name="hw_os" class="form-control" placeholder="Windows 10 Pro" value="<?= htmlspecialchars($_POST['hw_os'] ?? '') ?>">
        </div>
    </div>
</div>

<!-- Подключение к сети -->
<div class="form-section">
    <div class="section-title">Подключение к сети</div>
    <div class="row">
        <div class="col-md-6">
            <label class="form-label">Кабинет свитча</label>
            <select id="room-select" class="form-select">
                <option value="">— Не подключено —</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Устройство</label>
            <select name="connected_to_device_id" id="device-select" class="form-select">
                <option value="">— Не подключено —</option>
            </select>
        </div>
    </div>
</div>

</form>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
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
            img.addEventListener('click', () => {
                document.getElementById('icon-input').value = f;
                document.querySelectorAll('.icon-option').forEach(i => i.style.border = '2px solid transparent');
                img.style.border = '2px solid #4f6ef7';
            });
            c.appendChild(img);
        });
    });
});
document.getElementById('room-select').addEventListener('change', function(){
    const s = document.getElementById('device-select');
    if (!this.value) { s.innerHTML='<option value="">— Не подключено —</option>'; return; }
    s.innerHTML = '<option>Загрузка...</option>';
    fetch('../load_devices_by_room.php?room_id='+this.value).then(r=>r.text()).then(html=>{
        s.innerHTML = '<option value="">— Не подключено —</option>' + (html||'');
    });
});
</script>
</body>
</html>