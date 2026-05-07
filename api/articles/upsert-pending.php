<?php
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';

api_init();
api_require_method('POST');

$body = api_json_body();
$ean = preg_replace('/\D/', '', (string)($body['ean'] ?? '')) ?? '';

if (strlen($ean) !== 13) {
    api_error('Feld "ean" muss 13-stellig sein.', 400);
}

$bezeichnung = trim((string)($body['bezeichnung'] ?? ''));
if ($bezeichnung === '') {
    $bezeichnung = 'Zeitschrift (unbekannt)';
}

$weekday = isset($body['weekday']) ? (int)$body['weekday'] : null;
if ($weekday !== null && ($weekday < 1 || $weekday > 7)) $weekday = null;

$eanInfo = EanInspector::inspect($ean);
$mwst    = $eanInfo['mwst_satz']    ?? 7.0;
$brutto  = $eanInfo['preis_brutto'] ?? 0.0;
$netto   = $brutto > 0 ? round($brutto / (1 + $mwst/100), 4) : 0.0;

$pdo = db();

$stmt = $pdo->prepare("SELECT id, is_pending FROM articles WHERE supplier = 'PVG' AND ean = ? AND (weekday <=> ?) LIMIT 1");
$stmt->execute([$ean, $weekday]);
$existing = $stmt->fetch();

if ($existing) {
    api_response([
        'ok'         => true,
        'created'    => false,
        'article_id' => (int)$existing['id'],
        'is_pending' => (bool)$existing['is_pending'],
    ]);
}

$objekt = 'P' . substr(hash('sha256', $ean . '|' . ($weekday ?? '0')), 0, 8);

$pdo->prepare("INSERT INTO articles
    (supplier, objekt, ean, weekday, bezeichnung,
     aktueller_preis_netto, aktueller_preis_brutto, mwst_satz, is_pending,
     last_seen_at, created_at, updated_at)
    VALUES ('PVG', ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())")
    ->execute([$objekt, $ean, $weekday, $bezeichnung, $netto, $brutto, $mwst]);

$articleId = (int)$pdo->lastInsertId();

api_response([
    'ok'         => true,
    'created'    => true,
    'article_id' => $articleId,
    'is_pending' => true,
    'preis_brutto' => $brutto,
    'mwst_satz'    => $mwst,
], 201);
