<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class PdfTextParserException extends RuntimeException {}

class PdfTextParser
{
    public static function extract(string $pdfPath, string $mode = 'raw'): string
    {
        if (!is_file($pdfPath)) {
            throw new PdfTextParserException("PDF nicht gefunden: $pdfPath");
        }

        $bin = find_pdftotext();
        if ($bin === null) {
            throw new PdfTextParserException(
                "pdftotext wurde nicht gefunden. " .
                "Bitte Poppler (https://github.com/oschwartz10612/poppler-windows/releases) " .
                "oder Git for Windows installieren und den Pfad in config.php anpassen."
            );
        }

        $modeFlag = $mode === 'layout' ? '-layout' : '-raw';

        $cmd = sprintf(
            '%s %s -enc UTF-8 -nopgbrk %s - 2>&1',
            self::quote($bin),
            $modeFlag,
            self::quote($pdfPath)
        );

        $output    = [];
        $exitCode  = 0;
        $stdout    = shell_exec($cmd);

        if ($stdout === null || $stdout === false) {
            throw new PdfTextParserException("pdftotext lieferte keine Ausgabe.");
        }

        $stdout = self::stripBom((string)$stdout);

        if (trim($stdout) === '') {
            throw new PdfTextParserException("PDF lieferte leeren Text. Möglicherweise gescannte Bild-PDF (OCR nötig).");
        }

        return $stdout;
    }

    public static function hasZugferdAttachment(string $pdfPath): ?string
    {
        if (!is_file($pdfPath)) {
            return null;
        }
        $blob = (string)file_get_contents($pdfPath);
        if ($blob === '') {
            return null;
        }
        $candidates = ['factur-x.xml', 'ZUGFeRD-invoice.xml', 'zugferd-invoice.xml', 'xrechnung.xml'];
        foreach ($candidates as $name) {
            if (stripos($blob, $name) !== false) {
                return $name;
            }
        }
        return null;
    }

    private static function quote(string $arg): string
    {
        return '"' . str_replace('"', '\\"', $arg) . '"';
    }

    private static function stripBom(string $s): string
    {
        if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
            return substr($s, 3);
        }
        return $s;
    }
}
