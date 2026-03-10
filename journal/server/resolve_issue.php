<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$id = (int)($_POST['id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'index.php';

// Validate redirect to prevent open redirect
if (!preg_match('/^(index|edit)\.php/', $redirect)) {
    $redirect = 'index.php';
}

if ($id) {
    $pdo->prepare("UPDATE server_visit_issues SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL")
        ->execute([$id]);
}

header("Location: $redirect"); exit;