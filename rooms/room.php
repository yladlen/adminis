<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
require_once 'room_model.php';
require_once '../includes/functions.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) die("Некорректный ID кабинета.");

$room_id = (int) $_GET['id'];
$room    = getRoomById($pdo, $room_id);
if (!$room) die("Кабинет не найден.");

$filter_type   = $_GET['type']   ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['search'] ?? '');

$devices_all = getDevicesByRoom($pdo, $room_id);

$devices_filtered = array_values(array_filter($devices_all, function($d) use ($filter_type, $filter_status, $filter_search) {
    if ($filter_type   && $d['type']   !== $filter_type)   return false;
    if ($filter_status && $d['status'] !== $filter_status) return false;
    if ($filter_search) {
        $q = mb_strtolower($filter_search);
        $haystack = mb_strtolower(($d['name']??'').' '.($d['ip']??'').' '.($d['mac']??'').' '.($d['inventory_number']??'').' '.($d['comment']??''));
        if (!str_contains($haystack, $q)) return false;
    }
    return true;
}));

$types    = array_unique(array_column($devices_all, 'type'));
$statuses = array_unique(array_column($devices_all, 'status'));
sort($types); sort($statuses);

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
    <title><?= htmlspecialchars($room['name']) ?> — Устройства</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        .status-pill { display:inline-block; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; white-space:nowrap; border:1px solid; }
        th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
        th.sortable:hover { background:#eef0f8; }
        th.sortable .sort-icon { margin-left:5px; opacity:.35; font-style:normal; font-size:11px; }
        th.sortable.asc  .sort-icon::after { content:'↑'; opacity:1; }
        th.sortable.desc .sort-icon::after { content:'↓'; opacity:1; }
        th.sortable:not(.asc):not(.desc) .sort-icon::after { content:'↕'; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="mb-0" style="text-align:left"><?= htmlspecialchars($room['name']) ?></h1>
                <?php if ($room['description']): ?>
                    <p class="text-muted" style="margin-top:6px;margin-bottom:0"><?= nl2br(htmlspecialchars($room['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-success btn-sm">← Назад</a>
                <a href="edit_room.php?id=<?= $room_id ?>" class="btn btn-outline-success btn-sm">✏️ Редактировать</a>
                <a href="add_device.php?room_id=<?= $room_id ?>" class="btn btn-outline-success btn-sm">➕ Добавить устройство</a>
            </div>
        </div>

        <div class="filters-container">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <input type="text" id="search-input" name="search" class="form-control" style="flex:2;min-width:200px"
                       value="<?= htmlspecialchars($filter_search) ?>"
                       placeholder="Поиск по названию, IP, MAC, инв. номеру...">
                <select name="type" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все типы</option>
                    <?php foreach ($types as $t): ?>
                        <option <?= $filter_type == $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все статусы</option>
                    <?php foreach ($statuses as $s): ?>
                        <option <?= $filter_status == $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filter_type || $filter_status || $filter_search): ?>
                    <a href="room.php?id=<?= $room_id ?>" class="btn btn-outline-danger" style="white-space:nowrap">✕ Сбросить</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:13px;color:#6b7499">
            <span id="count-label">Всего: <?= count($devices_filtered) ?> устройств</span>
            <div class="d-flex align-items-center gap-2">
                <span>Записей на странице:</span>
                <select id="per-page-sel" class="form-select" style="width:80px;padding:4px 8px">
                    <?php foreach ([25,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= (($_GET['per_page']??25)==$n)?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($devices_filtered)): ?>
            <div class="alert alert-danger">Устройства не найдены.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="main-table">
                    <thead>
                        <tr>
                            <th class="sortable text-center" data-col="0">Статус<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="1">Устройство<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="2">Тип<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="3">IP<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="4">MAC<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="5">Инв. №<i class="sort-icon"></i></th>
                            <th>Подключено к</th>
                            <th>Комментарий</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices_filtered as $device):
                            $sc = $statusColors[$device['status']] ?? ['bg'=>'#f0f2f5','color'=>'#6b7499','border'=>'#e5e7ef'];
                        ?>
                            <tr onclick="location.href='edit_device.php?id=<?= $device['id'] ?>'" style="cursor:pointer">
                                <td class="text-center" data-sort="<?= htmlspecialchars($device['status']) ?>" style="white-space:nowrap">
                                    <span class="status-pill"
                                          style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border-color:<?= $sc['border'] ?>">
                                        <?= htmlspecialchars($device['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                        $folder = mapTypeToFolder($device['type']);
                                        $icon   = htmlspecialchars($device['icon']);
                                        $path   = "../assets/icons/{$folder}/{$icon}";
                                        if ($icon && file_exists($path))
                                            echo "<img src=\"$path\" style=\"width:22px;height:22px;vertical-align:middle;margin-right:6px\">";
                                        echo htmlspecialchars($device['name']);
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($device['type']) ?></td>
                                <td><?= htmlspecialchars($device['ip']) ?></td>
                                <td><?= htmlspecialchars($device['mac']) ?></td>
                                <td><?= htmlspecialchars($device['inventory_number']) ?></td>
                                <td><?php
                                    $connected = getDeviceConnectionName($pdo, $device['id']);
                                    echo $connected ? '→ ' . htmlspecialchars($connected) : '—';
                                ?></td>
                                <td style="max-width:280px;white-space:pre-wrap"><?= nl2br(htmlspecialchars($device['comment'])) ?></td>
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
    const tbody=table.querySelector('tbody'),headers=table.querySelectorAll('th.sortable'),perSel=document.getElementById('per-page-sel'),pgDiv=document.getElementById('pagination'),countLbl=document.getElementById('count-label');
    let sortCol=1,sortDir='asc',perPage=parseInt(perSel?.value||25),curPage=1;
    const allRows=Array.from(tbody.querySelectorAll('tr'));
    function getCellText(row,col){const td=row.cells[col];return(td?.dataset.sort??td?.textContent??'').trim().toLowerCase();}
    function sortRows(){allRows.sort((a,b)=>{const va=getCellText(a,sortCol),vb=getCellText(b,sortCol);return sortDir==='asc'?va.localeCompare(vb,'ru'):vb.localeCompare(va,'ru');});headers.forEach(h=>{h.classList.remove('asc','desc');if(parseInt(h.dataset.col)===sortCol)h.classList.add(sortDir);});}
    function renderPage(){const start=(curPage-1)*perPage;allRows.forEach((r,i)=>{r.style.display=(i>=start&&i<start+perPage)?'':'none';tbody.appendChild(r);});if(countLbl){const end=Math.min(start+perPage,allRows.length);countLbl.textContent=allRows.length===0?'Нет записей':`Показано ${start+1}–${end} из ${allRows.length}`;}renderPagination();}
    function renderPagination(){if(!pgDiv)return;const pages=Math.ceil(allRows.length/perPage);if(pages<=1){pgDiv.innerHTML='';return;}let html='';if(curPage>1)html+=`<a href="#" data-p="${curPage-1}">←</a>`;for(let i=1;i<=pages;i++){if(i===1||i===pages||Math.abs(i-curPage)<=2)html+=`<a href="#" data-p="${i}" class="${i===curPage?'active':''}">${i}</a>`;else if(Math.abs(i-curPage)===3)html+=`<span>…</span>`;}if(curPage<pages)html+=`<a href="#" data-p="${curPage+1}">→</a>`;pgDiv.innerHTML=html;pgDiv.querySelectorAll('a[data-p]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();curPage=parseInt(a.dataset.p);renderPage();}));}
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