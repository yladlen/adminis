<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
require_once 'room_model.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($name === '') {
        $error = "Название кабинета обязательно.";
    } else {
        createRoom($pdo, $name, $description);
        header("Location: index.php"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавить кабинет</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <form method="post">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0" style="text-align:left">Добавление кабинета</h1>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-success">💾 Сохранить</button>
                    <a href="index.php" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label">Название кабинета <span style="color:#d63031">*</span></label>
                <input type="text" name="name" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label class="form-label">Описание (необязательно)</label>
                <textarea name="description" rows="4" class="form-control"></textarea>
            </div>
        </form>

    </div>
</div>
</body>
</html>