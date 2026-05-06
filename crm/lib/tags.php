<?php
// Tags — normalized table with a many-to-many join. Auto-creates on first use.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_TAG_PALETTE = [
    '#6d28d9', '#dc2626', '#16a34a', '#f59e0b',
    '#2563eb', '#0891b2', '#db2777', '#475569',
];

function crm_normalizeTagName(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s);
    return mb_strtolower(mb_substr($s, 0, 60));
}

// Get-or-create a tag by name. Color is randomly picked from the palette.
function crm_upsertTag(string $name): ?int {
    $norm = crm_normalizeTagName($name);
    if ($norm === '') return null;
    try {
        $stmt = crm_db()->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$norm]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];

        $color = CRM_TAG_PALETTE[crc32($norm) % count(CRM_TAG_PALETTE)];
        $stmt = crm_db()->prepare('INSERT INTO tags (name, color) VALUES (?, ?)');
        $stmt->execute([$norm, $color]);
        return (int) crm_db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('[crm_upsertTag] ' . $e->getMessage());
        return null;
    }
}

function crm_listAllTags(): array {
    $stmt = crm_db()->query(
        'SELECT t.id, t.name, t.color, COUNT(lt.lead_id) AS lead_count
         FROM tags t
         LEFT JOIN lead_tags lt ON lt.tag_id = t.id
         GROUP BY t.id ORDER BY lead_count DESC, t.name ASC'
    );
    return $stmt->fetchAll();
}

function crm_listTagsForLead(int $leadId): array {
    $stmt = crm_db()->prepare(
        'SELECT t.id, t.name, t.color
         FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id
         WHERE lt.lead_id = ?
         ORDER BY t.name ASC'
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll();
}

function crm_setLeadTags(int $leadId, array $tagNames): void {
    try {
        $db = crm_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM lead_tags WHERE lead_id = ?')->execute([$leadId]);
        $ins = $db->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)');
        foreach ($tagNames as $name) {
            $tagId = crm_upsertTag((string)$name);
            if ($tagId) $ins->execute([$leadId, $tagId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        try { crm_db()->rollBack(); } catch (Throwable $e2) {}
        error_log('[crm_setLeadTags] ' . $e->getMessage());
    }
}

function crm_addTagToLead(int $leadId, string $tagName): ?int {
    $tagId = crm_upsertTag($tagName);
    if (!$tagId) return null;
    try {
        $stmt = crm_db()->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)');
        $stmt->execute([$leadId, $tagId]);
        return $tagId;
    } catch (Throwable $e) {
        return null;
    }
}

function crm_removeTagFromLead(int $leadId, int $tagId): bool {
    try {
        $stmt = crm_db()->prepare('DELETE FROM lead_tags WHERE lead_id = ? AND tag_id = ?');
        return $stmt->execute([$leadId, $tagId]);
    } catch (Throwable $e) { return false; }
}

// Batch-attach tags to a list of lead rows. Mutates each row to add ['tags'] => [...].
function crm_attachTagsToLeads(array &$rows): void {
    if (!$rows) return;
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = crm_db()->prepare(
            "SELECT lt.lead_id, t.id, t.name, t.color
             FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id
             WHERE lt.lead_id IN ({$placeholders})
             ORDER BY t.name"
        );
        $stmt->execute($ids);
        $byLead = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLead[(int)$row['lead_id']][] = [
                'id'    => (int)$row['id'],
                'name'  => $row['name'],
                'color' => $row['color'],
            ];
        }
        foreach ($rows as &$r) {
            $r['tags'] = $byLead[(int)$r['id']] ?? [];
        }
    } catch (Throwable $e) {
        foreach ($rows as &$r) $r['tags'] = [];
    }
}

// Resolve a tag name → id (for filtering). No insert.
function crm_findTagId(string $name): ?int {
    $norm = crm_normalizeTagName($name);
    if ($norm === '') return null;
    try {
        $stmt = crm_db()->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$norm]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) { return null; }
}
