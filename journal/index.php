<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

// Статистика для карточек
$laptops_taken   = $pdo->query("SELECT COUNT(*) FROM journal WHERE status='взят'")->fetchColumn();
$total_notebooks = $pdo->query("SELECT COUNT(*) FROM devices WHERE type='Ноутбук'")->fetchColumn();
$laptops_free    = max(0, $total_notebooks - $laptops_taken);

// Серверная
$server_visits_total = $pdo->query("SELECT COUNT(*) FROM server_visits")->fetchColumn();
$server_visits_month = $pdo->query("SELECT COUNT(*) FROM server_visits WHERE MONTH(visited_at)=MONTH(NOW()) AND YEAR(visited_at)=YEAR(NOW())")->fetchColumn();
$server_open_issues  = $pdo->query("SELECT COUNT(*) FROM server_visit_issues WHERE resolved_at IS NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журналы</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        .journal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 8px;
        }
        .journal-card {
            background: #fff;
            border: 1px solid #e5e7ef;
            border-radius: 12px;
            padding: 24px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: box-shadow .2s, border-color .2s, transform .15s;
        }
        .journal-card:hover {
            box-shadow: 0 6px 24px rgba(79,110,247,.12);
            border-color: #4f6ef7;
            transform: translateY(-2px);
            text-decoration: none;
            color: inherit;
        }
        .journal-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .journal-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #1e2130;
        }
        .journal-card-desc {
            font-size: 13px;
            color: #6b7499;
            line-height: 1.5;
        }
        .journal-card-stats {
            display: flex;
            gap: 16px;
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f0f2f5;
        }
        .journal-stat {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .journal-stat-value {
            font-size: 18px;
            font-weight: 700;
        }
        .journal-stat-label {
            font-size: 11px;
            color: #9ca3c4;
        }
        .journal-card-soon {
            opacity: .55;
            cursor: default;
            pointer-events: none;
        }
        .soon-badge {
            font-size: 11px;
            background: #f0f2f5;
            color: #9ca3c4;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
            align-self: flex-start;
        }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0" style="text-align:left">Журналы</h1>
        </div>

        <div class="journal-grid">

            <!-- Журнал выдачи ноутбуков -->
            <a href="laptops/" class="journal-card">
                <div class="journal-card-icon" style="background:#fff7ed">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="1.5">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                        <line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </div>
                <div>
                    <div class="journal-card-title">Выдача ноутбуков</div>
                    <div class="journal-card-desc">Учёт выданных сотрудникам ноутбуков, даты выдачи и возврата.</div>
                </div>
                <div class="journal-card-stats">
                    <div class="journal-stat">
                        <span class="journal-stat-value" style="color:#ea580c"><?= $laptops_taken ?></span>
                        <span class="journal-stat-label">На руках</span>
                    </div>
                    <div class="journal-stat">
                        <span class="journal-stat-value" style="color:#7c3aed"><?= $laptops_free ?></span>
                        <span class="journal-stat-label">Свободно</span>
                    </div>
                    <div class="journal-stat">
                        <span class="journal-stat-value" style="color:#6b7499"><?= $total_notebooks ?></span>
                        <span class="journal-stat-label">Всего ноутбуков</span>
                    </div>
                </div>
            </a>

            <!-- Журнал посещений серверной -->
            <a href="server/" class="journal-card">
                <div class="journal-card-icon" style="background:#f5f3ff">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="1.5">
                        <rect x="2" y="2" width="20" height="8" rx="2"/>
                        <rect x="2" y="14" width="20" height="8" rx="2"/>
                        <line x1="6" y1="6" x2="6.01" y2="6"/>
                        <line x1="6" y1="18" x2="6.01" y2="18"/>
                    </svg>
                </div>
                <div>
                    <div class="journal-card-title">Посещения серверной</div>
                    <div class="journal-card-desc">Журнал обходов серверного помещения, проверка оборудования и фиксация проблем.</div>
                </div>
                <div class="journal-card-stats">
                    <div class="journal-stat">
                        <span class="journal-stat-value" style="color:#7c3aed"><?= $server_visits_month ?></span>
                        <span class="journal-stat-label">В этом месяце</span>
                    </div>
                    <div class="journal-stat">
                        <span class="journal-stat-value" style="color:<?= $server_open_issues > 0 ? '#dc2626' : '#16a34a' ?>"><?= $server_open_issues ?></span>
                        <span class="journal-stat-label">Открытых проблем</span>
                    </div>
                    <div class="journal-stat">
                        <span class="journal-stat-value" style="color:#6b7499"><?= $server_visits_total ?></span>
                        <span class="journal-stat-label">Всего посещений</span>
                    </div>
                </div>
            </a>

        </div>

    </div>
</div>
</body>
</html>