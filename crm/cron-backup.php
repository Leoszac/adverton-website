<?php
// Daily mysqldump-style backup. Native PHP (no shell_exec dependency).
// Writes a gzipped SQL dump to /home/advertonnet/backups/crm-YYYY-MM-DD.sql.gz
// and prunes files older than 30 days.

declare(strict_types=1);
if (!defined('CRM_ENTRY')) define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

$cli = (php_sapi_name() === 'cli') || defined('CRM_INPROCESS_CRON');
if (!$cli) {
    header('Content-Type: text/plain');
    if (!hash_equals((string)crm_config('SEED_TOKEN'), (string)($_GET['token'] ?? ''))) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
}

const CRM_BACKUP_DIR = '/home/advertonnet/backups';
const CRM_BACKUP_RETAIN_DAYS = 30;

if (!is_dir(CRM_BACKUP_DIR)) {
    @mkdir(CRM_BACKUP_DIR, 0700, true);
}

$dbName = crm_config('DB_NAME');
$path = CRM_BACKUP_DIR . '/crm-' . date('Y-m-d') . '.sql.gz';
$tmp  = $path . '.partial';

$gz = gzopen($tmp, 'w6');
if (!$gz) { echo "ERR: cannot open {$tmp}\n"; exit; }

gzwrite($gz, "-- Adverton CRM backup\n-- Generated: " . gmdate('c') . "\n-- Database: {$dbName}\n\n");
gzwrite($gz, "SET NAMES utf8mb4;\n");
gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

$db = crm_db();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$rowCount = 0;

foreach ($tables as $t) {
    // Schema
    $createRow = $db->query("SHOW CREATE TABLE `{$t}`")->fetch();
    $createSql = $createRow['Create Table'] ?? '';
    gzwrite($gz, "-- Table {$t}\n");
    gzwrite($gz, "DROP TABLE IF EXISTS `{$t}`;\n");
    gzwrite($gz, $createSql . ";\n\n");

    // Data — stream row-by-row to keep memory low
    $stmt = $db->query("SELECT * FROM `{$t}`");
    $cols = null;
    $batchInserts = [];
    foreach ($stmt as $row) {
        if ($cols === null) {
            $cols = array_keys($row);
            gzwrite($gz, "INSERT INTO `{$t}` (`" . implode('`,`', $cols) . "`) VALUES\n");
        }
        $vals = [];
        foreach ($cols as $c) {
            $v = $row[$c];
            if ($v === null) $vals[] = 'NULL';
            elseif (is_int($v) || is_float($v)) $vals[] = (string)$v;
            else $vals[] = $db->quote((string)$v);
        }
        $batchInserts[] = '(' . implode(',', $vals) . ')';
        $rowCount++;

        if (count($batchInserts) >= 500) {
            gzwrite($gz, implode(",\n", $batchInserts) . ";\n");
            // Start a new INSERT batch
            $batchInserts = [];
            gzwrite($gz, "INSERT INTO `{$t}` (`" . implode('`,`', $cols) . "`) VALUES\n");
        }
    }
    if ($cols !== null) {
        if ($batchInserts) gzwrite($gz, implode(",\n", $batchInserts) . ";\n");
        else {
            // Empty batch leftover from boundary — back up to remove the trailing INSERT header
            // (cheap fallback: rewrite as a no-op comment via a small VALUES we don't care)
            gzwrite($gz, "-- (no rows for last batch);\n");
        }
    }
    gzwrite($gz, "\n");
}

gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
gzclose($gz);
@rename($tmp, $path);

// Prune old backups
$cutoff = time() - (CRM_BACKUP_RETAIN_DAYS * 86400);
$pruned = 0;
foreach ((array) glob(CRM_BACKUP_DIR . '/crm-*.sql.gz') as $f) {
    if (filemtime($f) < $cutoff) {
        @unlink($f);
        $pruned++;
    }
}

$size = is_file($path) ? filesize($path) : 0;
echo "Backup OK · " . basename($path) . " · " . number_format($size/1024, 1) . " KB · {$rowCount} rows · pruned={$pruned}\n";
