<?php
require_once 'includes/db.php';

$room_id = $_GET['room_id'] ?? '';
if (!is_numeric($room_id)) exit;

$stmt = $pdo->prepare("SELECT id, name, type FROM devices WHERE room_id = ? ORDER BY name");
$stmt->execute([$room_id]);
$devices = $stmt->fetchAll();

foreach ($devices as $d) {
    echo "<option value=\"{$d['id']}\">{$d['name']} ({$d['type']})</option>";
}
