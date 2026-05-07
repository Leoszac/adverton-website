<?php
// Client photo storage + AI-Vision classification.
//
// Storage layout (outside public_html — never directly servable):
//   /home2/advertonnet/crm-files/clients/{client_id}/photos/inbox/      ← unclassified
//   /home2/advertonnet/crm-files/clients/{client_id}/photos/job/        ← classified
//   …team / vehicle / equipment / before_after / logo / interior / exterior / other
//
// Photos arrive via:
//   1. /crm/client.php upload form         (operator manual upload)
//   2. crm/email-pipe.php                  (client emails assets@adverton.net)
//
// Both paths funnel into crm_storeClientPhoto(). Classification runs
// asynchronously via crm/cron-photo-classify.php (Anthropic Vision).

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

const CRM_PHOTO_ROOT      = '/home2/advertonnet/crm-files/clients';
const CRM_PHOTO_MAX_BYTES = 10 * 1024 * 1024;     // 10 MB per image
const CRM_PHOTO_MIMES     = ['image/jpeg','image/png','image/webp','image/heic','image/gif'];
const CRM_PHOTO_EXT_OF    = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/gif'  => 'gif',
];
const CRM_PHOTO_CATEGORIES = [
    'job','team','vehicle','equipment','before_after',
    'logo','interior','exterior','other',
];

function crm_photoDir(int $clientId, string $category = 'inbox'): string {
    return CRM_PHOTO_ROOT . '/' . $clientId . '/photos/' . $category;
}

// Common ingest path used by both manual upload and email pipe.
//   $sourceTmpPath: a readable file path on disk (move_uploaded_file or
//                   email-pipe scratch file).
//   $source:        'manual_upload' | 'email_inbound'
// Returns ['ok'=>bool, 'id'=>int|null, 'error'=>string|null].
function crm_storeClientPhoto(int $clientId, string $sourceTmpPath, string $originalName,
                              ?string $declaredMime, string $source): array {
    if (!is_readable($sourceTmpPath)) {
        return ['ok' => false, 'id' => null, 'error' => 'Source file not readable'];
    }
    $size = (int) @filesize($sourceTmpPath);
    if ($size <= 0)                       return ['ok' => false, 'id' => null, 'error' => 'Empty file'];
    if ($size > CRM_PHOTO_MAX_BYTES)      return ['ok' => false, 'id' => null, 'error' => 'File too big (max 10 MB)'];

    // Re-detect MIME server-side; never trust the client.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? (finfo_file($finfo, $sourceTmpPath) ?: $declaredMime) : ($declaredMime ?: 'application/octet-stream');
    if ($finfo) finfo_close($finfo);
    if (!in_array($mime, CRM_PHOTO_MIMES, true)) {
        return ['ok' => false, 'id' => null, 'error' => 'Unsupported image type (' . $mime . ')'];
    }

    $ext    = CRM_PHOTO_EXT_OF[$mime] ?? 'bin';
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;

    $dir = crm_photoDir($clientId, 'inbox');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'id' => null, 'error' => 'Could not create storage dir'];
    }
    $dest = $dir . '/' . $stored;

    // For manual upload we get a tmp_name from move_uploaded_file; for the
    // email pipe we already wrote the attachment to a scratch file. rename()
    // works in both cases (move_uploaded_file would only work for the first).
    if (!@rename($sourceTmpPath, $dest)) {
        if (!@copy($sourceTmpPath, $dest)) {
            return ['ok' => false, 'id' => null, 'error' => 'Could not write file'];
        }
        @unlink($sourceTmpPath);
    }
    @chmod($dest, 0600);

    $exif = crm_extractExif($dest);
    $original = mb_substr(preg_replace('/[\x00-\x1f]/', '', $originalName), 0, 255);

    try {
        $stmt = crm_db()->prepare(
            'INSERT INTO client_assets
                (client_id, source, category, original_name, stored_name,
                 mime, size_bytes, exif_json)
             VALUES (?, ?, "other", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $clientId, $source, $original, $stored, $mime, $size,
            $exif ? json_encode($exif) : null,
        ]);
        return ['ok' => true, 'id' => (int) crm_db()->lastInsertId(), 'error' => null];
    } catch (Throwable $e) {
        @unlink($dest);
        return ['ok' => false, 'id' => null, 'error' => 'DB error: ' . $e->getMessage()];
    }
}

// Pull a small subset of EXIF tags. Wrapped in try/catch because exif data
// from random phones is notoriously malformed and the PHP extension throws.
function crm_extractExif(string $path): ?array {
    if (!function_exists('exif_read_data')) return null;
    try {
        $raw = @exif_read_data($path, 'IFD0,EXIF,GPS', false, false);
        if (!is_array($raw)) return null;
        $out = [];
        foreach (['DateTimeOriginal','Make','Model','Orientation','GPSLatitude','GPSLongitude'] as $k) {
            if (isset($raw[$k])) $out[$k] = is_array($raw[$k]) ? $raw[$k] : (string)$raw[$k];
        }
        return $out ?: null;
    } catch (Throwable $e) {
        error_log('[crm_extractExif] ' . $e->getMessage());
        return null;
    }
}

// Look up an asset row + resolve its on-disk path.
function crm_getAsset(int $assetId): ?array {
    try {
        $stmt = crm_db()->prepare('SELECT * FROM client_assets WHERE id = ?');
        $stmt->execute([$assetId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['disk_path'] = crm_photoDir((int)$row['client_id'], (string)$row['category']) . '/' . $row['stored_name'];
        if (!is_readable($row['disk_path'])) {
            // Fall back to inbox/ for assets that haven't been moved yet.
            $row['disk_path'] = crm_photoDir((int)$row['client_id'], 'inbox') . '/' . $row['stored_name'];
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

// Filter assets for a client. $category null means all.
function crm_listAssetsForClient(int $clientId, ?string $category = null,
                                 bool $approvedOnly = false, int $limit = 200): array {
    $where  = ['client_id = ?'];
    $params = [$clientId];
    if ($category) {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    if ($approvedOnly) $where[] = 'approved = TRUE';
    $sql = 'SELECT id, client_id, source, category, original_name, stored_name,
                   mime, size_bytes, exif_json, ai_description, ai_tags_json,
                   ai_confidence, approved, uploaded_at
            FROM client_assets WHERE ' . implode(' AND ', $where)
         . ' ORDER BY uploaded_at DESC LIMIT ' . max(1, min(500, $limit));
    try {
        $stmt = crm_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// Move a photo to a different category — both updates the DB AND moves the
// file on disk so storage stays consistent.
function crm_recategorizeAsset(int $assetId, string $newCategory): bool {
    if (!in_array($newCategory, CRM_PHOTO_CATEGORIES, true)) return false;
    $a = crm_getAsset($assetId);
    if (!$a) return false;
    if ($a['category'] === $newCategory && is_readable($a['disk_path'])) return true;

    $newDir  = crm_photoDir((int)$a['client_id'], $newCategory);
    if (!is_dir($newDir) && !@mkdir($newDir, 0700, true) && !is_dir($newDir)) return false;
    $newPath = $newDir . '/' . $a['stored_name'];
    if (is_readable($a['disk_path'])) {
        if (!@rename($a['disk_path'], $newPath)) {
            error_log('[crm_recategorizeAsset rename] ' . $a['disk_path'] . ' → ' . $newPath);
        }
    }
    try {
        $stmt = crm_db()->prepare('UPDATE client_assets SET category = ? WHERE id = ?');
        return $stmt->execute([$newCategory, $assetId]);
    } catch (Throwable $e) { return false; }
}

function crm_approveAsset(int $assetId, bool $approved = true): bool {
    try {
        $stmt = crm_db()->prepare('UPDATE client_assets SET approved = ? WHERE id = ?');
        return $stmt->execute([$approved ? 1 : 0, $assetId]);
    } catch (Throwable $e) { return false; }
}

function crm_deleteAsset(int $assetId): bool {
    $a = crm_getAsset($assetId);
    if (!$a) return false;
    if (is_readable($a['disk_path'])) @unlink($a['disk_path']);
    try {
        $stmt = crm_db()->prepare('DELETE FROM client_assets WHERE id = ?');
        return $stmt->execute([$assetId]);
    } catch (Throwable $e) { return false; }
}

// ─── AI Vision classification ──────────────────────────────────────────
//
// Asks Claude to look at the image and return a JSON object matching the
// client_assets columns. On success: persists category/description/tags/
// confidence and (if classification put it in a new category) moves the
// file accordingly. Returns ['ok'=>bool, 'error'=>?string].

const CRM_VISION_MODEL = 'claude-sonnet-4-6';

function crm_classifyAssetWithAI(int $assetId): array {
    $apiKey = crm_config('ANTHROPIC_API_KEY');
    if (!$apiKey) return ['ok' => false, 'error' => 'ANTHROPIC_API_KEY not set'];
    $a = crm_getAsset($assetId);
    if (!$a) return ['ok' => false, 'error' => 'Asset not found'];
    if (!is_readable($a['disk_path'])) return ['ok' => false, 'error' => 'File not readable: ' . $a['disk_path']];

    $bytes = file_get_contents($a['disk_path']);
    if ($bytes === false) return ['ok' => false, 'error' => 'Could not read file'];
    $b64 = base64_encode($bytes);

    $payload = [
        'model'      => CRM_VISION_MODEL,
        'max_tokens' => 512,
        'system'     => crm_visionSystemPrompt(),
        'messages'   => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => [
                    'type'       => 'base64',
                    'media_type' => $a['mime'],
                    'data'       => $b64,
                ]],
                ['type' => 'text', 'text' => 'Classify this photo for a home-service contractor portfolio. Return ONLY the JSON object — no fence, no commentary.'],
            ],
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'error' => "Anthropic Vision HTTP {$code}: " . substr((string)($resp ?: $err), 0, 300)];
    }
    $data = json_decode((string)$resp, true) ?: [];
    $textOut = '';
    foreach ((array)($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $textOut .= (string)($block['text'] ?? '');
    }
    $j = crm_visionExtractJson($textOut);
    if (!$j) return ['ok' => false, 'error' => 'AI Vision returned non-JSON: ' . substr($textOut, 0, 200)];

    $cat   = in_array(($j['category'] ?? ''), CRM_PHOTO_CATEGORIES, true) ? $j['category'] : 'other';
    $desc  = mb_substr(trim((string)($j['description'] ?? '')), 0, 500);
    $tags  = is_array($j['tags'] ?? null) ? array_slice($j['tags'], 0, 8) : [];
    $conf  = (float)($j['confidence'] ?? 0.5);

    try {
        $stmt = crm_db()->prepare(
            'UPDATE client_assets
             SET ai_description = ?, ai_tags_json = ?, ai_confidence = ?
             WHERE id = ?'
        );
        $stmt->execute([$desc, json_encode($tags), $conf, $assetId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'DB persist failed: ' . $e->getMessage()];
    }

    // Move into the right category folder if AI suggested something other
    // than what we have. If recategorize fails, the description still saved.
    if ($cat !== $a['category']) {
        crm_recategorizeAsset($assetId, $cat);
    }
    return ['ok' => true, 'error' => null];
}

function crm_visionSystemPrompt(): string {
    return <<<SYS
You classify photos for a U.S. home-service contractor's website
portfolio. Output STRICT JSON, no markdown, no commentary:

{
  "category":   "job|team|vehicle|equipment|before_after|logo|interior|exterior|other",
  "description":"string  // 6–18 words, plain English, what the photo shows",
  "tags":       ["string", …  // 3–6 short keywords"],
  "confidence": 0.0-1.0
}

Category guide:
- job: completed installation/repair, equipment in working environment
- team: people in uniform, group photos, headshots
- vehicle: trucks, vans, branded vehicles
- equipment: tools, parts, gear (no install context)
- before_after: split or paired photos showing the change
- logo: company logo on white/clean background
- interior: indoor space (kitchen, bathroom) without job context
- exterior: outdoor/curb appeal without job context
- other: anything that doesn't fit above
SYS;
}

function crm_visionExtractJson(string $text): ?array {
    $text = trim($text);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) $text = $m[1];
    elseif (preg_match('/\{.*\}/s', $text, $m)) $text = $m[0];
    $j = json_decode($text, true);
    return is_array($j) ? $j : null;
}
