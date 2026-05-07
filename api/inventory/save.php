<?php
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';

api_init();
api_require_method('POST');

$body = api_json_body();
$bezeichnung = isset($body['bezeichnung']) ? trim((string)$body['bezeichnung']) : null;
$modus       = ($body['modus'] ?? 'partial') === 'full' ? 'full' : 'partial';
$stufe       = max(1, (int)($body['stufe'] ?? 1));
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
    $pdo->prepare("INSERT INTO inventory_runs
        (bezeichnung, mitarbeiter, station_id, modus, stufe, status, notiz, created_at, closed_at)
        VALUES (?, ?, ?, ?, ?, 'closed', ?, NOW(), NOW())")
        ->execute([$bezeichnung, $mitarbeiter, $station_id, $modus, $stufe, $notiz]);
    $runId = (int)$pdo->lastInsertId();

    $itemInsert = $pdo->prepare("INSERT INTO inventory_items
        (run_id, article_id, ausgabe, menge, einzelpreis_brutto, mwst_satz, scanned_ean, scanned_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

    $count = 0;
    foreach ($items as $it) {
        $articleId  = (int)($it['article_id'] ?? 0);
        $menge      = (int)($it['menge'] ?? 0);
        $ausgabe    = isset($it['ausgabe']) ? trim((string)$it['ausgabe']) : null;
        $vkpBrutto  = isset($it['vkp_brutto']) ? (float)$it['vkp_brutto'] : null;
        $mwst       = isset($it['mwst_satz']) ? (float)$it['mwst_satz'] : null;
        $scannedEan = isset($it['scanned_ean']) ? trim((string)$it['scanned_ean']) : null;

        if ($articleId <= 0 || $menge === 0) continue;

        $itemInsert->execute([$runId, $articleId, $ausgabe, $menge, $vkpBrutto, $mwst, $scannedEan]);
        $count++;
    }

    $pdo->commit();
    api_response([
        'ok'                  => true,
        'inventory_run_id'    => $runId,
        'items_saved'         => $count,
    ], 201);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    kiosk_log('inventory/save failed: ' . $e->getMessage(), 'ERROR');
    api_error('Speichern fehlgeschlagen: ' . $e->getMessage(), 500);
}
