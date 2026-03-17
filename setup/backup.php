<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

preg_match('/dbname=([^;]+)/', DB_DSN, $m);
$dbname = $m[1] ?? 'adminis_db';

$filename = 'adminis_backup_' . date('Y-m-d_H-i-s') . '.sql';
$cmd = sprintf(
    'mysqldump --no-tablespaces -h %s -u %s -p%s %s 2>&1',
    escapeshellarg('localhost'),
    escapeshellarg(DB_USER),
    escapeshellarg(DB_PASS),
    escapeshellarg($dbname)
);
$output = shell_exec($cmd);

if ($output && !str_contains($output, 'ERROR')) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($output));
    header('Cache-Control: no-cache');
    echo $output;
} else {
    http_response_code(500);
    echo 'Ошибка создания бэкапа. Убедитесь что mysqldump доступен.<br>';
    echo '<pre>' . htmlspecialchars($output ?? '') . '</pre>';
}
exit;