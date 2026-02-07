<?php
declare(strict_types=1);

final class CaoCategoryXmlMapperClassic
{
    public static function createRoot(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<CATEGORIES/>');
    }

    public static function categoryToCaoXml(
        array $row,
        string $langCode = 'de',
        string $langName = 'Deutsch',
        string $langId   = '2'
    ): \SimpleXMLElement {
        $x = new \SimpleXMLElement('<CATEGORIES_DATA/>');

        self::add($x, 'ID',            self::s($row['categories_id'] ?? $row['id'] ?? ''));
        self::add($x, 'PARENT_ID',     self::s($row['parent_id'] ?? $row['parentId'] ?? '0'));
        self::add($x, 'SORT_ORDER',    self::num($row['sort_order'] ?? $row['sortOrder'] ?? 0));
        self::add($x, 'DATE_ADDED',    self::dt($row['date_added'] ?? $row['dateAdded'] ?? ''));
        self::add($x, 'LAST_MODIFIED', self::dt($row['last_modified'] ?? $row['lastModified'] ?? ''));

        $cd = $x->addChild('CATEGORIES_DESCRIPTION');
        $cd->addAttribute('ID',   $langId);
        $cd->addAttribute('CODE', $langCode);
        $cd->addAttribute('NAME', $langName);

        self::add($cd, 'NAME',             self::s(self::pickLang($row['name'] ?? null,             $langCode)));
        self::add($cd, 'DESCRIPTION',      self::s(self::pickLang($row['description'] ?? null,      $langCode)));
        self::add($cd, 'META_TITLE',       self::s(self::pickLang($row['meta_title'] ?? null,       $langCode)));
        self::add($cd, 'META_DESCRIPTION', self::s(self::pickLang($row['meta_description'] ?? null, $langCode)));
        self::add($cd, 'META_KEYWORDS',    self::s(self::pickLang($row['meta_keywords'] ?? null,    $langCode)));
        self::add($cd, 'URL',              self::s(self::pickLang($row['url'] ?? null,              $langCode)));

        // WICHTIG: keine <CATEGORIES_STATUS> / <CATEGORIES_IMAGE> ausgeben.

        return $x;
    }

    /* ===== Helpers (identisch zur „gelösten“ dt-Logik aus den anderen Mappern) ===== */

    private static function add(\SimpleXMLElement $p, string $name, string $val=''): void
    {
        $p->addChild($name, $val);
    }

    private static function s($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function num($v): string
    {
        if ($v === '' || $v === null) return '';
        return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') ?: '0';
    }

    private static function dt($v): string
    {
        if ($v === null || $v === '') return '';

        // Falls verschachtelt (sollte hier nicht vorkommen, aber konsistent bleiben)
        if (is_array($v)) {
            $v = self::pickLang($v, 'de'); // irgendein String
        }

        // Unix-Zeitstempel (int/num-string)
        if (is_numeric($v) && (string)(int)$v === (string)$v) {
            return date('Y-m-d H:i:s', (int)$v);
        }

        $s = (string)$v;
        // ISO → Leerzeichen, Zeitzonenabschneider (Z, +02:00)
        $s = str_replace('T', ' ', $s);
        $s = preg_replace('/\s*(Z|[+-]\d{2}:\d{2})$/', '', $s);

        // Nur Datum → 00:00:00 anhängen
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s . ' 00:00:00';
        }
        // Bereits vollständiges Format
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) {
            return $s;
        }

        // Versuchen zu parsen
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : $s;
    }

    private static function pickLang($mixed, string $code)
    {
        if (is_array($mixed)) {
            return (string)($mixed[$code] ?? $mixed[strtoupper($code)] ?? reset($mixed) ?? '');
        }
        return (string)($mixed ?? '');
    }
}
