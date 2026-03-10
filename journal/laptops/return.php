<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("UPDATE journal SET status='сдан', end_date=CURDATE() WHERE id=? AND status='взят'")
        ->execute([$id]);
}
header("Location: index.php"); exit;