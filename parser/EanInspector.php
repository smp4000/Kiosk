<?php
declare(strict_types=1);

class EanInspector
{
    private const PRESS_PREFIXES = [
        '419' => ['mwst' => 7.0,  'jugendschutz' => false],
        '414' => ['mwst' => 19.0, 'jugendschutz' => false],
        '439' => ['mwst' => 7.0,  'jugendschutz' => true],
        '434' => ['mwst' => 19.0, 'jugendschutz' => true],
    ];

    public static function inspect(string $ean): array
    {
        $ean = preg_replace('/\D/', '', $ean) ?? '';

        $result = [
            'is_press'      => false,
            'prefix'        => null,
            'vdz_nr'        => null,
            'mwst_satz'     => null,
            'preis_brutto'  => null,
            'preis_netto'   => null,
            'jugendschutz'  => null,
            'check_digit'   => null,
            'check_valid'   => null,
        ];

        if (strlen($ean) !== 13) {
            return $result;
        }

        $result['check_digit'] = (int)substr($ean, 12, 1);
        $result['check_valid'] = self::isCheckDigitValid($ean);

        $prefix = substr($ean, 0, 3);
        if (!isset(self::PRESS_PREFIXES[$prefix])) {
            return $result;
        }

        $info = self::PRESS_PREFIXES[$prefix];
        $vdz  = substr($ean, 3, 5);
        $priceCents = (int)substr($ean, 8, 4);

        $brutto = $priceCents / 100;
        $netto  = $brutto / (1 + $info['mwst'] / 100);

        $result['is_press']     = true;
        $result['prefix']       = $prefix;
        $result['vdz_nr']       = $vdz;
        $result['mwst_satz']    = $info['mwst'];
        $result['preis_brutto'] = round($brutto, 4);
        $result['preis_netto']  = round($netto, 4);
        $result['jugendschutz'] = $info['jugendschutz'];

        return $result;
    }

    public static function parseAddon(?string $addon): array
    {
        $result = ['weekday' => null, 'region' => null, 'kw' => null];
        if ($addon === null || !preg_match('/^\d{5}$/', $addon)) {
            return $result;
        }
        $w = (int)substr($addon, 0, 1);
        $r = substr($addon, 1, 2);
        $k = (int)substr($addon, 3, 2);

        if ($w >= 1 && $w <= 7) $result['weekday'] = $w;
        $result['region'] = $r;
        if ($k >= 1 && $k <= 53) $result['kw'] = $k;

        return $result;
    }

    public static function isCheckDigitValid(string $ean): bool
    {
        if (strlen($ean) !== 13 || !ctype_digit($ean)) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $d = (int)$ean[$i];
            $sum += ($i % 2 === 0) ? $d : $d * 3;
        }
        $check = (10 - ($sum % 10)) % 10;
        return $check === (int)$ean[12];
    }
}
