<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$where = []; $params = [];
if (!empty($_GET['employee_id'])) { $where[] = 'j.employee_id = ?'; $params[] = $_GET['employee_id']; }
if (!empty($_GET['device_id']))   { $where[] = 'j.device_id = ?';   $params[] = $_GET['device_id']; }
if (!empty($_GET['status']))      { $where[] = 'j.status = ?';      $params[] = $_GET['status']; }
if (isset($_GET['show_permanent']) && !isset($_GET['show_temporary'])) $where[] = 'j.is_permanent = 1';
elseif (!isset($_GET['show_permanent']) && isset($_GET['show_temporary'])) $where[] = 'j.is_permanent = 0';

$sql = "SELECT j.*, e.full_name AS employee_name, d.name AS device_name, d.inventory_number, d.ip
        FROM journal j
        JOIN employees e ON j.employee_id = e.id
        JOIN devices  d ON j.device_id   = d.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY j.start_date DESC";

$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="journal_export_' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Сотрудник','Ноутбук','Инв. номер','IP','Дата выдачи','Дата возврата','Статус','Постоянно','Комментарий'], ';');
foreach ($rows as $r) {
    fputcsv($out, [
        $r['employee_name'], $r['device_name'], $r['inventory_number'] ?? '',
        $r['ip'] ?? '', $r['start_date'] ?? '', $r['end_date'] ?? '',
        $r['status'], $r['is_permanent'] ? 'Да' : 'Нет', $r['comment'] ?? ''
    ], ';');
}
fclose($out); exit;