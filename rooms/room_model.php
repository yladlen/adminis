<?php

function getRooms(PDO $pdo, array $filters = []): array {
    $sql = "SELECT r.id, r.name, r.description, COUNT(d.id) AS device_count
            FROM rooms r
            LEFT JOIN devices d ON d.room_id = r.id";

    $conditions = [];
    $params = [];

    if (!empty($filters['room_id'])) {
        $conditions[] = 'r.id = ?';
        $params[] = $filters['room_id'];
    }

    if (!empty($filters['device_type'])) {
        $conditions[] = 'd.type = ?';
        $params[] = $filters['device_type'];
    }

    if (!empty($filters['status'])) {
        $conditions[] = 'd.status = ?';
        $params[] = $filters['status'];
    }

    if ($conditions) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " GROUP BY r.id ORDER BY r.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRoomList(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll();
}

function createRoom(PDO $pdo, string $name, ?string $description): void {
    $stmt = $pdo->prepare("INSERT INTO rooms (name, description) VALUES (?, ?)");
    $stmt->execute([$name, $description]);
}

function getRoomById(PDO $pdo, int $id): array|false {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function updateRoom(PDO $pdo, int $id, string $name, ?string $description): void {
    $stmt = $pdo->prepare("UPDATE rooms SET name = ?, description = ? WHERE id = ?");
    $stmt->execute([$name, $description, $id]);
}

function deleteRoom(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM devices WHERE room_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
}

function duplicateRoom(PDO $pdo, int $originalId): int {
    $room = getRoomById($pdo, $originalId);
    if (!$room) return 0;

    $newName = $room['name'] . " (копия)";
    $stmt = $pdo->prepare("INSERT INTO rooms (name, description) VALUES (?, ?)");
    $stmt->execute([$newName, $room['description']]);
    $newRoomId = $pdo->lastInsertId();

    $devices = $pdo->prepare("SELECT * FROM devices WHERE room_id = ?");
    $devices->execute([$originalId]);
    foreach ($devices as $d) {
        $stmt = $pdo->prepare("INSERT INTO devices (room_id, name, type, ip, mac, inventory_number, status, comment, icon)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $newRoomId,
            $d['name'],
            $d['type'],
            $d['ip'],
            $d['mac'],
            $d['inventory_number'],
            $d['status'],
            $d['comment'],
            $d['icon']
        ]);
    }

    return $newRoomId;
}

function getDevicesByRoom(PDO $pdo, int $roomId): array {
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE room_id = ? ORDER BY name");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDeviceConnectionName(PDO $pdo, int $deviceId): ?string {
    $stmt = $pdo->prepare("
        SELECT d2.name 
        FROM switch_links s
        JOIN devices d2 ON s.connected_to_device_id = d2.id
        WHERE s.device_id = ?
    ");
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['name'] ?? null;
}
