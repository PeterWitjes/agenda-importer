<?php
header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// --- Config ---
define('ICLOUD_USER', trim($_POST['icloud_user'] ?? ''));
$icloudPass    = trim($_POST['icloud_pass'] ?? '');
$calendarName  = trim($_POST['calendar_name'] ?? 'Activiteitenrooster');

if (!ICLOUD_USER || !$icloudPass) {
    die(json_encode(['success' => false, 'message' => 'Vul je iCloud gebruikersnaam en wachtwoord in.']));
}

if (empty($_FILES['file']['tmp_name'])) {
    die(json_encode(['success' => false, 'message' => 'Geen bestand ontvangen.']));
}

// --- Excel inlezen ---
try {
    $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows  = $sheet->toArray(null, false, false, false);
} catch (Exception $e) {
    die(json_encode(['success' => false, 'message' => 'Kon Excel niet lezen: ' . $e->getMessage()]));
}

function excelToDateTime($val): ?\DateTime {
    if ($val === null || $val === '') return null;
    if (is_numeric($val) && $val > 1) {
        try {
            return ExcelDate::excelToDateTimeObject((float)$val);
        } catch (\Exception $e) {
            return null;
        }
    }
    return null;
}

// --- Activiteiten parsen ---
// Structuur: kolom 0=organisatie, 1=startdatum/tijd, 6=eindtijd, 7=locatie, 8=activiteit, 12=personen, 13=genre
$events = [];
foreach ($rows as $row) {
    $organisatie = trim($row[0] ?? '');
    $start       = excelToDateTime($row[1] ?? null);
    $eind        = excelToDateTime($row[6] ?? null);
    $locatie     = trim($row[7] ?? '');
    $activiteit  = trim($row[8] ?? '');
    $personen    = $row[12] ?? null;
    $genre       = trim($row[13] ?? '');

    // Sla lege rijen en header-rijen over
    if (!$activiteit || !$start) continue;
    if (in_array(strtolower($activiteit), ['activiteit', 'nan', ''])) continue;
    if (!$eind) {
        $eind = (clone $start)->modify('+1 hour');
    }

    $events[] = [
        'uid'         => uniqid('lvp-', true) . '@agenda.studiocultura.nl',
        'start'       => $start,
        'end'         => $eind,
        'summary'     => $activiteit,
        'location'    => $locatie === '0.24' ? 'Zaal 0.24' : $locatie,
        'description' => implode(' | ', array_filter([
            $organisatie,
            $personen ? $personen . ' personen' : null,
            $genre
        ])),
    ];
}

if (empty($events)) {
    die(json_encode(['success' => false, 'message' => 'Geen activiteiten gevonden in het Excel-bestand.']));
}

// --- iCloud CalDAV: calendar-URL ontdekken ---
$caldavBase = 'https://caldav.icloud.com';

function caldav_request(string $method, string $url, string $body, string $user, string $pass, array $extraHeaders = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => "$user:$pass",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: text/xml; charset=utf-8'], $extraHeaders),
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADER         => true,
    ]);
    $resp     = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($resp, $hSize), 'final_url' => $finalUrl, 'error' => $error];
}

function base_url(string $url): string {
    $p = parse_url($url);
    return $p['scheme'] . '://' . $p['host'];
}

function to_absolute(string $href, string $base): string {
    if (str_starts_with($href, 'http')) return $href;
    return $base . $href;
}

function xml_val(string $tag, string $xml): ?string {
    if (preg_match('#<(?:[^:>]+:)?' . preg_quote($tag, '#') . '[^>]*>(.*?)</(?:[^:>]+:)?' . preg_quote($tag, '#') . '>#si', $xml, $m)) {
        return trim($m[1]);
    }
    return null;
}

// Stap 1: Principal URL ophalen
$principalXml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:current-user-principal/>
  </d:prop>
</d:propfind>';

$resp = caldav_request('PROPFIND', $caldavBase . '/.well-known/caldav', $principalXml, ICLOUD_USER, $icloudPass, ['Depth: 0']);
if ($resp['code'] === 401) {
    die(json_encode(['success' => false, 'message' => 'iCloud authenticatie mislukt. Controleer je Apple ID en app-specifiek wachtwoord.']));
}
if ($resp['code'] >= 400) {
    die(json_encode(['success' => false, 'message' => 'iCloud verbinding mislukt (HTTP ' . $resp['code'] . ').']));
}

$serverBase = base_url($resp['final_url']);

$principalRaw = xml_val('current-user-principal', $resp['body']);
$principalUrl = $principalRaw ? xml_val('href', $principalRaw) : null;
if (!$principalUrl) die(json_encode(['success' => false, 'message' => 'Kon iCloud principal URL niet ophalen.']));
$principalUrl = to_absolute($principalUrl, $serverBase);

// Stap 2: Calendar home ophalen
$homeXml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop><cal:calendar-home-set/></d:prop>
</d:propfind>';

$resp = caldav_request('PROPFIND', $principalUrl, $homeXml, ICLOUD_USER, $icloudPass, ['Depth: 0']);
$serverBase = base_url($resp['final_url']);

$calHomeRaw = xml_val('calendar-home-set', $resp['body']);
$calHome    = $calHomeRaw ? xml_val('href', $calHomeRaw) : null;
if (!$calHome) die(json_encode(['success' => false, 'message' => 'Kon iCloud calendar home niet ophalen.']));
$calHome = to_absolute($calHome, $serverBase);

// Stap 3: Agenda zoeken op naam
$listXml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">
  <d:prop>
    <d:displayname/>
    <cal:calendar-description/>
  </d:prop>
</d:propfind>';

$resp = caldav_request('PROPFIND', $calHome, $listXml, ICLOUD_USER, $icloudPass, ['Depth: 1']);

// Calendars parsen — namespace-agnostisch
preg_match_all('#<(?:[^:>]+:)?response[^>]*>(.*?)</(?:[^:>]+:)?response>#si', $resp['body'], $matches);
$calendarUrl = null;
foreach ($matches[1] as $block) {
    $href = xml_val('href', $block);
    $name = xml_val('displayname', $block);
    if ($href && $name && strcasecmp(trim($name), $calendarName) === 0) {
        $calendarUrl = to_absolute(trim($href), $serverBase);
        break;
    }
}

if (!$calendarUrl) {
    $foundNames = [];
    foreach ($matches[1] as $block) {
        $n = xml_val('displayname', $block);
        if ($n) $foundNames[] = $n;
    }
    die(json_encode(['success' => false, 'message' => "Agenda '$calendarName' niet gevonden. Beschikbaar: " . implode(', ', array_filter($foundNames))]));
}

// --- Stap 4: Events aanmaken ---
$added  = 0;
$errors = [];

foreach ($events as $event) {
    $startStr = $event['start']->format('Ymd\THis');
    $endStr   = $event['end']->format('Ymd\THis');
    $now      = (new DateTime())->format('Ymd\THis\Z');
    $uid      = $event['uid'];
    $summary  = escapeIcal($event['summary']);
    $location = escapeIcal($event['location']);
    $desc     = escapeIcal($event['description']);

    $ics = "BEGIN:VCALENDAR\r\n"
         . "VERSION:2.0\r\n"
         . "PRODID:-//AgendaImporter//NL\r\n"
         . "BEGIN:VEVENT\r\n"
         . "UID:$uid\r\n"
         . "DTSTAMP:$now\r\n"
         . "DTSTART;TZID=Europe/Amsterdam:$startStr\r\n"
         . "DTEND;TZID=Europe/Amsterdam:$endStr\r\n"
         . "SUMMARY:$summary\r\n"
         . ($location ? "LOCATION:$location\r\n" : '')
         . ($desc     ? "DESCRIPTION:$desc\r\n"  : '')
         . "END:VEVENT\r\n"
         . "END:VCALENDAR\r\n";

    $eventUrl = rtrim($calendarUrl, '/') . '/' . urlencode($uid) . '.ics';
    $result = caldav_request('PUT', $eventUrl, $ics, ICLOUD_USER, $icloudPass, [
        'Content-Type: text/calendar; charset=utf-8',
    ]);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        $added++;
    } else {
        $errors[] = $event['summary'] . ' (HTTP ' . $result['code'] . ')';
    }
}

$msg = "$added activiteiten succesvol toegevoegd aan '$calendarName'.";
if ($errors) {
    $msg .= ' Mislukt: ' . implode(', ', array_slice($errors, 0, 5));
    if (count($errors) > 5) $msg .= ' en nog ' . (count($errors) - 5) . ' meer.';
}

echo json_encode(['success' => $added > 0, 'message' => $msg]);

function escapeIcal(string $s): string {
    return str_replace(["\r\n", "\n", "\r", ',', ';', '\\'], ['\\n', '\\n', '\\n', '\\,', '\\;', '\\\\'], $s);
}
