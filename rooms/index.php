<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
require_once 'room_model.php';

$filters = [
    'room_id'     => $_GET['room_id'] ?? null,
    'device_type' => $_GET['device_type'] ?? null,
    'status'      => $_GET['status'] ?? null,
    'search'      => trim($_GET['search'] ?? ''),
];

$rooms_all = getRooms($pdo, $filters);

if (!empty($filters['search'])) {
    $q = mb_strtolower($filters['search']);
    $rooms_all = array_values(array_filter($rooms_all, fn($r) =>
        str_contains(mb_strtolower($r['name'] ?? ''), $q) ||
        str_contains(mb_strtolower($r['description'] ?? ''), $q)
    ));
}

$roomList = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Кабинеты</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        th.sortable { cursor:pointer; user-select:none; white-space:nowrap; }
        th.sortable:hover { background:#eef0f8; }
        th.sortable .sort-icon { margin-left:5px; opacity:.35; font-style:normal; font-size:11px; }
        th.sortable.asc  .sort-icon::after { content:'↑'; opacity:1; }
        th.sortable.desc .sort-icon::after { content:'↓'; opacity:1; }
        th.sortable:not(.asc):not(.desc) .sort-icon::after { content:'↕'; }
        #main-table { table-layout:fixed; width:100%; }
        #main-table td:nth-child(1) { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        #main-table td:nth-child(3) { white-space:nowrap; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0" style="text-align:left">Кабинеты</h1>
            <div class="d-flex gap-2">
                <form method="POST" action="export_rooms.php">
                    <input type="hidden" name="room_id"     value="<?= htmlspecialchars($_GET['room_id'] ?? '') ?>">
                    <input type="hidden" name="device_type" value="<?= htmlspecialchars($_GET['device_type'] ?? '') ?>">
                    <input type="hidden" name="status"      value="<?= htmlspecialchars($_GET['status'] ?? '') ?>">
                    <button type="submit" class="btn btn-outline-success">⬇️ Экспорт CSV</button>
                </form>
                <a href="add_room.php" class="btn btn-outline-success">➕ Добавить кабинет</a>
            </div>
        </div>

        <div class="filters-container">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <input type="text" id="search-input" name="search" class="form-control" style="flex:2;min-width:200px"
                       value="<?= htmlspecialchars($filters['search']) ?>"
                       placeholder="Поиск по названию кабинета...">
                <select name="room_id" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все кабинеты</option>
                    <?php foreach ($roomList as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($_GET['room_id'] ?? '') == $r['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="device_type" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все типы устройств</option>
                    <?php foreach (['ПК','Сервер','Принтер','Маршрутизатор','Свитч','МФУ','Интерактивная доска','Прочее'] as $t): ?>
                        <option <?= ($_GET['device_type'] ?? '') == $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все статусы</option>
                    <?php foreach (['В работе','На ремонте','Списан','На хранении','Числится за кабинетом'] as $s): ?>
                        <option <?= ($_GET['status'] ?? '') == $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($_GET['room_id']) || !empty($_GET['device_type']) || !empty($_GET['status']) || !empty($filters['search'])): ?>
                    <a href="index.php" class="btn btn-outline-danger" style="white-space:nowrap">✕ Сбросить</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:13px;color:#6b7499">
            <span id="count-label">Всего: <?= count($rooms_all) ?> кабинетов</span>
            <div class="d-flex align-items-center gap-2">
                <span>Записей на странице:</span>
                <select id="per-page-sel" class="form-select" style="width:80px;padding:4px 8px">
                    <?php foreach ([25,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= (($_GET['per_page']??25)==$n)?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($rooms_all)): ?>
            <p class="p-center">Кабинеты не найдены.</p>
        <?php else: ?>
            <div>
                <table id="main-table">
                    <thead>
                        <tr>
                            <th class="sortable text-center" data-col="0" style="width:120px">Кабинет<i class="sort-icon"></i></th>
                            <th>Описание</th>
                            <th class="sortable text-center" data-col="2" style="width:120px">Устройств<i class="sort-icon"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rooms_all as $room): ?>
                            <tr onclick="location.href='room.php?id=<?= $room['id'] ?>'" style="cursor:pointer">
                                <td class="text-center"><?= htmlspecialchars($room['name']) ?></td>
                                <td><?= nl2br(htmlspecialchars($room['description'])) ?></td>
                                <td class="text-center" data-sort="<?= str_pad((int)$room['device_count'], 6, '0', STR_PAD_LEFT) ?>">
                                    <?= $room['device_count'] ?>
                                </td>
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
    let sortCol=0,sortDir='asc',perPage=parseInt(perSel?.value||25),curPage=1;
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