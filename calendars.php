<?php
header('Content-Type: application/json');
error_reporting(0);

$user = trim($_POST['icloud_user'] ?? '');
$pass = trim($_POST['icloud_pass'] ?? '');

if (!$user || !$pass) {
    die(json_encode(['success' => false, 'message' => 'Vul Apple ID en wachtwoord in.']));
}

function caldav_request(string $method, string $url, string $body, string $user, string $pass, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => "$user:$pass",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: text/xml; charset=utf-8'], $headers),
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADER         => true,
    ]);
    $resp  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($resp, $hSize)];
}

// Stap 1: principal URL
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>';
$resp = caldav_request('PROPFIND', 'https://caldav.icloud.com/', $xml, $user, $pass, ['Depth: 0']);

if ($resp['code'] === 401) die(json_encode(['success' => false, 'message' => 'Authenticatie mislukt. Controleer je Apple ID en app-wachtwoord.']));
if ($resp['code'] >= 400) die(json_encode(['success' => false, 'message' => 'iCloud verbinding mislukt (HTTP ' . $resp['code'] . ').']));

preg_match('#<current-user-principal[^>]*>\s*<href[^>]*>([^<]+)</href>#i', $resp['body'], $m);
$principalUrl = $m[1] ?? null;
if (!$principalUrl) die(json_encode(['success' => false, 'message' => 'Kon principal URL niet ophalen.']));
if (!str_starts_with($principalUrl, 'http')) $principalUrl = 'https://caldav.icloud.com' . $principalUrl;

// Stap 2: calendar home
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop><cal:calendar-home-set/></d:prop>
</d:propfind>';
$resp = caldav_request('PROPFIND', $principalUrl, $xml, $user, $pass, ['Depth: 0']);

preg_match('#<calendar-home-set[^>]*>\s*<href[^>]*>([^<]+)</href>#i', $resp['body'], $m);
$calHome = $m[1] ?? null;
if (!$calHome) die(json_encode(['success' => false, 'message' => 'Kon calendar home niet ophalen.']));
if (!str_starts_with($calHome, 'http')) $calHome = 'https://caldav.icloud.com' . $calHome;

// Stap 3: agenda's ophalen
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:displayname/>
    <cal:calendar-description/>
    <d:resourcetype/>
  </d:prop>
</d:propfind>';
$resp = caldav_request('PROPFIND', $calHome, $xml, $user, $pass, ['Depth: 1']);

preg_match_all('#<d:response[^>]*>(.*?)</d:response>#s', $resp['body'], $matches);

$calendars = [];
foreach ($matches[1] as $block) {
    // Alleen echte kalenders (heeft calendar resourcetype)
    if (!preg_match('#<cal:calendar\s*/>#i', $block) && !preg_match('#<cal:calendar>#i', $block)) continue;
    if (preg_match('#<d:displayname[^>]*>([^<]*)</d:displayname>#i', $block, $nm)) {
        $name = trim($nm[1]);
        if ($name) $calendars[] = $name;
    }
}

if (empty($calendars)) die(json_encode(['success' => false, 'message' => 'Geen agenda\'s gevonden in iCloud.']));

echo json_encode(['success' => true, 'calendars' => $calendars]);
