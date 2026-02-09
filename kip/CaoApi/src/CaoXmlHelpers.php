<?php
declare(strict_types=1);

/**
 * Legacy helper used by experimental mappers.
 * Kept minimal to avoid runtime errors if referenced.
 */
final class CaoXmlHelpers
{
    public static function add(\SimpleXMLElement $p, string $name, string $val = ''): void
    {
        $p->addChild($name, $val);
    }

    public static function s($v): string
    {
        if (is_array($v)) {
            $v = self::scalar($v);
        }
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function dt($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_array($v)) {
            $v = self::scalar($v);
        }
        if (is_numeric($v) && (string)(int)$v === (string)$v) {
            return date('Y-m-d H:i:s', (int)$v);
        }
        $s = (string)$v;
        $s = str_replace('T', ' ', $s);
        $s = preg_replace('/\s*(Z|[+-]\d{2}:\d{2})$/', '', $s);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) {
            return $s;
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : $s;
    }

    private static function scalar($v)
    {
        if (!is_array($v)) {
            return $v;
        }
        foreach (['value', 'name', 'title', 'code', 'id', 'module'] as $k) {
            if (isset($v[$k]) && !is_array($v[$k])) {
                return $v[$k];
            }
        }
        foreach ($v as $vv) {
            if (!is_array($vv)) {
                return $vv;
            }
        }
        return '';
    }
}
