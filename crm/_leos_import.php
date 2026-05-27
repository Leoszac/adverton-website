<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/leads.php';

if (($_GET['t'] ?? '') !== 'd7e61ea740d7b15c6fea6e050ef682287dd0014ae4d16d25') {
    http_response_code(403); exit;
}

$leads = [
    ['name'=>'Aaron Gade',          'phone'=>'+14194092514', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Helper - agenda personal Leo'],
    ['name'=>'Andres Rosas',         'phone'=>'+14197877881', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Handyman - agenda personal Leo'],
    ['name'=>'Andrew Wallace',       'business_name'=>'Mr Electric Toledo', 'phone'=>'+14065951220', 'trade'=>'Electrical', 'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
    ['name'=>'Artur',                'phone'=>'+15673969788', 'trade'=>'Plumbing',    'city_state'=>'Toledo, OH', 'notes'=>'Plumber - agenda personal Leo'],
    ['name'=>'Arturo Lopez',         'phone'=>'+14198701856', 'trade'=>'Roofing',     'city_state'=>'Toledo, OH', 'notes'=>'Techo - agenda personal Leo'],
    ['name'=>'Brian',                'phone'=>'+19734595876', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Handyman - agenda personal Leo'],
    ['name'=>'Dad Patchen',          'business_name'=>'Toledo Electrical', 'phone'=>'+14192624144', 'trade'=>'Electrical', 'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
    ['name'=>'Dave Sabino',          'business_name'=>'Cleaning Toledo LBP', 'phone'=>'+14197872139', 'trade'=>'Other', 'city_state'=>'Toledo, OH', 'notes'=>'Cleaning - agenda personal Leo'],
    ['business_name'=>'DCS Cleaning Toledo', 'phone'=>'+14193504970', 'trade'=>'Other', 'city_state'=>'Toledo, OH', 'notes'=>'Cleaning Trabaja Con Kelly - agenda personal Leo'],
    ['name'=>'Dwayne',               'phone'=>'+14194600011', 'trade'=>'Electrical',  'city_state'=>'Toledo, OH', 'notes'=>'Electricista - agenda personal Leo'],
    ['name'=>'Elizabeth Crowley',    'phone'=>'+14199176548', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Limpieza - agenda personal Leo'],
    ['name'=>'Emmanuel',             'phone'=>'+15679706279', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Handyman Hijo Andres - agenda personal Leo'],
    ['name'=>'Francisco Montellano', 'phone'=>'+14193222529', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Todero - agenda personal Leo'],
    ['name'=>'Jessie Rubio',         'phone'=>'+14197999104', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Handyman - agenda personal Leo'],
    ['name'=>'Joel',                 'phone'=>'+16142378284', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Todero - agenda personal Leo'],
    ['name'=>'Jorge',                'phone'=>'+14193892609', 'trade'=>'Plumbing',    'city_state'=>'Toledo, OH', 'notes'=>'Plomero Velez - agenda personal Leo'],
    ['name'=>'Juan Espinosa',        'phone'=>'+14195034520', 'trade'=>'Landscaping', 'city_state'=>'Toledo, OH', 'notes'=>'Lawn - agenda personal Leo'],
    ['name'=>'Justin',               'phone'=>'+14199060979', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Tuckpointing - agenda personal Leo'],
    ['name'=>'Keith',                'phone'=>'+14193779775', 'trade'=>'Plumbing',    'city_state'=>'Toledo, OH', 'notes'=>'Plumber - agenda personal Leo'],
    ['name'=>'Kevin',                'phone'=>'+14193926098', 'trade'=>'Electrical',  'city_state'=>'Toledo, OH', 'notes'=>'Electrician (eric) K2 - agenda personal Leo'],
    ['name'=>'Laziz',                'phone'=>'+19543105051', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Handy - agenda personal Leo'],
    ['name'=>'Nick Landise',         'business_name'=>'Landise Electrical', 'phone'=>'+15673959077', 'trade'=>'Electrical', 'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
    ['business_name'=>'Pest Inspector Exterminator Toledo', 'phone'=>'+14196990155', 'trade'=>'Pest Control', 'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
    ['business_name'=>'Plomero Toledo Plumber', 'phone'=>'+14197797818', 'trade'=>'Plumbing', 'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
    ['name'=>'Ron Goulding',         'phone'=>'+14192838675', 'trade'=>'Landscaping', 'city_state'=>'Toledo, OH', 'notes'=>'Tree Trimmer - agenda personal Leo'],
    ['name'=>'Ryan',                 'phone'=>'+14197798653', 'trade'=>'Electrical',  'city_state'=>'Toledo, OH', 'notes'=>'Licensed Electrician - agenda personal Leo'],
    ['name'=>'Steve Moore',          'phone'=>'+14193099972', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Helper - agenda personal Leo'],
    ['business_name'=>'Toledo Dumpster', 'phone'=>'+14194662388', 'trade'=>'Other',   'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
    ['name'=>'Toni',                 'phone'=>'+14012088111', 'trade'=>'Plumbing',    'city_state'=>'Toledo, OH', 'notes'=>'Plumber Electrician - agenda personal Leo'],
    ['name'=>'Willie',               'phone'=>'+14195141377', 'trade'=>'Electrical',  'city_state'=>'Toledo, OH', 'notes'=>'Electricista - agenda personal Leo'],
    ['name'=>'Aaron Tas',            'business_name'=>'Professional Service Inspector Toledo', 'phone'=>'+14197040366', 'trade'=>'Other', 'city_state'=>'Toledo, OH', 'notes'=>'Inspector - agenda personal Leo'],
    ['name'=>'Andrew Write',         'phone'=>'+14194602123', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Inspeccion - agenda personal Leo'],
    ['name'=>'Anthony Weaver',       'phone'=>'+14192909548', 'trade'=>'Other',       'city_state'=>'Toledo, OH', 'notes'=>'Lead Inspector - agenda personal Leo'],
    ['name'=>'Kelly Jacobs',         'business_name'=>'Sunbeam Home Inspection Toledo', 'phone'=>'+14192708622', 'trade'=>'Other', 'city_state'=>'Toledo, OH', 'notes'=>'Home Inspector - agenda personal Leo'],
    ['business_name'=>'Kinney Home Inspection Toledo', 'phone'=>'+14193457283', 'trade'=>'Other', 'city_state'=>'Toledo, OH', 'notes'=>'agenda personal Leo'],
];

$ok = 0; $skip = 0; $errs = [];
foreach ($leads as $i => $lead) {
    $lead['source'] = 'leos_contacts';
    try {
        $id = crm_insertLead($lead);
        // crm_insertLead returns existing id on dedupe (no exception), so check activity
        $ok++;
    } catch (Throwable $e) {
        $errs[] = "row $i: " . $e->getMessage();
    }
}

unlink(__FILE__);

header('Content-Type: text/plain');
echo "imported=$ok skipped=$skip errors=" . count($errs) . "\n";
foreach ($errs as $e) echo $e . "\n";
