<?php
declare(strict_types=1);

require_once __DIR__ . '/ParserInterface.php';

class PvgParserException extends RuntimeException {}

class PvgParser implements ParserInterface
{
    public const SUPPLIER = 'PVG';

    public static function canParse(string $pdfPath, string $rawText): bool
    {
        return stripos($rawText, 'PVG Presse-Vertriebs') !== false
            || stripos($rawText, 'pvg-group.com') !== false;
    }

    public static function parse(string $pdfPath, string $rawText): array
    {
        $lines = self::splitLines($rawText);

        $invoice = self::parseHeader($lines);
        $lines2  = self::stripFooters($lines);

        [$lieferungen, $remissionen] = self::parseLines($lines2);

        return [
            'supplier'    => self::SUPPLIER,
            'invoice'     => $invoice,
            'lieferungen' => $lieferungen,
            'remissionen' => $remissionen,
        ];
    }

    private static function splitLines(string $text): array
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $raw   = explode("\n", $text);
        $clean = [];
        foreach ($raw as $l) {
            $l = trim($l);
            if ($l !== '') {
                $clean[] = $l;
            }
        }
        return $clean;
    }

    private static function stripFooters(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            if (preg_match('/^Anlage zur Rechnung\s+\d+/u', $l)) continue;
            if (preg_match('/^vom\s+\d{2}\.\d{2}\.\d{4}\s+\(\d+\/\d+\)$/u', $l)) continue;
            if (preg_match('/^Seite\s+\d+\s+von\s+\d+/u', $l)) continue;
            if (preg_match('/^\d{5}\s+Christian Welle/u', $l)) continue;
            if (preg_match('/^\d{2}\d{3}\s+(Fulda|[A-Z][a-zäöüß]+)/u', $l)) {
            }
            $out[] = $l;
        }
        return $out;
    }

    private static function parseHeader(array $lines): array
    {
        $rechnungsnummer = null;
        $rechnungsdatum  = null;
        $vonDatum        = null;
        $bisDatum        = null;
        $kundennummer    = null;
        $zahlbetrag      = null;

        $tourIdx = null;
        foreach ($lines as $idx => $l) {
            if ($l === 'Tour/FF:' || preg_match('/^Tour\/FF:$/u', $l)) {
                $tourIdx = $idx;
                break;
            }
            if ($idx > 60) break;
        }

        if ($tourIdx !== null) {
            $valueLines = [];
            for ($k = $tourIdx + 1; $k < count($lines) && count($valueLines) < 6; $k++) {
                $candidate = $lines[$k];
                if (stripos($candidate, 'PVG Presse-Vertriebs') !== false) break;
                $valueLines[] = $candidate;
            }

            $rechnungsdatum  = isset($valueLines[0]) ? self::deDate($valueLines[0]) : null;
            $rechnungsnummer = isset($valueLines[1]) && preg_match('/^\d{6,12}$/', $valueLines[1]) ? $valueLines[1] : null;
            if (isset($valueLines[2]) && preg_match('/(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/u', $valueLines[2], $m)) {
                $vonDatum = self::deDate($m[1]);
                $bisDatum = self::deDate($m[2]);
            }
            if (isset($valueLines[3]) && preg_match('/^\d{2}\/\d{4,6}$/', $valueLines[3])) {
                $kundennummer = $valueLines[3];
            }
        }

        $blob = implode("\n", $lines);
        if ($rechnungsnummer === null && preg_match('/\b(\d{10})\b/u', $blob, $m)) {
            $rechnungsnummer = $m[1];
        }
        if ($vonDatum === null && preg_match('/(\d{2}\.\d{2}\.\d{4})\s*-\s*(\d{2}\.\d{2}\.\d{4})/u', $blob, $m)) {
            $vonDatum = self::deDate($m[1]);
            $bisDatum = self::deDate($m[2]);
        }
        if ($kundennummer === null && preg_match('/\b(\d{2}\/\d{4,6})\b/u', $blob, $m)) {
            $kundennummer = $m[1];
        }
        if (preg_match('/Zahlbetrag\s+(-?\d{1,3}(?:\.\d{3})*,\d{2})/u', $blob, $m)) {
            $zahlbetrag = self::deNumber($m[1]);
        }

        if ($rechnungsnummer === null) {
            throw new PvgParserException('Rechnungsnummer konnte nicht erkannt werden.');
        }

        return [
            'rechnungsnummer'       => $rechnungsnummer,
            'rechnungsdatum'        => $rechnungsdatum,
            'leistungszeitraum_von' => $vonDatum,
            'leistungszeitraum_bis' => $bisDatum,
            'kundennummer'          => $kundennummer,
            'zahlbetrag'            => $zahlbetrag,
        ];
    }

    private static function parseLines(array $lines): array
    {
        $lieferungen = [];
        $remissionen = [];

        $section = null;            // 'lieferung' | 'remission' | null
        $currentLs = null;          // ['nr' => string, 'datum' => 'Y-m-d']
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $l = $lines[$i];

            if (preg_match('/^Lieferungen$/u', $l)) {
                $section = 'lieferung';
                $currentLs = null;
                $i++;
                continue;
            }
            if (preg_match('/^Remissionen$/u', $l)) {
                $section = 'remission';
                $currentLs = null;
                $i++;
                continue;
            }
            if (preg_match('/^(Tagessummen|Paketsummen)$/u', $l)) {
                $section = null;
                $currentLs = null;
                $i++;
                continue;
            }

            if (preg_match('/^Lieferschein\s+#(\d+)\s+vom\s+(\d{2}\.\d{2}\.\d{4})/u', $l, $m)) {
                $currentLs = ['nr' => $m[1], 'datum' => self::deDate($m[2])];
                $i++;
                continue;
            }

            if (preg_match('/^Gesamtsumme:/u', $l) || preg_match('/^Summen je MwSt/u', $l)) {
                $i++;
                continue;
            }

            if ($section === 'lieferung' && self::isObjectLine($l)
                && isset($lines[$i+1], $lines[$i+2], $lines[$i+3], $lines[$i+4])
            ) {
                $tuple = self::tryArticleQuintuple($lines, $i, false);
                if ($tuple !== null) {
                    $tuple['typ']                = 'lieferung';
                    $tuple['lieferschein_nr']    = $currentLs['nr']    ?? null;
                    $tuple['lieferschein_datum'] = $currentLs['datum'] ?? null;
                    $tuple['paket']              = null;
                    $tuple['paket_datum']        = null;
                    $lieferungen[] = $tuple;
                    $i += 5;
                    continue;
                }
            }

            if ($section === 'remission' && self::isObjectLine($l)
                && isset($lines[$i+1], $lines[$i+2], $lines[$i+3], $lines[$i+4])
            ) {
                $tuple = self::tryArticleQuintuple($lines, $i, true);
                if ($tuple !== null) {
                    $tuple['typ']                = 'remission';
                    $tuple['lieferschein_nr']    = null;
                    $tuple['lieferschein_datum'] = null;
                    $remissionen[] = $tuple;
                    $i += 5;
                    continue;
                }
            }

            $i++;
        }

        return [$lieferungen, $remissionen];
    }

    private static function tryArticleQuintuple(array $lines, int $i, bool $remission): ?array
    {
        $objekt   = $lines[$i];
        $ausgabe  = $lines[$i+1];
        $bezeich  = $lines[$i+2];
        $eanLine  = $lines[$i+3];
        $dataLine = $lines[$i+4];

        if (!self::isObjectLine($objekt)) return null;
        if (!preg_match('/^\d{4}$/', $ausgabe)) return null;

        if (!preg_match('/^(\d{13})(?:\s+(\d{2,5}))?$/', $eanLine, $em)) {
            return null;
        }
        $ean      = $em[1];
        $eanAddon = $em[2] ?? null;

        if ($remission) {
            $pat = '/^(\d{4,6})\s+(\d{2}\.\d{2}\.\d{4})\s+(-?\d+)\s+(-?\d+(?:[.,]\d+)?)\s*€?\s+(\d+(?:[.,]\d+)?)\s*%\s+(-?\d+(?:[.,]\d+)?)\s*€?\s*$/u';
            if (!preg_match($pat, $dataLine, $dm)) {
                return null;
            }
            $paket        = $dm[1];
            $paketDatum   = self::deDate($dm[2]);
            $menge        = (int)$dm[3];
            $einzelNetto  = self::deNumber($dm[4]);
            $mwstSatz     = self::deNumber($dm[5]);
            $gesamtNetto  = self::deNumber($dm[6]);
        } else {
            $pat = '/^(-?\d+)\s+(-?\d+(?:[.,]\d+)?)\s*€?\s+(\d+(?:[.,]\d+)?)\s*%\s+(-?\d+(?:[.,]\d+)?)\s*€?\s*$/u';
            if (!preg_match($pat, $dataLine, $dm)) {
                return null;
            }
            $paket        = null;
            $paketDatum   = null;
            $menge        = (int)$dm[1];
            $einzelNetto  = self::deNumber($dm[2]);
            $mwstSatz     = self::deNumber($dm[3]);
            $gesamtNetto  = self::deNumber($dm[4]);
        }

        $factor       = 1 + ($mwstSatz / 100);
        $einzelBrutto = round($einzelNetto * $factor, 4);
        $gesamtBrutto = round($gesamtNetto * $factor, 2);

        $weekday = self::detectWeekday($bezeich, $eanAddon);

        return [
            'objekt'              => $objekt,
            'ausgabe'             => $ausgabe,
            'ean'                 => $ean,
            'ean_addon'           => $eanAddon,
            'weekday'             => $weekday,
            'bezeichnung'         => $bezeich,
            'paket'               => $paket,
            'paket_datum'         => $paketDatum,
            'menge'               => $menge,
            'einzelpreis_netto'   => $einzelNetto,
            'einzelpreis_brutto'  => $einzelBrutto,
            'mwst_satz'           => $mwstSatz,
            'gesamt_netto'        => $gesamtNetto,
            'gesamt_brutto'       => $gesamtBrutto,
            'raw_line'            => trim($objekt . ' ' . $bezeich . ' | ' . $ausgabe . ' ' . $eanLine . ' | ' . $dataLine),
        ];
    }

    private static function isObjectLine(string $l): bool
    {
        return (bool)preg_match('/^\d{5}$/', $l);
    }

    private static function detectWeekday(string $bezeichnung, ?string $eanAddon): ?int
    {
        $b = $bezeichnung;

        $patterns = [
            '/\b1\s*Mo\b/u'      => 1,
            '/\b2\s*Di\b/u'      => 2,
            '/\b3\s*Mi\b/u'      => 3,
            '/\b4\s*Do\b/u'      => 4,
            '/\b5\s*Fr\b/u'      => 5,
            '/\b6\s*Sa\b/u'      => 6,
            '/\b7\s*So(nntag)?\b/u' => 7,
            '/\bMontag\b/iu'     => 1,
            '/\bDienstag\b/iu'   => 2,
            '/\bMittwoch\b/iu'   => 3,
            '/\bDonnerstag\b/iu' => 4,
            '/\bFreitag\b/iu'    => 5,
            '/\bSamstag\b/iu'    => 6,
            '/\bSonntag\b/iu'    => 7,
            '/\bMO\.?\b/u'       => 1,
            '/\bDI\.?\b/u'       => 2,
            '/\bMI\.?\b/u'       => 3,
            '/\bDO\.?\b/u'       => 4,
            '/\bFR\.?\b/u'       => 5,
            '/\bSA\.?\b/u'       => 6,
            '/\bSO\.?\b/u'       => 7,
        ];

        foreach ($patterns as $rgx => $day) {
            if (preg_match($rgx, $b)) {
                return $day;
            }
        }

        if ($eanAddon !== null && strlen($eanAddon) === 5) {
            $first = (int)substr($eanAddon, 0, 1);
            if ($first >= 1 && $first <= 7) {
                return $first;
            }
        }

        return null;
    }

    private static function deNumber(string $s): float
    {
        $s = trim(str_replace(['€', ' '], '', $s));
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        return (float)$s;
    }

    private static function deDate(string $s): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }
}
