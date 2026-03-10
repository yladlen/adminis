<?php
/**
 * version_check.php
 * Подключать в самом начале каждой страницы сайта (после config.php и подключения к БД).
 * 
 * Требует: $pdo (PDO-соединение), APP_VERSION (константа из config.php)
 * 
 * Пример подключения в index.php:
 *   require __DIR__ . '/includes/config.php';
 *   require __DIR__ . '/includes/db.php';       // здесь создаётся $pdo
 *   require __DIR__ . '/includes/version_check.php';
 */

if (!defined('APP_VERSION')) return;  // защита от прямого вызова без конфига

// ── Создаём таблицу settings если её ещё нет ────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
    `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`      TEXT         DEFAULT NULL,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Читаем версию БД ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = 'db_version'");
$stmt->execute();
$dbVersion = $stmt->fetchColumn();

// Если записи нет — значит БД совсем старая (до системы версий), ставим '1.0.0'
if ($dbVersion === false) {
    $pdo->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('db_version', '1.0.0')");
    $dbVersion = '1.0.0';
}

// ── Сравниваем с версией приложения ─────────────────────────────────────────
if (version_compare($dbVersion, APP_VERSION, '<')) {
    // БД отстаёт — редирект на мастер миграций
    $currentUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $redirectBack = urlencode($currentUrl);
    header('Location: /adminis/setup/migrate.php?from=' . urlencode($dbVersion) . '&to=' . urlencode(APP_VERSION) . '&back=' . $redirectBack);
    exit;
}