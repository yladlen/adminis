<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/navbar.php';
require_once '../../includes/top_navbar.php';

$employees = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$devices   = $pdo->query("SELECT id, name, inventory_number FROM devices WHERE type='Ноутбук' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    'employee_id'    => $_GET['employee_id']   ?? '',
    'device_id'      => $_GET['device_id']     ?? '',
    'status'         => $_GET['status']        ?? '',
    'search'         => trim($_GET['search']   ?? ''),
    'show_permanent' => !isset($_GET['show_permanent']) || $_GET['show_permanent'],
    'show_temporary' => !isset($_GET['show_temporary']) || $_GET['show_temporary'],
];

$where  = [];
$params = [];

if ($filters['employee_id']) { $where[] = 'j.employee_id = ?'; $params[] = $filters['employee_id']; }
if ($filters['device_id'])   { $where[] = 'j.device_id = ?';   $params[] = $filters['device_id']; }
if ($filters['status'])      { $where[] = 'j.status = ?';      $params[] = $filters['status']; }

if ($filters['search']) {
    $where[] = '(e.full_name LIKE ? OR d.name LIKE ? OR d.inventory_number LIKE ? OR j.comment LIKE ?)';
    $s = '%' . $filters['search'] . '%';
    array_push($params, $s, $s, $s, $s);
}

if (isset($_GET['show_permanent']) || isset($_GET['show_temporary'])) {
    $sp = !empty($_GET['show_permanent']);
    $st = !empty($_GET['show_temporary']);
    if ($sp && !$st)      { $where[] = 'j.is_permanent = 1'; }
    elseif (!$sp && $st)  { $where[] = 'j.is_permanent = 0'; }
    elseif (!$sp && !$st) { $where[] = '1 = 0'; }
}

$sql = "SELECT j.*, e.full_name AS employee_name, d.name AS device_name,
               d.inventory_number, d.ip
        FROM journal j
        JOIN employees e ON j.employee_id = e.id
        JOIN devices   d ON j.device_id   = d.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY j.start_date DESC, j.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stat_taken    = $pdo->query("SELECT COUNT(*) FROM journal WHERE status='взят'")->fetchColumn();
$stat_returned = $pdo->query("SELECT COUNT(*) FROM journal WHERE status='сдан'")->fetchColumn();
$stat_perm     = $pdo->query("SELECT COUNT(*) FROM journal WHERE is_permanent=1 AND status='взят'")->fetchColumn();
$total_notebooks = $pdo->query("SELECT COUNT(*) FROM devices WHERE type='Ноутбук'")->fetchColumn();
$stat_free = max(0, $total_notebooks - $stat_taken);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Журнал выдачи ноутбуков</title>
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
                <div style="font-size:12px;color:#9ca3c4;margin-bottom:4px">
                    <a href="../journal/" style="color:#9ca3c4;text-decoration:none">← Журналы</a>
                </div>
                <h1 class="mb-0" style="text-align:left">Журнал выдачи ноутбуков</h1>
            </div>
            <div class="d-flex gap-2">
                <a href="export.php?<?= http_build_query($_GET) ?>" class="btn btn-outline-success" target="_blank">⬇ Экспорт CSV</a>
                <a href="add.php" class="btn btn-outline-success">➕ Добавить запись</a>
            </div>
        </div>

        <!-- Статистика -->
        <div class="d-flex mb-4" style="gap:16px">
            <div style="flex:1;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                <div><div style="font-size:20px;font-weight:700;color:#ea580c"><?= $stat_taken ?></div><div style="font-size:12px;color:#9a6a4a">На руках</div></div>
            </div>
            <div style="flex:1;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="1.5"><polyline points="20,6 9,17 4,12"/></svg>
                <div><div style="font-size:20px;font-weight:700;color:#16a34a"><?= $stat_returned ?></div><div style="font-size:12px;color:#4a8a5a">Возвращено</div></div>
            </div>
            <div style="flex:1;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <div><div style="font-size:20px;font-weight:700;color:#2563eb"><?= $stat_perm ?></div><div style="font-size:12px;color:#4a6a9a">Постоянное польз.</div></div>
            </div>
            <div style="flex:1;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="1.5"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/><line x1="9" y1="10" x2="15" y2="10" stroke-dasharray="2 1.5"/></svg>
                <div><div style="font-size:20px;font-weight:700;color:#7c3aed"><?= $stat_free ?></div><div style="font-size:12px;color:#6a4a9a">Свободно</div></div>
            </div>
        </div>

        <!-- Фильтры -->
        <div class="filters-container">
            <div class="d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <input type="text" id="search-input" name="search" class="form-control" style="flex:2;min-width:200px"
                       value="<?= htmlspecialchars($filters['search']) ?>"
                       placeholder="Поиск по ФИО, устройству, инв. номеру...">
                <select name="employee_id" class="form-select" style="flex:2;min-width:160px">
                    <option value="">Все сотрудники</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $filters['employee_id'] == $e['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['full_name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <select name="device_id" class="form-select" style="flex:2;min-width:160px">
                    <option value="">Все ноутбуки</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $filters['device_id'] == $d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['name']) ?><?= $d['inventory_number'] ? ' (' . $d['inventory_number'] . ')' : '' ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <select name="status" class="form-select" style="flex:1;min-width:130px">
                    <option value="">Все статусы</option>
                    <option value="взят" <?= $filters['status']==='взят' ? 'selected':'' ?>>Взят</option>
                    <option value="сдан" <?= $filters['status']==='сдан' ? 'selected':'' ?>>Сдан</option>
                </select>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer">
                    <input type="checkbox" name="show_permanent" value="1" <?= $filters['show_permanent'] ? 'checked':'' ?> style="width:15px;height:15px"> Постоянные
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;cursor:pointer">
                    <input type="checkbox" name="show_temporary" value="1" <?= $filters['show_temporary'] ? 'checked':'' ?> style="width:15px;height:15px"> Временные
                </label>
                <?php if ($filters['search'] || $filters['employee_id'] || $filters['device_id'] || $filters['status']): ?>
                    <a href="index.php" class="btn btn-outline-danger" style="white-space:nowrap">✕ Сбросить</a>
                <?php endif ?>
            </div>
        </div>

        <!-- Счётчик -->
        <div class="d-flex justify-content-between align-items-center mb-3" style="font-size:13px;color:#6b7499">
            <span id="count-label">Всего: <?= count($all) ?> записей</span>
            <div class="d-flex align-items-center gap-2">
                <span>Записей на странице:</span>
                <select id="per-page-sel" class="form-select" style="width:80px;padding:4px 8px">
                    <?php foreach ([25,50,100] as $n): ?>
                        <option value="<?= $n ?>" <?= (($_GET['per_page']??25)==$n)?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <!-- Таблица -->
        <?php if (empty($all)): ?>
            <p class="p-center">Записей не найдено.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table id="main-table">
                <thead>
                    <tr>
                        <th class="sortable text-center" data-col="0">Статус<i class="sort-icon"></i></th>
                        <th class="sortable" data-col="1">Сотрудник<i class="sort-icon"></i></th>
                        <th class="sortable" data-col="2">Ноутбук<i class="sort-icon"></i></th>
                        <th class="sortable" data-col="3">Инв. номер<i class="sort-icon"></i></th>
                        <th class="sortable" data-col="4">Дата выдачи<i class="sort-icon"></i></th>
                        <th class="sortable" data-col="5">Дата возврата<i class="sort-icon"></i></th>
                        <th class="sortable text-center" data-col="6">Постоянно<i class="sort-icon"></i></th>
                        <th>Комментарий</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all as $row):
                        $isPerm   = $row['is_permanent'];
                        $isTaken  = $row['status'] === 'взят';
                    ?>
                    <tr onclick="location.href='edit.php?id=<?= $row['id'] ?>'" style="cursor:pointer">
                        <td class="text-center" data-sort="<?= htmlspecialchars($row['status']) ?>" style="white-space:nowrap">
                            <?php if ($isTaken): ?>
                                <span class="status-pill" style="background:#fff7ed;color:#ea580c;border-color:#fed7aa">взят</span>
                            <?php else: ?>
                                <span class="status-pill" style="background:#f0fdf4;color:#16a34a;border-color:#bbf7d0">сдан</span>
                            <?php endif ?>
                        </td>
                        <td><strong><?= htmlspecialchars($row['employee_name']) ?></strong></td>
                        <td><?= htmlspecialchars($row['device_name']) ?></td>
                        <td style="color:#9ca3c4"><?= htmlspecialchars($row['inventory_number'] ?? '—') ?></td>
                        <td data-sort="<?= $isPerm ? '' : ($row['start_date'] ?? '') ?>">
                            <?= $isPerm ? '<span style="color:#9ca3c4">—</span>' : htmlspecialchars($row['start_date'] ?? '—') ?>
                        </td>
                        <td data-sort="<?= $isPerm ? '' : ($row['end_date'] ?? '') ?>">
                            <?= $isPerm ? '<span style="color:#9ca3c4">—</span>' : htmlspecialchars($row['end_date'] ?? '—') ?>
                        </td>
                        <td class="text-center" data-sort="<?= $isPerm ? '1' : '0' ?>">
                            <?= $isPerm ? '<span style="color:#2563eb;font-weight:600">Да</span>' : '—' ?>
                        </td>
                        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($row['comment'] ?? '') ?>
                        </td>
                        <td onclick="event.stopPropagation()" style="padding:4px;width:80px">
                            <?php if ($isTaken): ?>
                                <a href="return.php?id=<?= $row['id'] ?>"
                                   onclick="return confirm('Подтвердить возврат?')"
                                   style="display:flex;align-items:center;justify-content:center;gap:5px;background:#f0fdf4;border:1px solid #86efac;color:#16a34a;border-radius:6px;padding:5px 10px;font-size:12px;font-weight:600;text-decoration:none;white-space:nowrap;transition:background .15s"
                                   onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'">
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="#16a34a" stroke-width="2"><polyline points="1,6 4.5,9.5 11,2.5"/></svg> Сдан
                                </a>
                            <?php else: ?>
                                <span style="color:#9ca3c4;display:block;text-align:center">—</span>
                            <?php endif ?>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <div id="pagination" class="pagination"></div>
        <?php endif ?>

    </div>
</div>
<script>
(function() {
    const table=document.getElementById('main-table'); if(!table)return;
    const tbody=table.querySelector('tbody'),headers=table.querySelectorAll('th.sortable'),perSel=document.getElementById('per-page-sel'),pgDiv=document.getElementById('pagination'),countLbl=document.getElementById('count-label');
    let sortCol=4,sortDir='desc',perPage=parseInt(perSel?.value||25),curPage=1; // дефолт: по дате выдачи DESC
    const allRows=Array.from(tbody.querySelectorAll('tr'));
    function getCellText(row,col){const td=row.cells[col];return(td?.dataset.sort??td?.textContent??'').trim().toLowerCase();}
    function sortRows(){allRows.sort((a,b)=>{const va=getCellText(a,sortCol),vb=getCellText(b,sortCol);return sortDir==='asc'?va.localeCompare(vb,'ru'):vb.localeCompare(va,'ru');});headers.forEach(h=>{h.classList.remove('asc','desc');if(parseInt(h.dataset.col)===sortCol)h.classList.add(sortDir);});}
    function renderPage(){const start=(curPage-1)*perPage;allRows.forEach((r,i)=>{r.style.display=(i>=start&&i<start+perPage)?'':'none';tbody.appendChild(r);});if(countLbl){const end=Math.min(start+perPage,allRows.length);countLbl.textContent=allRows.length===0?'Нет записей':`Показано ${start+1}–${end} из ${allRows.length}`;}renderPagination();}
    function renderPagination(){if(!pgDiv)return;const pages=Math.ceil(allRows.length/perPage);if(pages<=1){pgDiv.innerHTML='';return;}let html='';if(curPage>1)html+=`<a href="#" data-p="${curPage-1}">←</a>`;for(let i=1;i<=pages;i++){if(i===1||i===pages||Math.abs(i-curPage)<=2)html+=`<a href="#" data-p="${i}" class="${i===curPage?'active':''}">${i}</a>`;else if(Math.abs(i-curPage)===3)html+=`<span>…</span>`;}if(curPage<pages)html+=`<a href="#" data-p="${curPage+1}">→</a>`;pgDiv.innerHTML=html;pgDiv.querySelectorAll('a[data-p]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();curPage=parseInt(a.dataset.p);renderPage();}));}
    headers.forEach(h=>h.addEventListener('click',()=>{const col=parseInt(h.dataset.col);if(col===sortCol)sortDir=sortDir==='asc'?'desc':'asc';else{sortCol=col;sortDir='asc';}curPage=1;sortRows();renderPage();}));
    if(perSel)perSel.addEventListener('change',()=>{perPage=parseInt(perSel.value);curPage=1;renderPage();});
    let searchTimer;
    function applyFilters(){
        const inputs=document.querySelectorAll('.filters-container select, .filters-container input[type=text]');
        const checks=document.querySelectorAll('.filters-container input[type=checkbox]');
        const params=new URLSearchParams(window.location.search);
        params.delete('page');
        inputs.forEach(el=>{if(el.value)params.set(el.name,el.value);else params.delete(el.name);});
        checks.forEach(el=>{if(el.checked)params.set(el.name,'1');else params.delete(el.name);});
        window.location.search=params.toString();
    }
    const si=document.getElementById('search-input');
    if(si){si.addEventListener('keydown',e=>{if(e.key==='Enter'){clearTimeout(searchTimer);applyFilters();}});si.addEventListener('blur',()=>{clearTimeout(searchTimer);if(si.value!==si.defaultValue)applyFilters();});si.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(applyFilters,900);});}
    document.querySelectorAll('.filters-container select, .filters-container input[type=checkbox]').forEach(el=>el.addEventListener('change',applyFilters));
    sortRows();renderPage();
})();
</script>
</body>
</html>