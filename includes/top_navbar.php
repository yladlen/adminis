<?php
$page_titles = [
    '/adminis/map'       => 'Карта сети',
    '/adminis/rooms'     => 'Кабинеты',
    '/adminis/computers' => 'Компьютеры',
    '/adminis/servers'   => 'Серверы',
    '/adminis/notebooks' => 'Ноутбуки',
    '/adminis/printers'  => 'Принтеры',
    '/adminis/ups'       => 'ИБП',
    '/adminis/switch'    => 'Комутация',
    '/adminis/journal'   => 'Журнал',
    '/adminis/employees' => 'Сотрудники',
    '/adminis/docs'      => 'Документация',
];

$current_uri = $_SERVER['REQUEST_URI'] ?? '';
$page_title = 'База данных';

foreach ($page_titles as $path => $title) {
    if (str_starts_with($current_uri, $path)) {
        $page_title = $title;
        break;
    }
}
?>

<div class="top-navbar">
    <h1 class="top-navbar-title"><?= htmlspecialchars($page_title) ?></h1>

    <div class="top-navbar-right">
        <!-- Глобальный поиск -->
        <div class="global-search" id="global-search">
            <div class="global-search-wrap">
                <svg class="gs-icon" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="#9ca3c4" stroke-width="2">
                    <circle cx="6.5" cy="6.5" r="4.5"/><line x1="10.5" y1="10.5" x2="14" y2="14"/>
                </svg>
                <input type="text" id="gs-input" placeholder="Поиск..." autocomplete="off">
                <kbd class="gs-kbd">/</kbd>
            </div>
            <div class="gs-dropdown" id="gs-dropdown"></div>
        </div>

        <span class="top-navbar-version">adminis v<?= APP_VERSION ?></span>
        <a href="/adminis/setup/admin.php" class="top-navbar-admin">⚙️ Админ</a>
        <a href="/adminis/logout.php" class="top-navbar-logout">🚪 Выход</a>
    </div>
</div>


<script>
(function() {
    const input    = document.getElementById('gs-input');
    const dropdown = document.getElementById('gs-dropdown');
    if (!input) return;
    let timer, activeIdx = -1, items = [];

    document.addEventListener('keydown', e => {
        if (e.key === '/' && document.activeElement !== input &&
            !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault(); input.focus(); input.select();
        }
        if (e.key === 'Escape') { close(); input.blur(); }
    });

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { close(); return; }
        dropdown.innerHTML = '<div class="gs-loading">Поиск...</div>';
        dropdown.classList.add('open');
        timer = setTimeout(() => doSearch(q), 250);
    });

    input.addEventListener('keydown', e => {
        if (!dropdown.classList.contains('open')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(activeIdx + 1); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); setActive(activeIdx - 1); }
        if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) {
            e.preventDefault(); location.href = items[activeIdx].href;
        }
    });

    document.addEventListener('click', e => {
        if (!document.getElementById('global-search').contains(e.target)) close();
    });

    function setActive(idx) {
        items.forEach(el => el.classList.remove('gs-active'));
        activeIdx = Math.max(-1, Math.min(idx, items.length - 1));
        if (activeIdx >= 0) { items[activeIdx].classList.add('gs-active'); items[activeIdx].scrollIntoView({block:'nearest'}); }
    }

    function close() { dropdown.classList.remove('open'); activeIdx = -1; items = []; }

    function esc(s) { return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function hi(text, q) {
        const escaped = esc(text);
        if (!q) return escaped;
        return escaped.replace(
            new RegExp('(' + esc(q).replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi'),
            '<mark>$1</mark>'
        );
    }

    function statusCls(s) {
        if (s === 'На ремонте') return 's-repair';
        if (s === 'Списан')     return 's-written';
        if (s === 'На хранении') return 's-storage';
        return '';
    }

    function doSearch(q) {
        fetch('/adminis/search.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.length) {
                    dropdown.innerHTML = '<div class="gs-empty">Ничего не найдено</div>';
                    dropdown.classList.add('open'); items = []; return;
                }
                let html = '', lastGroup = '';
                data.forEach(item => {
                    if (item.group !== lastGroup) {
                        html += `<div class="gs-group-label">${esc(item.group)}</div>`;
                        lastGroup = item.group;
                    }
                    html += `<a class="gs-item" href="${esc(item.url)}">
                        <span class="gs-item-icon">${item.icon}</span>
                        <span class="gs-item-body">
                            <div class="gs-item-title">${hi(item.title, q)}</div>
                            ${item.sub ? `<div class="gs-item-sub">${hi(item.sub, q)}</div>` : ''}
                        </span>
                        ${item.status ? `<span class="gs-status ${statusCls(item.status)}">${esc(item.status)}</span>` : ''}
                    </a>`;
                });
                dropdown.innerHTML = html;
                dropdown.classList.add('open');
                items = Array.from(dropdown.querySelectorAll('.gs-item'));
                activeIdx = -1;
            })
            .catch(() => { dropdown.innerHTML = '<div class="gs-empty">Ошибка поиска</div>'; });
    }
})();
</script>