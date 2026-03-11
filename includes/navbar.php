<?php
include_once __DIR__ . '/config.php';

$current = $_SERVER['REQUEST_URI'] ?? '';

function is_active_link(string $current, string $path): string {
    return str_starts_with($current, $path) ? 'active' : '';
}

$journalActive = str_starts_with($current, '/adminis/journal') ? 'active' : '';
?>

<div class="app-sidebar">
  <a class="sidebar-brand" href="/adminis/index.php"><?= SITE_TITLE ?></a>

  <nav class="nav">
    <a class="nav-link <?= is_active_link($current, '/adminis/map') ?>"       href="/adminis/map/">
      <span class="nav-icon">🗺</span> Карта сети
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/rooms') ?>"     href="/adminis/rooms/">
      <span class="nav-icon">🏫</span> Кабинеты
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/computers') ?>" href="/adminis/computers/">
      <span class="nav-icon">🖥</span> Компьютеры
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/servers') ?>"   href="/adminis/servers/">
      <span class="nav-icon">🗄</span> Серверы
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/notebooks') ?>" href="/adminis/notebooks/">
      <span class="nav-icon">💻</span> Ноутбуки
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/printers') ?>"  href="/adminis/printers/">
      <span class="nav-icon">🖨</span> Принтеры
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/ups') ?>"       href="/adminis/ups/">
      <span class="nav-icon">🔋</span> ИБП
    </a>
    <a class="nav-link <?= is_active_link($current, '/adminis/employees') ?>" href="/adminis/employees/">
      <span class="nav-icon">👥</span> Сотрудники
    </a>

    <!-- Журнал с выпадающим подменю -->
    <div class="nav-dropdown <?= $journalActive ?>">
      <div class="nav-dropdown-toggle nav-link <?= $journalActive ?>"
           onclick="toggleDropdown(this)">
        <span class="nav-icon">📋</span>
        Журнал
        <svg class="nav-dropdown-arrow" width="12" height="12" viewBox="0 0 12 12"
             fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="2,4 6,8 10,4"/>
        </svg>
      </div>
      <div class="nav-dropdown-menu <?= $journalActive ? 'open' : '' ?>">
        <a class="nav-sublink <?= is_active_link($current, '/adminis/journal/laptops') ?>"
           href="/adminis/journal/laptops/">
          💻 Выдача ноутбуков
        </a>
        <a class="nav-sublink <?= is_active_link($current, '/adminis/journal/server') ?>"
           href="/adminis/journal/server/">
          🗄 Посещение серверной
        </a>
      </div>
    </div>

    <a class="nav-link <?= is_active_link($current, '/adminis/docs') ?>" href="/adminis/docs/">
      <span class="nav-icon">📘</span> Документация
    </a>
  </nav>
</div>

<script>
function toggleDropdown(el) {
    const menu = el.nextElementSibling;
    menu.classList.toggle('open');
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.nav-dropdown').forEach(dd => {
        if (dd.classList.contains('active')) {
            dd.querySelector('.nav-dropdown-menu')?.classList.add('open');
        }
    });
});
</script>