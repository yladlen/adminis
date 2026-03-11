<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
require_once 'room_model.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) die("Некорректный ID кабинета.");

$room_id = (int) $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
$stmt->execute([$room_id]);
$room = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$room) die("Кабинет не найден.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name === '') {
            $error = "Название кабинета не может быть пустым.";
        } else {
            updateRoom($pdo, $room_id, $name, $description);
            header("Location: room.php?id=$room_id"); exit;
        }
    }
    if (isset($_POST['duplicate'])) {
        $newId = duplicateRoom($pdo, $room_id);
        header("Location: edit_room.php?id=$newId"); exit;
    }
    if (isset($_POST['delete'])) {
        deleteRoom($pdo, $room_id);
        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактировать — <?= htmlspecialchars($room['name']) ?></title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <form method="post">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0" style="text-align:left"><?= htmlspecialchars($room['name']) ?></h1>
                <div class="d-flex gap-2">
                    <button type="submit" name="update" class="btn btn-outline-success">💾 Сохранить</button>
                    <button type="submit" name="duplicate" class="btn btn-outline-success">📄 Дублировать</button>
                    <button type="submit" name="delete" class="btn btn-outline-danger"
                            onclick="return confirm('Удалить кабинет и все его устройства?')">🗑 Удалить</button>
                    <a href="room.php?id=<?= $room_id ?>" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Название кабинета</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($room['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Описание</label>
                <textarea name="description" rows="4" class="form-control"><?= htmlspecialchars($room['description']) ?></textarea>
            </div>
        </form>

    </div>
</div>
</body>
</html>