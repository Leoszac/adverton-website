<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

// phone (digits only) => [first_name, last_name, source]
$data = [
    '14194092514' => ['Aaron',       'Gade',          'Helper - agenda personal Leo'],
    '14197877881' => ['Andres',      'Rosas',         'Handyman - agenda personal Leo'],
    '14065951220' => ['Andrew',      'Wallace',       'Mr Electric Toledo - agenda personal Leo'],
    '15673969788' => ['Artur',       '',              'Plumber - agenda personal Leo'],
    '14198701856' => ['Arturo',      'Lopez',         'Techo/Roofing - agenda personal Leo'],
    '19734595876' => ['Brian',       '',              'Handyman - agenda personal Leo'],
    '14192624144' => ['Dad',         'Patchen',       'Toledo Electrical - agenda personal Leo'],
    '14197872139' => ['Dave',        'Sabino',        'Cleaning Toledo LBP - agenda personal Leo'],
    '14193504970' => ['',            '',              'DCS Cleaning Toledo - agenda personal Leo'],
    '14194600011' => ['Dwayne',      '',              'Electricista - agenda personal Leo'],
    '14199176548' => ['Elizabeth',   'Crowley',       'Limpieza - agenda personal Leo'],
    '15679706279' => ['Emmanuel',    '',              'Handyman Hijo Andres - agenda personal Leo'],
    '14193222529' => ['Francisco',   'Montellano',    'Todero - agenda personal Leo'],
    '14197999104' => ['Jessie',      'Rubio',         'Handyman - agenda personal Leo'],
    '16142378284' => ['Joel',        '',              'Todero - agenda personal Leo'],
    '14193892609' => ['Jorge',       '',              'Plomero Velez - agenda personal Leo'],
    '14195034520' => ['Juan',        'Espinosa',      'Lawn - agenda personal Leo'],
    '14199060979' => ['Justin',      '',              'Tuckpointing - agenda personal Leo'],
    '14193779775' => ['Keith',       '',              'Plumber - agenda personal Leo'],
    '14193926098' => ['Kevin',       '',              'Electrician (eric) K2 - agenda personal Leo'],
    '19543105051' => ['Laziz',       '',              'Handy - agenda personal Leo'],
    '15673959077' => ['Nick',        'Landise',       'Landise Electrical - agenda personal Leo'],
    '14196990155' => ['',            '',              'Pest Inspector Exterminator Toledo - agenda personal Leo'],
    '14197797818' => ['',            '',              'Plomero Toledo Plumber - agenda personal Leo'],
    '14192838675' => ['Ron',         'Goulding',      'Tree Trimmer - agenda personal Leo'],
    '14197798653' => ['Ryan',        '',              'Licensed Electrician - agenda personal Leo'],
    '14193099972' => ['Steve',       'Moore',         'Helper - agenda personal Leo'],
    '14194662388' => ['',            '',              'Toledo Dumpster - agenda personal Leo'],
    '14012088111' => ['Toni',        '',              'Plumber Electrician - agenda personal Leo'],
    '14195141377' => ['Willie',      '',              'Electricista - agenda personal Leo'],
    '14197040366' => ['Aaron',       'Tas',           'Inspector Toledo - agenda personal Leo'],
    '14194602123' => ['Andrew',      'Write',         'Inspeccion Toledo - agenda personal Leo'],
    '14192909548' => ['Anthony',     'Weaver',        'Lead Inspector Toledo - agenda personal Leo'],
    '14192708622' => ['Kelly',       'Jacobs',        'Sunbeam Home Inspection - agenda personal Leo'],
    '14193457283' => ['',            '',              'Kinney Home Inspection Toledo - agenda personal Leo'],
];

$db = crm_db();
$ok = 0; $notfound = [];

foreach ($data as $phone => $row) {
    [$first, $last, $notes] = $row;
    $stmt = $db->prepare('SELECT id FROM leads WHERE REGEXP_REPLACE(phone, "[^0-9]", "") = ?');
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $notfound[] = $phone;
        continue;
    }
    $db->prepare('UPDATE leads SET first_name=?, last_name=?, source=?, notes=COALESCE(NULLIF(?,\'\'), notes) WHERE id=?')
       ->execute([$first ?: null, $last ?: null, 'leos_contacts', $notes, (int)$id]);
    $ok++;
}

unlink(__FILE__);

header('Content-Type: text/plain');
echo "updated=$ok\n";
if ($notfound) echo "not_found=" . implode(',', $notfound) . "\n";
