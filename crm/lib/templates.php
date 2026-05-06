<?php
// Email templates with variable substitution.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

// Variables available in subject/body. Keep in sync with crm_renderTemplate().
const CRM_TEMPLATE_VARS = [
    'first_name', 'last_name', 'business_name', 'trade',
    'city_state', 'audit_score', 'website',
];

function crm_listTemplates(): array {
    try {
        return crm_db()->query('SELECT id, name, subject, body, updated_at FROM email_templates ORDER BY name ASC')->fetchAll();
    } catch (Throwable $e) { return []; }
}

function crm_getTemplate(int $id): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM email_templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function crm_saveTemplate(int $id, array $data, ?int $userId): int {
    $name    = mb_substr(trim((string)($data['name']    ?? '')), 0, 120);
    $subject = mb_substr(trim((string)($data['subject'] ?? '')), 0, 255);
    $body    = (string)($data['body'] ?? '');
    if ($name === '' || $subject === '') return 0;

    if ($id > 0) {
        $stmt = crm_db()->prepare('UPDATE email_templates SET name=?, subject=?, body=? WHERE id=?');
        $stmt->execute([$name, $subject, $body, $id]);
        return $id;
    }
    $stmt = crm_db()->prepare('INSERT INTO email_templates (name, subject, body, created_by) VALUES (?,?,?,?)');
    $stmt->execute([$name, $subject, $body, $userId]);
    return (int) crm_db()->lastInsertId();
}

function crm_deleteTemplate(int $id): bool {
    try {
        $stmt = crm_db()->prepare('DELETE FROM email_templates WHERE id = ?');
        return $stmt->execute([$id]);
    } catch (Throwable $e) { return false; }
}

// Substitute {first_name}, etc. into the template using lead row data.
// Falls back to "" for missing values rather than leaving the placeholder visible.
function crm_renderTemplate(string $text, array $lead): string {
    $repl = [];
    foreach (CRM_TEMPLATE_VARS as $v) {
        $val = $lead[$v] ?? '';
        if ($val === null) $val = '';
        $repl['{' . $v . '}'] = (string)$val;
    }
    return strtr($text, $repl);
}
