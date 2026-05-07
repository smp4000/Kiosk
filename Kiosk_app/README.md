# Kiosk_app — Android-Test-App für die Remi/Lieferung/Inventur

Test-App in Kotlin + Jetpack Compose, die mit den **Kiosk-Backend-Endpoints** unter `http://<host>/kiosk/api/` spricht. Funktioniert mit Hardware-Scannern im Keyboard-Wedge-Modus (Zebra I/O 900, Munbyn, Netum) ohne herstellerspezifische SDKs.

## Features

- **Lieferung erfassen** — Lieferschein-Nr + Datum + EAN-Scans, Menge per +/− Stepper
- **Remission erfassen** — Paket-Nr + Datum + Scans, Mengen werden serverseitig negiert
- **Inventur erfassen** — Bezeichnung + Scans + Bestandsmengen, zeigt Gesamtwert in EUR
- **Wochentag-Dialog** bei Mehrfach-EAN-Treffern (z. B. BILD Mo/Di/…/Sa)
- **Pending-Artikel-Anlage** bei unbekanntem EAN (Bezeichnung manuell, MwSt + VKP aus EAN-Code abgeleitet)
- **Settings**: Server-URL + optional Mitarbeiter + Station-ID
- **Verbindungstest** über `/api/ping.php`

## Voraussetzungen

- Android Studio Koala (2024.1.x) oder neuer
- Android SDK 34 installiert
- JDK 17

## Erstes Öffnen in Android Studio

1. `File → Open` und den Ordner `Kiosk_app` auswählen
2. Android Studio fragt: „Update Gradle wrapper?" — bestätigen.
   Dabei wird `gradle/wrapper/gradle-wrapper.jar` automatisch nachgeladen
   (steht nicht im Repo, weil binär).
3. Gradle Sync abwarten
4. App auf Emulator oder Hardware-Scanner installieren

## Backend-URL einstellen

Beim ersten Start öffnet sich der Hauptbildschirm. Oben rechts auf das **Zahnrad** → Server-URL eintragen:

| Szenario | URL |
|---|---|
| Android-Emulator → Host-PC mit XAMPP | `http://10.0.2.2/kiosk/` |
| MDE im selben WLAN wie der Server | `http://<IP-des-Servers>/kiosk/` |

Dann **Testen** drücken — sollte „OK – N Artikel im Backend" zeigen.

## Hardware-Scanner-Setup (Zebra I/O 900 / Munbyn / Netum)

Alle drei Geräte müssen im **HID / Keyboard-Wedge**-Modus betrieben werden:

- **Zebra**: DataWedge-Profil `Default` → Output-Plugin „Keystroke Output" → Suffix `\n` (oder TAB)
- **Munbyn**: in den Settings-Trigger des Scanners „HID-KEYBOARD" wählen, Suffix CR/LF
- **Netum**: Konfigurations-Barcode für „Add CR Suffix" einscannen (steht im Handbuch)

Die App fängt alle KeyEvents auf Activity-Ebene ab und erkennt Scan-Bursts (Zeichen kommen schnell hintereinander, Burst endet bei ENTER/TAB).

## Architektur

```
app/src/main/java/com/aral/kiosk/
├── KioskApp.kt              Application + Service-Locator
├── MainActivity.kt          Compose-Setup + dispatchKeyEvent → ScannerBridge
├── data/
│   ├── api/                 Retrofit-Service + Models
│   ├── prefs/               DataStore-Settings
│   └── scanner/             ScannerBridge (Keyboard-Wedge)
├── ui/
│   ├── components/          ScanField, ArticleCard, MengenStepper, AusgabePicker
│   ├── screens/
│   │   ├── home/            HomeScreen
│   │   ├── settings/        SettingsScreen
│   │   ├── lieferung/       LieferungScreen + ViewModel
│   │   ├── remission/       RemissionScreen + ViewModel
│   │   └── inventur/        InventurScreen + ViewModel
│   └── theme/               KioskTheme
```

## Tech-Stack

- Kotlin 1.9.24
- Compose BOM 2024.06
- Material 3
- Retrofit 2.11 + OkHttp 4.12
- kotlinx.serialization 1.6.3
- DataStore Preferences 1.1.1
- minSdk 26, targetSdk/compileSdk 34
- Java 17

## Was diese Test-App **nicht** macht (kommt später in der echten Rosi-App)

- ❌ Echtes Mitarbeiter-Login (jetzt nur Name als String)
- ❌ Sanctum-Token-Auth (jetzt offene API)
- ❌ Multi-Tenancy / Tankstellen-Auswahl beim Login
- ❌ Offline-Outbox (jetzt Online-only, weil MDEs im WLAN sind)
- ❌ Foto-Erfassung
- ❌ Drucken (Lieferschein/Etiketten via Rosi-Print-API)

## Mit-Log: Was später in die echte Rosi-App muss

Siehe Commit-Messages des Kiosk-Repos. Stand:

- [ ] Schema-Migrationen in Rosi (Laravel) für `articles`, `article_issues`, `deliveries`, `delivery_items`, `remi_packages`, `remi_items`, `inventory_runs`, `inventory_items`
- [ ] `EanInspector` als `App\Services\EanInspector`
- [ ] API-Routen unter `/api/v1/...` mit Sanctum-Auth + Tenant-Scope statt offen
- [ ] In Android-Client: Network-Module ergänzen, Token-Header injecten, Mitarbeiter-Scan-Login
- [ ] Filament-Resources für die 6 Bewegungs-Tabellen
