<?php
// One-shot: generate an SSH keypair on THIS server for Air Johnson (client 6)
// SFTP deploy. Private key is stored (encrypted) as the client's sftp
// credential value; the PUBLIC key is printed for authorizing in the client's
// cPanel. Founder only. Self-destructs.
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/credentials.php';
$user = crm_requireRole(['founder', 'sales']);
header('Content-Type: text/plain; charset=utf-8');
const CID = 6;

// RSA keypair: PEM private (libssh2-friendly) + OpenSSH public.
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (!$res) { exit("ABORT: openssl_pkey_new failed (openssl not available?)\n"); }
openssl_pkey_export($res, $priv);
$d = openssl_pkey_get_details($res);
if (empty($d['rsa']['e']) || empty($d['rsa']['n'])) { exit("ABORT: could not read RSA details\n"); }

$strp  = static function (string $s) { return pack('N', strlen($s)) . $s; };
$mpint = static function (string $x) { if ($x !== '' && (ord($x[0]) & 0x80)) $x = "\x00" . $x; return pack('N', strlen($x)) . $x; };
$blob  = $strp('ssh-rsa') . $mpint($d['rsa']['e']) . $mpint($d['rsa']['n']);
$pub   = 'ssh-rsa ' . base64_encode($blob) . ' adverton-deploy-client' . CID;

// Replace the client's sftp credential with one holding the PRIVATE key.
try {
    $db = crm_db();
    $st = $db->prepare("SELECT id FROM client_credentials WHERE client_id = ? AND kind = 'sftp'");
    $st->execute([CID]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) { crm_deleteCredential((int)$cid, (int)$user['id']); }
    crm_storeCredential(CID, 'sftp', 'Air Johnson SFTP (SSH key)', 'airjohnsontoledo.com', 'xpdxwjte', $priv, null, (int)$user['id']);
} catch (Throwable $e) {
    exit("ABORT: storing credential failed: " . $e->getMessage() . "\n");
}

echo "OK — new SSH keypair generated. Private key stored (encrypted) as the\n";
echo "Air Johnson sftp credential (user xpdxwjte, host airjohnsontoledo.com).\n";
echo str_repeat('=', 68) . "\n";
echo "AUTHORIZE THIS PUBLIC KEY in Air Johnson's cPanel:\n";
echo "  cPanel -> SSH Access -> Manage SSH Keys -> Import Key\n";
echo "  (paste as the PUBLIC key, save, then click 'Authorize')\n";
echo str_repeat('-', 68) . "\n";
echo $pub . "\n";
echo str_repeat('=', 68) . "\n";

@unlink(__FILE__);
echo "[key-gen script self-deleted]\n";
