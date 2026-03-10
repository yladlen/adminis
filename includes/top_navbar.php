<?php
$page_titles = [
    '/adminis/map'       => 'Карта сети',
    '/adminis/rooms'     => 'Кабинеты',
    '/adminis/computers' => 'Компьютеры',
    '/adminis/servers'   => 'Серверы',
    '/adminis/laptops'   => 'Ноутбуки',
    '/adminis/printers'  => 'Принтеры',
    '/adminis/ups'       => 'ИБП',
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
        <span class="top-navbar-version">adminis v<?= APP_VERSION ?></span>
        <a href="/adminis/logout.php" class="top-navbar-logout">🚪 Выход</a>
    </div>
</div>