<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

$db = crm_db();

// Step 1: extend the ENUM to include leos_contacts
$db->exec("ALTER TABLE leads MODIFY COLUMN source ENUM(
    'audit_auto','audit_manual','contact_form','inbound_call','manual',
    'ebook_growth_engine','referral','affiliate','csv_import',
    'cold_email_instantly','cold_call','leos_contacts'
) NOT NULL");

// Step 2: update all 35 leads by normalized phone
$phones = [
    '14194092514','14197877881','14065951220','15673969788','14198701856',
    '19734595876','14192624144','14197872139','14193504970','14194600011',
    '14199176548','15679706279','14193222529','14197999104','16142378284',
    '14193892609','14195034520','14199060979','14193779775','14193926098',
    '19543105051','15673959077','14196990155','14197797818','14192838675',
    '14197798653','14193099972','14194662388','14012088111','14195141377',
    '14197040366','14194602123','14192909548','14192708622','14193457283',
];

$ok = 0; $notfound = [];
foreach ($phones as $phone) {
    $stmt = $db->prepare("SELECT id FROM leads WHERE REGEXP_REPLACE(phone, '[^0-9]', '') = ?");
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    if (!$id) { $notfound[] = $phone; continue; }
    $db->prepare("UPDATE leads SET source='leos_contacts' WHERE id=?")->execute([(int)$id]);
    $ok++;
}

unlink(__FILE__);

header('Content-Type: text/plain');
echo "alter=ok updated=$ok\n";
if ($notfound) echo "not_found=" . implode(',', $notfound) . "\n";
