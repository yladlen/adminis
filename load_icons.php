<?php
/**
 * Отдаёт список иконок из /assets/icons/ как JSON-массив имён файлов.
 * Используется в edit.php для пикера иконок.
 */
require_once __DIR__ . '/includes/auth.php';

$dir = __DIR__ . '/assets/icons/';
$files = [];

if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','gif','svg','webp'])) {
            $files[] = $f;
        }
    }
    sort($files);
}

header('Content-Type: application/json');
echo json_encode($files);