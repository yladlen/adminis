<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/navbar.php';
require_once '../../includes/top_navbar.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) die("Некорректный ID записи.");

$entry_id = (int)$_GET['id'];
$stmt = $pdo->prepare(
    "SELECT j.*, d.name AS device_name, e.full_name AS employee_name
     FROM journal j
     LEFT JOIN devices d ON d.id = j.device_id
     LEFT JOIN employees e ON e.id = j.employee_id
     WHERE j.id = ?"
);
$stmt->execute([$entry_id]);
$entry = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entry) die("Запись не найдена.");

$employees = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$notebooks = $pdo->query("SELECT id, name FROM devices WHERE type = 'Ноутбук' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        $device_id   = !empty($_POST['device_id'])  ? (int)$_POST['device_id']   : null;
        $employee_id = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
        $is_permanent= isset($_POST['is_permanent']) ? 1 : 0;
        $start_date  = trim($_POST['start_date'] ?? '');
        $end_date    = trim($_POST['end_date'] ?? '');
        $status      = $_POST['status'] ?? 'взят';
        $comment     = trim($_POST['comment'] ?? '');

        if (!$device_id)   $error = "Выберите ноутбук.";
        elseif (!$employee_id) $error = "Выберите сотрудника.";
        else {
            $pdo->prepare("UPDATE journal SET device_id=?, employee_id=?, is_permanent=?, start_date=?, end_date=?, status=?, comment=? WHERE id=?")
                ->execute([
                    $device_id, $employee_id, $is_permanent,
                    $start_date ?: null, $end_date ?: null,
                    $status, $comment ?: null,
                    $entry_id,
                ]);
            // Обновляем статус устройства по последней записи журнала
            $lastStatus = $pdo->prepare(
                "SELECT status FROM journal WHERE device_id = ? ORDER BY id DESC LIMIT 1"
            );
            $lastStatus->execute([$device_id]);
            $lastRow = $lastStatus->fetchColumn();
            $deviceStatus = ($lastRow === 'взят') ? 'В работе' : 'На хранении';
            $pdo->prepare("UPDATE devices SET status = ? WHERE id = ?")->execute([$deviceStatus, $device_id]);

            header("Location: index.php"); exit;
        }
    }

    if (isset($_POST['delete'])) {
        $pdo->prepare("DELETE FROM journal WHERE id = ?")->execute([$entry_id]);
        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Запись журнала — <?= htmlspecialchars($entry['device_name'] ?? '—') ?></title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <form method="post">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0" style="text-align:left">
                    <?= htmlspecialchars($entry['device_name'] ?? '—') ?>
                    <span style="font-size:16px;color:#6b7499;font-weight:400">
                        — <?= htmlspecialchars($entry['employee_name'] ?? '—') ?>
                    </span>
                </h1>
                <div class="d-flex gap-2">
                    <button type="submit" name="update" class="btn btn-outline-success">💾 Сохранить</button>
                    <button type="submit" name="delete" class="btn btn-outline-danger"
                            onclick="return confirm('Удалить эту запись?')">🗑️ Удалить</button>
                    <a href="index.php" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Ноутбук <span style="color:#d63031">*</span></label>
                    <select name="device_id" class="form-select" required>
                        <option value="">— Выберите ноутбук —</option>
                        <?php foreach ($notebooks as $nb): ?>
                            <option value="<?= $nb['id'] ?>" <?= ($entry['device_id'] == $nb['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nb['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Сотрудник <span style="color:#d63031">*</span></label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Выберите сотрудника —</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= ($entry['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row" style="margin-top:16px">
                <div class="col-md-4">
                    <label class="form-label">Статус</label>
                    <select name="status" class="form-select">
                        <option value="взят" <?= ($entry['status'] === 'взят') ? 'selected' : '' ?>>Взят</option>
                        <option value="сдан" <?= ($entry['status'] === 'сдан') ? 'selected' : '' ?>>Сдан</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Дата выдачи</label>
                    <input type="date" name="start_date" class="form-control"
                           value="<?= htmlspecialchars($entry['start_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Дата возврата</label>
                    <input type="date" name="end_date" class="form-control"
                           value="<?= htmlspecialchars($entry['end_date'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-3" style="margin-top:16px">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_permanent" id="is_permanent"
                           <?= $entry['is_permanent'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_permanent">Постоянная выдача</label>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Комментарий</label>
                <textarea name="comment" rows="3" class="form-control"><?= htmlspecialchars($entry['comment'] ?? '') ?></textarea>
            </div>
        </form>

    </div>
</div>
</body>
</html>