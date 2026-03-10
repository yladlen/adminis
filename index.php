<?php
if (!file_exists(__DIR__ . '/includes/config.php')) {
    header('Location: setup/index.php');
    exit;
}
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/version_check.php';
require_once 'includes/navbar.php';
require_once 'includes/top_navbar.php';

// === Общая статистика ===
$totalRooms     = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$totalDevices   = $pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$totalComputers = $pdo->query("SELECT COUNT(*) FROM devices WHERE type = 'ПК'")->fetchColumn();
$totalServers   = $pdo->query("SELECT COUNT(*) FROM devices WHERE type = 'Сервер'")->fetchColumn();
$totalNotebooks = $pdo->query("SELECT COUNT(*) FROM devices WHERE type = 'Ноутбук'")->fetchColumn();
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();

// === Журнал выдачи ноутбуков ===
$journalTaken    = $pdo->query("SELECT COUNT(*) FROM journal WHERE status='взят'")->fetchColumn();
$journalReturned = $pdo->query("SELECT COUNT(*) FROM journal WHERE status='сдан'")->fetchColumn();
$journalPerm     = $pdo->query("SELECT COUNT(*) FROM journal WHERE is_permanent=1 AND status='взят'")->fetchColumn();
$journalFree     = max(0, $totalNotebooks - $journalTaken);

// === Последние 5 выдач ===
$recentIssues = $pdo->query("
    SELECT j.*, d.name AS device_name, d.inventory_number,
           t.full_name AS employee_name
    FROM journal j
    JOIN devices d ON j.device_id = d.id
    JOIN employees t ON j.employee_id = t.id
    ORDER BY j.start_date DESC, j.created_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// === Статусы устройств ===
$statusStats = $pdo->query("
    SELECT status, COUNT(*) as cnt FROM devices GROUP BY status ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// === Топ кабинетов по количеству устройств ===
$topRooms = $pdo->query("
    SELECT r.name, COUNT(d.id) as cnt
    FROM rooms r LEFT JOIN devices d ON d.room_id = r.id
    GROUP BY r.id, r.name ORDER BY cnt DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// === Устройства по типу (для мини-диаграммы) ===
$deviceTypes = $pdo->query("
    SELECT type, COUNT(*) as cnt FROM devices WHERE type != '' GROUP BY type ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// === Ноутбуки на руках скоро просроченные (end_date в ближайшие 7 дней) ===
$soonDue = $pdo->query("
    SELECT j.end_date, d.name AS device_name,
           t.full_name AS employee_name
    FROM journal j
    JOIN devices d ON j.device_id = d.id
    JOIN employees t ON j.employee_id = t.id
    WHERE j.status = 'взят' AND j.is_permanent = 0
      AND j.end_date IS NOT NULL
      AND j.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY j.end_date ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// === Просроченные возвраты ===
$overdue = $pdo->query("
    SELECT j.end_date, d.name AS device_name,
           t.full_name AS employee_name
    FROM journal j
    JOIN devices d ON j.device_id = d.id
    JOIN employees t ON j.employee_id = t.id
    WHERE j.status = 'взят' AND j.is_permanent = 0
      AND j.end_date IS NOT NULL AND j.end_date < CURDATE()
    ORDER BY j.end_date ASC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// === Данные для календаря (выдачи/возвраты в текущем месяце) ===
$calYear  = (int)($_GET['cal_year']  ?? date('Y'));
$calMonth = (int)($_GET['cal_month'] ?? date('n'));
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth = 1;  $calYear++; }

$calEvents = $pdo->prepare("
    SELECT
        DAY(start_date) AS day,
        'issue' AS etype,
        COUNT(*) AS cnt
    FROM journal
    WHERE YEAR(start_date) = ? AND MONTH(start_date) = ? AND start_date IS NOT NULL
    GROUP BY DAY(start_date)
    UNION ALL
    SELECT
        DAY(end_date) AS day,
        'return' AS etype,
        COUNT(*) AS cnt
    FROM journal
    WHERE YEAR(end_date) = ? AND MONTH(end_date) = ? AND end_date IS NOT NULL AND status = 'сдан'
    GROUP BY DAY(end_date)
");
$calEvents->execute([$calYear, $calMonth, $calYear, $calMonth]);
$calEventsRaw = $calEvents->fetchAll(PDO::FETCH_ASSOC);

// Build calendar map: day => {issue, return}
$calMap = [];
foreach ($calEventsRaw as $e) {
    $d = (int)$e['day'];
    if (!isset($calMap[$d])) $calMap[$d] = ['issue' => 0, 'return' => 0];
    $calMap[$d][$e['etype']] += $e['cnt'];
}

$calMonthNames = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
$daysInMonth   = cal_days_in_month(CAL_GREGORIAN, $calMonth, $calYear);
$firstWeekday  = (int)date('N', mktime(0,0,0,$calMonth,1,$calYear)); // 1=Mon, 7=Sun

$prevMonth = $calMonth - 1; $prevYear = $calYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $calMonth + 1; $nextYear = $calYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Главная — <?= defined('SITE_TITLE') ? SITE_TITLE : 'adminis' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        /* ── Dashboard layout ── */
        .dashboard { display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px; }
        .dashboard-full  { grid-column: 1 / -1; }
        .dashboard-half  { grid-column: span 1; }
        .dashboard-2col  { grid-column: span 2; }

        @media (max-width: 1100px) {
            .dashboard { grid-template-columns: 1fr 1fr; }
            .dashboard-2col { grid-column: span 2; }
        }
        @media (max-width: 700px) {
            .dashboard { grid-template-columns: 1fr; }
            .dashboard-2col, .dashboard-full { grid-column: span 1; }
        }

        /* ── Stat cards strip ── */
        .kpi-strip { display:grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:14px; }
        .kpi-card {
            background:#fff; border:1px solid #e5e7ef; border-radius:12px;
            padding:16px 18px; display:flex; flex-direction:column; gap:6px;
        }
        .kpi-card .kpi-icon {
            width:36px; height:36px; border-radius:8px;
            display:flex; align-items:center; justify-content:center; margin-bottom:4px;
        }
        .kpi-card .kpi-val { font-size:24px; font-weight:700; color:#1e2130; line-height:1; }
        .kpi-card .kpi-label { font-size:12px; color:#9ca3c4; }
        .kpi-card a { text-decoration:none; color:inherit; }
        .kpi-card:hover { border-color:#4f6ef7; box-shadow:0 4px 16px rgba(79,110,247,.1); }

        /* ── Dashboard panel ── */
        .panel {
            background:#fff; border:1px solid #e5e7ef; border-radius:12px;
            padding:20px 22px; display:flex; flex-direction:column; gap:16px;
        }
        .panel-title {
            font-size:14px; font-weight:700; color:#1e2130;
            display:flex; align-items:center; gap:8px;
        }
        .panel-title svg { flex-shrink:0; }

        /* ── Journal stat row ── */
        .journal-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
        .jstat {
            background:#f8f9ff; border:1px solid #e5e7ef; border-radius:10px;
            padding:14px 16px; text-align:center;
        }
        .jstat-val { font-size:22px; font-weight:700; }
        .jstat-lbl { font-size:11px; color:#9ca3c4; margin-top:2px; }

        /* ── Recent issues list ── */
        .recent-list { display:flex; flex-direction:column; gap:8px; }
        .recent-item {
            display:flex; align-items:center; justify-content:space-between;
            padding:10px 14px; background:#f8f9ff; border-radius:8px;
            font-size:13px; gap:10px;
        }
        .recent-item .ri-name { font-weight:600; color:#1e2130; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .recent-item .ri-device { color:#6b7499; font-size:12px; }
        .recent-item .ri-date { color:#9ca3c4; font-size:11px; white-space:nowrap; }

        /* ── Status bar chart ── */
        .status-bars { display:flex; flex-direction:column; gap:10px; }
        .status-bar-row { display:flex; align-items:center; gap:10px; font-size:13px; }
        .status-bar-label { width:170px; flex-shrink:0; color:#4b5275; }
        .status-bar-track { flex:1; background:#f0f2f5; border-radius:20px; height:8px; overflow:hidden; }
        .status-bar-fill  { height:100%; border-radius:20px; transition:width .4s; }
        .status-bar-count { width:32px; text-align:right; font-weight:600; color:#1e2130; font-size:13px; }

        /* ── Top rooms list ── */
        .top-rooms { display:flex; flex-direction:column; gap:8px; }
        .top-room-row { display:flex; align-items:center; gap:10px; font-size:13px; }
        .top-room-name { flex:1; color:#4b5275; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .top-room-bar { width:100px; background:#f0f2f5; border-radius:20px; height:6px; overflow:hidden; }
        .top-room-fill { height:100%; background:#4f6ef7; border-radius:20px; }
        .top-room-cnt  { width:28px; text-align:right; font-weight:600; color:#1e2130; font-size:13px; }

        /* ── Device type pills ── */
        .dtype-grid { display:flex; flex-wrap:wrap; gap:8px; }
        .dtype-pill {
            display:flex; align-items:center; gap:6px;
            background:#f0f2f5; border-radius:20px; padding:5px 12px; font-size:12px; color:#4b5275;
        }
        .dtype-pill strong { color:#1e2130; }

        /* ── Alert rows ── */
        .alert-list { display:flex; flex-direction:column; gap:8px; }
        .alert-row {
            display:flex; align-items:center; justify-content:space-between;
            padding:9px 14px; border-radius:8px; font-size:13px; gap:10px;
        }
        .alert-row.overdue  { background:#fff1f0; border:1px solid #fca5a5; }
        .alert-row.soon     { background:#fffbeb; border:1px solid #fcd34d; }
        .alert-row .ar-name { font-weight:600; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .alert-row .ar-date { font-size:11px; white-space:nowrap; }
        .alert-row.overdue .ar-date { color:#dc2626; }
        .alert-row.soon    .ar-date { color:#d97706; }

        /* ── Calendar ── */
        .cal-header {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:12px;
        }
        .cal-nav { display:flex; align-items:center; gap:8px; }
        .cal-nav a {
            width:28px; height:28px; border-radius:6px; background:#f0f2f5;
            display:flex; align-items:center; justify-content:center;
            color:#6b7499; text-decoration:none; font-size:14px;
            transition:background .15s;
        }
        .cal-nav a:hover { background:#4f6ef7; color:#fff; }
        .cal-month-title { font-size:14px; font-weight:700; color:#1e2130; }
        .cal-grid {
            display:grid; grid-template-columns:repeat(7,1fr); gap:4px;
        }
        .cal-dow {
            text-align:center; font-size:11px; font-weight:600;
            color:#9ca3c4; padding:4px 0; text-transform:uppercase;
        }
        .cal-dow.weekend { color:#f87171; }
        .cal-cell {
            min-height:52px; border-radius:8px; padding:5px 6px;
            background:#f8f9ff; border:1px solid transparent;
            display:flex; flex-direction:column; align-items:center; gap:3px;
            position:relative;
        }
        .cal-cell.empty { background:transparent; border-color:transparent; }
        .cal-cell.today { background:#eff3ff; border-color:#c7d2fe; }
        .cal-cell.has-events { border-color:#e0e7ff; }
        .cal-cell.weekend { background:#fff5f5; }
        .cal-cell.weekend .cal-day-num { color:#f87171; }
        .cal-cell.today.weekend { background:#eff3ff; }
        .cal-cell.today.weekend .cal-day-num { color:#4f6ef7; }
        .cal-day-num {
            font-size:12px; font-weight:600; color:#6b7499;
            align-self:flex-start; line-height:1;
        }
        .cal-cell.today .cal-day-num { color:#4f6ef7; }
        .cal-dots { display:flex; gap:3px; flex-wrap:wrap; justify-content:center; margin-top:2px; }
        .cal-dot {
            width:6px; height:6px; border-radius:50%;
        }
        .cal-dot.issue  { background:#f97316; }
        .cal-dot.return { background:#16a34a; }
        .cal-legend { display:flex; gap:12px; margin-top:8px; }
        .cal-legend-item { display:flex; align-items:center; gap:5px; font-size:11px; color:#9ca3c4; }
        .cal-legend-dot { width:8px; height:8px; border-radius:50%; }

        .empty-hint { font-size:13px; color:#9ca3c4; text-align:center; padding:16px 0; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <!-- KPI Strip -->
        <div class="kpi-strip mb-4">
            <a href="/adminis/rooms/" style="text-decoration:none">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#eff6ff">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    </div>
                    <div class="kpi-val"><?= $totalRooms ?></div>
                    <div class="kpi-label">Кабинетов</div>
                </div>
            </a>
            <a href="/adminis/rooms/" style="text-decoration:none">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#f0fdf4">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    </div>
                    <div class="kpi-val"><?= $totalDevices ?></div>
                    <div class="kpi-label">Всего устройств</div>
                </div>
            </a>
            <a href="/adminis/computers/" style="text-decoration:none">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fff7ed">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    </div>
                    <div class="kpi-val"><?= $totalComputers ?></div>
                    <div class="kpi-label">Компьютеров</div>
                </div>
            </a>
            <a href="/adminis/servers/" style="text-decoration:none">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fdf4ff">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9333ea" stroke-width="1.8"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                    </div>
                    <div class="kpi-val"><?= $totalServers ?></div>
                    <div class="kpi-label">Серверов</div>
                </div>
            </a>
            <a href="/adminis/notebooks/" style="text-decoration:none">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#fff7ed">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="1.8"><rect x="3" y="4" width="18" height="14" rx="1"/><line x1="3" y1="20" x2="21" y2="20"/></svg>
                    </div>
                    <div class="kpi-val"><?= $totalNotebooks ?></div>
                    <div class="kpi-label">Ноутбуков</div>
                </div>
            </a>
            <a href="/adminis/employees/" style="text-decoration:none">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background:#f0f9ff">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="1.8"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    </div>
                    <div class="kpi-val"><?= $totalEmployees ?></div>
                    <div class="kpi-label">Сотрудников</div>
                </div>
            </a>
        </div>

        <!-- Dashboard grid -->
        <div class="dashboard">

            <!-- Журнал выдачи ноутбуков — статистика -->
            <div class="panel dashboard-2col">
                <div class="panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    Журнал выдачи ноутбуков
                    <a href="/adminis/journal/laptops/" style="margin-left:auto;font-size:12px;color:#4f6ef7;font-weight:400;text-decoration:none">Открыть →</a>
                </div>
                <div class="journal-stats">
                    <div class="jstat">
                        <div class="jstat-val" style="color:#ea580c"><?= $journalTaken ?></div>
                        <div class="jstat-lbl">На руках</div>
                    </div>
                    <div class="jstat">
                        <div class="jstat-val" style="color:#16a34a"><?= $journalReturned ?></div>
                        <div class="jstat-lbl">Возвращено</div>
                    </div>
                    <div class="jstat">
                        <div class="jstat-val" style="color:#2563eb"><?= $journalPerm ?></div>
                        <div class="jstat-lbl">Постоянное польз.</div>
                    </div>
                    <div class="jstat">
                        <div class="jstat-val" style="color:#7c3aed"><?= $journalFree ?></div>
                        <div class="jstat-lbl">Свободно</div>
                    </div>
                </div>
            </div>

            <!-- Последние выдачи -->
            <div class="panel dashboard-half">
                <div class="panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Последние выдачи
                </div>
                <?php if (empty($recentIssues)): ?>
                    <p class="empty-hint">Записей нет</p>
                <?php else: ?>
                    <div class="recent-list">
                        <?php foreach ($recentIssues as $ri): ?>
                            <div class="recent-item">
                                <div style="flex:1;overflow:hidden">
                                    <div class="ri-name"><?= htmlspecialchars(trim($ri['employee_name'])) ?></div>
                                    <div class="ri-device"><?= htmlspecialchars($ri['device_name']) ?>
                                        <?php if ($ri['inventory_number']): ?>
                                            <span style="color:#c4c9dd">(<?= htmlspecialchars($ri['inventory_number']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ri-date">
                                    <?= $ri['start_date'] ? date('d.m.Y', strtotime($ri['start_date'])) : '—' ?>
                                    <div style="margin-top:2px">
                                        <span style="
                                            display:inline-block;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:600;
                                            <?= $ri['status'] === 'взят'
                                                ? 'background:#fff7ed;color:#ea580c;border:1px solid #fed7aa'
                                                : 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0' ?>
                                        "><?= $ri['status'] === 'взят' ? 'Взят' : 'Сдан' ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Статусы устройств -->
            <div class="panel dashboard-half">
                <div class="panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Статусы устройств
                </div>
                <?php
                $statusColors = [
                    'В работе'               => '#16a34a',
                    'На ремонте'             => '#d97706',
                    'Списан'                 => '#dc2626',
                    'На хранении'            => '#2563eb',
                    'Числится за кабинетом'  => '#7c3aed',
                ];
                $maxCnt = $statusStats ? max(array_column($statusStats, 'cnt')) : 1;
                ?>
                <div class="status-bars">
                    <?php foreach ($statusStats as $ss): ?>
                        <?php $color = $statusColors[$ss['status']] ?? '#9ca3c4'; ?>
                        <div class="status-bar-row">
                            <div class="status-bar-label"><?= htmlspecialchars($ss['status']) ?></div>
                            <div class="status-bar-track">
                                <div class="status-bar-fill" style="width:<?= round($ss['cnt'] / $maxCnt * 100) ?>%;background:<?= $color ?>"></div>
                            </div>
                            <div class="status-bar-count"><?= $ss['cnt'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:4px">
                    <div class="dtype-grid">
                        <?php foreach ($deviceTypes as $dt): ?>
                            <div class="dtype-pill"><strong><?= $dt['cnt'] ?></strong> <?= htmlspecialchars($dt['type']) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Просрочено и скоро -->
            <?php if (!empty($overdue) || !empty($soonDue)): ?>
            <div class="panel dashboard-half">
                <div class="panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Возвраты
                </div>
                <div class="alert-list">
                    <?php foreach ($overdue as $row): ?>
                        <div class="alert-row overdue">
                            <div class="ar-name"><?= htmlspecialchars($row['employee_name']) ?> — <?= htmlspecialchars($row['device_name']) ?></div>
                            <div class="ar-date">просрочен <?= date('d.m.Y', strtotime($row['end_date'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($soonDue as $row): ?>
                        <div class="alert-row soon">
                            <div class="ar-name"><?= htmlspecialchars($row['employee_name']) ?> — <?= htmlspecialchars($row['device_name']) ?></div>
                            <div class="ar-date">до <?= date('d.m.Y', strtotime($row['end_date'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Топ кабинетов -->
            <div class="panel dashboard-half">
                <div class="panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                    Кабинеты по устройствам
                </div>
                <?php $maxRoom = $topRooms ? max(array_column($topRooms, 'cnt')) : 1; ?>
                <div class="top-rooms">
                    <?php foreach ($topRooms as $tr): ?>
                        <div class="top-room-row">
                            <div class="top-room-name"><?= htmlspecialchars($tr['name']) ?></div>
                            <div class="top-room-bar">
                                <div class="top-room-fill" style="width:<?= $maxRoom > 0 ? round($tr['cnt'] / $maxRoom * 100) : 0 ?>%"></div>
                            </div>
                            <div class="top-room-cnt"><?= $tr['cnt'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Календарь -->
            <div class="panel dashboard-full">
                <div class="cal-header">
                    <div class="panel-title" style="margin:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f6ef7" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Календарь выдач и возвратов
                    </div>
                    <div class="cal-nav">
                        <a href="?cal_year=<?= $prevYear ?>&cal_month=<?= $prevMonth ?>">&#8592;</a>
                        <span class="cal-month-title"><?= $calMonthNames[$calMonth] ?> <?= $calYear ?></span>
                        <a href="?cal_year=<?= $nextYear ?>&cal_month=<?= $nextMonth ?>">&#8594;</a>
                    </div>
                </div>

                <div class="cal-grid">
                    <?php foreach (['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $i => $dow): ?>
                        <div class="cal-dow<?= $i >= 5 ? ' weekend' : '' ?>"><?= $dow ?></div>
                    <?php endforeach; ?>

                    <?php
                    // Empty cells before first day
                    for ($i = 1; $i < $firstWeekday; $i++) {
                        echo '<div class="cal-cell empty"></div>';
                    }
                    $today = (int)date('j');
                    $isCurrentMonth = ((int)date('n') === $calMonth && (int)date('Y') === $calYear);
                    for ($day = 1; $day <= $daysInMonth; $day++):
                        $hasEvents  = isset($calMap[$day]);
                        $isToday    = $isCurrentMonth && $day === $today;
                        $weekday    = (int)date('N', mktime(0,0,0,$calMonth,$day,$calYear)); // 6=Sat, 7=Sun
                        $isWeekend  = $weekday >= 6;
                        $cls = 'cal-cell'
                            . ($hasEvents  ? ' has-events' : '')
                            . ($isToday    ? ' today'      : '')
                            . ($isWeekend  ? ' weekend'    : '');
                    ?>
                        <div class="<?= $cls ?>">
                            <span class="cal-day-num"><?= $day ?></span>
                            <?php if ($hasEvents): ?>
                                <div class="cal-dots">
                                    <?php
                                    $iss = $calMap[$day]['issue'] ?? 0;
                                    $ret = $calMap[$day]['return'] ?? 0;
                                    for ($x = 0; $x < min($iss, 3); $x++) echo '<div class="cal-dot issue"></div>';
                                    for ($x = 0; $x < min($ret, 3); $x++) echo '<div class="cal-dot return"></div>';
                                    ?>
                                </div>
                                <div style="font-size:9px;color:#9ca3c4;line-height:1.3;text-align:center">
                                    <?php if ($iss) echo "<span style='color:#f97316'>+$iss</span> "; ?>
                                    <?php if ($ret) echo "<span style='color:#16a34a'>-$ret</span>"; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="cal-legend">
                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#f97316"></div>Выдача</div>
                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#16a34a"></div>Возврат</div>
                </div>
            </div>

        </div><!-- /dashboard -->
    </div>
</div>
</body>
</html>