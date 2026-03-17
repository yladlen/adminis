<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Очистка списанных устройств
if (isset($_POST['action']) && $_POST['action'] === 'cleanup') {
    $months = (int)($_POST['months'] ?? 6);
    $stmt = $pdo->prepare("DELETE FROM devices WHERE status = 'Списан' AND updated_at < DATE_SUB(NOW(), INTERVAL ? MONTH)");
    $stmt->execute([$months]);
    $deleted = $stmt->rowCount();
    $success = "Удалено $deleted списанных устройств старше $months мес.";
}

// ── Статистика ───────────────────────────────────────────────────────────────
$stats = [];
$stats['devices']   = $pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$stats['rooms']     = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$stats['employees'] = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$stats['broken']    = $pdo->query("SELECT COUNT(*) FROM devices WHERE status='На ремонте'")->fetchColumn();
$stats['written']   = $pdo->query("SELECT COUNT(*) FROM devices WHERE status='Списан'")->fetchColumn();
$stats['storage']   = $pdo->query("SELECT COUNT(*) FROM devices WHERE status='На хранении'")->fetchColumn();

// По типам
$typeRows = $pdo->query("SELECT type, COUNT(*) as cnt FROM devices GROUP BY type ORDER BY cnt DESC")->fetchAll();

// ── Проверка целостности ─────────────────────────────────────────────────────
$integrity = [];

$orphanDevices = $pdo->query("SELECT COUNT(*) FROM devices d LEFT JOIN rooms r ON r.id=d.room_id WHERE r.id IS NULL AND d.room_id IS NOT NULL")->fetchColumn();
if ($orphanDevices > 0)
    $integrity[] = ['warn', "Устройств без кабинета: $orphanDevices"];

$noIpDevices = $pdo->query("SELECT COUNT(*) FROM devices WHERE type IN ('ПК','Сервер','Маршрутизатор','Свитч') AND (ip IS NULL OR ip='')")->fetchColumn();
if ($noIpDevices > 0)
    $integrity[] = ['info', "Устройств без IP (ПК/Серверы/Сеть): $noIpDevices"];

$noInvDevices = $pdo->query("SELECT COUNT(*) FROM devices WHERE (inventory_number IS NULL OR inventory_number='') AND type IN ('ПК','Сервер','Ноутбук','Принтер')")->fetchColumn();
if ($noInvDevices > 0)
    $integrity[] = ['info', "Устройств без инвентарного номера: $noInvDevices"];

// Журнал — записи с несуществующим сотрудником
try {
    $orphanJournal = $pdo->query("SELECT COUNT(*) FROM journal j LEFT JOIN employees e ON e.id=j.employee_id WHERE e.id IS NULL")->fetchColumn();
    if ($orphanJournal > 0)
        $integrity[] = ['warn', "Записей журнала с удалённым сотрудником: $orphanJournal"];
} catch (Exception $e) {}

if (empty($integrity))
    $integrity[] = ['ok', 'Проблем не обнаружено — база данных в порядке'];

// ── Последние изменения ──────────────────────────────────────────────────────
try {
    $recentChanges = $pdo->query("
        SELECT ch.action, ch.description, ch.created_at, d.name as device_name
        FROM computer_history ch
        LEFT JOIN devices d ON d.id = ch.device_id
        ORDER BY ch.created_at DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {
    $recentChanges = [];
}

// ── Версии ───────────────────────────────────────────────────────────────────
$dbVersion = $pdo->query("SELECT value FROM settings WHERE `key`='db_version'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админ-панель</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">⚙️ Админ-панель</h1>
            <div style="font-size:13px;color:#6b7499">
                Код: <strong>v<?= APP_VERSION ?></strong> &nbsp;·&nbsp; БД: <strong>v<?= htmlspecialchars($dbVersion ?: '—') ?></strong>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- ── Статистика ── -->
        <div class="admin-section">
            <div class="admin-section-title">📊 Статистика</div>
            <div class="admin-stats-grid">
                <div class="admin-stat">
                    <div class="admin-stat-value"><?= $stats['devices'] ?></div>
                    <div class="admin-stat-label">Устройств</div>
                </div>
                <div class="admin-stat">
                    <div class="admin-stat-value"><?= $stats['rooms'] ?></div>
                    <div class="admin-stat-label">Кабинетов</div>
                </div>
                <div class="admin-stat">
                    <div class="admin-stat-value"><?= $stats['employees'] ?></div>
                    <div class="admin-stat-label">Сотрудников</div>
                </div>
                <div class="admin-stat admin-stat-warn">
                    <div class="admin-stat-value"><?= $stats['broken'] ?></div>
                    <div class="admin-stat-label">На ремонте</div>
                </div>
                <div class="admin-stat admin-stat-muted">
                    <div class="admin-stat-value"><?= $stats['written'] ?></div>
                    <div class="admin-stat-label">Списано</div>
                </div>
                <div class="admin-stat admin-stat-info">
                    <div class="admin-stat-value"><?= $stats['storage'] ?></div>
                    <div class="admin-stat-label">На хранении</div>
                </div>
            </div>

            <?php if ($typeRows): ?>
            <div style="margin-top:16px">
                <div style="font-size:12px;font-weight:600;color:#6b7499;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">По типам</div>
                <div class="admin-type-bars">
                    <?php
                    $maxCnt = max(array_column($typeRows, 'cnt'));
                    foreach ($typeRows as $row):
                        $pct = $maxCnt > 0 ? round($row['cnt'] / $maxCnt * 100) : 0;
                    ?>
                    <div class="admin-type-bar-row">
                        <span class="admin-type-bar-label"><?= htmlspecialchars($row['type']) ?></span>
                        <div class="admin-type-bar-track">
                            <div class="admin-type-bar-fill" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="admin-type-bar-count"><?= $row['cnt'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Проверка целостности ── -->
        <div class="admin-section">
            <div class="admin-section-title">🏥 Проверка базы данных</div>
            <div class="admin-integrity-list">
                <?php foreach ($integrity as [$type, $msg]): ?>
                <div class="admin-integrity-item integrity-<?= $type ?>">
                    <?= $type === 'ok' ? '✅' : ($type === 'warn' ? '⚠️' : 'ℹ️') ?>
                    <?= htmlspecialchars($msg) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Бэкап ── -->
        <div class="admin-section">
            <div class="admin-section-title">💾 Резервная копия базы данных</div>
            <p style="font-size:13px;color:#6b7499;margin-bottom:12px">
                Скачать полный дамп БД в формате <code>.sql</code>. Файл содержит структуру и все данные.
            </p>
            <form method="POST">
                <a href="/adminis/setup/backup.php" class="btn btn-outline-success">💾 Скачать backup.sql</a>
            </form>
        </div>

        <!-- ── Миграции ── -->
        <div class="admin-section">
            <div class="admin-section-title">🔄 Миграции</div>
            <p style="font-size:13px;color:#6b7499;margin-bottom:12px">
                Текущая версия БД: <strong>v<?= htmlspecialchars($dbVersion ?: '—') ?></strong>,
                версия кода: <strong>v<?= APP_VERSION ?></strong>.
                <?php if ($dbVersion === APP_VERSION): ?>
                    <span style="color:#16a34a">База данных актуальна.</span>
                <?php else: ?>
                    <span style="color:#ea580c">Требуется обновление!</span>
                <?php endif; ?>
            </p>
            <a href="/adminis/setup/migrate.php" class="btn btn-outline-primary">🔄 Открыть страницу миграций</a>
        </div>

        <!-- ── Очистка ── -->
        <div class="admin-section">
            <div class="admin-section-title">🗑️ Очистка списанных устройств</div>
            <p style="font-size:13px;color:#6b7499;margin-bottom:12px">
                Удалить устройства со статусом <strong>«Списан»</strong>, которые не обновлялись дольше указанного срока.
                Сейчас таких: <strong><?= $stats['written'] ?></strong>.
            </p>
            <form method="POST" onsubmit="return confirm('Удалить списанные устройства? Это действие необратимо.')">
                <input type="hidden" name="action" value="cleanup">
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:13px">Старше</span>
                    <select name="months" class="form-select" style="width:100px">
                        <?php foreach ([3,6,12,24] as $m): ?>
                            <option value="<?= $m ?>" <?= $m==6?'selected':'' ?>><?= $m ?> мес.</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-danger">🗑️ Удалить</button>
                </div>
            </form>
        </div>

        <!-- ── Последние изменения ── -->
        <?php if (!empty($recentChanges)): ?>
        <div class="admin-section">
            <div class="admin-section-title">📋 Последние изменения</div>
            <table class="admin-log-table">
                <thead>
                    <tr><th>Дата</th><th>Устройство</th><th>Действие</th><th>Описание</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentChanges as $ch): ?>
                    <tr>
                        <td style="white-space:nowrap;color:#6b7499"><?= date('d.m.Y H:i', strtotime($ch['created_at'])) ?></td>
                        <td><?= htmlspecialchars($ch['device_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($ch['action'] ?? '—') ?></td>
                        <td style="color:#6b7499"><?= htmlspecialchars($ch['description'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>