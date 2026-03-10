<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) die("Некорректный ID сотрудника.");

$employee_id = (int) $_GET['id'];
$stmt = $pdo->prepare("SELECT t.*, r.name AS room_name FROM employees t LEFT JOIN rooms r ON t.room_id = r.id WHERE t.id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) die("Сотрудник не найден.");

$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        $full_name      = trim($_POST['full_name'] ?? '');
        $position       = trim($_POST['position'] ?? '');
        $room_id        = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
        $internal_phone = trim($_POST['internal_phone'] ?? '');
        $mobile_phone   = trim($_POST['mobile_phone'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $comment        = trim($_POST['comment'] ?? '');

        if ($full_name === '') {
            $error = "ФИО обязательно для заполнения.";
        } else {
            $pdo->prepare("UPDATE employees SET full_name=?, position=?, room_id=?, internal_phone=?, mobile_phone=?, email=?, comment=? WHERE id=?")
                ->execute([$full_name, $position ?: null, $room_id, $internal_phone ?: null, $mobile_phone ?: null, $email ?: null, $comment ?: null, $employee_id]);
            header("Location: index.php"); exit;
        }
    }

    if (isset($_POST['delete'])) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM journal WHERE employee_id = ?");
        $count->execute([$employee_id]);
        if ($count->fetchColumn() > 0) {
            $error = "Нельзя удалить сотрудника — он используется в записях выдачи ноутбуков.";
        } else {
            $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$employee_id]);
            header("Location: index.php"); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактирование — <?= htmlspecialchars($employee['full_name']) ?></title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <form method="post">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0" style="text-align:left"><?= htmlspecialchars($employee['full_name']) ?></h1>
                <div class="d-flex gap-2">
                    <button type="submit" name="update" class="btn btn-outline-success">💾 Сохранить</button>
                    <button type="submit" name="delete" class="btn btn-outline-danger"
                            onclick="return confirm('Удалить этого сотрудника?')">🗑️ Удалить</button>
                    <a href="index.php" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">ФИО <span style="color:#d63031">*</span></label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($employee['full_name']) ?>" required>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label class="form-label">Должность</label>
                    <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($employee['position'] ?? '') ?>" placeholder="Например: Преподаватель">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Кабинет</label>
                    <select name="room_id" class="form-select">
                        <option value="">— Не указан —</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>" <?= ($employee['room_id'] == $room['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($room['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row" style="margin-top:16px">
                <div class="col-md-6">
                    <label class="form-label">Внутренний телефон</label>
                    <input type="text" name="internal_phone" class="form-control" value="<?= htmlspecialchars($employee['internal_phone'] ?? '') ?>" placeholder="Например: 1234">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Мобильный телефон</label>
                    <input type="tel" name="mobile_phone" class="form-control" value="<?= htmlspecialchars($employee['mobile_phone'] ?? '') ?>" placeholder="+7 (XXX) XXX-XX-XX">
                </div>
            </div>

            <div class="mb-3" style="margin-top:16px">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($employee['email'] ?? '') ?>" placeholder="example@mail.ru">
            </div>

            <div class="mb-3">
                <label class="form-label">Комментарий</label>
                <textarea name="comment" rows="3" class="form-control"><?= htmlspecialchars($employee['comment'] ?? '') ?></textarea>
            </div>
        </form>

    </div>
</div>
</body>
</html>