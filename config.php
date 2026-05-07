<?php
declare(strict_types=1);

const DB_HOST     = '127.0.0.1';
const DB_PORT     = 3306;
const DB_USER     = 'root';
const DB_PASSWORD = '';
const DB_NAME     = 'zeitschriften_orders';
const DB_CHARSET  = 'utf8mb4';

const UPLOAD_DIR = __DIR__ . '/uploads';
const LOG_DIR    = __DIR__ . '/logs';
const LOG_FILE   = __DIR__ . '/logs/import.log';

const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

const PDFTOTEXT_CANDIDATES = [
    'D:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
    'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
    'C:\\xampp\\htdocs\\kiosk\\bin\\pdftotext.exe',
    'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
    'C:\\poppler\\bin\\pdftotext.exe',
    'pdftotext',
    'pdftotext.exe',
];

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

function kiosk_log(string $message, string $level = 'INFO'): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0777, true);
    }
    $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND);
}

function find_pdftotext(): ?string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached === false ? null : $cached;
    }
    foreach (PDFTOTEXT_CANDIDATES as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $cached = $candidate;
        }
        $check = @shell_exec('where ' . escapeshellarg($candidate) . ' 2>nul');
        if (is_string($check) && trim($check) !== '') {
            $first = strtok(trim($check), "\r\n");
            if ($first && is_file($first)) {
                return $cached = $first;
            }
        }
    }
    $cached = false;
    return null;
}
