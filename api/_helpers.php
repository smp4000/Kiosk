<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../parser/EanInspector.php';

function api_init(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Device-Token, X-Mitarbeiter');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        api_error('Methode nicht erlaubt – erwartet ' . $method, 405);
    }
}

function api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('Ungültiger JSON-Body', 400);
    }
    return $data;
}

function api_response(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $code = 400, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_mitarbeiter(): ?string
{
    $h = $_SERVER['HTTP_X_MITARBEITER'] ?? null;
    if ($h !== null && trim($h) !== '') {
        return mb_substr(trim($h), 0, 100);
    }
    return null;
}

function station_id_from_request(array $body): ?int
{
    $v = $body['station_id'] ?? $_GET['station_id'] ?? null;
    return $v === null ? null : (int)$v;
}

function article_to_dto(array $row): array
{
    return [
        'id'                     => (int)$row['id'],
        'supplier'               => $row['supplier'],
        'objekt'                 => $row['objekt'],
        'ean'                    => $row['ean'],
        'weekday'                => $row['weekday'] === null ? null : (int)$row['weekday'],
        'bezeichnung'            => $row['bezeichnung'],
        'aktueller_preis_netto'  => (float)$row['aktueller_preis_netto'],
        'aktueller_preis_brutto' => (float)$row['aktueller_preis_brutto'],
        'mwst_satz'              => (float)$row['mwst_satz'],
        'ek'                     => $row['ek'] === null ? null : (float)$row['ek'],
        'is_pending'             => (int)$row['is_pending'] === 1,
        'last_seen_at'           => $row['last_seen_at'],
    ];
}
