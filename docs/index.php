<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';
require_once '../includes/db.php';

$current_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

function getDocumentationTree($pdo, $parent_id = null) {
    $sql = "SELECT id, title, type, parent_id, order_index FROM documentation WHERE parent_id "
         . ($parent_id === null ? "IS NULL" : "= ?")
         . " ORDER BY order_index, title";
    $stmt = $pdo->prepare($sql);
    $parent_id === null ? $stmt->execute() : $stmt->execute([$parent_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDocumentById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT id, title, content, type, parent_id FROM documentation WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function cleanDocumentContent($content, $title) {
    $cleaned = preg_replace('/<h1[^>]*>' . preg_quote($title, '/') . '<\/h1>/i', '', $content);
    return preg_replace('/<h2[^>]*>' . preg_quote($title, '/') . '<\/h2>/i', '', $cleaned);
}

$current_doc = $current_id > 0 ? getDocumentById($pdo, $current_id) : null;
if (!$current_doc && $current_id > 0) {
    $root_docs = getDocumentationTree($pdo);
    if (!empty($root_docs)) {
        $current_doc = getDocumentById($pdo, $root_docs[0]['id']);
        $current_id  = $current_doc['id'];
    }
}

$doc_content = '<p style="color:#9ca3c4">Документация пока не добавлена.</p>';
if ($current_doc) {
    $doc_content = $current_doc['type'] === 'content'
        ? cleanDocumentContent($current_doc['content'], $current_doc['title'])
        : '<p style="color:#9ca3c4">Это раздел. Выберите документ из списка слева.</p>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Документация</title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <style>
        /* ---- Docs layout ---- */
        .docs-layout { display:flex; height:calc(100vh - 50px); overflow:hidden; }

        .docs-sidebar-left {
            width: 280px;
            min-width: 280px;
            background: #f8f9fc;
            border-right: 1px solid #e5e7ef;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .docs-sidebar-header {
            padding: 16px;
            border-bottom: 1px solid #e5e7ef;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .docs-sidebar-header h5 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #3a3f5c;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .docs-search {
            position: relative;
        }
        .docs-search input {
            width: 100%;
            padding: 7px 10px 7px 30px;
            border: 1px solid #e5e7ef;
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
            color: #3a3f5c;
            outline: none;
        }
        .docs-search input:focus { border-color: #4f6ef7; }
        .docs-search .search-icon {
            position: absolute;
            left: 9px; top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: #9ca3c4;
        }
        .search-results {
            display: none;
            background: #fff;
            border: 1px solid #e5e7ef;
            border-radius: 6px;
            margin-top: 4px;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }
        .search-results.active { display: block; }
        .search-result-item {
            padding: 8px 12px;
            font-size: 13px;
            cursor: pointer;
            color: #3a3f5c;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .search-result-item:hover { background: #f0f2ff; }
        .search-result-item:last-child { border-bottom: none; }

        .docs-add-buttons {
            display: flex;
            gap: 6px;
        }
        .docs-add-buttons a {
            flex: 1;
            font-size: 12px;
            padding: 6px 8px;
            text-align: center;
        }

        #doc-tree {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }

        /* Tree items */
        .doc-tree-list { list-style: none; margin: 0; padding: 0; }
        .doc-tree-item { position: relative; }

        .doc-tree-row {
            display: flex;
            align-items: center;
            padding: 0 8px;
            min-height: 32px;
            border-radius: 6px;
            margin: 1px 6px;
            cursor: pointer;
            transition: background .15s;
            gap: 4px;
        }
        .doc-tree-row:hover { background: #eef0ff; }
        .doc-tree-row.active { background: #4f6ef7; color: #fff; }
        .doc-tree-row.active .doc-tree-actions a { color: rgba(255,255,255,.8); }
        .doc-tree-row.active .doc-tree-actions a:hover { color: #fff; }

        .drag-handle {
            cursor: grab;
            color: #c0c6d9;
            font-size: 12px;
            padding: 2px 2px 2px 0;
            flex-shrink: 0;
        }
        .drag-handle:active { cursor: grabbing; }
        .doc-tree-row.active .drag-handle { color: rgba(255,255,255,.5); }

        .doc-tree-toggle {
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            flex-shrink: 0;
            color: #9ca3c4;
            transition: transform .2s;
        }
        .doc-tree-toggle.open { transform: rotate(90deg); }
        .doc-tree-toggle.empty { visibility: hidden; }

        .doc-tree-icon { font-size: 13px; flex-shrink: 0; }

        .doc-tree-label {
            flex: 1;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-decoration: none;
            color: inherit;
        }
        .doc-tree-label:hover { text-decoration: none; color: inherit; }

        /* Inline rename input */
        .doc-tree-label input.rename-input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            font-size: 13px;
            color: inherit;
            padding: 0;
        }
        .doc-tree-row.active .rename-input { color: #fff; }

        .doc-tree-actions {
            display: none;
            gap: 4px;
            flex-shrink: 0;
        }
        .doc-tree-row:hover .doc-tree-actions,
        .doc-tree-row.active .doc-tree-actions { display: flex; }
        .doc-tree-actions a {
            font-size: 11px;
            color: #9ca3c4;
            text-decoration: none;
            padding: 2px 3px;
            border-radius: 3px;
        }
        .doc-tree-actions a:hover { color: #4f6ef7; background: rgba(79,110,247,.1); }

        /* Drag & drop visual */
        .doc-tree-item.drag-over-top > .doc-tree-row    { border-top: 2px solid #4f6ef7; }
        .doc-tree-item.drag-over-bottom > .doc-tree-row { border-bottom: 2px solid #4f6ef7; }
        .doc-tree-item.drag-over-inside > .doc-tree-row { background: #e8edff !important; }
        .doc-tree-item.dragging { opacity: .4; }

        /* Nested children */
        .doc-tree-children { padding-left: 20px; }

        /* Docs content area */
        .docs-content {
            flex: 1;
            overflow-y: auto;
            padding: 28px 32px;
        }
        .doc-main-title {
            font-size: 22px;
            font-weight: 700;
            color: #1e2130;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e5e7ef;
        }
        #doc-content h1,#doc-content h2,#doc-content h3 { color:#1e2130; margin-top:20px; }
        #doc-content p { color:#4a4f6a; line-height:1.7; }
        #doc-content code { background:#f0f2f5; padding:2px 6px; border-radius:4px; font-size:13px; }
        #doc-content pre { background:#1e2130; color:#e2e8f0; padding:16px; border-radius:8px; overflow-x:auto; }
        #doc-content a { color:#4f6ef7; }
        #doc-content ul, #doc-content ol { padding-left:20px; color:#4a4f6a; }

        .doc-meta {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 20px;
        }
        .doc-meta a { font-size: 13px; }
    </style>
</head>
<body>
<div class="docs-layout">

    <!-- Sidebar -->
    <div class="docs-sidebar-left">
        <div class="docs-sidebar-header">
            <h5>Документация</h5>
            <div class="docs-search">
                <span class="search-icon"><svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="#9ca3c4" stroke-width="2"><circle cx="6.5" cy="6.5" r="4.5"/><line x1="10.5" y1="10.5" x2="14" y2="14"/></svg></span>
                <input type="text" id="search-input" placeholder="Поиск...">
                <div class="search-results" id="search-results"></div>
            </div>
            <div class="docs-add-buttons">
                <a href="add_docs.php?type=section" class="btn btn-outline-success">📁 Раздел</a>
                <a href="add_docs.php?type=content" class="btn btn-outline-success">📄 Документ</a>
            </div>
        </div>

        <div id="doc-tree">
            <?php
            function renderDocTree($pdo, $parent_id = null, $current_id = 0, $level = 0) {
                $docs = getDocumentationTree($pdo, $parent_id);
                if (empty($docs)) return;
                $class = $level === 0 ? 'doc-tree-list' : 'doc-tree-list doc-tree-children';
                echo '<ul class="' . $class . '" data-parent="' . ($parent_id ?? 'null') . '">';
                foreach ($docs as $doc) {
                    $active     = $doc['id'] == $current_id ? 'active' : '';
                    $is_section = $doc['type'] === 'section';
                    $has_children = $pdo->prepare("SELECT COUNT(*) FROM documentation WHERE parent_id=?");
                    $has_children->execute([$doc['id']]);
                    $child_count = $has_children->fetchColumn();
                    echo '<li class="doc-tree-item" data-id="' . $doc['id'] . '" data-type="' . $doc['type'] . '">';
                    echo '<div class="doc-tree-row ' . $active . '" data-id="' . $doc['id'] . '">';
                    echo '<span class="drag-handle" title="Перетащить"><svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2.5" cy="2.5" r="1.5"/><circle cx="7.5" cy="2.5" r="1.5"/><circle cx="2.5" cy="7" r="1.5"/><circle cx="7.5" cy="7" r="1.5"/><circle cx="2.5" cy="11.5" r="1.5"/><circle cx="7.5" cy="11.5" r="1.5"/></svg></span>';
                    if ($is_section) {
                        $toggle_class = $child_count > 0 ? 'open' : 'empty';
                        echo '<span class="doc-tree-toggle ' . $toggle_class . '"><svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor"><polygon points="1,1 7,4 1,7"/></svg></span>';
                    } else {
                        echo '<span class="doc-tree-toggle empty"><svg width="8" height="8" viewBox="0 0 8 8" fill="currentColor"><polygon points="1,1 7,4 1,7"/></svg></span>';
                    }
                    $icon_section = '<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="#4f6ef7" stroke-width="1.5"><path d="M1 3.5A1.5 1.5 0 012.5 2H6l1.5 2H14a1.5 1.5 0 011.5 1.5V13A1.5 1.5 0 0114 14.5H2.5A1.5 1.5 0 011 13V3.5z"/></svg>';
                    $icon_doc     = '<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="#6b7499" stroke-width="1.5"><rect x="2" y="1" width="9" height="14" rx="1.5"/><path d="M11 1l3 3v11a1.5 1.5 0 01-1.5 1.5H2.5"/><path d="M11 1v3h3"/><line x1="4.5" y1="6.5" x2="9.5" y2="6.5"/><line x1="4.5" y1="9" x2="9.5" y2="9"/><line x1="4.5" y1="11.5" x2="7.5" y2="11.5"/></svg>';
                    echo '<span class="doc-tree-icon">' . ($is_section ? $icon_section : $icon_doc) . '</span>';
                    echo '<a href="index.php?id=' . $doc['id'] . '" class="doc-tree-label" data-id="' . $doc['id'] . '">' . htmlspecialchars($doc['title']) . '</a>';
                    echo '<span class="doc-tree-actions">';
                    echo '<a href="#" class="rename-btn" data-id="' . $doc['id'] . '" title="Переименовать"><svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11 2l3 3L5 14H2v-3L11 2z"/></svg></a>';
                    echo '<a href="edit_docs.php?id=' . $doc['id'] . '" title="Настройки"><svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="2.5"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.05 3.05l1.41 1.41M11.54 11.54l1.41 1.41M3.05 12.95l1.41-1.41M11.54 4.46l1.41-1.41"/></svg></a>';
                    echo '<a href="#" class="delete-btn" data-id="' . $doc['id'] . '" data-title="' . htmlspecialchars($doc['title']) . '" title="Удалить"><svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="3,4 13,4"/><path d="M5 4V2.5a.5.5 0 01.5-.5h5a.5.5 0 01.5.5V4"/><path d="M6 7v5M10 7v5"/><path d="M4 4l.8 9.5a.5.5 0 00.5.5h5.4a.5.5 0 00.5-.5L12 4"/></svg></a>';
                    echo '</span>';
                    echo '</div>';
                    if ($is_section) {
                        renderDocTree($pdo, $doc['id'], $current_id, $level + 1);
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            renderDocTree($pdo, null, $current_id);
            ?>
        </div>
    </div>

    <!-- Content -->
    <div class="docs-content">
        <?php if ($current_doc): ?>
            <div class="doc-meta">
                <h1 class="doc-main-title" style="flex:1;border:none;margin:0;padding:0"><?= htmlspecialchars($current_doc['title']) ?></h1>
                <a href="edit_docs.php?id=<?= $current_id ?>" class="btn btn-outline-success btn-sm">✎ Редактировать</a>
            </div>
            <hr style="border-color:#e5e7ef;margin-bottom:20px">
            <div id="doc-content"><?= $doc_content ?></div>
        <?php else: ?>
            <div style="text-align:center;padding:60px 20px;color:#9ca3c4">
                <div style="font-size:48px;margin-bottom:16px;opacity:.4">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#9ca3c4" stroke-width="1.2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
                </div>
                <h3 style="color:#6b7499">Добро пожаловать в документацию</h3>
                <p>Используйте кнопки «Раздел» или «Документ» для создания контента.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// ---- Search ----
const searchInput = document.getElementById('search-input');
const searchResults = document.getElementById('search-results');
let searchTimer;

searchInput.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (!q) { searchResults.classList.remove('active'); return; }
    searchTimer = setTimeout(() => {
        fetch('reorder.php?action=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(items => {
                if (!items.length) {
                    searchResults.innerHTML = '<div class="search-result-item" style="color:#9ca3c4">Ничего не найдено</div>';
                } else {
                    searchResults.innerHTML = items.map(i => {
                        const iconSection = `<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="#4f6ef7" stroke-width="1.5"><path d="M1 3.5A1.5 1.5 0 012.5 2H6l1.5 2H14a1.5 1.5 0 011.5 1.5V13A1.5 1.5 0 0114 14.5H2.5A1.5 1.5 0 011 13V3.5z"/></svg>`;
                        const iconDoc     = `<svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="#6b7499" stroke-width="1.5"><rect x="2" y="1" width="9" height="14" rx="1.5"/><path d="M11 1l3 3"/><line x1="4.5" y1="7" x2="9.5" y2="7"/><line x1="4.5" y1="9.5" x2="9.5" y2="9.5"/></svg>`;
                        return `<div class="search-result-item" onclick="location.href='index.php?id=${i.id}'">${i.type==='section'?iconSection:iconDoc} ${i.title.replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi'), '<strong>$1</strong>')}</div>`;
                    }).join('');
                }
                searchResults.classList.add('active');
            });
    }, 250);
});

document.addEventListener('click', e => {
    if (!searchInput.contains(e.target) && !searchResults.contains(e.target))
        searchResults.classList.remove('active');
});

// ---- Toggle sections — click on the whole row ----
function toggleSection(item) {
    const children = item.querySelector(':scope > .doc-tree-list');
    const toggle   = item.querySelector(':scope > .doc-tree-row .doc-tree-toggle');
    if (!children) return;
    const hidden = children.style.display === 'none';
    children.style.display = hidden ? '' : 'none';
    if (toggle) toggle.classList.toggle('open', hidden);
}

document.querySelectorAll('.doc-tree-row').forEach(row => {
    row.addEventListener('click', e => {
        // Ignore clicks on action buttons, rename, delete, settings links
        if (e.target.closest('.doc-tree-actions')) return;
        // Ignore clicks on drag handle
        if (e.target.closest('.drag-handle')) return;

        const item = row.closest('.doc-tree-item');
        if (item.dataset.type === 'section') {
            e.preventDefault();
            toggleSection(item);
        }
        // For documents — let the <a> link navigate normally
    });
});

// ---- Inline rename ----
document.querySelectorAll('.rename-btn').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        const id = btn.dataset.id;
        const label = btn.closest('.doc-tree-row').querySelector('.doc-tree-label');
        const oldTitle = label.textContent.trim();
        const input = document.createElement('input');
        input.className = 'rename-input';
        input.value = oldTitle;
        label.innerHTML = '';
        label.appendChild(input);
        input.focus(); input.select();

        function save() {
            const newTitle = input.value.trim() || oldTitle;
            fetch('reorder.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `action=rename&id=${id}&title=${encodeURIComponent(newTitle)}`
            }).then(() => { label.textContent = newTitle; });
        }
        input.addEventListener('blur', save);
        input.addEventListener('keydown', e => { if (e.key==='Enter') input.blur(); if (e.key==='Escape') { label.textContent = oldTitle; } });
    });
});

// ---- Delete ----
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        if (!confirm(`Удалить "${btn.dataset.title}"? Все вложенные элементы тоже будут удалены.`)) return;
        fetch('reorder.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `action=delete&id=${btn.dataset.id}`
        }).then(() => location.reload());
    });
});

// ---- Drag & drop reorder ----
let dragItem = null;
let dragCounter = 0; // track enter/leave for nested elements

function getAllItems() {
    const items = [];
    function traverse(list, parent_id) {
        list.querySelectorAll(':scope > .doc-tree-item').forEach((item, idx) => {
            items.push({ id: item.dataset.id, parent_id: parent_id, order: idx });
            const sub = item.querySelector(':scope > .doc-tree-list');
            if (sub) traverse(sub, item.dataset.id);
        });
    }
    const root = document.querySelector('#doc-tree > .doc-tree-list');
    if (root) traverse(root, '');
    return items;
}

function saveOrder() {
    const items = getAllItems();
    if (!items.length) return;
    fetch('reorder.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'reorder', items: items })
    })
    .then(r => r.json())
    .then(d => { if (!d.ok) console.error('reorder failed', d); })
    .catch(err => console.error('reorder error', err));
}

function clearDropIndicators() {
    document.querySelectorAll('.drag-over-top,.drag-over-bottom,.drag-over-inside')
        .forEach(el => el.classList.remove('drag-over-top','drag-over-bottom','drag-over-inside'));
}

function getTargetItem(e) {
    // Walk up from the actual event target to find the nearest .doc-tree-item
    let el = e.target;
    while (el && !el.classList.contains('doc-tree-item')) el = el.parentElement;
    return el;
}

// Attach drag events to ALL items (including those inside sections)
function attachDragEvents(item) {
    // Only enable dragging when mousedown is on the handle
    const handle = item.querySelector(':scope > .doc-tree-row .drag-handle');
    if (handle) {
        handle.addEventListener('mousedown', () => { item.draggable = true; });
        handle.addEventListener('mouseup',   () => { item.draggable = false; });
        // Disable by default — will be re-enabled on mousedown of handle
        item.draggable = false;
    }

    item.addEventListener('dragstart', e => {
        if (!item.draggable) { e.preventDefault(); return; }
        dragItem = item;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', item.dataset.id);
        setTimeout(() => item.classList.add('dragging'), 0);
        e.stopPropagation();
    });

    item.addEventListener('dragend', e => {
        item.classList.remove('dragging');
        clearDropIndicators();
        dragItem = null;
        saveOrder();
    });
}

// Use the doc-tree container for dragover/drop to handle all items globally
const docTree = document.getElementById('doc-tree');

docTree.addEventListener('dragover', e => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (!dragItem) return;

    const target = getTargetItem(e);
    if (!target || target === dragItem || dragItem.contains(target)) return;

    clearDropIndicators();

    const row = target.querySelector(':scope > .doc-tree-row');
    if (!row) return;
    const rect = row.getBoundingClientRect();
    const y = e.clientY - rect.top;
    const h = rect.height;

    if (target.dataset.type === 'section' && y > h * 0.25 && y < h * 0.75) {
        target.classList.add('drag-over-inside');
    } else if (y <= h * 0.5) {
        target.classList.add('drag-over-top');
    } else {
        target.classList.add('drag-over-bottom');
    }
});

docTree.addEventListener('dragleave', e => {
    // Only clear if leaving the entire doc-tree
    if (!docTree.contains(e.relatedTarget)) clearDropIndicators();
});

docTree.addEventListener('drop', e => {
    e.preventDefault();
    if (!dragItem) return;

    const target = getTargetItem(e);
    if (!target || target === dragItem || dragItem.contains(target)) {
        clearDropIndicators();
        return;
    }

    if (target.classList.contains('drag-over-inside')) {
        // Drop INTO section
        let sub = target.querySelector(':scope > .doc-tree-list');
        if (!sub) {
            sub = document.createElement('ul');
            sub.className = 'doc-tree-list doc-tree-children';
            target.appendChild(sub);
            const toggle = target.querySelector(':scope > .doc-tree-row .doc-tree-toggle');
            if (toggle) { toggle.classList.remove('empty'); toggle.classList.add('open'); }
        }
        sub.appendChild(dragItem);
        // Make sure the section is visible
        sub.style.display = '';
    } else if (target.classList.contains('drag-over-top')) {
        target.parentNode.insertBefore(dragItem, target);
    } else {
        target.parentNode.insertBefore(dragItem, target.nextSibling);
    }

    clearDropIndicators();
    saveOrder();
    dragItem = null;
});

// Attach to all existing items
document.querySelectorAll('.doc-tree-item').forEach(attachDragEvents);
</script>
</body>
</html>