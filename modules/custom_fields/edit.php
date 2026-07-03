<?php
// ── Bootstrap ─────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!isSuperAdmin()) {
    setFlash('danger', 'Access denied. Super Admin role required.');
    header('Location: /modules/dashboard/index.php');
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM custom_fields WHERE id = ?");
$st->execute([$id]);
$field = $st->fetch();
if (!$field) {
    setFlash('danger', 'Custom field not found.');
    header('Location: index.php');
    exit;
}

$errors = [];

// ── Process POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fieldLabel   = trim($_POST['field_label']  ?? '');
    $fieldName    = trim($_POST['field_name']   ?? '');
    $fieldType    = trim($_POST['field_type']   ?? 'text');
    $categoryId   = (int)($_POST['category_id'] ?? 0);
    $isRequired   = isset($_POST['is_required']) ? 1 : 0;
    $sortOrder    = (int)($_POST['sort_order']  ?? 0);
    $fieldOptions = trim($_POST['field_options'] ?? '');
    $isActive     = isset($_POST['is_active'])   ? 1 : 0;

    $validTypes = ['text','number','date','select','textarea','url','email'];

    if ($fieldLabel === '') $errors[] = 'Field label is required.';
    if ($fieldName  === '') $errors[] = 'Field name is required.';
    elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) $errors[] = 'Field name must be lowercase letters, digits, underscores, and start with a letter.';
    if (!in_array($fieldType, $validTypes, true)) $errors[] = 'Invalid field type.';

    // Check uniqueness (excluding self)
    if (empty($errors)) {
        $dup = $pdo->prepare("SELECT id FROM custom_fields WHERE field_name = ? AND id != ?");
        $dup->execute([$fieldName, $id]);
        if ($dup->fetch()) $errors[] = "Field name '{$fieldName}' is already in use.";
    }

    // Build JSON options for select type
    $optionsJson = null;
    if ($fieldType === 'select' && $fieldOptions !== '') {
        $opts = array_values(array_filter(array_map('trim', explode("\n", $fieldOptions))));
        $optionsJson = json_encode($opts);
    }

    if (empty($errors)) {
        $old = $field;
        $st2 = $pdo->prepare("UPDATE custom_fields SET
            category_id=?, field_name=?, field_label=?, field_type=?,
            field_options=?, is_required=?, sort_order=?, is_active=?
            WHERE id=?");
        $st2->execute([
            $categoryId ?: null,
            $fieldName,
            $fieldLabel,
            $fieldType,
            $optionsJson,
            $isRequired,
            $sortOrder,
            $isActive,
            $id,
        ]);
        auditLog($pdo, 'update', 'custom_fields', $id,
            ['field_name' => $old['field_name'], 'field_label' => $old['field_label']],
            ['field_name' => $fieldName,         'field_label' => $fieldLabel]
        );
        setFlash('success', "Custom field <strong>{$fieldLabel}</strong> updated.");
        header('Location: index.php');
        exit;
    }

    // Re-populate $field for form re-display
    $field = array_merge($field, [
        'field_label'  => $fieldLabel,
        'field_name'   => $fieldName,
        'field_type'   => $fieldType,
        'category_id'  => $categoryId ?: null,
        'is_required'  => $isRequired,
        'sort_order'   => $sortOrder,
        'is_active'    => $isActive,
    ]);
}

// Decode options for textarea
$existingOptions = '';
if ($field['field_type'] === 'select' && $field['field_options']) {
    $decoded = json_decode($field['field_options'], true);
    if (is_array($decoded)) {
        $existingOptions = implode("\n", $decoded);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $existingOptions = trim($_POST['field_options'] ?? '');
}

$categories = $pdo->query("SELECT id, name, type FROM categories ORDER BY type, name")->fetchAll();

$pageTitle = 'Edit Custom Field';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
  <h2><i class="bi bi-pencil me-2" style="color:var(--brand-primary)"></i>Edit Custom Field</h2>
  <p class="text-muted mb-0">Modifying: <strong><?= e($field['field_label']) ?></strong></p>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card">
  <div class="card-header"><i class="bi bi-sliders2 me-2"></i>Field Definition</div>
  <div class="card-body">
    <form method="POST" class="row g-3">
      <input type="hidden" name="id" value="<?= $id ?>">

      <div class="col-12">
        <label class="form-label fw-semibold small">Field Label <span class="text-danger">*</span></label>
        <input type="text" name="field_label" id="fieldLabel" class="form-control"
               value="<?= e($field['field_label']) ?>" required>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold small">Field Name <span class="text-danger">*</span></label>
        <input type="text" name="field_name" id="fieldName" class="form-control font-monospace"
               value="<?= e($field['field_name']) ?>" readonly>
        <div class="form-text">Auto-generated from label (snake_case). Changing the label updates this.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold small">Field Type <span class="text-danger">*</span></label>
        <select name="field_type" id="fieldType" class="form-select">
          <?php foreach (['text'=>'Text','number'=>'Number','date'=>'Date','select'=>'Select (Dropdown)','textarea'=>'Textarea','url'=>'URL','email'=>'Email'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $field['field_type'] === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold small">Category <small class="text-muted fw-normal">(optional)</small></label>
        <select name="category_id" class="form-select">
          <option value="">All Categories</option>
          <?php $lastType = ''; foreach ($categories as $cat):
            if ($cat['type'] !== $lastType) { if ($lastType) echo '</optgroup>'; echo '<optgroup label="' . e($cat['type']) . '">'; $lastType = $cat['type']; } ?>
          <option value="<?= $cat['id'] ?>" <?= ($field['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; if ($lastType) echo '</optgroup>'; ?>
        </select>
      </div>

      <div id="optionsWrap" class="col-12" style="display:none">
        <label class="form-label fw-semibold small">Select Options</label>
        <textarea name="field_options" class="form-control font-monospace" rows="4"
                  placeholder="One option per line"><?= e($existingOptions) ?></textarea>
        <div class="form-text">Enter each option on its own line.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold small">Sort Order</label>
        <input type="number" name="sort_order" class="form-control" min="0" max="9999"
               value="<?= (int)$field['sort_order'] ?>">
      </div>

      <div class="col-md-4 d-flex align-items-end pb-1">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_required" id="isRequired"
                 <?= $field['is_required'] ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold small" for="isRequired">Required field</label>
        </div>
      </div>

      <div class="col-md-4 d-flex align-items-end pb-1">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                 <?= $field['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold small" for="isActive">Active</label>
        </div>
      </div>

      <div class="col-12 d-flex gap-2 pt-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>
</div>
</div>

<script>
(function () {
  const labelEl  = document.getElementById('fieldLabel');
  const nameEl   = document.getElementById('fieldName');
  const typeEl   = document.getElementById('fieldType');
  const optsWrap = document.getElementById('optionsWrap');

  labelEl.addEventListener('input', function () {
    nameEl.value = this.value
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
      .substring(0, 64);
  });

  function toggleOptions() {
    optsWrap.style.display = typeEl.value === 'select' ? '' : 'none';
  }
  typeEl.addEventListener('change', toggleOptions);
  toggleOptions();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
