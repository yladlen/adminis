<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/navbar.php';
require_once '../../includes/top_navbar.php';

$employees = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$notebooks = $pdo->query("SELECT id, name FROM devices WHERE type = 'Ноутбук' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $device_id   = !empty($_POST['device_id'])   ? (int)$_POST['device_id']   : null;
    $employee_id = !empty($_POST['employee_id'])  ? (int)$_POST['employee_id'] : null;
    $is_permanent= isset($_POST['is_permanent']) ? 1 : 0;
    $start_date  = trim($_POST['start_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');
    $status      = $_POST['status'] ?? 'взят';
    $comment     = trim($_POST['comment'] ?? '');

    if (!$device_id)   $error = "Выберите ноутбук.";
    elseif (!$employee_id) $error = "Выберите сотрудника.";
    else {
        $pdo->prepare("INSERT INTO journal (device_id, employee_id, is_permanent, start_date, end_date, status, comment)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $device_id, $employee_id, $is_permanent,
                $start_date ?: null, $end_date ?: null,
                $status, $comment ?: null,
            ]);
        // Обновляем статус устройства
        $deviceStatus = ($status === 'взят') ? 'В работе' : 'На хранении';
        $pdo->prepare("UPDATE devices SET status = ? WHERE id = ?")->execute([$deviceStatus, $device_id]);

        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Новая запись журнала</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <form method="post">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0" style="text-align:left">Новая запись журнала</h1>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-success">💾 Сохранить</button>
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
                            <option value="<?= $nb['id'] ?>" <?= (($_POST['device_id'] ?? '') == $nb['id']) ? 'selected' : '' ?>>
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
                            <option value="<?= $emp['id'] ?>" <?= (($_POST['employee_id'] ?? '') == $emp['id']) ? 'selected' : '' ?>>
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
                        <option value="взят" <?= (($_POST['status'] ?? 'взят') === 'взят') ? 'selected' : '' ?>>Взят</option>
                        <option value="сдан" <?= (($_POST['status'] ?? '') === 'сдан') ? 'selected' : '' ?>>Сдан</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Дата выдачи</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Дата возврата</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-3" style="margin-top:16px">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_permanent" id="is_permanent"
                           <?= !empty($_POST['is_permanent']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_permanent">Постоянная выдача</label>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Комментарий</label>
                <textarea name="comment" rows="3" class="form-control"><?= htmlspecialchars($_POST['comment'] ?? '') ?></textarea>
            </div>
        </form>

    </div>
</div>
</body>
</html>