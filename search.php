<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$results = [];

// ── Устройства ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT d.id, d.name, d.type, d.ip, d.status, r.name AS room_name
    FROM devices d
    LEFT JOIN rooms r ON r.id = d.room_id
    WHERE d.name LIKE ? OR d.ip LIKE ? OR d.mac LIKE ? OR d.inventory_number LIKE ?
    ORDER BY d.name
    LIMIT 7
");
$stmt->execute([$like, $like, $like, $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $typeFolder = [
        'ПК'                  => 'computers',
        'Сервер'              => 'servers',
        'Принтер'             => 'printers',
        'Маршрутизатор'       => 'routers',
        'Свитч'               => 'switches',
        'Интерактивная доска' => 'other',
        'Ноутбук'             => 'notebooks',
        'ИБП'                 => 'other',
        'Прочее'              => 'other',
    ][$row['type']] ?? 'other';

    $results[] = [
        'group'    => 'Устройства',
        'icon'     => '🖥',
        'title'    => $row['name'],
        'sub'      => ($row['room_name'] ?? '—') . ($row['ip'] ? ' · ' . $row['ip'] : ''),
        'status'   => $row['status'],
        'url'      => '/adminis/rooms/edit_device.php?id=' . $row['id'],
    ];
}

// ── Кабинеты ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT id, name, description,
           (SELECT COUNT(*) FROM devices WHERE room_id = rooms.id) AS cnt
    FROM rooms
    WHERE name LIKE ? OR description LIKE ?
    ORDER BY name
    LIMIT 4
");
$stmt->execute([$like, $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $results[] = [
        'group' => 'Кабинеты',
        'icon'  => '🏫',
        'title' => $row['name'],
        'sub'   => ($row['description'] ? mb_substr($row['description'], 0, 50) . '…' : '') . ' · ' . $row['cnt'] . ' устр.',
        'url'   => '/adminis/rooms/room.php?id=' . $row['id'],
    ];
}

// ── Сотрудники ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.id, e.full_name, e.position, e.internal_phone, r.name AS room_name
    FROM employees e
    LEFT JOIN rooms r ON r.id = e.room_id
    WHERE e.full_name LIKE ? OR e.position LIKE ? OR e.internal_phone LIKE ?
    ORDER BY e.full_name
    LIMIT 4
");
$stmt->execute([$like, $like, $like]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sub = [];
    if ($row['position'])   $sub[] = $row['position'];
    if ($row['room_name'])  $sub[] = $row['room_name'];
    if ($row['internal_phone']) $sub[] = '☎ ' . $row['internal_phone'];
    $results[] = [
        'group' => 'Сотрудники',
        'icon'  => '👤',
        'title' => $row['full_name'],
        'sub'   => implode(' · ', $sub),
        'url'   => '/adminis/employees/?edit=' . $row['id'],
    ];
}

echo json_encode($results, JSON_UNESCAPED_UNICODE);