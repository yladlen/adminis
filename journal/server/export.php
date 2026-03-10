<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$where = []; $params = [];
if (!empty($_GET['room_id'])) { $where[] = 'sv.room_id = ?'; $params[] = (int)$_GET['room_id']; }

$sql = "SELECT sv.*, r.name AS room_name FROM server_visits sv JOIN rooms r ON r.id=sv.room_id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY sv.visited_at DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="server_visits_' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Дата/время','Помещение','Серверы','ИБП','Свитчи','Температура','Охлаждение','Электропитание','Доступ/замки','Комментарий'], ';');

$yn = fn($v) => $v ? 'OK' : 'Проблема';
foreach ($rows as $r) {
    fputcsv($out, [
        date('d.m.Y H:i', strtotime($r['visited_at'])),
        $r['room_name'],
        $yn($r['check_servers']),
        $yn($r['check_ups']),
        $yn($r['check_switches']),
        $yn($r['check_temp']),
        $yn($r['check_cooling']),
        $yn($r['check_power']),
        $yn($r['check_access']),
        $r['comment'] ?? '',
    ], ';');
}
fclose($out); exit;