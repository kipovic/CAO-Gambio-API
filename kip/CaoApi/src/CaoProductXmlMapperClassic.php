<?php
declare(strict_types=1);

final class CaoProductXmlMapperClassic
{
    public static function createRoot(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<PRODUCTS/>');
    }

    /**
     * Baut die "alte" CAO-Struktur:
     * <PRODUCT_INFO><PRODUCT_DATA> ... + <PRODUCT_DESCRIPTION>...</PRODUCT_DESCRIPTION></PRODUCT_DATA></PRODUCT_INFO>
     */
    public static function productToCaoClassic(array $row, string $langCode = 'de', string $langName = 'Deutsch', string $langId = '2'): \SimpleXMLElement
    {
        $d = $row['data'] ?? $row;
        if (is_object($d)) { $d = json_decode(json_encode($d), true); }

        $info = new \SimpleXMLElement('<PRODUCT_INFO/>');
        $x    = $info->addChild('PRODUCT_DATA');

        // ---- Grundfelder (wie in alter Funktion) ----
        self::add($x, 'PRODUCT_ID',           self::s($d['id'] ?? $d['products_id'] ?? ''));
        self::add($x, 'PRODUCT_QUANTITY',     self::num($d['quantity'] ?? $d['products_quantity'] ?? 0));
        self::add($x, 'PRODUCT_MODEL',        self::s($d['productModel'] ?? $d['products_model'] ?? ''));
        self::add($x, 'PRODUCT_FSK18',        self::num(isset($d['isFsk18']) ? ($d['isFsk18'] ? 1 : 0) : ($d['products_fsk18'] ?? 0)));
        self::add($x, 'PRODUCT_IMAGE',        self::s(self::mainImage($d)));
        self::add($x, 'PRODUCT_EAN',          self::s($d['ean'] ?? $d['products_ean'] ?? ''));

        // Ein einzelner Preis wie früher: wir nehmen den Netto-Shoppreis
        self::add($x, 'PRODUCT_PRICE',        self::money($d['price'] ?? $d['products_price'] ?? 0));

        // Steuer-Klasse/Rate (klassische Tag-Namen)
        $taxClassId = $d['taxClassId'] ?? $d['products_tax_class_id'] ?? '';
        $taxRate    = $d['prices']['tax_rate'] ?? $d['tax_rate'] ?? 0;
        self::add($x, 'PRODUCT_TAX_CLASS_ID', self::s((string)$taxClassId));
        self::add($x, 'PRODUCT_TAX_RATE',     self::num($taxRate));

        // Gewicht / Status / Hersteller / Timestamps / Ordered
        self::add($x, 'PRODUCT_WEIGHT',       self::num($d['weight'] ?? $d['products_weight'] ?? 0));
        self::add($x, 'PRODUCT_STATUS',       self::num(isset($d['isActive']) ? ($d['isActive'] ? 1 : 0) : ($d['products_status'] ?? 1)));
        self::add($x, 'MANUFACTURERS_ID',     self::s($d['manufacturerId'] ?? $d['manufacturers_id'] ?? ''));
        self::add($x, 'PRODUCT_DATE_ADDED',   self::dt($d['dateAdded'] ?? $d['products_date_added'] ?? ''));
        self::add($x, 'PRODUCT_LAST_MODIFIED',self::dt($d['lastModified'] ?? $d['products_last_modified'] ?? ''));
        self::add($x, 'PRODUCT_DATE_AVAILABLE', self::dt(($d['dateAvailable'] ?? $d['products_date_available'] ?? '1000-01-01 00:00:00') ?: '1000-01-01 00:00:00'));
        self::add($x, 'PRODUCTS_ORDERED',     self::num($d['orderedCount'] ?? $d['products_ordered'] ?? 0));

        // ---- Sprach-Block wie früher: PRODUCT_DESCRIPTION mit Unterfeldern ----
        $pd = $x->addChild('PRODUCT_DESCRIPTION');
        $pd->addAttribute('ID',   $langId);    // falls du echte Lang-IDs willst, hier anpassen
        $pd->addAttribute('CODE', $langCode);
        $pd->addAttribute('NAME', $langName);

        $name = self::pickLang($d['name'] ?? null, $langCode);
        self::add($pd, 'NAME', self::s($name));

        // URL / Beschreibung / Kurztext / Meta aus den v2-Details
        $url            = self::pickLang($d['url'] ?? null,            $langCode);
        $shortDesc      = self::pickLang($d['shortDescription'] ?? null,$langCode);
        $metaTitle      = self::pickLang($d['metaTitle'] ?? null,      $langCode);
        $metaDesc       = self::pickLang($d['metaDescription'] ?? null,$langCode);
        $metaKeywords   = self::pickLang($d['metaKeywords'] ?? null,   $langCode);
        $htmlDesc       = self::pickLang($d['description'] ?? null,    $langCode);

        self::add($pd, 'URL',               self::s($url));
        self::add($pd, 'DESCRIPTION',       self::s($htmlDesc));
        self::add($pd, 'SHORT_DESCRIPTION', self::s($shortDesc));
        self::add($pd, 'META_TITLE',        self::s($metaTitle));
        self::add($pd, 'META_DESCRIPTION',  self::s($metaDesc));
        self::add($pd, 'META_KEYWORDS',     self::s($metaKeywords));

        return $info;
    }

    /* ===== Helpers ===== */

    private static function add(\SimpleXMLElement $p, string $name, string $val=''): void { $p->addChild($name, $val); }

    private static function s($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function num($v): string
    {
        if ($v === '' || $v === null) return '';
        return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') ?: '0';
    }

    private static function money($v): string
    {
        if ($v === '' || $v === null) return '';
        return number_format((float)$v, 4, '.', '');
    }

    private static function dt($v): string
    {
        if ($v === null || $v === '') return '';
        if (is_array($v)) $v = self::scalar($v);

        if (is_numeric($v) && (string)(int)$v === (string)$v) return date('Y-m-d H:i:s', (int)$v);

        $s = (string)$v;
        $s = str_replace('T',' ',$s);
        $s = preg_replace('/\s*(Z|[+-]\d{2}:\d{2})$/','',$s);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)) return $s.' 00:00:00';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$s)) return $s;

        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : $s;
    }

    private static function mainImage(array $d): string
    {
        if (!empty($d['images']) && is_array($d['images'])) {
            $first = $d['images'][0];
            return is_array($first) ? ($first['filename'] ?? $first['image'] ?? '') : (string)$first;
        }
        return (string)($d['image'] ?? '');
    }

    private static function scalar($v)
    {
        if (!is_array($v)) return $v;
        foreach (['value','name','title','code','id','url','image','filename','de','DE'] as $k) {
            if (isset($v[$k]) && !is_array($v[$k])) return $v[$k];
        }
        foreach ($v as $vv) {
            if (!is_array($vv)) return $vv;
        }
        return '';
    }

    private static function pickLang($mixed, string $code)
    {
        if (is_array($mixed)) {
            return (string)($mixed[$code] ?? $mixed[strtoupper($code)] ?? reset($mixed) ?? '');
        }
        return (string)($mixed ?? '');
    }
}
