<?php

function mapTypeToFolder(string $type): string {
    return [
        'ПК'                   => 'pc',
        'Сервер'               => 'server',
        'Принтер'              => 'printer',
        'Маршрутизатор'        => 'router',
        'Свитч'                => 'switch',
        'Коммутатор'           => 'switch',
        'МФУ'                  => 'mfu',
        'Интерактивная доска'  => 'board',
        'Ноутбук'              => 'pc',
        'ИБП'                  => 'ups',
        'Прочее'               => 'other',
    ][$type] ?? 'other';
}