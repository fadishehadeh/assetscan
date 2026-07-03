<?php
/**
 * Custom Fields Helper
 * Include this file wherever custom field support is needed.
 * Requires $pdo (PDO) to be available in the including scope when calling functions.
 */

/**
 * Get all active custom fields applicable to a given category.
 * Returns fields where category_id matches OR category_id IS NULL (applies to all).
 *
 * @param PDO $pdo
 * @param int $categoryId  Pass 0 or negative to get only universal fields (NULL category_id).
 * @return array           Array of custom_fields rows, ordered by sort_order ASC, id ASC.
 */
function getCustomFieldsForAsset(PDO $pdo, int $categoryId): array
{
    if ($categoryId > 0) {
        $st = $pdo->prepare("
            SELECT * FROM custom_fields
            WHERE is_active = 1
              AND (category_id = ? OR category_id IS NULL)
            ORDER BY sort_order ASC, id ASC
        ");
        $st->execute([$categoryId]);
    } else {
        // No specific category — return only universal fields
        $st = $pdo->query("
            SELECT * FROM custom_fields
            WHERE is_active = 1
              AND category_id IS NULL
            ORDER BY sort_order ASC, id ASC
        ");
    }
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Return a map of field_id => value for a given asset.
 *
 * @param PDO $pdo
 * @param int $assetId
 * @return array  [field_id => value, ...]
 */
function getCustomFieldValues(PDO $pdo, int $assetId): array
{
    $st = $pdo->prepare("SELECT field_id, value FROM custom_field_values WHERE asset_id = ?");
    $st->execute([$assetId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['field_id']] = $row['value'];
    }
    return $map;
}

/**
 * Render an HTML form input for a custom field.
 * Input name is cf_{field_id} for easy POST extraction.
 *
 * @param array  $field  Row from custom_fields table.
 * @param string $value  Current/existing value.
 * @return string        HTML string (label + input wrapped in a div).
 */
function renderCustomFieldInput(array $field, string $value = ''): string
{
    $id       = (int)$field['id'];
    $label    = htmlspecialchars($field['field_label'], ENT_QUOTES, 'UTF-8');
    $name     = 'cf_' . $id;
    $htmlId   = 'cf_field_' . $id;
    $required = $field['is_required'] ? ' required' : '';
    $reqStar  = $field['is_required'] ? ' <span class="text-danger">*</span>' : '';
    $val      = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $input = '';

    switch ($field['field_type']) {
        case 'text':
            $input = "<input type=\"text\" id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\"{$required}>";
            break;

        case 'number':
            $input = "<input type=\"number\" step=\"any\" id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\"{$required}>";
            break;

        case 'date':
            $input = "<input type=\"date\" id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\"{$required}>";
            break;

        case 'url':
            $input = "<input type=\"url\" id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\" placeholder=\"https://\"{$required}>";
            break;

        case 'email':
            $input = "<input type=\"email\" id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\"{$required}>";
            break;

        case 'textarea':
            $input = "<textarea id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" rows=\"3\"{$required}>{$val}</textarea>";
            break;

        case 'select':
            $options  = '';
            $rawOpts  = $field['field_options'] ? json_decode($field['field_options'], true) : [];
            if (!is_array($rawOpts)) $rawOpts = [];
            $options .= '<option value="">— Select —</option>';
            foreach ($rawOpts as $opt) {
                $optVal  = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                $sel     = ($value === $opt) ? ' selected' : '';
                $options .= "<option value=\"{$optVal}\"{$sel}>{$optVal}</option>";
            }
            $input = "<select id=\"{$htmlId}\" name=\"{$name}\" class=\"form-select\"{$required}>{$options}</select>";
            break;

        default:
            $input = "<input type=\"text\" id=\"{$htmlId}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\"{$required}>";
    }

    return <<<HTML
<div class="mb-3">
  <label for="{$htmlId}" class="form-label fw-semibold small">{$label}{$reqStar}</label>
  {$input}
</div>
HTML;
}

/**
 * Save (upsert) custom field values for an asset from POST data.
 *
 * @param PDO   $pdo      Active PDO connection.
 * @param int   $assetId  Target asset ID.
 * @param array $postData Typically $_POST.
 * @param array $fields   Array of custom_fields rows (from getCustomFieldsForAsset).
 */
function saveCustomFieldValues(PDO $pdo, int $assetId, array $postData, array $fields): void
{
    $st = $pdo->prepare("
        INSERT INTO custom_field_values (asset_id, field_id, value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");

    foreach ($fields as $field) {
        $fieldId  = (int)$field['id'];
        $postKey  = 'cf_' . $fieldId;
        $value    = isset($postData[$postKey]) ? trim((string)$postData[$postKey]) : '';

        // For required fields with empty value, still save empty string
        // (form-level validation should catch missing required values before calling this)
        $st->execute([$assetId, $fieldId, $value]);
    }
}

/**
 * Validate required custom fields from POST data.
 * Returns array of error strings (empty array = valid).
 *
 * @param array $postData Typically $_POST.
 * @param array $fields   Array of custom_fields rows.
 * @return string[]
 */
function validateCustomFields(array $postData, array $fields): array
{
    $errors = [];
    foreach ($fields as $field) {
        if (!$field['is_required']) continue;
        $postKey = 'cf_' . $field['id'];
        $value   = isset($postData[$postKey]) ? trim((string)$postData[$postKey]) : '';
        if ($value === '') {
            $errors[] = htmlspecialchars($field['field_label'], ENT_QUOTES, 'UTF-8') . ' is required.';
        }
    }
    return $errors;
}
