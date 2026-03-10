<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $docs = $pdo->query("SELECT id, title FROM documentation ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $current_id = isset($_GET['id']) ? (int)$_GET['id'] : ($docs[0]['id'] ?? 0);

    $current_doc = null;
    if ($current_id > 0) {
        $stmt = $pdo->prepare("SELECT title, content FROM documentation WHERE id = ?");
        $stmt->execute([$current_id]);
        $current_doc = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Если документ не найден или нет документов вообще
    if (!$current_doc && count($docs) > 0 && $current_id > 0) {
        // Если запрошен несуществующий ID, берем первый
        $current_id = $docs[0]['id'];
        $stmt = $pdo->prepare("SELECT title, content FROM documentation WHERE id = ?");
        $stmt->execute([$current_id]);
        $current_doc = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$current_doc) {
        $current_doc = [
            'title' => 'Нет разделов', 
            'content' => '<p>Документация пока не добавлена. Используйте кнопку "Добавить раздел" для создания первого раздела.</p>'
        ];
        $current_id = 0;
    }

    echo json_encode([
        'docs' => $docs,
        'current_id' => $current_id,
        'current' => $current_doc
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка загрузки данных',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
