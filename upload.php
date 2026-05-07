<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/parser/PdfTextParser.php';
require_once __DIR__ . '/parser/PvgParser.php';
require_once __DIR__ . '/parser/ZugferdParser.php';
require_once __DIR__ . '/parser/EanInspector.php';

session_start();

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function back(): void
{
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back();
}

if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
    flash('error', 'Keine Datei empfangen oder Upload-Fehler.');
    back();
}

$tmp      = $_FILES['pdf']['tmp_name'];
$origName = basename($_FILES['pdf']['name']);
$size     = (int)$_FILES['pdf']['size'];

if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
    flash('error', 'Datei zu groß oder leer.');
    back();
}

$mime = function_exists('mime_content_type') ? @mime_content_type($tmp) : 'application/pdf';
if ($mime !== 'application/pdf' && !preg_match('/\.pdf$/i', $origName)) {
    flash('error', 'Nur PDF-Dateien werden akzeptiert.');
    back();
}

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0777, true);
}

$hash    = hash_file('sha256', $tmp);
$safeName = preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $origName);
$safeName = trim($safeName) !== '' ? trim($safeName) : 'invoice.pdf';
$target  = UPLOAD_DIR . '/' . date('Ymd_His') . '_' . $safeName;

if (!move_uploaded_file($tmp, $target)) {
    flash('error', 'Datei konnte nicht gespeichert werden.');
    back();
}

try {
    $report = importInvoice($target, $hash, $safeName);
    $msg = sprintf(
        'Import OK: Rechnung <b>%s</b> – %d neue Artikel, %d Preisänderungen, %d neue Ausgaben, %d Positionen, %d übersprungen.',
        htmlspecialchars($report['rechnungsnummer'] ?? '?'),
        $report['articles_inserted'],
        $report['price_changes'],
        $report['issues_inserted'],
        $report['lines_inserted'],
        $report['lines_skipped']
    );
    flash('ok', $msg);
} catch (DuplicateImportException $e) {
    flash('warn', $e->getMessage());
} catch (Throwable $e) {
    kiosk_log('Upload fehlgeschlagen: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'ERROR');
    flash('error', 'Fehler beim Import: ' . $e->getMessage());

    try {
        db()->prepare("INSERT INTO imports (filename, file_hash, status, error_message, created_at) VALUES (?, ?, 'error', ?, NOW())")
            ->execute([$safeName, $hash, $e->getMessage()]);
    } catch (Throwable $e2) {
        kiosk_log('Konnte Fehler nicht in imports speichern: ' . $e2->getMessage(), 'ERROR');
    }
}

back();


// =========================================================================

class DuplicateImportException extends RuntimeException {}

function importInvoice(string $pdfPath, string $hash, string $filename): array
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT id, invoice_id FROM imports WHERE file_hash = ? AND status = 'success' LIMIT 1");
    $stmt->execute([$hash]);
    $existing = $stmt->fetch();
    if ($existing) {
        $pdo->prepare("INSERT INTO imports (invoice_id, filename, file_hash, status, skipped_count, created_at) VALUES (?, ?, ?, 'skipped', 1, NOW())")
            ->execute([$existing['invoice_id'], $filename, $hash]);
        throw new DuplicateImportException('Diese Datei wurde bereits importiert (identischer SHA256). Übersprungen.');
    }

    $rawText = '';
    try {
        $rawText = PdfTextParser::extract($pdfPath, 'raw');
    } catch (Throwable $e) {
        kiosk_log('PdfTextParser Fehler: ' . $e->getMessage(), 'WARN');
    }

    $parsed = null;
    if (ZugferdParser::canParse($pdfPath, $rawText)) {
        try {
            $parsed = ZugferdParser::parse($pdfPath, $rawText);
            kiosk_log('ZUGFeRD-Parser benutzt für ' . $filename, 'INFO');
        } catch (Throwable $e) {
            kiosk_log('ZUGFeRD-Parser fehlgeschlagen, Fallback auf PVG: ' . $e->getMessage(), 'WARN');
        }
    }
    if ($parsed === null) {
        if ($rawText === '') {
            throw new RuntimeException('PDF-Text konnte nicht extrahiert werden. pdftotext fehlt oder PDF ist gescannt.');
        }
        if (!PvgParser::canParse($pdfPath, $rawText)) {
            throw new RuntimeException('Rechnungs-Format wurde nicht erkannt. Aktuell wird nur PVG/ZUGFeRD unterstützt.');
        }
        $parsed = PvgParser::parse($pdfPath, $rawText);
    }

    return persistParsedInvoice($pdo, $parsed, $hash, $filename);
}

function persistParsedInvoice(PDO $pdo, array $parsed, string $hash, string $filename): array
{
    $supplier = $parsed['supplier'];
    $inv      = $parsed['invoice'];
    $allLines = array_merge($parsed['lieferungen'], $parsed['remissionen']);

    $articlesInserted = 0;
    $priceChangesCount = 0;
    $issuesInserted   = 0;
    $linesInserted    = 0;
    $linesSkipped     = 0;

    $pdo->beginTransaction();
    try {
        // 1) Invoice anlegen / aktualisieren
        $stmt = $pdo->prepare("SELECT id, file_hash FROM invoices WHERE supplier = ? AND rechnungsnummer = ?");
        $stmt->execute([$supplier, $inv['rechnungsnummer']]);
        $existingInvoice = $stmt->fetch();

        if ($existingInvoice) {
            $invoiceId = (int)$existingInvoice['id'];
            if ($existingInvoice['file_hash'] === $hash) {
                $pdo->commit();
                $pdo->prepare("INSERT INTO imports (invoice_id, filename, file_hash, status, skipped_count, created_at) VALUES (?, ?, ?, 'skipped', 1, NOW())")
                    ->execute([$invoiceId, $filename, $hash]);
                throw new DuplicateImportException("Rechnung {$inv['rechnungsnummer']} wurde bereits importiert (gleicher Hash). Übersprungen.");
            }

            $pdo->prepare("UPDATE invoices SET
                rechnungsdatum = ?, leistungszeitraum_von = ?, leistungszeitraum_bis = ?,
                kundennummer = ?, zahlbetrag = ?, filename = ?, file_hash = ?
                WHERE id = ?")->execute([
                $inv['rechnungsdatum'], $inv['leistungszeitraum_von'], $inv['leistungszeitraum_bis'],
                $inv['kundennummer'], $inv['zahlbetrag'], $filename, $hash, $invoiceId,
            ]);
        } else {
            $pdo->prepare("INSERT INTO invoices
                (supplier, rechnungsnummer, rechnungsdatum, leistungszeitraum_von, leistungszeitraum_bis, kundennummer, zahlbetrag, filename, file_hash, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                ->execute([
                    $supplier, $inv['rechnungsnummer'], $inv['rechnungsdatum'],
                    $inv['leistungszeitraum_von'], $inv['leistungszeitraum_bis'],
                    $inv['kundennummer'], $inv['zahlbetrag'], $filename, $hash,
                ]);
            $invoiceId = (int)$pdo->lastInsertId();
        }

        // 2) Statements vorbereiten
        $articleSelect = $pdo->prepare("SELECT id, aktueller_preis_netto, aktueller_preis_brutto, mwst_satz, ek, bezeichnung
                                          FROM articles WHERE supplier = ? AND objekt = ?");
        $articleInsert = $pdo->prepare("INSERT INTO articles
            (supplier, objekt, ean, weekday, bezeichnung,
             aktueller_preis_netto, aktueller_preis_brutto, mwst_satz, ek, is_pending,
             last_seen_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW(), NOW())");
        $articleUpdatePrice = $pdo->prepare("UPDATE articles SET
             ean = ?, weekday = COALESCE(?, weekday), bezeichnung = ?,
             aktueller_preis_netto = ?, aktueller_preis_brutto = ?, mwst_satz = ?, ek = ?,
             is_pending = 0, last_seen_at = NOW(), updated_at = NOW()
             WHERE id = ?");
        $articleTouch = $pdo->prepare("UPDATE articles SET
             ean = ?, weekday = COALESCE(?, weekday), last_seen_at = NOW()
             WHERE id = ?");

        $logPrice = $pdo->prepare("INSERT INTO price_change_log
            (article_id, objekt, ean, bezeichnung, change_type,
             old_vkp_netto, new_vkp_netto,
             old_vkp_brutto, new_vkp_brutto,
             old_ek, new_ek,
             old_mwst_satz, new_mwst_satz,
             rechnungsnummer, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        $issueSelect = $pdo->prepare("SELECT id FROM article_issues WHERE article_id = ? AND ausgabe = ?");
        $issueInsert = $pdo->prepare("INSERT INTO article_issues
            (article_id, ausgabe, ean_addon, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, NOW(), NOW())");
        $issueTouch  = $pdo->prepare("UPDATE article_issues SET ean_addon = COALESCE(?, ean_addon), last_seen_at = NOW() WHERE id = ?");

        $lineInsert = $pdo->prepare("INSERT IGNORE INTO order_lines
            (invoice_id, article_id, ausgabe, typ, lieferschein_nr, lieferschein_datum, paket,
             menge, einzelpreis_netto, einzelpreis_brutto, mwst_satz,
             gesamt_netto, gesamt_brutto, raw_line, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

        $articleCache = []; // objekt → article_id (innerhalb dieser Rechnung)

        foreach ($allLines as $li) {
            $objekt = $li['objekt'];

            // 3) Article upsert (per supplier+objekt)
            //    EK = PVG-Einkaufspreis netto (aus Rechnung)
            //    VKP = aus EAN-Code extrahiert (Stellen 9-12 / 100)
            if (!isset($articleCache[$objekt])) {
                $articleSelect->execute([$supplier, $objekt]);
                $art = $articleSelect->fetch();

                $eanInfo  = EanInspector::inspect($li['ean']);
                $newEk    = (float)$li['einzelpreis_netto'];

                $newVkpBrutto = $eanInfo['preis_brutto'] ?? null;
                $newMwst      = $eanInfo['mwst_satz']    ?? (float)$li['mwst_satz'];

                if ($newVkpBrutto !== null && $newVkpBrutto > 0) {
                    $newVkpNetto = round($newVkpBrutto / (1 + $newMwst / 100), 4);
                } else {
                    // Fallback: kein Preis aus EAN ableitbar → VKP = NULL, manuell pflegen
                    $newVkpBrutto = 0.0;
                    $newVkpNetto  = 0.0;
                }

                if ($art) {
                    $oldVkpNetto  = (float)$art['aktueller_preis_netto'];
                    $oldVkpBrutto = (float)$art['aktueller_preis_brutto'];
                    $oldMwst      = (float)$art['mwst_satz'];
                    $oldEk        = $art['ek'] === null ? null : (float)$art['ek'];

                    $vkpChanged  = abs($oldVkpBrutto - $newVkpBrutto) > 0.0001;
                    $ekChanged   = ($oldEk === null) || abs($oldEk - $newEk) > 0.0001;
                    $mwstChanged = abs($oldMwst - $newMwst) > 0.001;

                    if ($vkpChanged || $ekChanged || $mwstChanged) {
                        $changeType = 'multi';
                        if ($vkpChanged && !$ekChanged && !$mwstChanged)      $changeType = 'vkp';
                        elseif (!$vkpChanged && $ekChanged && !$mwstChanged)  $changeType = 'ek';
                        elseif (!$vkpChanged && !$ekChanged && $mwstChanged)  $changeType = 'mwst';

                        $logPrice->execute([
                            $art['id'], $objekt, $li['ean'], $li['bezeichnung'], $changeType,
                            $oldVkpNetto,  $newVkpNetto,
                            $oldVkpBrutto, $newVkpBrutto,
                            $oldEk,        $newEk,
                            $oldMwst,      $newMwst,
                            $inv['rechnungsnummer'],
                        ]);
                        $articleUpdatePrice->execute([
                            $li['ean'], $li['weekday'], $li['bezeichnung'],
                            $newVkpNetto, $newVkpBrutto, $newMwst, $newEk, $art['id'],
                        ]);
                        $priceChangesCount++;
                    } else {
                        $articleTouch->execute([$li['ean'], $li['weekday'], $art['id']]);
                    }
                    $articleCache[$objekt] = (int)$art['id'];
                } else {
                    $articleInsert->execute([
                        $supplier, $objekt, $li['ean'], $li['weekday'], $li['bezeichnung'],
                        $newVkpNetto, $newVkpBrutto, $newMwst, $newEk,
                    ]);
                    $articleCache[$objekt] = (int)$pdo->lastInsertId();
                    $articlesInserted++;
                }
            }
            $articleId = $articleCache[$objekt];

            // 4) article_issues upsert
            $issueSelect->execute([$articleId, $li['ausgabe']]);
            $issue = $issueSelect->fetch();
            if ($issue) {
                $issueTouch->execute([$li['ean_addon'], $issue['id']]);
            } else {
                $issueInsert->execute([$articleId, $li['ausgabe'], $li['ean_addon']]);
                $issuesInserted++;
            }

            // 5) order_lines insert
            $lineInsert->execute([
                $invoiceId, $articleId, $li['ausgabe'], $li['typ'],
                $li['lieferschein_nr'] ?? null, $li['lieferschein_datum'] ?? null, $li['paket'] ?? null,
                $li['menge'], $li['einzelpreis_netto'], $li['einzelpreis_brutto'], $li['mwst_satz'],
                $li['gesamt_netto'], $li['gesamt_brutto'], $li['raw_line'] ?? null,
            ]);
            if ($lineInsert->rowCount() === 0) {
                $linesSkipped++;
            } else {
                $linesInserted++;
            }
        }

        $pdo->prepare("INSERT INTO imports
            (invoice_id, filename, file_hash, status, inserted_count, updated_count, skipped_count, created_at)
            VALUES (?, ?, ?, 'success', ?, ?, ?, NOW())")
            ->execute([$invoiceId, $filename, $hash, $articlesInserted, $priceChangesCount, $linesSkipped]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'rechnungsnummer'   => $inv['rechnungsnummer'],
        'articles_inserted' => $articlesInserted,
        'price_changes'     => $priceChangesCount,
        'issues_inserted'   => $issuesInserted,
        'lines_inserted'    => $linesInserted,
        'lines_skipped'     => $linesSkipped,
    ];
}
