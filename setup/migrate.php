<?php
/**
 * Мастер миграций базы данных.
 * 
 * Вызывается автоматически из version_check.php когда db_version < APP_VERSION.
 * Параметры GET: from=1.0.0, to=2.0.0, back=<url для редиректа после успеха>
 * 
 * КАК ДОБАВИТЬ НОВУЮ МИГРАЦИЮ ПРИ ОБНОВЛЕНИИ САЙТА:
 *   1. Увеличь APP_VERSION в config.php (например с 2.0.0 до 2.1.0)
 *   2. Добавь новый элемент в массив $migrations ниже с ключом '2.1.0'
 *   3. Опиши SQL-изменения в 'up' и при желании откат в 'down'
 *   Всё — при следующем открытии сайта пользователь увидит экран миграции.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

$configFile = __DIR__ . '/../includes/config.php';
if (!file_exists($configFile)) {
    die('Сайт не установлен. <a href="index.php">Установить</a>');
}
require $configFile;

// Подключение к БД
try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die('Ошибка подключения к БД: ' . htmlspecialchars($e->getMessage()));
}

$toVersion = $_GET['to']  ?? APP_VERSION;
$backUrl   = $_GET['back'] ?? '../index.php';

// Если таблицы settings нет — создаём её и считаем версию БД 1.0.0
$settingsExists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'settings'")->fetchColumn();

if (!$settingsExists) {
    $pdo->exec("CREATE TABLE `settings` (
        `key`   VARCHAR(64)  NOT NULL PRIMARY KEY,
        `value` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO `settings` (`key`, `value`) VALUES ('db_version', '1.0.0')");
}

$fromVersion = $_GET['from']
    ?? $pdo->query("SELECT `value` FROM `settings` WHERE `key` = 'db_version'")->fetchColumn()
    ?: '1.0.0';

// ════════════════════════════════════════════════════════════════════════════
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ PHP-ШАГОВ МИГРАЦИЙ
// ════════════════════════════════════════════════════════════════════════════

function migrationMdToHtml(string $md): string {
    $lines  = explode("\n", $md);
    $html   = '';
    $inUl   = false;
    $inOl   = false;
    $inCode = false;

    $flushList = function() use (&$html, &$inUl, &$inOl) {
        if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
        if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
    };

    foreach ($lines as $line) {
        $t = rtrim($line);

        if (preg_match('/^```/', $t)) {
            if (!$inCode) {
                $flushList();
                $lang = trim(substr($t, 3));
                $html .= '<pre><code' . ($lang ? ' class="language-' . htmlspecialchars($lang) . '"' : '') . '>';
                $inCode = true;
            } else {
                $html .= "</code></pre>\n";
                $inCode = false;
            }
            continue;
        }
        if ($inCode) { $html .= htmlspecialchars($t) . "\n"; continue; }

        if (preg_match('/^---+$/', $t))                { $flushList(); $html .= "<hr>\n"; continue; }
        if (preg_match('/^(#{1,6})\s+(.+)$/', $t, $m)) { $flushList(); $lvl = strlen($m[1]); $html .= "<h$lvl>" . mdInline($m[2]) . "</h$lvl>\n"; continue; }
        if (preg_match('/^>\s?(.*)$/', $t, $m))         { $flushList(); $html .= '<blockquote>' . mdInline($m[1]) . "</blockquote>\n"; continue; }

        if (preg_match('/^[\*\-]\s+(.+)$/', $t, $m)) {
            if ($inOl) { $html .= "</ol>\n"; $inOl = false; }
            if (!$inUl) { $html .= "<ul>\n"; $inUl = true; }
            $html .= '<li>' . mdInline($m[1]) . "</li>\n";
            continue;
        }
        if (preg_match('/^\d+\.\s+(.+)$/', $t, $m)) {
            if ($inUl) { $html .= "</ul>\n"; $inUl = false; }
            if (!$inOl) { $html .= "<ol>\n"; $inOl = true; }
            $html .= '<li>' . mdInline($m[1]) . "</li>\n";
            continue;
        }

        if ($t === '') { $flushList(); continue; }
        $flushList();
        $html .= '<p>' . mdInline($t) . "</p>\n";
    }
    $flushList();
    return $html;
}

function mdInline(string $text): string {
    $text = preg_replace('/`([^`]+)`/',            '<code>$1</code>',     $text);
    $text = preg_replace('/\*\*(.+?)\*\*/',        '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/',            '<em>$1</em>',         $text);
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $text);
    return $text;
}

// ════════════════════════════════════════════════════════════════════════════
// РЕЕСТР МИГРАЦИЙ
// Ключ = целевая версия, 'up' = массив SQL-запросов для применения
// ════════════════════════════════════════════════════════════════════════════
$migrations = [

    // ── v1.0.0 → v1.1.0 ──────────────────────────────────────────────────
    // Пример: добавили тип 'Ноутбук' в devices
    '1.1.0' => [
        'title' => 'Добавление типа Ноутбук и таблицы computer_hardware',
        'steps' => [
            'Перевод устройств МФУ → Принтер' =>
                "UPDATE `devices` SET `type` = 'Принтер' WHERE `type` = 'МФУ'",

            'Изменение ENUM в таблице devices' =>
                "ALTER TABLE `devices` MODIFY COLUMN `type`
                 ENUM('ПК','Сервер','Принтер','Маршрутизатор','Свитч','Интерактивная доска','Ноутбук','Прочее') NOT NULL",

            'Создание таблицы computer_hardware' =>
                "CREATE TABLE IF NOT EXISTS `computer_hardware` (
                    `device_id`  INT          PRIMARY KEY,
                    `cpu`        VARCHAR(255) DEFAULT NULL,
                    `ram_gb`     VARCHAR(64)  DEFAULT NULL,
                    `hdd_gb`     VARCHAR(64)  DEFAULT NULL,
                    `gpu`        VARCHAR(255) DEFAULT NULL,
                    `os`         VARCHAR(255) DEFAULT NULL,
                    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `computer_hardware_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы computer_components' =>
                "CREATE TABLE IF NOT EXISTS `computer_components` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Перенос данных device_specs → computer_hardware' =>
                "INSERT IGNORE INTO `computer_hardware` (device_id, cpu, ram_gb, hdd_gb, gpu, os)
                 SELECT device_id, cpu, ram, storage, gpu, os FROM `device_specs`
                 WHERE EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'device_specs')",
        ],
    ],

    // ── v1.1.0 → v2.0.0 ──────────────────────────────────────────────────
    // Большое обновление: employees, journal, server_visits, документация 2.0
    '2.0.0' => [
        'title' => 'Обновление до версии 2.0 — сотрудники, журнал, визиты в серверную',
        'steps' => [
            'Создание таблицы employees (вместо teachers)' =>
                "CREATE TABLE IF NOT EXISTS `employees` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Перенос учителей → сотрудники' =>
                "INSERT IGNORE INTO `employees` (id, full_name)
                 SELECT id, full_name FROM `teachers`
                 WHERE EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'teachers')",

            'Перевод устройств МФУ → Принтер' =>
                "UPDATE `devices` SET `type` = 'Принтер' WHERE `type` = 'МФУ'",

            'Добавление типа ИБП в ENUM devices.type (без МФУ)' =>
                "ALTER TABLE devices MODIFY COLUMN type ENUM(
                    'ПК','Сервер','Принтер','Маршрутизатор','Свитч',
                    'Интерактивная доска','Ноутбук','ИБП','Прочее'
                ) NOT NULL DEFAULT 'Прочее'",

            'Создание таблицы journal' =>
                "CREATE TABLE IF NOT EXISTS `journal` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Перенос ноутбуков: создание устройств и записей журнала' => function($pdo) {
                $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = 'laptops'")->fetchColumn();
                if (!$exists) return 'таблица laptops не найдена — пропускаем';

                $laptops = $pdo->query("SELECT * FROM laptops ORDER BY number, id")->fetchAll(PDO::FETCH_ASSOC);
                if (!$laptops) return 'нет записей в laptops';

                $fallbackRoom = $pdo->query("SELECT id FROM rooms LIMIT 1")->fetchColumn();
                if (!$fallbackRoom) return 'нет кабинетов — невозможно создать устройства';

                $insDev = $pdo->prepare(
                    "INSERT INTO devices (room_id, name, type, status) VALUES (?, ?, 'Ноутбук', ?)"
                );
                $insJrn = $pdo->prepare(
                    "INSERT INTO journal (device_id, employee_id, is_permanent, start_date, end_date, status, comment)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                // Для каждого номера определяем room_id и статус:
                // берём из последней записи со статусом 'взят', иначе из любой
                $numberMeta = [];
                foreach ($laptops as $l) {
                    $num = (int)$l['number'];
                    if (!isset($numberMeta[$num]) || $l['status'] === 'взят') {
                        $numberMeta[$num] = [
                            'room_id' => $l['room_id'] ?: $fallbackRoom,
                            'status'  => $l['status'],
                        ];
                    }
                }

                // Создаём по одному устройству на каждый уникальный номер
                $numberToDeviceId = [];
                $cntDev = 0;
                foreach ($numberMeta as $num => $meta) {
                    $deviceStatus = ($meta['status'] === 'взят') ? 'В работе' : 'На хранении';
                    $insDev->execute([$meta['room_id'], "Ноутбук $num", $deviceStatus]);
                    $numberToDeviceId[$num] = (int)$pdo->lastInsertId();
                    $cntDev++;
                }

                // Создаём записи журнала
                $cntJrn = 0; $cntSkip = 0;
                foreach ($laptops as $l) {
                    $devId = $numberToDeviceId[(int)$l['number']] ?? null;
                    if (!$devId) { $cntSkip++; continue; }

                    $empExists = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id = ?");
                    $empExists->execute([$l['teacher_id']]);
                    if (!$empExists->fetchColumn()) { $cntSkip++; continue; }

                    $insJrn->execute([
                        $devId, $l['teacher_id'], $l['is_permanent'],
                        $l['start_date'] ?: null, $l['end_date'] ?: null,
                        $l['status'], $l['comment'],
                    ]);
                    $cntJrn++;
                }

                return "создано устройств: $cntDev, записей журнала: $cntJrn" . ($cntSkip ? ", пропущено: $cntSkip" : '');
            },

            'Создание таблицы server_visits' =>
                "CREATE TABLE IF NOT EXISTS `server_visits` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблиц инцидентов серверной' =>
                "CREATE TABLE IF NOT EXISTS `server_visit_issues` (
                    `id`                INT AUTO_INCREMENT PRIMARY KEY,
                    `device_name`       VARCHAR(255) NOT NULL,
                    `problem`           TEXT         NOT NULL,
                    `notified`          VARCHAR(255) DEFAULT NULL,
                    `reported_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at`       TIMESTAMP    NULL     DEFAULT NULL,
                    `resolved_visit_id` INT          DEFAULT NULL,
                    KEY `resolved_visit_id` (`resolved_visit_id`),
                    CONSTRAINT `server_visit_issues_ibfk_1` FOREIGN KEY (`resolved_visit_id`) REFERENCES `server_visits` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы связей инцидентов' =>
                "CREATE TABLE IF NOT EXISTS `server_visit_issue_links` (
                    `visit_id` INT NOT NULL,
                    `issue_id` INT NOT NULL,
                    PRIMARY KEY (`visit_id`, `issue_id`),
                    KEY `issue_id` (`issue_id`),
                    CONSTRAINT `server_visit_issue_links_ibfk_1` FOREIGN KEY (`visit_id`) REFERENCES `server_visits`       (`id`) ON DELETE CASCADE,
                    CONSTRAINT `server_visit_issue_links_ibfk_2` FOREIGN KEY (`issue_id`) REFERENCES `server_visit_issues` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы computer_issues' =>
                "CREATE TABLE IF NOT EXISTS `computer_issues` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`   INT  NOT NULL,
                    `component`   VARCHAR(64) DEFAULT NULL,
                    `description` TEXT NOT NULL,
                    `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                    `resolution`  TEXT        DEFAULT NULL,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `computer_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы computer_history' =>
                "CREATE TABLE IF NOT EXISTS `computer_history` (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Обновление структуры documentation (добавление иерархии)' =>
                "ALTER TABLE `documentation`
                    ADD COLUMN IF NOT EXISTS `parent_id`   INT  DEFAULT NULL AFTER `updated_at`,
                    ADD COLUMN IF NOT EXISTS `order_index` INT  DEFAULT 0    AFTER `parent_id`,
                    ADD COLUMN IF NOT EXISTS `type`        ENUM('section','content') DEFAULT 'content' AFTER `order_index`,
                    ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL AFTER `type`,
                    ADD COLUMN IF NOT EXISTS `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `description`",

            'Создание VIEW documentation_hierarchy' =>
                "CREATE OR REPLACE VIEW `documentation_hierarchy` AS
                 SELECT d.id, d.title, d.content, d.parent_id, d.type, d.order_index, d.description,
                        COUNT(p.id) AS level
                 FROM documentation d
                 LEFT JOIN documentation p ON d.parent_id = p.id
                 GROUP BY d.id
                 ORDER BY d.order_index",

            'Конвертация документации Markdown → HTML' => function($pdo) {
                $docs = $pdo->query("SELECT id, content FROM documentation WHERE type = 'content'")->fetchAll(PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare("UPDATE documentation SET content = ? WHERE id = ?");
                $cnt  = 0;
                foreach ($docs as $doc) {
                    $content = $doc['content'];
                    // Пропускаем если уже HTML
                    if (preg_match('/<(p|h[1-6]|ul|ol|li|strong|em|code|pre|blockquote)\b/i', $content)) continue;
                    // Пропускаем если нет markdown-паттернов
                    if (!preg_match('/^#{1,6}\s|^\*\s|^\-\s|\*\*.+\*\*|`[^`]+`|^---/m', $content)) continue;
                    $stmt->execute([migrationMdToHtml($content), $doc['id']]);
                    $cnt++;
                }
                return $cnt > 0 ? "сконвертировано документов: $cnt" : "нечего конвертировать";
            },
        ],
    ],

    // ── v2.0.0 → v2.1.0 ──────────────────────────────────────────────────
    // Расширение computer_hardware: паспортные данные устройства
    '2.1.0' => [
        'title' => 'Паспортные данные компьютеров — производитель, модель, гарантия',
        'steps' => [
            'Добавление колонки manufacturer' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `manufacturer` VARCHAR(255) DEFAULT NULL COMMENT 'Производитель' AFTER `os`",

            'Добавление колонки model' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `model` VARCHAR(255) DEFAULT NULL COMMENT 'Модель' AFTER `manufacturer`",

            'Добавление колонки serial_number' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `serial_number` VARCHAR(255) DEFAULT NULL COMMENT 'Серийный номер' AFTER `model`",

            'Добавление колонки year_manufactured' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `year_manufactured` SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Год производства' AFTER `serial_number`",

            'Добавление колонки commissioned_at' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `commissioned_at` DATE DEFAULT NULL COMMENT 'Дата ввода в эксплуатацию' AFTER `year_manufactured`",

            'Добавление колонки warranty_until' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `warranty_until` DATE DEFAULT NULL COMMENT 'Гарантия до' AFTER `commissioned_at`",

            'Добавление колонки floor' =>
                "ALTER TABLE `computer_hardware` ADD COLUMN IF NOT EXISTS `floor` VARCHAR(32) DEFAULT NULL COMMENT 'Этаж' AFTER `warranty_until`",

            'Создание таблицы server_hardware' =>
                "CREATE TABLE IF NOT EXISTS `server_hardware` (
                    `device_id`          INT           PRIMARY KEY,
                    `cpu`                VARCHAR(255)  DEFAULT NULL,
                    `ram_gb`             VARCHAR(64)   DEFAULT NULL,
                    `hdd_gb`             VARCHAR(64)   DEFAULT NULL,
                    `os`                 VARCHAR(255)  DEFAULT NULL COMMENT 'ОС / Гипервизор',
                    `form_factor`        VARCHAR(32)   DEFAULT NULL COMMENT 'Форм-фактор (1U/2U/Tower...)',
                    `manufacturer`       VARCHAR(255)  DEFAULT NULL,
                    `model`              VARCHAR(255)  DEFAULT NULL,
                    `serial_number`      VARCHAR(255)  DEFAULT NULL,
                    `year_manufactured`  SMALLINT UNSIGNED DEFAULT NULL,
                    `commissioned_at`    DATE          DEFAULT NULL,
                    `warranty_until`     DATE          DEFAULT NULL,
                    `floor`              VARCHAR(32)   DEFAULT NULL,
                    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `server_hardware_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы server_history' =>
                "CREATE TABLE IF NOT EXISTS `server_history` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`  INT         NOT NULL,
                    `action`     VARCHAR(64) NOT NULL,
                    `field_name` VARCHAR(64) DEFAULT NULL,
                    `old_value`  TEXT        DEFAULT NULL,
                    `new_value`  TEXT        DEFAULT NULL,
                    `note`       TEXT        DEFAULT NULL,
                    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `server_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы server_issues' =>
                "CREATE TABLE IF NOT EXISTS `server_issues` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`   INT  NOT NULL,
                    `component`   VARCHAR(64) DEFAULT NULL,
                    `description` TEXT NOT NULL,
                    `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                    `resolution`  TEXT        DEFAULT NULL,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `server_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Удаление устаревшей таблицы server_stats' =>
                "DROP TABLE IF EXISTS `server_stats`",

            'Удаление устаревшей таблицы servers' =>
                "DROP TABLE IF EXISTS `servers`",

            'Создание таблицы notebook_hardware' =>
                "CREATE TABLE IF NOT EXISTS `notebook_hardware` (
                    `device_id`          INT           PRIMARY KEY,
                    `cpu`                VARCHAR(255)  DEFAULT NULL,
                    `ram_gb`             VARCHAR(64)   DEFAULT NULL,
                    `hdd_gb`             VARCHAR(64)   DEFAULT NULL,
                    `gpu`                VARCHAR(255)  DEFAULT NULL,
                    `os`                 VARCHAR(255)  DEFAULT NULL,
                    `manufacturer`       VARCHAR(255)  DEFAULT NULL,
                    `model`              VARCHAR(255)  DEFAULT NULL,
                    `serial_number`      VARCHAR(255)  DEFAULT NULL,
                    `year_manufactured`  SMALLINT UNSIGNED DEFAULT NULL,
                    `commissioned_at`    DATE          DEFAULT NULL,
                    `warranty_until`     DATE          DEFAULT NULL,
                    `floor`              VARCHAR(32)   DEFAULT NULL,
                    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `notebook_hardware_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы notebook_history' =>
                "CREATE TABLE IF NOT EXISTS `notebook_history` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`  INT         NOT NULL,
                    `action`     VARCHAR(64) NOT NULL,
                    `field_name` VARCHAR(64) DEFAULT NULL,
                    `old_value`  TEXT        DEFAULT NULL,
                    `new_value`  TEXT        DEFAULT NULL,
                    `note`       TEXT        DEFAULT NULL,
                    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `notebook_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы notebook_issues' =>
                "CREATE TABLE IF NOT EXISTS `notebook_issues` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`   INT  NOT NULL,
                    `component`   VARCHAR(64) DEFAULT NULL,
                    `description` TEXT NOT NULL,
                    `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                    `resolution`  TEXT        DEFAULT NULL,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `notebook_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы printer_passport' =>
                "CREATE TABLE IF NOT EXISTS `printer_passport` (
                    `device_id`          INT           PRIMARY KEY,
                    `manufacturer`       VARCHAR(255)  DEFAULT NULL,
                    `model`              VARCHAR(255)  DEFAULT NULL,
                    `serial_number`      VARCHAR(255)  DEFAULT NULL,
                    `year_manufactured`  SMALLINT UNSIGNED DEFAULT NULL,
                    `commissioned_at`    DATE          DEFAULT NULL,
                    `warranty_until`     DATE          DEFAULT NULL,
                    `connection_type`    VARCHAR(32)   DEFAULT NULL COMMENT 'USB/LAN/Wi-Fi',
                    `network_support`    TINYINT(1)    NOT NULL DEFAULT 0,
                    `floor`              VARCHAR(32)   DEFAULT NULL COMMENT 'Этаж',
                    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `printer_passport_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы printer_history' =>
                "CREATE TABLE IF NOT EXISTS `printer_history` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`  INT         NOT NULL,
                    `action`     VARCHAR(64) NOT NULL,
                    `field_name` VARCHAR(64) DEFAULT NULL,
                    `old_value`  TEXT        DEFAULT NULL,
                    `new_value`  TEXT        DEFAULT NULL,
                    `note`       TEXT        DEFAULT NULL,
                    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `printer_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы printer_issues' =>
                "CREATE TABLE IF NOT EXISTS `printer_issues` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`   INT  NOT NULL,
                    `component`   VARCHAR(64) DEFAULT NULL COMMENT 'fuser/rollers/film/cartridge/drum/repair_kit/maintenance/other',
                    `description` TEXT NOT NULL,
                    `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                    `resolution`  TEXT        DEFAULT NULL,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `printer_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы ups_hardware' =>
                "CREATE TABLE IF NOT EXISTS `ups_hardware` (
                    `device_id`          INT           PRIMARY KEY,
                    `power_va`           VARCHAR(32)   DEFAULT NULL COMMENT 'Мощность в ВА',
                    `battery_type`       VARCHAR(255)  DEFAULT NULL COMMENT 'Тип батареи',
                    `battery_replaced`   DATE          DEFAULT NULL COMMENT 'Дата замены батареи',
                    `manufacturer`       VARCHAR(255)  DEFAULT NULL,
                    `model`              VARCHAR(255)  DEFAULT NULL,
                    `serial_number`      VARCHAR(255)  DEFAULT NULL,
                    `year_manufactured`  SMALLINT UNSIGNED DEFAULT NULL,
                    `commissioned_at`    DATE          DEFAULT NULL,
                    `warranty_until`     DATE          DEFAULT NULL,
                    `floor`              VARCHAR(32)   DEFAULT NULL,
                    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `ups_hardware_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы ups_history' =>
                "CREATE TABLE IF NOT EXISTS `ups_history` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`  INT         NOT NULL,
                    `action`     VARCHAR(64) NOT NULL,
                    `field_name` VARCHAR(64) DEFAULT NULL,
                    `old_value`  TEXT        DEFAULT NULL,
                    `new_value`  TEXT        DEFAULT NULL,
                    `note`       TEXT        DEFAULT NULL,
                    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `ups_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы ups_issues' =>
                "CREATE TABLE IF NOT EXISTS `ups_issues` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`   INT  NOT NULL,
                    `component`   VARCHAR(64) DEFAULT NULL COMMENT 'battery/charging/output/signal/overheating/other',
                    `description` TEXT NOT NULL,
                    `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                    `resolution`  TEXT        DEFAULT NULL,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `ups_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // ── Коммутация ────────────────────────────────────────────────

            'Обновление ENUM devices.type — добавление Коммутатор (временно, со старыми типами)' =>
                "ALTER TABLE `devices` MODIFY COLUMN `type`
                 ENUM('ПК','Сервер','Принтер','Маршрутизатор','Свитч','Коммутатор','Интерактивная доска','Ноутбук','ИБП','Прочее')
                 NOT NULL DEFAULT 'Прочее'",

            'Миграция Свитч/Маршрутизатор → Коммутатор' =>
                "UPDATE `devices` SET `type` = 'Коммутатор' WHERE `type` IN ('Свитч', 'Маршрутизатор')",

            'Финальное обновление ENUM devices.type — удаление старых типов Свитч/Маршрутизатор' =>
                "ALTER TABLE `devices` MODIFY COLUMN `type`
                 ENUM('ПК','Сервер','Принтер','Коммутатор','Интерактивная доска','Ноутбук','ИБП','Прочее')
                 NOT NULL DEFAULT 'Прочее'",

            'Добавление колонки port_number в switch_links' =>
                "ALTER TABLE `switch_links`
                 ADD COLUMN IF NOT EXISTS `port_number` VARCHAR(16) DEFAULT NULL COMMENT 'Номер порта на коммутаторе' AFTER `connected_to_device_id`",

            'Создание таблицы switch_hardware' =>
                "CREATE TABLE IF NOT EXISTS `switch_hardware` (
                    `device_id`          INT           PRIMARY KEY,
                    `device_type`        VARCHAR(64)   DEFAULT NULL COMMENT 'Коммутатор/Маршрутизатор/Точка доступа/...',
                    `ports`              SMALLINT UNSIGNED DEFAULT NULL COMMENT 'Количество портов',
                    `port_speed`         VARCHAR(32)   DEFAULT NULL COMMENT 'Скорость портов',
                    `managed`            TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Управляемый',
                    `manufacturer`       VARCHAR(255)  DEFAULT NULL,
                    `model`              VARCHAR(255)  DEFAULT NULL,
                    `serial_number`      VARCHAR(255)  DEFAULT NULL,
                    `year_manufactured`  SMALLINT UNSIGNED DEFAULT NULL,
                    `commissioned_at`    DATE          DEFAULT NULL,
                    `warranty_until`     DATE          DEFAULT NULL,
                    `floor`              VARCHAR(32)   DEFAULT NULL,
                    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT `switch_hardware_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы switch_history' =>
                "CREATE TABLE IF NOT EXISTS `switch_history` (
                    `id`         INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`  INT         NOT NULL,
                    `action`     VARCHAR(64) NOT NULL,
                    `field_name` VARCHAR(64) DEFAULT NULL,
                    `old_value`  TEXT        DEFAULT NULL,
                    `new_value`  TEXT        DEFAULT NULL,
                    `note`       TEXT        DEFAULT NULL,
                    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `switch_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Создание таблицы switch_issues' =>
                "CREATE TABLE IF NOT EXISTS `switch_issues` (
                    `id`          INT AUTO_INCREMENT PRIMARY KEY,
                    `device_id`   INT  NOT NULL,
                    `component`   VARCHAR(64) DEFAULT NULL COMMENT 'ports/power/firmware/uplink/other',
                    `description` TEXT NOT NULL,
                    `reported_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `resolved_at` TIMESTAMP   NULL     DEFAULT NULL,
                    `resolution`  TEXT        DEFAULT NULL,
                    KEY `device_id` (`device_id`),
                    CONSTRAINT `switch_issues_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            'Добавление флага рекомендации к списанию в devices' =>
                "ALTER TABLE `devices`
                 ADD COLUMN IF NOT EXISTS `recommended_for_writeoff` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Рекомендовано к списанию' AFTER `icon`",

            'Добавление даты рекомендации к списанию в devices' =>
                "ALTER TABLE `devices`
                 ADD COLUMN IF NOT EXISTS `writeoff_recommended_at` DATE DEFAULT NULL COMMENT 'Дата рекомендации к списанию' AFTER `recommended_for_writeoff`",

            'Перевод устройств типа Прочее → Коммутатор' =>
                "UPDATE `devices` SET `type` = 'Коммутатор' WHERE `type` = 'Прочее'",

            'Создание записей switch_hardware для перенесённых устройств' => function($pdo) {
                // Для всех Коммутаторов у которых нет записи в switch_hardware — создаём с device_type=Прочее
                $rows = $pdo->query("
                    SELECT d.id FROM devices d
                    LEFT JOIN switch_hardware sh ON sh.device_id = d.id
                    WHERE d.type = 'Коммутатор' AND sh.device_id IS NULL
                ")->fetchAll(PDO::FETCH_COLUMN);
                if (!$rows) return 'нет устройств без записи';
                $ins = $pdo->prepare("INSERT IGNORE INTO switch_hardware (device_id, device_type) VALUES (?, 'Прочее')");
                foreach ($rows as $id) $ins->execute([$id]);
                return 'создано записей: ' . count($rows);
            },

            'Удаление типа Прочее из ENUM devices.type' =>
                "ALTER TABLE `devices` MODIFY COLUMN `type`
                 ENUM('ПК','Сервер','Принтер','Коммутатор','Интерактивная доска','Ноутбук','ИБП')
                 NOT NULL DEFAULT 'Коммутатор'",
        ],
    ],

];

// ════════════════════════════════════════════════════════════════════════════
// ЛОГИКА ВЫПОЛНЕНИЯ
// ════════════════════════════════════════════════════════════════════════════

// Определяем какие миграции нужно применить (все версии > from и <= to)
$pendingMigrations = [];
foreach ($migrations as $ver => $migration) {
    if (version_compare($ver, $fromVersion, '>') && version_compare($ver, $toVersion, '<=')) {
        $pendingMigrations[$ver] = $migration;
    }
}
uksort($pendingMigrations, 'version_compare');

$runResults  = [];
$runErrors   = [];
$runSuccess  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {

    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    foreach ($pendingMigrations as $ver => $migration) {
        $versionOk = true;
        foreach ($migration['steps'] as $stepName => $step) {
            try {
                if (is_callable($step)) {
                    $note = $step($pdo);
                    $runResults[] = ['ver' => $ver, 'step' => $stepName, 'ok' => true, 'note' => $note ?? ''];
                } else {
                    $pdo->exec($step);
                    $runResults[] = ['ver' => $ver, 'step' => $stepName, 'ok' => true];
                }
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                // Игнорируем "уже существует" / "уже добавлено"
                if (strpos($msg, 'Duplicate') !== false ||
                    strpos($msg, 'already exists') !== false ||
                    strpos($msg, '1060') !== false || // duplicate column
                    strpos($msg, '1050') !== false    // table already exists
                ) {
                    $runResults[] = ['ver' => $ver, 'step' => $stepName, 'ok' => true, 'note' => 'уже существует'];
                } else {
                    $runResults[] = ['ver' => $ver, 'step' => $stepName, 'ok' => false, 'error' => $msg];
                    $runErrors[]  = "[$ver] $stepName: $msg";
                    $versionOk = false;
                }
            }
        }
        // Обновляем версию БД после каждой успешной блок-миграции
        if ($versionOk) {
            $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES ('db_version', ?)
                           ON DUPLICATE KEY UPDATE `value` = ?")->execute([$ver, $ver]);
        }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    // Если ошибок нет — финальное обновление версии и редирект
    if (empty($runErrors)) {
        $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES ('db_version', ?)
                       ON DUPLICATE KEY UPDATE `value` = ?")->execute([$toVersion, $toVersion]);
        $runSuccess = true;
    }
}

// Получаем текущую версию БД для отображения
$currentDbVer = $pdo->query("SELECT `value` FROM `settings` WHERE `key` = 'db_version'")->fetchColumn() ?: '—';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Обновление базы данных — Adminis</title>
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
.card {
    background: #fff;
    border: 1px solid #e5e7ef;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    width: 100%;
    max-width: 640px;
    overflow: hidden;
}
.card-header {
    background: #1e2130;
    padding: 26px 32px;
    text-align: center;
}
.card-header h1 { color: #fff; font-size: 22px; font-weight: 700; }
.card-header p  { color: #9ca3c4; font-size: 13px; margin-top: 6px; }
.card-body { padding: 28px 32px; }

.version-badge {
    display: flex; align-items: center; justify-content: center;
    gap: 16px; margin-bottom: 24px;
    padding: 16px; background: #f5f6fa; border-radius: 10px;
    font-size: 14px;
}
.v-box {
    text-align: center;
}
.v-box .label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3c4; margin-bottom: 4px; }
.v-box .ver   { font-size: 20px; font-weight: 700; color: #1e2130; font-family: monospace; }
.v-arrow { font-size: 22px; color: #4f6ef7; }
.v-box.new .ver { color: #4f6ef7; }

.section { margin-bottom: 22px; }
.section-title {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .7px; color: #9ca3c4; margin-bottom: 12px;
    padding-bottom: 8px; border-bottom: 1px solid #f0f2f5;
}

.migration-block {
    border: 1px solid #e5e7ef; border-radius: 9px; overflow: hidden; margin-bottom: 12px;
}
.migration-head {
    padding: 10px 14px; background: #f5f6fa;
    font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px;
}
.migration-steps { padding: 8px 14px; }
.step-row {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 6px 0; border-bottom: 1px solid #f5f6fa; font-size: 12px;
}
.step-row:last-child { border-bottom: none; }
.step-icon { flex-shrink: 0; width: 18px; text-align: center; }
.step-name { flex: 1; color: #374151; }
.step-note { font-size: 11px; color: #9ca3c4; }
.step-error { font-size: 11px; color: #dc2626; margin-top: 2px; word-break: break-all; }

.alert { padding: 12px 16px; border-radius: 9px; font-size: 13px; margin-bottom: 18px; line-height: 1.6; }
.alert-warn    { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
.alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #15803d; text-align: center; }
.alert-error   { background: #fff0f0; border: 1px solid #fca5a5; color: #b91c1c; }
.alert-info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1d4ed8; }
.alert-empty   { background: #f5f6fa; border: 1px solid #e5e7ef; color: #6b7499; text-align: center; padding: 24px; }

.btn {
    display: block; width: 100%; padding: 12px; border: none; border-radius: 9px;
    font-size: 15px; font-weight: 600; cursor: pointer; text-align: center;
    text-decoration: none; transition: background .15s; margin-top: 6px;
}
.btn-primary { background: #4f6ef7; color: #fff; }
.btn-primary:hover { background: #3d5ce5; }
.btn-secondary { background: #f0f2f5; color: #4f6ef7; }
.btn-secondary:hover { background: #e5e7ef; }

.no-updates { text-align: center; padding: 30px 0; color: #6b7499; font-size: 14px; }
</style>
</head>
<body>
<div class="card">

    <div class="card-header">
        <h1>🔄 Обновление базы данных</h1>
        <p><?= defined('SITE_TITLE') ? htmlspecialchars(SITE_TITLE) : 'Adminis' ?></p>
    </div>

    <div class="card-body">

    <!-- Версии -->
    <div class="version-badge">
        <div class="v-box">
            <div class="label">Версия БД</div>
            <div class="ver"><?= htmlspecialchars($currentDbVer) ?></div>
        </div>
        <div class="v-arrow">→</div>
        <div class="v-box new">
            <div class="label">Новая версия</div>
            <div class="ver"><?= htmlspecialchars($toVersion) ?></div>
        </div>
    </div>

    <?php if ($runSuccess): ?>

        <!-- УСПЕХ -->
        <div class="alert alert-success">
            ✅ База данных успешно обновлена до версии <strong><?= htmlspecialchars($toVersion) ?></strong>!
        </div>

        <div class="section">
            <div class="section-title">Что было выполнено</div>
            <?php
            $lastVer = '';
            foreach ($runResults as $r):
                if ($r['ver'] !== $lastVer):
                    if ($lastVer !== '') echo '</div></div>';
                    $lastVer = $r['ver'];
                    echo '<div class="migration-block"><div class="migration-head">📦 ' . htmlspecialchars($r['ver']) . '</div><div class="migration-steps">';
                endif;
            ?>
            <div class="step-row">
                <span class="step-icon"><?= $r['ok'] ? '✅' : '❌' ?></span>
                <span class="step-name">
                    <?= htmlspecialchars($r['step']) ?>
                    <?php if (!empty($r['note'])): ?><span class="step-note">(<?= htmlspecialchars($r['note']) ?>)</span><?php endif; ?>
                    <?php if (!empty($r['error'])): ?><div class="step-error"><?= htmlspecialchars($r['error']) ?></div><?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if ($lastVer !== '') echo '</div></div>'; ?>
        </div>

        <a href="<?= htmlspecialchars(urldecode($backUrl)) ?>" class="btn btn-primary">
            Перейти к сайту →
        </a>

    <?php elseif (!empty($runResults) && !$runSuccess): ?>

        <!-- ОШИБКИ -->
        <div class="alert alert-error">
            ❌ Во время миграции возникли ошибки. Часть изменений могла применится, часть — нет.<br>
            Проверьте лог ниже и обратитесь к администратору.
        </div>

        <div class="section">
            <div class="section-title">Лог выполнения</div>
            <?php
            $lastVer = '';
            foreach ($runResults as $r):
                if ($r['ver'] !== $lastVer):
                    if ($lastVer !== '') echo '</div></div>';
                    $lastVer = $r['ver'];
                    echo '<div class="migration-block"><div class="migration-head">📦 ' . htmlspecialchars($r['ver']) . '</div><div class="migration-steps">';
                endif;
            ?>
            <div class="step-row">
                <span class="step-icon"><?= $r['ok'] ? '✅' : '❌' ?></span>
                <span class="step-name">
                    <?= htmlspecialchars($r['step']) ?>
                    <?php if (!empty($r['error'])): ?><div class="step-error"><?= htmlspecialchars($r['error']) ?></div><?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php if ($lastVer !== '') echo '</div></div>'; ?>
        </div>

        <form method="post">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="btn btn-primary">Попробовать ещё раз</button>
        </form>

    <?php elseif (empty($pendingMigrations)): ?>

        <!-- БД УЖЕ АКТУАЛЬНА -->
        <div class="no-updates">
            ✅ База данных уже актуальна — обновлений не требуется.
        </div>
        <a href="<?= htmlspecialchars(urldecode($backUrl)) ?>" class="btn btn-primary">Перейти к сайту →</a>

    <?php else: ?>

        <!-- ЭКРАН ПОДТВЕРЖДЕНИЯ -->
        <div class="alert alert-warn">
            ⚠️ Перед обновлением рекомендуется сделать резервную копию базы данных.
        </div>

        <div class="section">
            <div class="section-title">Будут применены следующие изменения</div>
            <?php foreach ($pendingMigrations as $ver => $migration): ?>
            <div class="migration-block">
                <div class="migration-head">
                    📦 Версия <?= htmlspecialchars($ver) ?> — <?= htmlspecialchars($migration['title']) ?>
                </div>
                <div class="migration-steps">
                    <?php foreach ($migration['steps'] as $stepName => $step): ?>
                    <div class="step-row">
                        <span class="step-icon"><?= is_callable($step) ? '⚙️' : '▸' ?></span>
                        <span class="step-name"><?= htmlspecialchars($stepName) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="post">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="btn btn-primary">Применить обновления →</button>
        </form>

        <a href="<?= htmlspecialchars(urldecode($backUrl)) ?>" class="btn btn-secondary" style="margin-top:8px">
            Отменить и вернуться на сайт
        </a>

    <?php endif; ?>

    </div>
</div>
</body>
</html>