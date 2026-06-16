# Automatisering Ovatic → iCloud — Snapshot

## Huidige situatie (werkend)
De webpagina **agenda.studiocultura.nl** werkt volledig:
1. Sleep Excel-export (LVP/Ovatic) op de pagina
2. Laad agenda's → kies "Cultura Dans en Atrium"
3. Klik Importeer → 70 activiteiten in iCloud

## Gewenste automatisering (nog te bouwen)
Elke **maandag 09:00** komt een mail van `reports@ovatic.nl` naar `info@studiocultura.nl`
met een link naar het Ovatic rapport. De automatisering moet:

1. IMAP inlezen → email van reports@ovatic.nl vinden
2. Token-URL extraheren (bijv. `https://culturaede.app.ovatic.nl/reports/report/1039665/viewer?token=...`)
3. Excel downloaden van Ovatic
4. Importeren in iCloud agenda "Cultura Dans en Atrium"

## Wat we weten over de Ovatic setup

### Mail
- **Van:** reports@ovatic.nl
- **Aan:** info@studiocultura.nl
- **Onderwerp:** Studio (bezetting atrium en 0.24)
- **Tijdstip:** maandag 09:00
- **IMAP server:** mail.op-email.eu
- **IMAP user:** info@studiocultura.nl

### Ovatic viewer URL structuur
```
https://culturaede.app.ovatic.nl/reports/report/{REPORT_ID}/viewer?token={TOKEN}
```
- **Report ID:** 1039665 (vast)
- **Token:** elke week anders (zit in de mail)
- De token **vervalt** na gebruik/tijd → kan niet hergebruikt worden

### Ovatic xlsx download
- De viewer is een **Angular SPA** (JavaScript app)
- De viewer chunk die downloads afhandelt: `chunk-J7SHCKP3.js` (route: `report/:id/viewer`)
- De downloadLink voor xlsx zit in de Angular component — exacte API endpoint nog niet gevonden
- Geprobeerde endpoints (allemaal 200 maar text/html terug = Angular fallback):
  - `/api/reports/{id}/export/xlsx?token=...`
  - `/api/v1/reports/{id}/download?format=xlsx&token=...`
- **Volgende stap:** chunk-J7SHCKP3.js verder analyseren voor exact API endpoint,
  of een headless browser installeren

### Server (ai.cpu.nl)
- **PHP 8.5** met imap + curl extensies ✓
- **Python 3.9** beschikbaar (geen pip)
- **Geen Node.js / npm**
- **Geen headless browser** — maar kan geïnstalleerd worden via Composer (PHP)
- `exec()` en `shell_exec()` beschikbaar ✓

### Headless browser optie via Composer
PHP Playwright-wrapper of Browsershot (via Composer) kan geïnstalleerd worden:
```bash
composer require spatie/browsershot
# Vereist ook: npm + puppeteer op de server
```
Of pure PHP headless optie:
```bash
composer require chrome-php/chrome
# Gebruikt lokale Chrome/Chromium installatie
```
→ **Vraag DirectAdmin of Chromium/Chrome installeerbaar is op het hosting pakket**

## IMAP credentials (op server opslaan in config.php)
- server: mail.op-email.eu
- user: info@studiocultura.nl
- pass: DmUVD%d6hgMtF@AC

## iCloud credentials (op server opslaan in config.php)
- user: peterwitjes@mac.com
- pass: [app-specifiek wachtwoord — Peter vult in]
- agenda: Cultura Dans en Atrium

## Locatie mapping
| Ovatic waarde | iCal locatie |
|---|---|
| 0.24 / Zaal 0.24 | DANS |
| Atrium | ATRIUM |

## Stappen voor volledige automatisering
1. [ ] Exacte Ovatic xlsx API endpoint achterhalen (chunk-J7SHCKP3.js of network tab)
2. [ ] Headless browser installeren op server (Browsershot via Composer)
3. [ ] `cron.php` bouwen: IMAP → token → xlsx download → iCloud import
4. [ ] Cronjob instellen via DirectAdmin: maandag 09:05
5. [ ] config.php op server aanmaken met credentials (gitignored)
