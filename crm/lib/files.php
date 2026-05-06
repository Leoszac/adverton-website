<?php
// File attachments per lead. Stored OUTSIDE public_html so they're never
// directly servable — downloads go through file.php which checks login.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_FILE_ROOT          = '/home2/advertonnet/crm-files';
const CRM_FILE_MAX_BYTES     = 25 * 1024 * 1024; // 25 MB per file
const CRM_FILE_ALLOWED_MIMES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip',
    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic',
    'text/plain', 'text/csv',
];
const CRM_FILE_EXT_FROM_MIME = [
    'application/pdf'  => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/zip' => 'zip',
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'image/gif' => 'gif', 'image/heic' => 'heic',
    'text/plain' => 'txt', 'text/csv' => 'csv',
];

function crm_filesDir(int $leadId): string {
    return CRM_FILE_ROOT . '/' . $leadId;
}

function crm_storeUploadedFile(int $leadId, array $upload, ?int $userId): array {
    if (!isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload error: ' . ($upload['error'] ?? '?')];
    }
    if ((int)$upload['size'] > CRM_FILE_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File too big (max 25 MB)'];
    }
    $tmp = $upload['tmp_name'];

    // Re-detect MIME server-side instead of trusting the browser.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmp) : ($upload['type'] ?? 'application/octet-stream');
    if ($finfo) finfo_close($finfo);

    if (!in_array($mime, CRM_FILE_ALLOWED_MIMES, true)) {
        return ['ok' => false, 'error' => 'File type not allowed (' . $mime . ')'];
    }

    $ext = CRM_FILE_EXT_FROM_MIME[$mime] ?? 'bin';
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;

    $dir = crm_filesDir($leadId);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create storage dir'];
        }
    }
    $dest = $dir . '/' . $stored;
    if (!@move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not move file'];
    }
    @chmod($dest, 0600);

    $original = mb_substr(preg_replace('/[\x00-\x1f]/', '', (string)$upload['name']), 0, 255);

    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO lead_files (lead_id, uploaded_by, original_name, stored_name, mime, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$leadId, $userId, $original, $stored, $mime, (int)$upload['size']]);
        $id = (int) crm_db()->lastInsertId();
        return ['ok' => true, 'id' => $id, 'name' => $original];
    } catch (Throwable $e) {
        @unlink($dest);
        return ['ok' => false, 'error' => 'DB error: ' . $e->getMessage()];
    }
}

function crm_listFiles(int $leadId): array {
    try {
        $stmt = crm_db()->prepare(
            'SELECT f.*, u.display_name AS uploader
             FROM lead_files f
             LEFT JOIN users u ON u.id = f.uploaded_by
             WHERE f.lead_id = ?
             ORDER BY f.uploaded_at DESC'
        );
        $stmt->execute([$leadId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) { return []; }
}

function crm_getFile(int $fileId): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM lead_files WHERE id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function crm_deleteFile(int $fileId): bool {
    $f = crm_getFile($fileId);
    if (!$f) return false;
    $path = crm_filesDir((int)$f['lead_id']) . '/' . $f['stored_name'];
    @unlink($path);
    try {
        $stmt = crm_db()->prepare('DELETE FROM lead_files WHERE id = ?');
        return $stmt->execute([$fileId]);
    } catch (Throwable $e) { return false; }
}

function crm_fmtFileSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024*1024) return number_format($bytes/1024, 1) . ' KB';
    return number_format($bytes/1048576, 1) . ' MB';
}
