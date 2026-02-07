<?php
declare(strict_types=1);

final class CaoManufacturerXmlMapper
{
    public static function createManufacturersRoot(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<MANUFACTURERS/>');
    }

    public static function manufacturerToCaoXml(array $row): \SimpleXMLElement
    {
        $d = $row['data'] ?? $row;
        if (is_object($d)) {
            $d = json_decode(json_encode($d), true);
        }

        cao_api_log('Manufacturer input data: ' . json_encode($d), $GLOBALS['config']['logFile'] ?? null);

        $mx = new \SimpleXMLElement('<MANUFACTURERS_DATA/>');

        $id   = self::pick($d, ['manufacturers_id','id']);
        $name = self::pick($d, ['manufacturers_name','name']);
        $img  = self::pick($d, ['manufacturers_image','image']);
        $da   = self::pick($d, ['date_added','dateAdded','createdAt']);
        $lm   = self::pick($d, ['last_modified','lastModified','updatedAt']);

        self::add($mx, 'ID',            self::s($id));
        self::add($mx, 'NAME',          self::s($name));
        self::add($mx, 'IMAGE',         self::s($img));
        $da_formatted = self::dt($da);
        $lm_formatted = self::dt($lm);
        self::add($mx, 'DATE_ADDED',    $da_formatted);
        self::add($mx, 'LAST_MODIFIED', $lm_formatted);

        cao_api_log("Manufacturer ID=$id, DATE_ADDED=$da_formatted, LAST_MODIFIED=$lm_formatted", $GLOBALS['config']['logFile'] ?? null);

        // Korrekte Defaults für CAO-Faktura
        $langDefaults = [
            'de' => ['id' => '2', 'name' => 'Deutsch'],
            'en' => ['id' => '1', 'name' => 'English'],
            // Weitere bei Bedarf: 'fr' => ['id' => '3', 'name' => 'Français']
        ];

        // Fall A: neues Schema mit urls = { "DE": "...", "EN": "..." }
        if (!empty($d['urls']) && is_array($d['urls'])) {
            foreach ($d['urls'] as $code => $url) {
                $lowerCode = strtolower($code);
                $node = $mx->addChild('MANUFACTURERS_DESCRIPTION');
                if ($lowerCode) {
                    $node->addAttribute('CODE', $lowerCode);
                }
                if (isset($langDefaults[$lowerCode])) {
                    $node->addAttribute('ID', $langDefaults[$lowerCode]['id']);
                    $node->addAttribute('NAME', $langDefaults[$lowerCode]['name']);
                }
                self::add($node, 'URL',             self::s($url));
                self::add($node, 'URL_CLICK',       '0');
                self::add($node, 'DATE_LAST_CLICK', '');
                cao_api_log("Manufacturer description: CODE=$lowerCode, ID=" . ($langDefaults[$lowerCode]['id'] ?? '') . ", NAME=" . ($langDefaults[$lowerCode]['name'] ?? ''), $GLOBALS['config']['logFile'] ?? null);
            }
        }
        // Fall B: klassisches Schema mit descriptions[]
        elseif (!empty($d['descriptions']) && is_array($d['descriptions'])) {
            foreach ($d['descriptions'] as $desc) {
                if (!is_array($desc)) continue;
                $node = $mx->addChild('MANUFACTURERS_DESCRIPTION');
                $lid = (string) self::scalar($desc['languages_id'] ?? $desc['language_id'] ?? '');
                $code = strtolower((string) self::scalar($desc['lang_code'] ?? $desc['code'] ?? ''));
                $name = (string) self::scalar($desc['lang_name'] ?? $desc['language'] ?? '');
                if (!$lid && isset($langDefaults[$code])) $lid = $langDefaults[$code]['id'];
                if (!$name && isset($langDefaults[$code])) $name = $langDefaults[$code]['name'];
                if ($lid) $node->addAttribute('ID', $lid);
                if ($code) $node->addAttribute('CODE', $code);
                if ($name) $node->addAttribute('NAME', $name);
                self::add($node, 'URL',             self::s($desc['manufacturers_url'] ?? $desc['url'] ?? ''));
                self::add($node, 'URL_CLICK',       self::s($desc['url_clicked'] ?? $desc['clicks'] ?? '0'));
                $dlc = self::dt($desc['date_last_click'] ?? '');
                self::add($node, 'DATE_LAST_CLICK', $dlc);
                cao_api_log("Manufacturer description: ID=$lid, CODE=$code, NAME=$name, DATE_LAST_CLICK=$dlc", $GLOBALS['config']['logFile'] ?? null);
            }
        }

        return $mx;
    }

    private static function add(\SimpleXMLElement $p, string $name, string $val=''): void
    {
        $p->addChild($name, $val);
    }

    private static function s($v): string
    {
        if (is_array($v)) $v = self::scalar($v);
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function dt($v): string
    {
        if ($v === null || $v === '') return '';
        if (is_array($v)) $v = self::scalar($v);

        if (is_numeric($v) && (int)$v > 0 && (string)(int)$v === (string)$v) {
            return date('Y-m-d H:i:s', (int)$v);
        }
        $s = (string)$v;
        $s = str_replace('T',' ',$s);
        $s = preg_replace('/\s*(Z|[+-]\d{2}:\d{2})$/','',$s);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s.' 00:00:00';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;

        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : $s;
    }

    private static function pick(array $arr, array $paths, $default = null)
    {
        foreach ($paths as $p) {
            $v = self::get($arr, $p);
            if ($v !== null && $v !== '') return $v;
        }
        return $default;
    }

    private static function get(array $arr, string $path)
    {
        $ref = $arr;
        foreach (explode('.', $path) as $k) {
            if (is_array($ref) && array_key_exists($k, $ref)) {
                $ref = $ref[$k];
            } else {
                return null;
            }
        }
        return $ref;
    }

    private static function scalar($v)
    {
        if (!is_array($v)) return $v;
        foreach (['value','name','title','code','id','url','image','languages_id','language_id','lang_code'] as $k) {
            if (isset($v[$k]) && !is_array($v[$k])) return $v[$k];
        }
        foreach ($v as $vv) {
            if (!is_array($vv)) return $vv;
        }
        return '';
    }
}