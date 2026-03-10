<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

// Read body once — needed for JSON requests
$raw_body  = file_get_contents('php://input');
$json_body = !empty($raw_body) ? json_decode($raw_body, true) : null;

// action can come from JSON body, POST form, or GET
$action = $json_body['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';

// ---- Rename ----
if ($action === 'rename') {
    $id    = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    if (!$id || !$title) { echo json_encode(['ok'=>false]); exit; }
    $pdo->prepare("UPDATE documentation SET title=? WHERE id=?")->execute([$title, $id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ---- Delete ----
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false]); exit; }
    function deleteNode($pdo, $id) {
        $children = $pdo->prepare("SELECT id FROM documentation WHERE parent_id=?");
        $children->execute([$id]);
        foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $child_id) deleteNode($pdo, $child_id);
        $pdo->prepare("DELETE FROM documentation WHERE id=?")->execute([$id]);
    }
    deleteNode($pdo, $id);
    echo json_encode(['ok'=>true]);
    exit;
}

// ---- Reorder ----
if ($action === 'reorder') {
    $items = $json_body['items'] ?? [];
    if (empty($items)) {
        echo json_encode(['ok'=>false, 'error'=>'no items']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE documentation SET parent_id=?, order_index=? WHERE id=?");
    foreach ($items as $item) {
        $parent = ($item['parent_id'] === '' || $item['parent_id'] === null) ? null : (int)$item['parent_id'];
        $stmt->execute([$parent, (int)$item['order'], (int)$item['id']]);
    }
    echo json_encode(['ok'=>true, 'saved'=>count($items)]);
    exit;
}

// ---- Search ----
if ($action === 'search') {
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("SELECT id, title, type FROM documentation WHERE title LIKE ? ORDER BY title LIMIT 20");
    $stmt->execute([$q]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

echo json_encode(['ok'=>false, 'error'=>'unknown action: ' . $action]);