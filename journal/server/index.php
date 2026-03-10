<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/navbar.php';
require_once '../../includes/top_navbar.php';

// === Статистика ===
$totalVisits  = $pdo->query("SELECT COUNT(*) FROM server_visits")->fetchColumn();
$thisMonth    = $pdo->query("SELECT COUNT(*) FROM server_visits WHERE MONTH(visited_at)=MONTH(NOW()) AND YEAR(visited_at)=YEAR(NOW())")->fetchColumn();
$openIssues   = $pdo->query("SELECT COUNT(*) FROM server_visit_issues WHERE resolved_at IS NULL")->fetchColumn();
$resolvedAll  = $pdo->query("SELECT COUNT(*) FROM server_visit_issues WHERE resolved_at IS NOT NULL")->fetchColumn();

// === Открытые проблемы ===
$issues = $pdo->query("
    SELECT i.*, r.name AS room_name,
           sv.visited_at AS reported_visit_date
    FROM server_visit_issues i
    LEFT JOIN server_visit_issue_links lnk ON lnk.issue_id = i.id
    LEFT JOIN server_visits sv ON sv.id = lnk.visit_id
    LEFT JOIN rooms r ON r.id = sv.room_id
    WHERE i.resolved_at IS NULL
    GROUP BY i.id
    ORDER BY i.reported_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// === Фильтры списка посещений ===
$filters = [
    'room_id' => $_GET['room_id'] ?? '',
    'search'  => trim($_GET['search'] ?? ''),
];

$conditions = []; $params = [];
if (!empty($filters['room_id'])) { $conditions[] = 'sv.room_id = ?'; $params[] = (int)$filters['room_id']; }
if (!empty($filters['search'])) {
    $conditions[] = '(r.name LIKE ? OR sv.comment LIKE ?)';
    $s = '%' . $filters['search'] . '%';
    array_push($params, $s, $s);
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT sv.*, r.name AS room_name,
        (sv.check_servers AND sv.check_ups AND sv.check_switches AND sv.check_temp
         AND sv.check_cooling AND sv.check_power AND sv.check_access) AS all_ok
        FROM server_visits sv
        JOIN rooms r ON r.id = sv.room_id
        $where
        ORDER BY sv.visited_at DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

$per_page_val = (int)($_GET['per_page'] ?? 25);
$per_page = in_array($per_page_val, [25,50,100]) ? $per_page_val : 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page-1) * $per_page;
$total    = count($all);
$pages    = $total > 0 ? (int)ceil($total/$per_page) : 1;
$visits   = array_slice($all, $offset, $per_page);

$rooms = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$checks = [
    'check_servers'  => 'Серверы',
    'check_ups'      => 'ИБП',
    'check_switches' => 'Свитчи',
    'check_temp'     => 'Температура',
    'check_cooling'  => 'Охлаждение',
    'check_power'    => 'Электропитание',
    'check_access'   => 'Доступ/замки',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журнал посещений серверной</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        .stat-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:24px; }
        .stat-card { background:#fff; border:1px solid #e5e7ef; border-radius:10px; padding:14px 18px; }
        .stat-card .val { font-size:24px; font-weight:700; line-height:1; }
        .stat-card .lbl { font-size:12px; color:#9ca3c4; margin-top:4px; }

        .issues-block { background:#fff; border:1px solid #fca5a5; border-radius:10px; padding:18px 20px; margin-bottom:24px; }
        .issues-title { font-size:14px; font-weight:700; color:#dc2626; display:flex; align-items:center; gap:8px; margin-bottom:12px; }
        .issue-row { display:flex; align-items:flex-start; gap:12px; padding:10px 14px; background:#fff5f5; border-radius:8px; margin-bottom:8px; }
        .issue-row:last-child { margin-bottom:0; }
        .issue-device { font-weight:700; color:#1e2130; font-size:13px; }
        .issue-problem { font-size:13px; color:#6b7499; margin-top:2px; }
        .issue-notified { font-size:11px; color:#9ca3c4; margin-top:3px; }
        .issue-date { font-size:11px; color:#c4c9dd; white-space:nowrap; }
        .btn-resolve {
            display:inline-flex; align-items:center; gap:5px;
            background:#f0fdf4; border:1px solid #86efac; color:#16a34a;
            border-radius:6px; padding:4px 10px; font-size:12px; font-weight:600;
            text-decoration:none; white-space:nowrap; cursor:pointer;
            transition:background .15s;
        }
        .btn-resolve:hover { background:#dcfce7; }

        .check-badge {
            display:inline-flex; align-items:center; gap:4px;
            font-size:11px; padding:2px 7px; border-radius:20px; font-weight:600;
        }
        .check-ok  { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .check-bad { background:#fff1f0; color:#dc2626; border:1px solid #fca5a5; }
        .all-ok-badge  { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; }
        .has-bad-badge { background:#fff1f0; color:#dc2626; border:1px solid #fca5a5; border-radius:6px; padding:3px 10px; font-size:12px; font-weight:600; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <div style="font-size:12px;color:#9ca3c4;margin-bottom:4px">
                    <a href="../" style="color:#9ca3c4;text-decoration:none">← Журналы</a>
                </div>
                <h1 class="mb-0" style="text-align:left">Журнал посещений серверной</h1>
            </div>
            <div class="d-flex gap-2">
                <a href="export.php?<?= http_build_query($filters) ?>" class="btn btn-outline-success" target="_blank">⬇ Экспорт CSV</a>
                <a href="add.php" class="btn btn-outline-success">➕ Добавить посещение</a>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stat-row">
            <div class="stat-card">
                <div class="val" style="color:#4f6ef7"><?= $totalVisits ?></div>
                <div class="lbl">Всего посещений</div>
            </div>
            <div class="stat-card">
                <div class="val" style="color:#2563eb"><?= $thisMonth ?></div>
                <div class="lbl">В этом месяце</div>
            </div>
            <div class="stat-card">
                <div class="val" style="color:<?= $openIssues > 0 ? '#dc2626' : '#16a34a' ?>"><?= $openIssues ?></div>
                <div class="lbl">Открытых проблем</div>
            </div>
            <div class="stat-card">
                <div class="val" style="color:#16a34a"><?= $resolvedAll ?></div>
                <div class="lbl">Устранено всего</div>
            </div>
        </div>

        <!-- Открытые проблемы -->
        <?php if (!empty($issues)): ?>
        <div class="issues-block">
            <div class="issues-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Нерешённые проблемы (<?= count($issues) ?>)
            </div>
            <?php foreach ($issues as $issue): ?>
                <div class="issue-row">
                    <div style="flex:1">
                        <div class="issue-device">
                            <?= htmlspecialchars($issue['device_name']) ?>
                            <?php if ($issue['room_name']): ?>
                                <span style="font-weight:400;color:#9ca3c4;font-size:12px">— <?= htmlspecialchars($issue['room_name']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="issue-problem"><?= nl2br(htmlspecialchars($issue['problem'])) ?></div>
                        <?php if ($issue['notified']): ?>
                            <div class="issue-notified">Сообщено: <?= htmlspecialchars($issue['notified']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px">
                        <div class="issue-date"><?= date('d.m.Y H:i', strtotime($issue['reported_at'])) ?></div>
                        <form method="post" action="resolve_issue.php" style="margin:0">
                            <input type="hidden" name="id" value="<?= $issue['id'] ?>">
                            <button type="submit" class="btn-resolve"
                                    onclick="return confirm('Подтвердить устранение проблемы?')">
                                <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="#16a34a" stroke-width="2"><polyline points="1,6 4.5,9.5 11,2.5"/></svg>
                                Устранено
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Фильтры -->
        <div class="filters-container">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <input type="text" id="search-input" name="search" class="form-control" style="flex:2;min-width:200px"
                       value="<?= htmlspecialchars($filters['search']) ?>"
                       placeholder="Поиск по комментарию...">
                <select name="room_id" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все помещения</option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $filters['room_id'] == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($filters['room_id']) || !empty($filters['search'])): ?>
                    <a href="index.php" class="btn btn-outline-danger" style="white-space:nowrap">✕ Сбросить</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Счётчик -->
        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:13px;color:#6b7499">
            <span>Показано <?= $total===0?0:$offset+1 ?>–<?= min($offset+$per_page,$total) ?> из <?= $total ?> посещений</span>
            <div class="d-flex align-items-center gap-2">
                <span>Записей на странице:</span>
                <select class="form-select" style="width:80px;padding:4px 8px" onchange="changePerPage(this.value)">
                    <?php foreach ([25,50,100] as $n): ?>
                        <option <?= $per_page==$n?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Таблица -->
        <?php if (empty($visits)): ?>
            <p class="p-center">Посещений не найдено.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Дата и время</th>
                            <th>Помещение</th>
                            <th class="text-center">Состояние</th>
                            <th>Проверки</th>
                            <th>Комментарий</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visits as $v):
                            // Get issues count for this visit
                            $vIssues = $pdo->prepare("
                                SELECT COUNT(*) FROM server_visit_issue_links lnk
                                JOIN server_visit_issues i ON i.id = lnk.issue_id
                                WHERE lnk.visit_id = ? AND i.resolved_at IS NULL
                            ");
                            $vIssues->execute([$v['id']]);
                            $vIssuesCnt = $vIssues->fetchColumn();
                        ?>
                            <tr onclick="location.href='edit.php?id=<?= $v['id'] ?>'" style="cursor:pointer">
                                <td style="white-space:nowrap"><?= date('d.m.Y H:i', strtotime($v['visited_at'])) ?></td>
                                <td><?= htmlspecialchars($v['room_name']) ?></td>
                                <td class="text-center">
                                    <?php if ($v['all_ok'] && $vIssuesCnt == 0): ?>
                                        <span class="all-ok-badge">✓ Всё ок</span>
                                    <?php else: ?>
                                        <span class="has-bad-badge">⚠ Проблемы</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;flex-wrap:wrap;gap:4px">
                                        <?php foreach ($checks as $key => $label): ?>
                                            <span class="check-badge <?= $v[$key] ? 'check-ok' : 'check-bad' ?>">
                                                <?= $v[$key] ? '✓' : '✗' ?> <?= $label ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td style="max-width:200px"><?= nl2br(htmlspecialchars($v['comment'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php
                    $q = $_GET; unset($q['page']);
                    $base = '?' . http_build_query($q);
                    if ($page > 1) echo "<a href=\"{$base}&page=".($page-1)."\">←</a>";
                    for ($i = 1; $i <= $pages; $i++) {
                        if ($i==1||$i==$pages||abs($i-$page)<=2)
                            echo "<a href=\"{$base}&page={$i}\" class=\"".($i==$page?'active':'')."\">{$i}</a>";
                        elseif (abs($i-$page)==3) echo "<span>…</span>";
                    }
                    if ($page < $pages) echo "<a href=\"{$base}&page=".($page+1)."\">→</a>";
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<script>
let searchTimer;
function applyFilters() {
    const inputs = document.querySelectorAll('.filters-container select, .filters-container input[name]');
    const params = new URLSearchParams(window.location.search);
    params.delete('page');
    inputs.forEach(el => { if (el.value) params.set(el.name, el.value); else params.delete(el.name); });
    window.location.search = params.toString();
}
function changePerPage(val) {
    const params = new URLSearchParams(window.location.search);
    params.set('per_page', val); params.delete('page');
    window.location.search = params.toString();
}
const si = document.getElementById('search-input');
if (si) {
    si.addEventListener('keydown', e => { if (e.key==='Enter') { clearTimeout(searchTimer); applyFilters(); } });
    si.addEventListener('blur', () => { clearTimeout(searchTimer); if (si.value!==si.defaultValue) applyFilters(); });
    si.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 900); });
}
document.querySelectorAll('.filters-container select').forEach(s => s.addEventListener('change', applyFilters));
</script>
</body>
</html>