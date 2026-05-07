# Kiosk — Zeitungsrechnungs-Dashboard

Lokale PHP/XAMPP-Webanwendung zum Importieren und Auswerten von PVG- und ZUGFeRD-Pressevertriebs-Rechnungen. Vorbereitung für eine Android-Remi-App (Lieferung / Remission / Inventur).

## Schnellstart

1. Repo nach `C:\xampp\htdocs\kiosk\` klonen
2. **MySQL** im XAMPP Control Panel starten (Apache ebenfalls)
3. Browser: `http://localhost/kiosk/install.php` — DB + Tabellen werden automatisch angelegt, pdftotext-Pfad wird verifiziert
4. Browser: `http://localhost/kiosk/` — PVG-PDF hochladen

## Voraussetzungen

- XAMPP (PHP 8.2+, MySQL 5.7+/MariaDB 10+)
- `pdftotext.exe` (Poppler oder Git Bash). Pfad ist in `config.php` unter `PDFTOTEXT_CANDIDATES` konfigurierbar — Auto-Detection enthalten.

## Projektstruktur

```
kiosk/
├── config.php          DB-Zugang + pdftotext-Auto-Detection
├── db.php              PDO-Connection, Schema, Auto-Migration
├── install.php         Status-Seite + DB-Reset
├── index.php           Dashboard
├── upload.php          PDF-Upload + Hash + Parser-Dispatch + Persistenz
├── assets/style.css    Styling (eigenständig, kein Framework)
├── parser/
│   ├── ParserInterface.php
│   ├── PdfTextParser.php   pdftotext-Wrapper (raw + UTF-8)
│   ├── PvgParser.php       PVG-Format (5-Zeilen-Quintupel)
│   ├── ZugferdParser.php   Factur-X/ZUGFeRD-XML aus PDF-Anhang
│   └── EanInspector.php    EAN-13 → MwSt + VKP brutto
├── uploads/.gitkeep    PDF-Originale (nicht im Repo)
└── logs/.gitkeep       import.log (nicht im Repo)
```

## Datenbankschema (Stand)

### Stammdaten
- **`articles`** — eine Zeile pro Wochentags-Variante
  `UNIQUE (supplier, objekt)` · `INDEX (ean)` · `INDEX (ean, weekday)`
  Felder: `aktueller_preis_netto/brutto` (= **VKP** aus EAN), `ek` (= PVG-EK netto), `mwst_satz`, `weekday`, `is_pending`
- **`article_issues`** — Pivot pro Wochenausgabe
  `UNIQUE (article_id, ausgabe)` · enthält `ean_addon`

### Rechnungen
- **`invoices`** · `UNIQUE (supplier, rechnungsnummer)`
- **`order_lines`** · enthält `ausgabe`-Spalte
- **`price_change_log`** · mit `change_type` (vkp/ek/mwst/multi) und alten/neuen Werten
- **`imports`** · Audit-Trail für jeden Upload

### Bewegungs-Tabellen für die Remi-App (Schema steht, noch keine API)
- `deliveries` + `delivery_items` — Wareneingang
- `remi_packages` + `remi_items` — Remission
- `inventory_runs` + `inventory_items` — Inventur

## EAN-Schema (deutsche Pressevertriebs-EANs)

Implementiert in `parser/EanInspector.php`:

- **Stellen 1–3** = Warengruppen-Präfix → MwSt
  - `419` = 7 % (Standard-Presse)
  - `414` = 19 % (Werbung/Kataloge)
  - `439` = 7 % mit Jugendschutz
  - `434` = 19 % mit Jugendschutz
- **Stellen 4–8** = VDZ-Nr (Verlag)
- **Stellen 9–12** = VKP brutto in Cent (`0150` → 1,50 €)
- **Stelle 13** = Prüfziffer
- **Add-On (5-stellig)**: `WRRKK` — Wochentag + Regionalausgabe + KW

## Verifiziert mit echter PVG-Rechnung

Test-PDF (Rg. 0026124911 KW18/2026):

| Wert | PDF | Parser |
|---|---|---|
| Lieferungen netto | 350,65 € | 350,65 € ✓ |
| Remissionen netto | -290,92 € | -290,92 € ✓ |
| Summe netto | 59,73 € | 59,73 € ✓ |
| Zahlbetrag | 61,50 € | 61,50 € ✓ |
| Positionen | 42 Lief / 55 Remi | 42 / 55 ✓ |

## Was ist fertig

- [x] Schema mit Auto-Install + Auto-Migration (alte Schemata werden bei Bedarf still gedroppt)
- [x] PVG-Parser (Header + Lieferungen + Remissionen) — saldenstabil
- [x] ZUGFeRD-Parser-Stub (XML-Extraktion aus PDF-Anhang vorbereitet)
- [x] EanInspector mit VDZ-Schema und Prüfziffer-Validierung
- [x] EK kommt automatisch aus PVG-Rechnung, VKP aus EAN-Code
- [x] Marge wird im Dashboard angezeigt
- [x] Doppelimport-Schutz: gleicher Hash → Skip, anderer Hash bei gleicher Rechnungsnummer → Update + Log
- [x] Preisänderungen werden mit Typ (vkp/ek/mwst/multi) geloggt
- [x] Wochentag-Erkennung aus Bezeichnung **und** EAN-Add-On

## Nächste Schritte

1. **REST-API für die Remi-App** im Kiosk anlegen:
   - `GET  /api/articles/lookup.php?ean=…`
   - `GET  /api/articles/by-objekt.php?objekt=…`
   - `GET  /api/articles/recent-issues.php?article_id=…`
   - `POST /api/deliveries/save.php`
   - `POST /api/remissions/save.php`
   - `POST /api/inventory/save.php`
   - `POST /api/articles/upsert-pending.php`

2. **Android-Test-App** in Kotlin + Jetpack Compose:
   - Drei MDE-Geräte (Zebra, Munbyn, Netum) → gemeinsamer Nenner: Keyboard-Wedge-Modus
   - Screens: Login (nur Server-URL), Lieferung, Remission, Inventur
   - Wochentag-Dialog bei Mehrfach-EAN-Treffer

3. **Migration in echte Rosi-App** (Laravel-Backend, separates Repo) — Liste in den Commit-Messages mitgeführt.

## Konfiguration

Wenn `pdftotext` an einem anderen Ort liegt, in `config.php` ergänzen:

```php
const PDFTOTEXT_CANDIDATES = [
    'C:\\Pfad\\zu\\pdftotext.exe',
    // ...
];
```

DB-Zugangsdaten in `config.php`:

```php
const DB_HOST     = '127.0.0.1';
const DB_USER     = 'root';
const DB_PASSWORD = '';
const DB_NAME     = 'zeitschriften_orders';
```
