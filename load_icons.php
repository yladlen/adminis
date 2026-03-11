<?php
$type = $_GET['type'] ?? '';
$type = preg_replace('/[^а-яА-Яa-zA-Z0-9 _-]/u', '', $type);

// Преобразуем тип в имя папки
$typeToFolder = [
    'ПК' => 'pc',
    'Сервер' => 'server',
    'Принтер' => 'printer',
    'Маршрутизатор' => 'router',
    'Свитч' => 'switch',
    'МФУ' => 'mfu',
    'Интерактивная доска' => 'board',
    'Ноутбук' => 'laptop',
    'Прочее' => 'other',
];
$folder = $typeToFolder[$type] ?? 'other';
$dir = "assets/icons/$folder";

if (!is_dir($dir)) {
    echo "<p>Нет иконок для выбранного типа.</p>";
    exit;
}

$files = glob("$dir/*.png");

foreach ($files as $file) {
    $name = basename($file);
    $base_path = '/adminis'; // если нужно
    echo "<img src=\"$base_path/assets/icons/$folder/$name\" class=\"icon-option\" data-filename=\"$name\" style=\"width:64px; height:64px; margin:5px; cursor:pointer;\">";
}
