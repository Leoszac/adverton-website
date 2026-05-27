<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

// phone (digits only) => new trade
$updates = [
    // Handyman
    '14194092514' => 'Handyman',  // Aaron Gade - Helper
    '14197877881' => 'Handyman',  // Andres Rosas
    '19734595876' => 'Handyman',  // Brian
    '15679706279' => 'Handyman',  // Emmanuel
    '14193222529' => 'Handyman',  // Francisco Montellano - Todero
    '14197999104' => 'Handyman',  // Jessie Rubio
    '16142378284' => 'Handyman',  // Joel - Todero
    '14199060979' => 'Handyman',  // Justin - Tuckpointing
    '19543105051' => 'Handyman',  // Laziz
    '14193099972' => 'Handyman',  // Steve Moore - Helper
    // Home Cleaning
    '14197872139' => 'Home Cleaning',  // Dave Sabino
    '14193504970' => 'Home Cleaning',  // DCS Cleaning
    '14199176548' => 'Home Cleaning',  // Elizabeth Crowley - Limpieza
    // Home Inspector
    '14197040366' => 'Home Inspector',  // Aaron Tas
    '14194602123' => 'Home Inspector',  // Andrew Write
    '14192909548' => 'Home Inspector',  // Anthony Weaver
    '14192708622' => 'Home Inspector',  // Kelly Jacobs - Sunbeam
    '14193457283' => 'Home Inspector',  // Kinney Home Inspection
];

$db = crm_db();
$ok = 0; $notfound = [];

foreach ($updates as $phone => $trade) {
    $stmt = $db->prepare("SELECT id FROM leads WHERE REGEXP_REPLACE(phone, '[^0-9]', '') = ?");
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    if (!$id) { $notfound[] = $phone; continue; }
    $db->prepare("UPDATE leads SET trade=? WHERE id=?")->execute([$trade, (int)$id]);
    $ok++;
}

unlink(__FILE__);

header('Content-Type: text/plain');
echo "updated=$ok\n";
if ($notfound) echo "not_found=" . implode(',', $notfound) . "\n";
