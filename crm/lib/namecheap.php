<?php
// Namecheap Domain API wrapper — buy domains on behalf of clients when
// they don't bring their own.
//
// API docs: https://www.namecheap.com/support/api/intro/
// Sandbox:  https://api.sandbox.namecheap.com/xml.response  (URL is sandbox.*)
// Live:     https://api.namecheap.com/xml.response
//
// Auth: ApiUser, ApiKey, UserName (same value as ApiUser for our case),
// ClientIP (whitelist on Namecheap side — must include adverton.net's
// outbound IP).
//
// Conf in crm-config.php:
//   NAMECHEAP_API_USER  → user name (also used as ClientIp's "username")
//   NAMECHEAP_API_KEY   → api key
//   NAMECHEAP_CLIENT_IP → outbound IP whitelisted on Namecheap (defaults to
//                         REMOTE_ADDR which usually doesn't work for outbound)
//   NAMECHEAP_SANDBOX   → '1' for sandbox API (testing), default live
//
// Returns are XML-shaped; we convert to PHP arrays via SimpleXMLElement.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';

function crm_namecheapEndpoint(): string {
    return crm_config('NAMECHEAP_SANDBOX')
        ? 'https://api.sandbox.namecheap.com/xml.response'
        : 'https://api.namecheap.com/xml.response';
}

function crm_namecheapBaseParams(): array {
    return [
        'ApiUser'  => (string)crm_config('NAMECHEAP_API_USER'),
        'ApiKey'   => (string)crm_config('NAMECHEAP_API_KEY'),
        'UserName' => (string)crm_config('NAMECHEAP_API_USER'),
        'ClientIp' => (string)(crm_config('NAMECHEAP_CLIENT_IP') ?: $_SERVER['SERVER_ADDR'] ?? ''),
    ];
}

// Single transport with XML→array decode. Returns ['ok','data','error'].
function crm_namecheapCall(string $command, array $params): array {
    $base = crm_namecheapBaseParams();
    foreach (['ApiUser','ApiKey','UserName'] as $k) {
        if ($base[$k] === '') return ['ok' => false, 'data' => null, 'error' => "Namecheap config missing: {$k}"];
    }
    $query = http_build_query(array_merge($base, ['Command' => $command], $params));
    $url   = crm_namecheapEndpoint() . '?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return ['ok' => false, 'data' => null, 'error' => "HTTP {$code}: " . ($err ?: substr((string)$resp, 0, 200))];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$resp);
    if (!$xml) {
        $msg = '';
        foreach (libxml_get_errors() as $e) $msg .= $e->message . '; ';
        return ['ok' => false, 'data' => null, 'error' => 'XML parse: ' . $msg];
    }
    $status = (string)$xml['Status'];
    if ($status !== 'OK') {
        $errs = [];
        foreach ($xml->Errors->Error ?? [] as $e) $errs[] = (string)$e;
        return ['ok' => false, 'data' => null, 'error' => 'API: ' . implode('; ', $errs)];
    }
    return ['ok' => true, 'data' => $xml, 'error' => null];
}

// Check availability for a single domain. Returns ['ok','available','error'].
function crm_namecheapCheckAvailable(string $domain): array {
    $r = crm_namecheapCall('namecheap.domains.check', ['DomainList' => $domain]);
    if (!$r['ok']) return $r + ['available' => null];
    $node = $r['data']->CommandResponse->DomainCheckResult ?? null;
    if (!$node) return ['ok' => false, 'available' => null, 'error' => 'no DomainCheckResult'];
    return ['ok' => true, 'available' => ((string)$node['Available']) === 'true', 'error' => null];
}

// Register a domain for the given client. Pass billing/registrant info from
// the client row — Namecheap requires registrant + tech + admin + auxBilling
// contacts (we use the same details for all four).
function crm_namecheapRegister(string $domain, array $client, int $years = 1): array {
    $first = '';
    $last  = '';
    $signer = trim((string)($client['authorized_signer'] ?? ''));
    if ($signer !== '') {
        $parts = preg_split('/\s+/', $signer, 2);
        $first = (string)($parts[0] ?? '');
        $last  = (string)($parts[1] ?? $first);
    }
    if ($first === '') $first = 'Owner';
    if ($last === '')  $last  = 'Owner';

    $contact = [
        'FirstName'   => mb_substr($first, 0, 50),
        'LastName'    => mb_substr($last,  0, 50),
        'Address1'    => mb_substr((string)($client['billing_address'] ?? ''), 0, 100),
        'City'        => mb_substr((string)($client['billing_city']    ?? ''), 0, 50),
        'StateProvince' => mb_substr((string)($client['billing_state'] ?? ''), 0, 50),
        'PostalCode'  => mb_substr((string)($client['billing_zip']     ?? ''), 0, 50),
        'Country'     => 'US',
        'Phone'       => crm_namecheapPhone((string)($client['primary_phone'] ?? '')),
        'EmailAddress'=> mb_substr((string)($client['billing_email'] ?: $client['primary_email'] ?? ''), 0, 100),
        'OrganizationName' => mb_substr((string)($client['legal_entity_name'] ?: $client['business_name'] ?? ''), 0, 100),
    ];
    foreach (['Address1','City','StateProvince','PostalCode','Phone','EmailAddress'] as $req) {
        if ($contact[$req] === '') {
            return ['ok' => false, 'error' => "Namecheap requires registrant {$req}; check billing fields"];
        }
    }

    // Stamp the same contact under all 4 contact-type prefixes
    $params = ['DomainName' => $domain, 'Years' => max(1, $years), 'AddFreeWhoisguard' => 'yes', 'WGEnabled' => 'yes'];
    foreach (['Registrant','Tech','Admin','AuxBilling'] as $prefix) {
        foreach ($contact as $k => $v) {
            $params[$prefix . $k] = $v;
        }
    }
    return crm_namecheapCall('namecheap.domains.create', $params);
}

// Set custom DNS hosts (A records) so the domain points to client's hosting
// IP. Useful right after we provision cPanel reseller.
function crm_namecheapSetA(string $sld, string $tld, array $records): array {
    // $records: [['HostName' => '@', 'Address' => '1.2.3.4', 'TTL' => 1800], ...]
    $params = ['SLD' => $sld, 'TLD' => $tld];
    foreach ($records as $i => $r) {
        $n = $i + 1;
        $params["HostName{$n}"]  = $r['HostName']  ?? '@';
        $params["RecordType{$n}"]= $r['RecordType']?? 'A';
        $params["Address{$n}"]   = $r['Address']   ?? '';
        $params["TTL{$n}"]       = (string)($r['TTL'] ?? 1800);
    }
    return crm_namecheapCall('namecheap.domains.dns.setHosts', $params);
}

// Namecheap-friendly phone formatting: +1.5551234567 (period after country code).
function crm_namecheapPhone(string $raw): string {
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === '') return '';
    if (strlen($digits) === 10) $digits = '1' . $digits;
    if ($digits[0] !== '1') $digits = '1' . $digits;
    return '+' . substr($digits, 0, 1) . '.' . substr($digits, 1);
}
