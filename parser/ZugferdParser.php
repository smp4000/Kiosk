<?php
declare(strict_types=1);

require_once __DIR__ . '/ParserInterface.php';
require_once __DIR__ . '/PdfTextParser.php';

class ZugferdParserException extends RuntimeException {}

class ZugferdParser implements ParserInterface
{
    public static function canParse(string $pdfPath, string $rawText): bool
    {
        return PdfTextParser::hasZugferdAttachment($pdfPath) !== null;
    }

    public static function parse(string $pdfPath, string $rawText): array
    {
        $xml = self::extractAttachedXml($pdfPath);
        if ($xml === null) {
            throw new ZugferdParserException('Kein ZUGFeRD/Factur-X-Anhang im PDF gefunden.');
        }

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            throw new ZugferdParserException('XML konnte nicht geparst werden: ' . implode('; ', $errors));
        }

        $namespaces = $doc->getDocNamespaces(true);
        foreach ($namespaces as $prefix => $ns) {
            if ($prefix === '') $prefix = 'default';
            $doc->registerXPathNamespace($prefix, $ns);
        }
        $doc->registerXPathNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $doc->registerXPathNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $doc->registerXPathNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        $invoice = self::extractInvoice($doc);
        $lines   = self::extractLines($doc);

        $lieferungen = [];
        $remissionen = [];
        foreach ($lines as $li) {
            if (($li['menge'] ?? 0) < 0 || ($li['gesamt_netto'] ?? 0) < 0) {
                $li['typ'] = 'remission';
                $remissionen[] = $li;
            } else {
                $li['typ'] = 'lieferung';
                $lieferungen[] = $li;
            }
        }

        return [
            'supplier'    => $invoice['supplier'] ?? 'ZUGFeRD',
            'invoice'     => $invoice,
            'lieferungen' => $lieferungen,
            'remissionen' => $remissionen,
        ];
    }

    private static function extractAttachedXml(string $pdfPath): ?string
    {
        $blob = (string)file_get_contents($pdfPath);
        if ($blob === '') return null;

        $offset = 0;
        while (($start = strpos($blob, '<?xml', $offset)) !== false) {
            $end = strpos($blob, '</', $start);
            if ($end === false) break;
            $closeTagEnd = strpos($blob, '>', $end);
            if ($closeTagEnd === false) break;

            $candidate = substr($blob, $start, $closeTagEnd - $start + 1);
            if (stripos($candidate, 'CrossIndustryInvoice') !== false
                || stripos($candidate, 'CrossIndustryDocument') !== false
                || stripos($candidate, 'urn:cen.eu:en16931') !== false
            ) {
                return $candidate;
            }
            $offset = $closeTagEnd + 1;
        }

        if (preg_match('/stream\s*\n(.*?)\nendstream/s', $blob, $m)) {
            $stream = $m[1];
            $inflated = @gzuncompress($stream);
            if ($inflated !== false && stripos($inflated, 'CrossIndustryInvoice') !== false) {
                return $inflated;
            }
        }

        return null;
    }

    private static function extractInvoice(SimpleXMLElement $doc): array
    {
        $rechnungsnummer = (string)self::xq($doc, '//ram:ExchangedDocument/ram:ID')[0] ?? '';
        $rechnungsdatum  = self::yyyymmdd((string)(self::xq($doc, '//ram:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')[0] ?? ''));
        $vonDatum        = self::yyyymmdd((string)(self::xq($doc, '//ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString')[0] ?? ''));
        $bisDatum        = self::yyyymmdd((string)(self::xq($doc, '//ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString')[0] ?? ''));
        $kundennummer    = (string)(self::xq($doc, '//ram:BuyerTradeParty/ram:ID')[0] ?? '');
        $zahlbetragRaw   = (string)(self::xq($doc, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount')[0] ?? '');
        $supplierName    = (string)(self::xq($doc, '//ram:SellerTradeParty/ram:Name')[0] ?? '');

        return [
            'rechnungsnummer'       => $rechnungsnummer ?: null,
            'rechnungsdatum'        => $rechnungsdatum,
            'leistungszeitraum_von' => $vonDatum,
            'leistungszeitraum_bis' => $bisDatum,
            'kundennummer'          => $kundennummer ?: null,
            'zahlbetrag'            => $zahlbetragRaw !== '' ? (float)$zahlbetragRaw : null,
            'supplier'              => $supplierName ?: 'ZUGFeRD',
        ];
    }

    private static function extractLines(SimpleXMLElement $doc): array
    {
        $items = self::xq($doc, '//ram:IncludedSupplyChainTradeLineItem');
        $out = [];
        foreach ($items as $item) {
            $item->registerXPathNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

            $bezeichnung = (string)($item->xpath('ram:SpecifiedTradeProduct/ram:Name')[0] ?? '');
            $ean         = (string)($item->xpath('ram:SpecifiedTradeProduct/ram:GlobalID')[0] ?? '');
            $sellerId    = (string)($item->xpath('ram:SpecifiedTradeProduct/ram:SellerAssignedID')[0] ?? '');
            $netto       = (float)((string)($item->xpath('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount')[0] ?? '0'));
            $menge       = (float)((string)($item->xpath('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity')[0] ?? '0'));
            $gesamtNetto = (float)((string)($item->xpath('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount')[0] ?? '0'));
            $mwst        = (float)((string)($item->xpath('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent')[0] ?? '0'));

            $factor       = 1 + ($mwst / 100);
            $einzelBrutto = round($netto * $factor, 4);
            $gesamtBrutto = round($gesamtNetto * $factor, 2);

            $eanShort = $ean !== '' ? substr(preg_replace('/\D/', '', $ean), 0, 13) : '';

            $out[] = [
                'objekt'              => $sellerId !== '' ? $sellerId : substr(md5($bezeichnung), 0, 5),
                'ausgabe'             => '0000',
                'ean'                 => $eanShort ?: str_pad('', 13, '0'),
                'ean_addon'           => null,
                'weekday'             => null,
                'bezeichnung'         => $bezeichnung,
                'paket'               => null,
                'paket_datum'         => null,
                'menge'               => (int)round($menge),
                'einzelpreis_netto'   => $netto,
                'einzelpreis_brutto'  => $einzelBrutto,
                'mwst_satz'           => $mwst,
                'gesamt_netto'        => $gesamtNetto,
                'gesamt_brutto'       => $gesamtBrutto,
                'lieferschein_nr'     => null,
                'lieferschein_datum'  => null,
                'raw_line'            => $bezeichnung . ' | ' . $eanShort,
            ];
        }
        return $out;
    }

    private static function xq(SimpleXMLElement $node, string $xpath): array
    {
        $r = $node->xpath($xpath);
        return $r === false ? [] : $r;
    }

    private static function yyyymmdd(string $s): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "$m[1]-$m[2]-$m[3]";
        }
        return null;
    }
}
