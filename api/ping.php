<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';

api_init();

try {
    $pdo = db();
    $articleCount = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    api_response([
        'ok'        => true,
        'service'   => 'kiosk-api',
        'version'   => '0.1',
        'timestamp' => date('c'),
        'articles'  => $articleCount,
    ]);
} catch (Throwable $e) {
    api_error('DB nicht erreichbar: ' . $e->getMessage(), 500);
}
