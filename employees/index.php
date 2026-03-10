<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

$filters = [
    'room_id'  => $_GET['room_id'] ?? null,
    'position' => $_GET['position'] ?? null,
    'search'   => trim($_GET['search'] ?? ''),
];

$params = [];
$conditions = [];

if (!empty($filters['room_id']))  { $conditions[] = 't.room_id = ?';  $params[] = (int)$filters['room_id']; }
if (!empty($filters['position'])) { $conditions[] = 't.position = ?'; $params[] = $filters['position']; }
if (!empty($filters['search'])) {
    $conditions[] = '(t.full_name LIKE ? OR t.mobile_phone LIKE ? OR t.internal_phone LIKE ? OR t.email LIKE ?)';
    $s = '%' . $filters['search'] . '%';
    array_push($params, $s, $s, $s, $s);
}

$sql  = "SELECT t.*, r.name AS room_name FROM employees t LEFT JOIN rooms r ON t.room_id = r.id";
if ($conditions) $sql .= " WHERE " . implode(' AND ', $conditions);
$sql .= " ORDER BY t.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rooms     = $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$positions = $pdo->query("SELECT DISTINCT position FROM employees WHERE position IS NOT NULL AND position != '' ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Сотрудники</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
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
            <h1 class="mb-0" style="text-align:left">Сотрудники</h1>
            <a href="add.php" class="btn btn-outline-success">➕ Добавить сотрудника</a>
        </div>

        <div class="filters-container">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <input type="text" id="search-input" name="search" class="form-control" style="flex:1;min-width:180px"
                       value="<?= htmlspecialchars($filters['search']) ?>"
                       placeholder="Поиск по ФИО, телефону, email...">
                <select name="room_id" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все кабинеты</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?= $room['id'] ?>" <?= ($filters['room_id']??'')==$room['id']?'selected':'' ?>>
                            <?= htmlspecialchars($room['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="position" class="form-select" style="flex:1;min-width:160px">
                    <option value="">Все должности</option>
                    <?php foreach ($positions as $pos): ?>
                        <option value="<?= htmlspecialchars($pos) ?>" <?= ($filters['position']??'')==$pos?'selected':'' ?>>
                            <?= htmlspecialchars($pos) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($filters['room_id'])||!empty($filters['position'])||!empty($filters['search'])): ?>
                    <a href="index.php" class="btn btn-outline-danger" style="white-space:nowrap">✕ Сбросить</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:13px;color:#6b7499">
            <span id="count-label">Всего: <?= count($employees) ?> сотрудников</span>
            <div class="d-flex align-items-center gap-2">
                <span>Записей на странице:</span>
                <select id="per-page-sel" class="form-select" style="width:80px;padding:4px 8px">
                    <?php foreach ([25,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= (($_GET['per_page']??25)==$n)?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($employees)): ?>
            <p class="p-center">Сотрудники не найдены.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table id="main-table">
                    <thead>
                        <tr>
                            <th class="sortable" data-col="0">ФИО<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="1">Должность<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="2">Кабинет<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="3">Внутренний тел.<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="4">Мобильный тел.<i class="sort-icon"></i></th>
                            <th class="sortable" data-col="5">Email<i class="sort-icon"></i></th>
                            <th>Комментарий</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                            <tr onclick="location.href='edit.php?id=<?= $emp['id'] ?>'" style="cursor:pointer">
                                <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                <td><?= htmlspecialchars($emp['position'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($emp['room_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($emp['internal_phone'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($emp['mobile_phone'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($emp['email'] ?? '—') ?></td>
                                <td><?= nl2br(htmlspecialchars($emp['comment'] ?? '')) ?></td>
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