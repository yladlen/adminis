<?php
require_once 'includes/db.php';

if (!isset($_GET['id'])) {
    header('Location: laptops.php');
    exit;
}

$id = (int)$_GET['id'];

$stmt = $pdo->prepare("UPDATE laptops SET status = 'сдан', end_date = CURDATE() WHERE id = ?");
$stmt->execute([$id]);

header('Location: laptops/index.php');
exit;
