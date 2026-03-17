<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

$filters = [
    'room_id' => $_GET['room_id'] ?? null,
    'status'  => $_GET['status'] ?? null,
    'search'  => trim($_GET['search'] ?? ''),
];

$params     = [];
$conditions = ['d.type = ?'];
$params[]   = 'ИБП';

if (!empty($filters['room_id'])) { $conditions[] = 'd.room_id = ?'; $params[] = (int)$filters['room_id']; }
if (!empty($filters['status']))  { $conditions[] = 'd.status = ?';  $params[] = $filters['status']; }
if (!empty($filters['search'])) {
    $conditions[] = '(d.name LIKE ? OR d.ip LIKE ? OR d.mac LIKE ? OR d.inventory_number LIKE ? OR d.comment LIKE ?)';
    $s = '%' . $filters['search'] . '%';
    array_push($params, $s, $s, $s, $s, $s);
}

$sql  = "SELECT d.*, r.name AS room_name,
         uh.power_va, uh.battery_type, uh.battery_replaced,
         uh.manufacturer, uh.model, uh.serial_number, uh.year_manufactured,
         uh.commissioned_at, uh.warranty_until, uh.floor,
         (SELECT COUNT(*) FROM ups_issues ci WHERE ci.device_id=d.id AND ci.resolved_at IS NULL) AS open_issues
         FROM devices d
         LEFT JOIN rooms r ON r.id = d.room_id
         LEFT JOIN ups_hardware uh ON uh.device_id = d.id";
$sql .= " WHERE " . implode(' AND ', $conditions) . " ORDER BY r.name, d.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Экспорт CSV — до любого HTML вывода
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ups_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, [
        'Название', 'Кабинет', 'Этаж', 'Статус', 'Инв. номер', 'IP', 'MAC',
        'Производитель', 'Модель', 'Серийный номер', 'Год производства',
        'Ввод в эксплуатацию', 'Гарантия до',
        'Мощность (ВА)', 'Тип батареи', 'Дата замены батареи',
        'Комментарий', 'К списанию', 'Дата рекомендации к списанию',
        'Открытых проблем',
    ], ';');
    foreach ($items as $row) {
        fputcsv($out, [
            $row['name'],
            $row['room_name'] ?? '',
            $row['floor'] ?? '',
            $row['status'],
            $row['inventory_number'] ?? '',
            $row['ip'] ?? '',
            $row['mac'] ?? '',
            $row['manufacturer'] ?? '',
            $row['model'] ?? '',
            $row['serial_number'] ?? '',
            $row['year_manufactured'] ?? '',
            $row['commissioned_at'] ? date('d.m.Y', strtotime($row['commissioned_at'])) : '',
            $row['warranty_until']  ? date('d.m.Y', strtotime($row['warranty_until']))  : '',
            $row['power_va'] ?? '',
            $row['battery_type'] ?? '',
            $row['battery_replaced'] ? date('d.m.Y', strtotime($row['battery_replaced'])) : '',
            $row['comment'] ?? '',
            $row['recommended_for_writeoff'] ? 'Да' : '',
            $row['writeoff_recommended_at'] ? date('d.m.Y', strtotime($row['writeoff_recommended_at'])) : '',
            $row['open_issues'] ?? 0,
        ], ';');
    }
    fclose($out);
    exit;
}

require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

$rooms    = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$statuses = ['В работе','На ремонте','Списан','На хранении','Числится за кабинетом'];

$statusColors = [
    'В работе'              => ['bg'=>'#f0fdf4','color'=>'#16a34a','border'=>'#bbf7d0'],
    'На ремонте'            => ['bg'=>'#fff7ed','color'=>'#ea580c','border'=>'#fed7aa'],
    'Списан'                => ['bg'=>'#f1f5f9','color'=>'#64748b','border'=>'#cbd5e1'],
    'На хранении'           => ['bg'=>'#eff6ff','color'=>'#2563eb','border'=>'#bfdbfe'],
    'Числится за кабинетом' => ['bg'=>'#fdf4ff','color'=>'#9333ea','border'=>'#e9d5ff'],
];

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>ИБП</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        .status-pill { display:inline-block; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; border:1px solid; }
        .issue-dot { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#dc2626; background:#fee2e2; border:1px solid #fca5a5; border-radius:20px; padding:2px 8px; margin-top:4px; }
        th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
        th.sortable:hover { background:#eef0f8; }
        th.sortable .sort-icon { margin-left:5px; opacity:.35; font-style:normal; font-size:11px; }
        th.sortable.asc  .sort-icon::after { content:'↑'; opacity:1; }
        th.sortable.desc .sort-icon::after { content:'↓'; opacity:1; }
        th.sortable:not(.asc):not(.desc) .sort-icon::after { content:'↕'; }
        #main-table { table-layout:fixed; width:100%; }
        #main-table th:nth-child(1) { width:120px; }
        #main-table th:nth-child(2) { width:auto; }
        #main-table th:nth-child(3) { width:100px; }
        #main-table th:nth-child(4) { width:100px; }
        #main-table th:nth-child(5) { width:120px; }
        #main-table th:nth-child(6) { width:120px; }
        #main-table th:nth-child(7) { width:130px; }
        #main-table th:nth-child(8) { width:180px; }
        #main-table td { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        #main-table td:nth-child(8) { white-space:normal; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0" style="text-align:left">ИБП</h1>
            <div class="d-flex gap-2">
                <a href="?<?= http_build_query(array_filter(array_merge($filters, ['export'=>'csv']))) ?>" class="btn btn-outline-success">⬇ CSV</a>
                <a href="add.php?from=ups" class="btn btn-outline-success">➕ Добавить</a>
            </div>
        </div>

        <div class="filters-container">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <input type="text" id="search-input" name="search" class="form-control" style="flex:2;min-width:200px"
                       value="<?= htmlspecialchars($filters['search']) ?>"
                       placeholder="Поиск по названию, IP, MAC, инв. номеру...">
                <select name="room_id" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все кабинеты</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?= $room['id'] ?>" <?= ($filters['room_id']??'')==$room['id']?'selected':'' ?>>
                            <?= htmlspecialchars($room['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все статусы</option>
                    <?php foreach ($statuses as $s): ?>
                        <option <?= ($filters['status']??'')===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($filters['room_id'])||!empty($filters['status'])||!empty($filters['search'])): ?>
                    <a href="index.php" class="btn btn-outline-danger" style="white-space:nowrap">✕ Сбросить</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:13px;color:#6b7499">
            <span id="count-label">Всего: <?= count($items) ?> устройств</span>
            <div class="d-flex align-items-center gap-2">
                <span>Записей на странице:</span>
                <select id="per-page-sel" class="form-select" style="width:80px;padding:4px 8px">
                    <?php foreach ([25,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= (($_GET['per_page']??25)==$n)?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($items)): ?>
            <p class="p-center">Записи не найдены.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table id="main-table">
                    <thead>
                        <tr>
                            <th class="sortable text-center" data-col="0">Статус<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="1">Название<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="2">Кабинет<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="3">Этаж<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="4">IP<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="5">MAC<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="6">Инв. номер<i class="sort-icon"></i></th>
                            <th>Комментарий</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $sc = $statusColors[$item['status']] ?? ['bg'=>'#f0f2f5','color'=>'#6b7499','border'=>'#e5e7ef'];
                        ?>
                            <tr onclick="location.href='edit.php?id=<?= $item['id'] ?>&from=ups'" style="cursor:pointer">
                                <td class="text-center" data-sort="<?= htmlspecialchars($item['status']) ?>" style="white-space:nowrap">
                                    <span class="status-pill"
                                          style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border-color:<?= $sc['border'] ?>">
                                        <?= htmlspecialchars($item['status']) ?>
                                    </span>
                                    <?php if ($item['open_issues'] > 0): ?>
                                        <br><span class="issue-dot">⚠ <?= $item['open_issues'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap">
                                    <?php
                                        $iconFile = $item['icon'] ?? '';
                                        $iconPath = __DIR__ . "/../assets/icons/{$iconFile}";
                                        if ($iconFile && file_exists($iconPath)):
                                    ?>
                                        <img src="/adminis/assets/icons/<?= htmlspecialchars($iconFile) ?>"
                                             style="width:20px;height:20px;object-fit:contain;vertical-align:middle;margin-right:6px">
                                    <?php else: ?>
                                        <span style="display:inline-block;width:26px"></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($item['name']) ?>
                                </td>
                                <td><?= htmlspecialchars($item['room_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($item['floor'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($item['ip']) ?></td>
                                <td><?= htmlspecialchars($item['mac']) ?></td>
                                <td><?= htmlspecialchars($item['inventory_number']) ?></td>
                                <td><?= nl2br(htmlspecialchars($item['comment'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="pagination" class="pagination"></div>
        <?php endif; ?>

    </div>
</div>
<script>
(function() {
    const table=document.getElementById('main-table'); if(!table)return;
    const tbody=table.querySelector('tbody'),headers=table.querySelectorAll('th.sortable');
    const perSel=document.getElementById('per-page-sel'),pgDiv=document.getElementById('pagination'),countLbl=document.getElementById('count-label');
    let sortCol=1,sortDir='asc',perPage=parseInt(perSel?.value||25),curPage=1;
    const allRows=Array.from(tbody.querySelectorAll('tr'));
    function getCellText(row,col){const td=row.cells[col];return(td?.dataset.sort??td?.textContent??'').trim().toLowerCase();}
    function sortRows(){allRows.sort((a,b)=>{const va=getCellText(a,sortCol),vb=getCellText(b,sortCol);return sortDir==='asc'?va.localeCompare(vb,'ru'):vb.localeCompare(va,'ru');});headers.forEach(h=>{h.classList.remove('asc','desc');if(parseInt(h.dataset.col)===sortCol)h.classList.add(sortDir);});}
    function renderPage(){const start=(curPage-1)*perPage;allRows.forEach((r,i)=>{r.style.display=(i>=start&&i<start+perPage)?'':'none';tbody.appendChild(r);});if(countLbl){const end=Math.min(start+perPage,allRows.length);countLbl.textContent=allRows.length===0?'Нет записей':`Показано ${start+1}\u2013${end} из ${allRows.length}`;}renderPagination();}
    function renderPagination(){if(!pgDiv)return;const pages=Math.ceil(allRows.length/perPage);if(pages<=1){pgDiv.innerHTML='';return;}let html='';if(curPage>1)html+=`<a href="#" data-p="${curPage-1}">\u2190</a>`;for(let i=1;i<=pages;i++){if(i===1||i===pages||Math.abs(i-curPage)<=2)html+=`<a href="#" data-p="${i}" class="${i===curPage?'active':''}">${i}</a>`;else if(Math.abs(i-curPage)===3)html+=`<span>\u2026</span>`;}if(curPage<pages)html+=`<a href="#" data-p="${curPage+1}">\u2192</a>`;pgDiv.innerHTML=html;pgDiv.querySelectorAll('a[data-p]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();curPage=parseInt(a.dataset.p);renderPage();}));}
    headers.forEach(h=>h.addEventListener('click',()=>{const col=parseInt(h.dataset.col);if(col===sortCol)sortDir=sortDir==='asc'?'desc':'asc';else{sortCol=col;sortDir='asc';}curPage=1;sortRows();renderPage();}));
    if(perSel)perSel.addEventListener('change',()=>{perPage=parseInt(perSel.value);curPage=1;renderPage();});
    let searchTimer;
    function applyFilters(){const inputs=document.querySelectorAll('.filters-container select, .filters-container input[name]');const params=new URLSearchParams(window.location.search);params.delete('page');inputs.forEach(el=>{if(el.value)params.set(el.name,el.value);else params.delete(el.name);});window.location.search=params.toString();}
    const si=document.getElementById('search-input');
    if(si){si.addEventListener('keydown',e=>{if(e.key==='Enter'){clearTimeout(searchTimer);applyFilters();}});si.addEventListener('blur',()=>{clearTimeout(searchTimer);if(si.value!==si.defaultValue)applyFilters();});si.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(applyFilters,900);});}
    document.querySelectorAll('.filters-container select').forEach(s=>s.addEventListener('change',applyFilters));
    sortRows();renderPage();
})();
</script>
</body>
</html>