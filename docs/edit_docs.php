<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';
require_once '../includes/top_navbar.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM documentation WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) die("Раздел не найден.");

function getSectionTree($pdo, $exclude_id = null) {
    $sql = "SELECT id, title, parent_id FROM documentation WHERE type = 'section'";
    if ($exclude_id) $sql .= " AND id != $exclude_id";
    $sql .= " ORDER BY title";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function renderSectionOptions($sections, $parent_id = null, $level = 0, $selected = null) {
    $html = '';
    foreach ($sections as $s) {
        if ($s['parent_id'] == $parent_id) {
            $sel = $selected == $s['id'] ? 'selected' : '';
            $html .= '<option value="' . $s['id'] . '" ' . $sel . '>' . str_repeat('— ', $level) . htmlspecialchars($s['title']) . '</option>';
            $html .= renderSectionOptions($sections, $s['id'], $level + 1, $selected);
        }
    }
    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update'])) {
        $title     = trim($_POST['title'] ?? '');
        $content   = $_POST['content'] ?? '';
        $type      = $_POST['type'] ?? 'content';
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        if ($title !== '') {
            $pdo->prepare("UPDATE documentation SET title=?, content=?, type=?, parent_id=? WHERE id=?")
                ->execute([$title, $content, $type, $parent_id, $id]);
            header("Location: index.php?id=$id"); exit;
        } else {
            $error = "Название не может быть пустым.";
        }
    }
    if (isset($_POST['delete'])) {
        function deleteNode($pdo, $id) {
            $children = $pdo->prepare("SELECT id FROM documentation WHERE parent_id=?");
            $children->execute([$id]);
            foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $child) deleteNode($pdo, $child);
            $pdo->prepare("DELETE FROM documentation WHERE id=?")->execute([$id]);
        }
        deleteNode($pdo, $id);
        header("Location: index.php"); exit;
    }
}

$sections = getSectionTree($pdo, $id);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Редактировать — <?= htmlspecialchars($doc['title']) ?></title>
    <link href="/adminis/includes/style.css" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        #editor { height: 400px; background: #fff; }
        .ql-toolbar.ql-snow { border-radius: 6px 6px 0 0; border-color: #e5e7ef; }
        .ql-container.ql-snow { border-radius: 0 0 6px 6px; border-color: #e5e7ef; }
    </style>
</head>
<body>
<div class="content-wrapper">
    <div class="content-container">

        <form method="post" id="main-form" onsubmit="submitForm()">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="mb-0" style="text-align:left"><?= htmlspecialchars($doc['title']) ?></h1>
                <div class="d-flex gap-2">
                    <button type="submit" name="update" class="btn btn-outline-success">💾 Сохранить</button>
                    <button type="submit" name="delete" class="btn btn-outline-danger"
                            onclick="return confirm('Удалить этот элемент и всё вложенное?')">🗑️ Удалить</button>
                    <a href="index.php?id=<?= $id ?>" class="btn btn-outline-danger">🚫 Отмена</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">Тип</label>
                    <select name="type" id="type-select" class="form-select">
                        <option value="section" <?= $doc['type']==='section' ? 'selected' : '' ?>>📁 Раздел</option>
                        <option value="content" <?= $doc['type']==='content' ? 'selected' : '' ?>>📄 Документ</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Название</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($doc['title']) ?>" required>
                </div>
            </div>

            <div class="mb-3" style="margin-top:16px">
                <label class="form-label">Родительский раздел</label>
                <select name="parent_id" class="form-select">
                    <option value="">— Корневой уровень —</option>
                    <?= renderSectionOptions($sections, null, 0, $doc['parent_id']) ?>
                </select>
            </div>

            <div class="mb-3" id="content-block">
                <label class="form-label">Содержимое</label>
                <div id="editor"></div>
                <input type="hidden" name="content" id="hiddenContent">
            </div>
        </form>

    </div>
</div>

<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
const quill = new Quill('#editor', { theme: 'snow' });

// Load existing content properly
const existingContent = <?= json_encode($doc['content'] ?? '') ?>;
if (existingContent) {
    quill.clipboard.dangerouslyPasteHTML(existingContent);
}

function submitForm() {
    document.getElementById('hiddenContent').value = quill.root.innerHTML;
}

function updateView() {
    const type = document.getElementById('type-select').value;
    document.getElementById('content-block').style.display = type === 'content' ? 'block' : 'none';
}

document.getElementById('type-select').addEventListener('change', updateView);
updateView();
</script>
</body>
</html>