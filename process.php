<?php
header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

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
    $rows  = $sheet->toArray(null, true, true, false);
} catch (Exception $e) {
    die(json_encode(['success' => false, 'message' => 'Kon Excel niet lezen: ' . $e->getMessage()]));
}

// --- Activiteiten parsen ---
// Structuur: kolom 0=organisatie, 1=startdatum/tijd, 4=starttijd, 6=eindtijd, 7=locatie, 8=activiteit, 12=personen, 13=genre
$events = [];
foreach ($rows as $row) {
    $organisatie = trim($row[0] ?? '');
    $start       = $row[1] ?? null;
    $eind        = $row[6] ?? null;
    $locatie     = trim($row[7] ?? '');
    $activiteit  = trim($row[8] ?? '');
    $personen    = $row[12] ?? null;
    $genre       = trim($row[13] ?? '');

    // Sla lege rijen en header-rijen over
    if (!$activiteit || !$start || !is_object($start)) continue;
    if (in_array(strtolower($activiteit), ['activiteit', 'nan', ''])) continue;
    if (!($start instanceof \DateTime || $start instanceof \DateTimeImmutable)) {
        if (is_string($start) && strtotime($start)) {
            $start = new DateTime($start);
        } else {
            continue;
        }
    }
    if (!($eind instanceof \DateTime || $eind instanceof \DateTimeImmutable)) {
        if (is_string($eind) && strtotime($eind)) {
            $eind = new DateTime($eind);
        } else {
            $eind = (clone $start)->modify('+1 hour');
        }
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
        CURLOPT_HTTPHEADER     => array_merge([
            'Content-Type: text/xml; charset=utf-8',
            'Depth: 1',
        ], $extraHeaders),
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HEADER         => true,
    ]);
    $resp   = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error  = curl_error($ch);
    curl_close($ch);
    return [
        'code'    => $code,
        'headers' => substr($resp, 0, $hSize),
        'body'    => substr($resp, $hSize),
        'error'   => $error,
    ];
}

// Stap 1: Principal URL ophalen
$principalXml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:current-user-principal/>
  </d:prop>
</d:propfind>';

$resp = caldav_request('PROPFIND', $caldavBase . '/', $principalXml, ICLOUD_USER, $icloudPass, ['Depth: 0']);
if ($resp['code'] === 401) {
    die(json_encode(['success' => false, 'message' => 'iCloud authenticatie mislukt. Controleer je app-specifiek wachtwoord.']));
}
if ($resp['code'] >= 400) {
    die(json_encode(['success' => false, 'message' => 'iCloud verbinding mislukt (HTTP ' . $resp['code'] . ').']));
}

// Principal URL uit response halen
preg_match('#<current-user-principal[^>]*>\s*<href[^>]*>([^<]+)</href>#i', $resp['body'], $m);
$principalUrl = $m[1] ?? null;
if (!$principalUrl) {
    die(json_encode(['success' => false, 'message' => 'Kon iCloud principal URL niet ophalen.']));
}
if (!str_starts_with($principalUrl, 'http')) {
    $principalUrl = $caldavBase . $principalUrl;
}

// Stap 2: Calendar home ophalen
$homeXml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <cal:calendar-home-set/>
  </d:prop>
</d:propfind>';

$resp = caldav_request('PROPFIND', $principalUrl, $homeXml, ICLOUD_USER, $icloudPass, ['Depth: 0']);
preg_match('#<calendar-home-set[^>]*>\s*<href[^>]*>([^<]+)</href>#i', $resp['body'], $m);
$calHome = $m[1] ?? null;
if (!$calHome) {
    die(json_encode(['success' => false, 'message' => 'Kon iCloud calendar home niet ophalen.']));
}
if (!str_starts_with($calHome, 'http')) {
    $calHome = $caldavBase . $calHome;
}

// Stap 3: Agenda zoeken op naam
$listXml = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">
  <d:prop>
    <d:displayname/>
    <cal:calendar-description/>
  </d:prop>
</d:propfind>';

$resp = caldav_request('PROPFIND', $calHome, $listXml, ICLOUD_USER, $icloudPass);

// Calendars parsen
preg_match_all('#<d:response[^>]*>(.*?)</d:response>#s', $resp['body'], $matches);
$calendarUrl = null;
foreach ($matches[1] as $block) {
    if (preg_match('#<d:href[^>]*>([^<]+)</d:href>#i', $block, $hm) &&
        preg_match('#<d:displayname[^>]*>([^<]*)</d:displayname>#i', $block, $nm)) {
        if (strcasecmp(trim($nm[1]), $calendarName) === 0) {
            $calendarUrl = trim($hm[1]);
            break;
        }
    }
}

if (!$calendarUrl) {
    die(json_encode(['success' => false, 'message' => "Agenda '$calendarName' niet gevonden in iCloud. Maak hem eerst aan in iCal."]));
}
if (!str_starts_with($calendarUrl, 'http')) {
    $calendarUrl = $caldavBase . $calendarUrl;
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
