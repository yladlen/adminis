<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$configFile   = __DIR__ . '/../includes/config.php';
$installedFlag = __DIR__ . '/.installed';

if (file_exists($configFile)) {
    header('Location: ../index.php');
    exit;
}

// Проверка требований
$requirements = [
    'PHP >= 7.4'                => version_compare(PHP_VERSION, '7.4.0', '>='),
    'Расширение PDO'            => extension_loaded('pdo'),
    'Расширение PDO MySQL'      => extension_loaded('pdo_mysql'),
    'Расширение mbstring'       => extension_loaded('mbstring'),
    'Расширение openssl'        => extension_loaded('openssl'),
    'Расширение json'           => extension_loaded('json'),
    'Права на запись в includes/' => is_writable(__DIR__ . '/../includes/'),
];
$all_ok = !in_array(false, $requirements, true);

$error       = '';
$success     = false;
$manualConfig = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host      = trim($_POST['host']       ?? 'localhost');
    $dbname    = trim($_POST['dbname']     ?? '');
    $user      = trim($_POST['user']       ?? '');
    $pass      = $_POST['pass']            ?? '';
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminPass = trim($_POST['admin_pass'] ?? '');
    $siteTitle = trim($_POST['site_title'] ?? 'Adminis');
    $appVersion = '2.0.0';

    if (empty($dbname) || empty($adminUser) || empty($adminPass)) {
        $error = "Заполните все обязательные поля.";
    } else {
        try {
            $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // ── Создание таблиц в правильном порядке (с учётом FK) ──────────────

            $pdo->exec("CREATE TABLE IF NOT EXISTS `rooms` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `name`        VARCHAR(50)  NOT NULL,
                `description` TEXT         DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `devices` (
                `id`               INT AUTO_INCREMENT PRIMARY KEY,
                `room_id`          INT NOT NULL,
                `name`             VARCHAR(100) NOT NULL,
                `type`             ENUM('ПК','Сервер','Принтер','Маршрутизатор','Свитч','МФУ','Интерактивная доска','Ноутбук','Прочее') NOT NULL,
                `ip`               VARCHAR(15)  DEFAULT NULL,
                `mac`              VARCHAR(17)  DEFAULT NULL,
                `inventory_number` VARCHAR(50)  DEFAULT NULL,
                `status`           ENUM('В работе','На ремонте','Списан','На хранении','Числится за кабинетом') NOT NULL,
                `comment`          TEXT         DEFAULT NULL,
                `icon`             VARCHAR(255) DEFAULT NULL,
                KEY `room_id` (`room_id`),
                CONSTRAINT `devices_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `switch_links` (
                `id`                    INT AUTO_INCREMENT PRIMARY KEY,
                `device_id`             INT NOT NULL,
                `connected_to_device_id` INT NOT NULL,
                KEY `device_id` (`device_id`),
                KEY `connected_to_device_id` (`connected_to_device_id`),
                CONSTRAINT `switch_links_ibfk_1` FOREIGN KEY (`device_id`)             REFERENCES `devices` (`id`),
                CONSTRAINT `switch_links_ibfk_2` FOREIGN KEY (`connected_to_device_id`) REFERENCES `devices` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `employees` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `full_name`      VARCHAR(255) NOT NULL,
                `internal_phone` VARCHAR(20)  DEFAULT NULL COMMENT 'Внутренний телефон',
                `mobile_phone`   VARCHAR(20)  DEFAULT NULL COMMENT 'Мобильный телефон',
                `room_id`        INT          DEFAULT NULL COMMENT 'Кабинет',
                `position`       VARCHAR(255) DEFAULT NULL COMMENT 'Должность',
                `email`          VARCHAR(255) DEFAULT NULL COMMENT 'Email',
                `comment`        TEXT         DEFAULT NULL COMMENT 'Комментарий',
                KEY `room_id` (`room_id`),
                CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `journal` (
                `id`           INT AUTO_INCREMENT PRIMARY KEY,
                `device_id`    INT NOT NULL,
                `employee_id`  INT NOT NULL,
                `is_permanent` TINYINT(1)   NOT NULL DEFAULT 0,
                `start_date`   DATE         DEFAULT NULL,
                `end_date`     DATE         DEFAULT NULL,
                `status`       ENUM('взят','сдан') NOT NULL DEFAULT 'взят',
                `comment`      TEXT         DEFAULT NULL,
                `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `device_id`   (`device_id`),
                KEY `employee_id` (`employee_id`),
                CONSTRAINT `journal_ibfk_1` FOREIGN KEY (`device_id`)   REFERENCES `devices`   (`id`),
                CONSTRAINT `journal_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `servers` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `name`       VARCHAR(100) NOT NULL,
                `ip`         VARCHAR(45)  NOT NULL,
                `user`       VARCHAR(50)  NOT NULL DEFAULT 'root',
                `services`   TEXT         DEFAULT NULL,
                `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `server_stats` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `server_id`  INT   NOT NULL,
                `cpu_used`   FLOAT NOT NULL,
                `mem_used`   INT   NOT NULL,
                `mem_total`  INT   NOT NULL,
                `disk`       TEXT  DEFAULT NULL,
                `services`   TEXT  DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `server_stats_ibfk_1` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `server_visits` (
                `id`             INT AUTO_INCREMENT PRIMARY KEY,
                `room_id`        INT       NOT NULL,
                `visited_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `check_servers`  TINYINT(1) NOT NULL DEFAULT 1,
                `check_ups`      TINYINT(1) NOT NULL DEFAULT 1,
                `check_switches` TINYINT(1) NOT NULL DEFAULT 1,
                `check_temp`     TINYINT(1) NOT NULL DEFAULT 1,
                `check_cooling`  TINYINT(1) NOT NULL DEFAULT 1,
                `check_power`    TINYINT(1) NOT NULL DEFAULT 1,
                `check_access`   TINYINT(1) NOT NULL DEFAULT 1,
                `comment`        TEXT      DEFAULT NULL,
                KEY `room_id` (`room_id`),
                CONSTRAINT `server_visits_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `server_visit_issues` (
                `id`                INT AUTO_INCREMENT PRIMARY KEY,
                `device_name`       VARCHAR(255) NOT NULL,
                `problem`           TEXT         NOT NULL,
                `notified`          VARCHAR(255) DEFAULT NULL,
                `reported_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `resolved_at`       TIMESTAMP    NULL     DEFAULT NULL,
                `resolved_visit_id` INT          DEFAULT NULL,
                KEY `resolved_visit_id` (`resolved_visit_id`),
                CONSTRAINT `server_visit_issues_ibfk_1` FOREIGN KEY (`resolved_visit_id`) REFERENCES `server_visits` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `server_visit_issue_links` (
                `visit_id` INT NOT NULL,
                `issue_id` INT NOT NULL,
                PRIMARY KEY (`visit_id`, `issue_id`),
                KEY `issue_id` (`issue_id`),
                CONSTRAINT `server_visit_issue_links_ibfk_1` FOREIGN KEY (`visit_id`) REFERENCES `server_visits`       (`id`) ON DELETE CASCADE,
                CONSTRAINT `server_visit_issue_links_ibfk_2` FOREIGN KEY (`issue_id`) REFERENCES `server_visit_issues` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `documentation` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `title`       VARCHAR(255) NOT NULL,
                `content`     TEXT         NOT NULL,
                `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `parent_id`   INT          DEFAULT NULL,
                `order_index` INT          DEFAULT 0,
                `type`        ENUM('section','content') DEFAULT 'content',
                `description` TEXT         DEFAULT NULL,
                `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_docs_parent` (`parent_id`),
                KEY `idx_docs_type`   (`type`),
                KEY `idx_docs_order`  (`order_index`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE OR REPLACE VIEW `documentation_hierarchy` AS
                SELECT
                    d.id, d.title, d.content, d.parent_id,
                    d.type, d.order_index, d.description,
                    COUNT(p.id) AS level
                FROM documentation d
                LEFT JOIN documentation p ON d.parent_id = p.id
                GROUP BY d.id
                ORDER BY d.order_index");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `computer_hardware` (
                `device_id`  INT          PRIMARY KEY,
                `cpu`        VARCHAR(255) DEFAULT NULL,
                `ram_gb`     VARCHAR(64)  DEFAULT NULL,
                `hdd_gb`     VARCHAR(64)  DEFAULT NULL,
                `gpu`        VARCHAR(255) DEFAULT NULL,
                `os`         VARCHAR(255) DEFAULT NULL,
                `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `computer_hardware_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `computer_components` (
                `device_id`   INT PRIMARY KEY,
                `fan`         ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `hdd`         ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `psu`         ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `ram`         ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `motherboard` ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `maintenance` ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `os_errors`   ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `sw_errors`   ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `other`       ENUM('ok','problem','unknown') DEFAULT 'unknown',
                `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `computer_components_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `computer_issues` (
                `id`          INT AUTO_INCREMENT PRIMARY KEY,
                `device_id`   INT  NOT NULL,
                `component`   VARCHAR(64) DEFAULT NULL,
                `description` TEXT NOT NULL,
                `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                `resolution`  TEXT        DEFAULT NULL,
                KEY `device_id` (`device_id`),
                CONSTRAINT `computer_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `computer_history` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `device_id`  INT         NOT NULL,
                `action`     VARCHAR(64) NOT NULL,
                `field_name` VARCHAR(64) DEFAULT NULL,
                `old_value`  TEXT        DEFAULT NULL,
                `new_value`  TEXT        DEFAULT NULL,
                `note`       TEXT        DEFAULT NULL,
                `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `device_id` (`device_id`),
                CONSTRAINT `computer_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ── Сохраняем config.php ─────────────────────────────────────────────
            $configData = <<<PHP
<?php
define('DB_TYPE',        'mysql');
define('DB_DSN',         '$dsn');
define('DB_USER',        '$user');
define('DB_PASS',        '$pass');
define('ADMIN_LOGIN',    '$adminUser');
define('ADMIN_PASSWORD', '$adminPass');
define('SITE_TITLE',     '$siteTitle');
define('APP_VERSION',    '$appVersion');
PHP;
            $saved = @file_put_contents($configFile, $configData);
            if ($saved !== false) {
                file_put_contents($installedFlag, 'installed');
                $success = true;
            } else {
                $manualConfig = htmlspecialchars($configData);
                $error = "Не удалось записать includes/config.php — создайте файл вручную:";
            }

        } catch (Exception $e) {
            $error = "Ошибка подключения: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Установка Adminis</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: Inter, system-ui, sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px;
    color: #1e2130;
}

.setup-card {
    background: #fff;
    border: 1px solid #e5e7ef;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(0,0,0,.07);
    width: 100%;
    max-width: 620px;
    overflow: hidden;
}

.setup-header {
    background: #1e2130;
    padding: 28px 32px;
    text-align: center;
}
.setup-header h1 {
    color: #fff;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: .3px;
}
.setup-header p {
    color: #9ca3c4;
    font-size: 13px;
    margin-top: 6px;
}

.setup-body { padding: 28px 32px; }

/* Секции */
.section {
    margin-bottom: 28px;
}
.section-title {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: #9ca3c4;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f2f5;
}

/* Требования */
.req-table { width: 100%; border-collapse: collapse; }
.req-table td {
    padding: 7px 0;
    font-size: 13px;
    border-bottom: 1px solid #f5f6fa;
}
.req-table td:last-child { text-align: right; width: 32px; }
.req-ok   { color: #16a34a; font-size: 16px; }
.req-fail { color: #dc2626; font-size: 16px; }

/* Форма */
.form-group { margin-bottom: 14px; }
.form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #6b7499;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #d1d5e8;
    border-radius: 8px;
    font-size: 14px;
    color: #1e2130;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.form-control:focus {
    border-color: #4f6ef7;
    box-shadow: 0 0 0 3px rgba(79,110,247,.12);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.form-hint {
    font-size: 11px;
    color: #9ca3c4;
    margin-top: 4px;
}

/* Алерты */
.alert {
    padding: 12px 16px;
    border-radius: 9px;
    font-size: 13px;
    margin-bottom: 16px;
    line-height: 1.5;
}
.alert-danger  { background: #fff0f0; border: 1px solid #fca5a5; color: #b91c1c; }
.alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; text-align: center; }

pre.manual-config {
    background: #f8f9fd;
    border: 1px solid #e5e7ef;
    border-radius: 8px;
    padding: 14px;
    font-size: 12px;
    overflow-x: auto;
    margin-top: 10px;
    white-space: pre-wrap;
    word-break: break-all;
}

/* Кнопка */
.btn-submit {
    width: 100%;
    padding: 12px;
    background: #4f6ef7;
    color: #fff;
    border: none;
    border-radius: 9px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, transform .1s;
    margin-top: 4px;
}
.btn-submit:hover  { background: #3d5ce5; }
.btn-submit:active { transform: scale(.99); }

.btn-link {
    display: inline-block;
    margin-top: 12px;
    padding: 10px 24px;
    background: #f0f2f5;
    color: #4f6ef7;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: background .15s;
}
.btn-link:hover { background: #e5e7ef; }

.warning-banner {
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 9px;
    padding: 10px 14px;
    font-size: 12px;
    color: #92400e;
    margin-bottom: 20px;
}
</style>
</head>
<body>
<div class="setup-card">

    <div class="setup-header">
        <h1>🚀 Установка Adminis</h1>
        <p>Мастер первоначальной настройки</p>
    </div>

    <div class="setup-body">

        <?php if (!$all_ok): ?>
        <div class="warning-banner">
            ⚠️ Не все требования выполнены. Установка может завершиться с ошибкой.
        </div>
        <?php endif; ?>

        <!-- Проверка окружения -->
        <div class="section">
            <div class="section-title">Проверка окружения</div>
            <table class="req-table">
                <?php foreach ($requirements as $label => $ok): ?>
                <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td><?= $ok ? '<span class="req-ok">✓</span>' : '<span class="req-fail">✗</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php if ($success): ?>

            <div class="alert alert-success">
                ✅ Установка завершена успешно!<br>
                Все таблицы созданы, конфигурация сохранена.<br><br>
                <a href="../index.php" class="btn-link">Перейти к сайту →</a>
            </div>

        <?php else: ?>

        <form method="post" action="">

            <!-- БД -->
            <div class="section">
                <div class="section-title">База данных (MySQL / MariaDB)</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Хост</label>
                        <input type="text" class="form-control" name="host"
                               value="<?= htmlspecialchars($_POST['host'] ?? 'localhost') ?>"
                               placeholder="localhost">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Имя базы данных <span style="color:#dc2626">*</span></label>
                        <input type="text" class="form-control" name="dbname" required
                               value="<?= htmlspecialchars($_POST['dbname'] ?? '') ?>"
                               placeholder="adminis_db">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Пользователь</label>
                        <input type="text" class="form-control" name="user"
                               value="<?= htmlspecialchars($_POST['user'] ?? '') ?>"
                               placeholder="root">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Пароль</label>
                        <input type="password" class="form-control" name="pass"
                               value="<?= htmlspecialchars($_POST['pass'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-hint">База данных должна быть создана заранее. Пользователь должен иметь права CREATE TABLE.</div>
            </div>

            <!-- Сайт -->
            <div class="section">
                <div class="section-title">Настройки сайта</div>
                <div class="form-group">
                    <label class="form-label">Название системы</label>
                    <input type="text" class="form-control" name="site_title"
                           value="<?= htmlspecialchars($_POST['site_title'] ?? 'Adminis') ?>"
                           placeholder="Adminis" required>
                </div>
            </div>

            <!-- Администратор -->
            <div class="section">
                <div class="section-title">Учётная запись администратора</div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Логин <span style="color:#dc2626">*</span></label>
                        <input type="text" class="form-control" name="admin_user" required
                               value="<?= htmlspecialchars($_POST['admin_user'] ?? '') ?>"
                               placeholder="admin">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Пароль <span style="color:#dc2626">*</span></label>
                        <input type="password" class="form-control" name="admin_pass" required
                               placeholder="••••••••">
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
                <?php if ($manualConfig): ?>
                <pre class="manual-config"><?= $manualConfig ?></pre>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-submit">Установить Adminis</button>

        </form>

        <?php endif; ?>

    </div>
</div>
</body>
</html>