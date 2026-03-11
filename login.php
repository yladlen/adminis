<?php
session_start();
require_once 'includes/config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login    = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($login === ADMIN_LOGIN && $password === ADMIN_PASSWORD) {
        $_SESSION['logged_in'] = true;
        header("Location: index.php");
        exit;
    } else {
        $error = "Неверный логин или пароль.";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход — <?= defined('SITE_TITLE') ? SITE_TITLE : 'adminis' ?></title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body class="login-page">

<div class="login-card">
    <div class="login-logo">🖥</div>
    <h1 class="login-title"><?= defined('SITE_TITLE') ? SITE_TITLE : 'adminis' ?></h1>
    <p class="login-subtitle">Система учёта оборудования</p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="login-field">
            <label class="form-label" for="login">Логин</label>
            <input type="text" id="login" name="login" class="form-control" required autofocus>
        </div>
        <div class="login-field">
            <label class="form-label" for="password">Пароль</label>
            <input type="password" id="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Войти</button>
    </form>
</div>

</body>
</html>