<?php
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';

api_init();
api_require_method('POST');

$body = api_json_body();
$paket       = isset($body['paket']) ? trim((string)$body['paket']) : null;
$paket_datum = isset($body['paket_datum']) ? trim((string)$body['paket_datum']) : null;
$notiz       = isset($body['notiz']) ? trim((string)$body['notiz']) : null;
$mitarbeiter = $body['mitarbeiter'] ?? api_mitarbeiter();
$station_id  = station_id_from_request($body);
$items       = $body['items'] ?? [];

if (!is_array($items) || count($items) === 0) {
    api_error('Mindestens eine Position erforderlich (items).', 400);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO remi_packages
        (paket, paket_datum, mitarbeiter, station_id, status, notiz, created_at, closed_at)
        VALUES (?, ?, ?, ?, 'closed', ?, NOW(), NOW())")
        ->execute([$paket, $paket_datum, $mitarbeiter, $station_id, $notiz]);
    $packageId = (int)$pdo->lastInsertId();

    $itemInsert = $pdo->prepare("INSERT INTO remi_items
        (remi_package_id, article_id, ausgabe, menge, einzelpreis_brutto, mwst_satz, scanned_ean, scanned_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

    $touchIssue = $pdo->prepare("INSERT INTO article_issues
        (article_id, ausgabe, ean_addon, first_seen_at, last_seen_at)
        VALUES (?, ?, NULL, NOW(), NOW())
        ON DUPLICATE KEY UPDATE last_seen_at = NOW()");

    $count = 0;
    foreach ($items as $it) {
        $articleId  = (int)($it['article_id'] ?? 0);
        $menge      = (int)($it['menge'] ?? 0);
        $ausgabe    = isset($it['ausgabe']) ? trim((string)$it['ausgabe']) : null;
        $vkpBrutto  = isset($it['vkp_brutto']) ? (float)$it['vkp_brutto'] : null;
        $mwst       = isset($it['mwst_satz']) ? (float)$it['mwst_satz'] : null;
        $scannedEan = isset($it['scanned_ean']) ? trim((string)$it['scanned_ean']) : null;

        if ($articleId <= 0 || $menge === 0) continue;

        // Remissionsmengen sind negativ. Wenn der Client positive Mengen schickt,
        // negieren wir hier serverseitig zur Sicherheit.
        if ($menge > 0) $menge = -$menge;

        $itemInsert->execute([$packageId, $articleId, $ausgabe, $menge, $vkpBrutto, $mwst, $scannedEan]);
        if ($ausgabe !== null && $ausgabe !== '') {
            $touchIssue->execute([$articleId, $ausgabe]);
        }
        $count++;
    }

    $pdo->commit();
    api_response([
        'ok'              => true,
        'remi_package_id' => $packageId,
        'items_saved'     => $count,
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    kiosk_log('remissions/save failed: ' . $e->getMessage(), 'ERROR');
    api_error('Speichern fehlgeschlagen: ' . $e->getMessage(), 500);
}
