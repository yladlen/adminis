<?php
function mapTypeToFolder(string $type): string {
    return [
        'ПК'                   => 'pc',
        'Сервер'               => 'server',
        'Принтер'              => 'printer',
        'Маршрутизатор'        => 'router',
        'Свитч'                => 'switch',
        'МФУ'                  => 'mfu',
        'Интерактивная доска'  => 'board',
        'Ноутбук'              => 'pc',
        'ИБП'                  => 'other',
        'Прочее'               => 'other',
    ][$type] ?? 'other';
}

function getMapData(PDO $pdo): array {
    $devices = $pdo->query("
        SELECT d.*, r.name AS room_name, r.id AS room_id,
               (SELECT COUNT(*) FROM computer_issues ci WHERE ci.device_id=d.id AND ci.resolved_at IS NULL) AS open_issues
        FROM devices d
        JOIN rooms r ON d.room_id = r.id
        ORDER BY r.id, d.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $links = $pdo->query("SELECT * FROM switch_links")->fetchAll(PDO::FETCH_ASSOC);

    $nodes  = [];
    $edges  = [];
    $groups = [];

    foreach ($devices as $device) {
        $groups[$device['room_id']]['devices'][]   = $device;
        $groups[$device['room_id']]['room_name']   = $device['room_name'];
    }

    foreach ($groups as $roomId => $group) {
        $groupKey = "room_$roomId";
        $nodes[] = [
            'key'     => $groupKey,
            'isGroup' => true,
            'text'    => $group['room_name'],
        ];

        foreach ($group['devices'] as $d) {
            // Короткая подпись под иконкой
            $label = $d['name'];

            // Детальный тултип при наведении
            $tooltip = $d['name'] . "\n" . $d['type'];
            if (!empty($d['ip']))               $tooltip .= "\nIP: "  . $d['ip'];
            if (!empty($d['mac']))              $tooltip .= "\nMAC: " . $d['mac'];
            if (!empty($d['inventory_number'])) $tooltip .= "\nИнв: " . $d['inventory_number'];
            if (!empty($d['status']))           $tooltip .= "\n"      . $d['status'];

            $nodes[] = [
                'key'       => (int)$d['id'],
                'text'      => $label,
                'tooltip'   => $tooltip,
                'type'      => $d['type'],
                'room_id'   => (int)$d['room_id'],
                'room_name' => $d['room_name'],
                'hasIssues' => (int)$d['open_issues'] > 0,
                'group'     => $groupKey,
                'icon'      => '../assets/icons/' . ($d['icon'] ?: 'default.png'),
            ];
        }
    }

    foreach ($links as $link) {
        $edges[] = [
            'from' => (int)$link['connected_to_device_id'],
            'to'   => (int)$link['device_id'],
        ];
    }

    return ['nodes' => $nodes, 'edges' => $edges];
}