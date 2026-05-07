<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_database_exists();

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    ensure_schema($pdo);
    return $pdo;
}

function ensure_database_exists(): void
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $tmp = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $tmp->exec(sprintf(
        "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s_unicode_ci",
        DB_NAME, DB_CHARSET, DB_CHARSET
    ));
}

function ensure_schema(PDO $pdo): void
{
    detect_and_migrate_old_schema($pdo);

    $statements = [
        // === Stammdaten ===
        "CREATE TABLE IF NOT EXISTS articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(100) NOT NULL DEFAULT 'PVG',
            objekt VARCHAR(50) NOT NULL,
            ean VARCHAR(13) NOT NULL,
            weekday TINYINT NULL,
            bezeichnung VARCHAR(255) NOT NULL,
            aktueller_preis_netto  DECIMAL(10,4) NOT NULL DEFAULT 0,
            aktueller_preis_brutto DECIMAL(10,4) NOT NULL DEFAULT 0,
            mwst_satz DECIMAL(5,2)  NOT NULL DEFAULT 0,
            ek DECIMAL(10,4) NULL,
            is_pending TINYINT(1) NOT NULL DEFAULT 0,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_article (supplier, objekt),
            INDEX idx_ean (ean),
            INDEX idx_ean_weekday (ean, weekday),
            INDEX idx_pending (is_pending)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Pivot je Wochenausgabe
        "CREATE TABLE IF NOT EXISTS article_issues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            ausgabe VARCHAR(50) NOT NULL,
            ean_addon VARCHAR(10) NULL,
            first_seen_at DATETIME NOT NULL,
            last_seen_at  DATETIME NOT NULL,
            UNIQUE KEY uk_issue (article_id, ausgabe),
            INDEX idx_ausgabe (ausgabe),
            CONSTRAINT fk_issue_article FOREIGN KEY (article_id)
                REFERENCES articles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // === PVG-Rechnungen ===
        "CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier VARCHAR(100) NOT NULL,
            rechnungsnummer VARCHAR(100) NOT NULL,
            rechnungsdatum DATE NULL,
            leistungszeitraum_von DATE NULL,
            leistungszeitraum_bis DATE NULL,
            kundennummer VARCHAR(100) NULL,
            zahlbetrag DECIMAL(10,2) NULL,
            filename VARCHAR(255) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_invoice (supplier, rechnungsnummer),
            INDEX idx_hash (file_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS order_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            article_id INT NOT NULL,
            ausgabe VARCHAR(50) NOT NULL,
            typ ENUM('lieferung','remission') NOT NULL,
            lieferschein_nr VARCHAR(100) NULL,
            lieferschein_datum DATE NULL,
            paket VARCHAR(100) NULL,
            menge INT NOT NULL,
            einzelpreis_netto  DECIMAL(10,4) NOT NULL,
            einzelpreis_brutto DECIMAL(10,4) NOT NULL,
            mwst_satz DECIMAL(5,2) NOT NULL,
            gesamt_netto  DECIMAL(10,2) NOT NULL,
            gesamt_brutto DECIMAL(10,2) NOT NULL,
            raw_line TEXT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uk_line (invoice_id, typ, lieferschein_nr, paket, article_id, ausgabe, menge, gesamt_netto),
            INDEX idx_invoice (invoice_id),
            INDEX idx_article (article_id),
            CONSTRAINT fk_line_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            CONSTRAINT fk_line_article FOREIGN KEY (article_id) REFERENCES articles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS price_change_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            objekt VARCHAR(50) NULL,
            ean VARCHAR(13) NULL,
            bezeichnung VARCHAR(255) NULL,
            change_type ENUM('vkp','ek','mwst','multi') NOT NULL DEFAULT 'multi',
            old_vkp_netto  DECIMAL(10,4) NULL,
            new_vkp_netto  DECIMAL(10,4) NULL,
            old_vkp_brutto DECIMAL(10,4) NULL,
            new_vkp_brutto DECIMAL(10,4) NULL,
            old_ek         DECIMAL(10,4) NULL,
            new_ek         DECIMAL(10,4) NULL,
            old_mwst_satz  DECIMAL(5,2)  NULL,
            new_mwst_satz  DECIMAL(5,2)  NULL,
            rechnungsnummer VARCHAR(100) NULL,
            changed_at DATETIME NOT NULL,
            INDEX idx_article (article_id),
            INDEX idx_changed (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS imports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NULL,
            filename VARCHAR(255) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            status ENUM('success','error','skipped') NOT NULL,
            inserted_count INT NOT NULL DEFAULT 0,
            updated_count  INT NOT NULL DEFAULT 0,
            skipped_count  INT NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_invoice (invoice_id),
            INDEX idx_hash (file_hash),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // === Bewegungs-Tabellen für die Remi-App ===
        // Wareneingang / Lieferung (manuell erfasst, nicht aus PVG-Rechnung)
        "CREATE TABLE IF NOT EXISTS deliveries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lieferschein_nr VARCHAR(100) NULL,
            lieferschein_datum DATE NULL,
            mitarbeiter VARCHAR(100) NULL,
            station_id INT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            notiz TEXT NULL,
            created_at DATETIME NOT NULL,
            closed_at  DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS delivery_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NOT NULL,
            article_id INT NOT NULL,
            ausgabe VARCHAR(50) NULL,
            menge INT NOT NULL,
            einzelpreis_brutto DECIMAL(10,4) NULL,
            mwst_satz DECIMAL(5,2) NULL,
            scanned_ean VARCHAR(13) NULL,
            scanned_at DATETIME NOT NULL,
            INDEX idx_delivery (delivery_id),
            INDEX idx_article (article_id),
            CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_di_article  FOREIGN KEY (article_id)  REFERENCES articles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Remission (Pakete)
        "CREATE TABLE IF NOT EXISTS remi_packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            paket VARCHAR(100) NULL,
            paket_datum DATE NULL,
            mitarbeiter VARCHAR(100) NULL,
            station_id INT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            notiz TEXT NULL,
            created_at DATETIME NOT NULL,
            closed_at  DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS remi_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            remi_package_id INT NOT NULL,
            article_id INT NOT NULL,
            ausgabe VARCHAR(50) NULL,
            menge INT NOT NULL,
            einzelpreis_brutto DECIMAL(10,4) NULL,
            mwst_satz DECIMAL(5,2) NULL,
            scanned_ean VARCHAR(13) NULL,
            scanned_at DATETIME NOT NULL,
            INDEX idx_pkg (remi_package_id),
            INDEX idx_article (article_id),
            CONSTRAINT fk_ri_pkg     FOREIGN KEY (remi_package_id) REFERENCES remi_packages(id) ON DELETE CASCADE,
            CONSTRAINT fk_ri_article FOREIGN KEY (article_id)      REFERENCES articles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Inventur
        "CREATE TABLE IF NOT EXISTS inventory_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bezeichnung VARCHAR(255) NULL,
            mitarbeiter VARCHAR(100) NULL,
            station_id INT NULL,
            modus ENUM('full','partial') NOT NULL DEFAULT 'partial',
            stufe TINYINT NOT NULL DEFAULT 1,
            status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
            notiz TEXT NULL,
            created_at DATETIME NOT NULL,
            closed_at  DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS inventory_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id INT NOT NULL,
            article_id INT NOT NULL,
            ausgabe VARCHAR(50) NULL,
            menge INT NOT NULL,
            einzelpreis_brutto DECIMAL(10,4) NULL,
            mwst_satz DECIMAL(5,2) NULL,
            scanned_ean VARCHAR(13) NULL,
            scanned_at DATETIME NOT NULL,
            INDEX idx_run (run_id),
            INDEX idx_article (article_id),
            CONSTRAINT fk_ii_run     FOREIGN KEY (run_id)     REFERENCES inventory_runs(id) ON DELETE CASCADE,
            CONSTRAINT fk_ii_article FOREIGN KEY (article_id) REFERENCES articles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

function detect_and_migrate_old_schema(PDO $pdo): void
{
    $articleCols = table_columns($pdo, 'articles');
    if ($articleCols !== null) {
        $hasOldAusgabe   = in_array('ausgabe', $articleCols, true);
        $hasNewIsPending = in_array('is_pending', $articleCols, true);
        if ($hasOldAusgabe && !$hasNewIsPending) {
            kiosk_log('Altes articles-Schema erkannt → komplette Daten-Migration: alle Tabellen werden neu angelegt (alte Daten gehen verloren).', 'WARN');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ([
                'inventory_items','inventory_runs',
                'remi_items','remi_packages',
                'delivery_items','deliveries',
                'imports','price_change_log',
                'order_lines','invoices',
                'article_issues','articles',
            ] as $t) {
                $pdo->exec("DROP TABLE IF EXISTS `$t`");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            return;
        }
    }

    $pclCols = table_columns($pdo, 'price_change_log');
    if ($pclCols !== null && !in_array('change_type', $pclCols, true)) {
        kiosk_log('Altes price_change_log-Schema erkannt → DROP & CREATE.', 'WARN');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec("DROP TABLE IF EXISTS price_change_log");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function table_columns(PDO $pdo, string $table): ?array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : null;
    } catch (Throwable $e) {
        return null;
    }
}

function reset_database(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $tables = [
        'inventory_items', 'inventory_runs',
        'remi_items', 'remi_packages',
        'delivery_items', 'deliveries',
        'imports', 'price_change_log',
        'order_lines', 'invoices',
        'article_issues', 'articles',
    ];
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    ensure_schema($pdo);
}
