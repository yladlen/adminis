<?php
function mapTypeToFolder(string $type): string {
    return [
        'ПК' => 'pc',
        'Сервер' => 'server',
        'Принтер' => 'printer',
        'Маршрутизатор' => 'router',
        'Свитч' => 'switch',
        'МФУ' => 'mfu',
        'Интерактивная доска' => 'board',
        'Прочее' => 'other',
    ][$type] ?? 'other';
}
