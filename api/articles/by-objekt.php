<?php
declare(strict_types=1);
require_once __DIR__ . '/../_helpers.php';

api_init();
api_require_method('GET');

$objekt = trim((string)($_GET['objekt'] ?? ''));
$supplier = trim((string)($_GET['supplier'] ?? 'PVG'));

if ($objekt === '' || !preg_match('/^\d{1,10}$/', $objekt)) {
    api_error('Parameter "objekt" muss numerisch sein.', 400);
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT a.*,
        (SELECT GROUP_CONCAT(ai.ausgabe ORDER BY ai.ausgabe DESC SEPARATOR ',')
            FROM article_issues ai WHERE ai.article_id = a.id) AS issues_csv
    FROM articles a
    WHERE a.supplier = ? AND a.objekt = ?
    LIMIT 1
");
$stmt->execute([$supplier, $objekt]);
$row = $stmt->fetch();

if (!$row) {
    api_error('Kein Artikel mit objekt=' . $objekt . ' gefunden.', 404);
}

$dto = article_to_dto($row);
$dto['ausgaben'] = $row['issues_csv'] ? explode(',', $row['issues_csv']) : [];

api_response([
    'ok'      => true,
    'article' => $dto,
]);
