<?php
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';

api_init();
api_require_method('GET');

$ean = trim((string)($_GET['ean'] ?? ''));
$ean = preg_replace('/\D/', '', $ean) ?? '';

if (strlen($ean) !== 13) {
    api_error('Parameter "ean" muss 13-stellig numerisch sein.', 400);
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT a.*,
        (SELECT GROUP_CONCAT(ai.ausgabe ORDER BY ai.ausgabe DESC SEPARATOR ',')
            FROM article_issues ai WHERE ai.article_id = a.id) AS issues_csv
    FROM articles a
    WHERE a.ean = ?
    ORDER BY a.weekday IS NULL, a.weekday, a.bezeichnung
");
$stmt->execute([$ean]);
$rows = $stmt->fetchAll();

$articles = array_map(function (array $r) {
    $dto = article_to_dto($r);
    $dto['ausgaben'] = $r['issues_csv'] ? explode(',', $r['issues_csv']) : [];
    return $dto;
}, $rows);

$eanInfo = EanInspector::inspect($ean);

api_response([
    'ok'         => true,
    'ean'        => $ean,
    'count'      => count($articles),
    'articles'   => $articles,
    'ean_info'   => [
        'is_press'      => $eanInfo['is_press'],
        'mwst_satz'     => $eanInfo['mwst_satz'],
        'preis_brutto'  => $eanInfo['preis_brutto'],
        'jugendschutz'  => $eanInfo['jugendschutz'],
        'check_valid'   => $eanInfo['check_valid'],
    ],
]);
