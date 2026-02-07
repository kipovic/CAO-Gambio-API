<?php
declare(strict_types=1);

final class CaoCustomerXmlMapper
{
    public static function createRoot(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<CUSTOMERS/>');
    }

    /**
     * Baut einen <CUSTOMERS_DATA>-Block exakt wie in Kaiser::SendCustomers().
     * Erwartet die normalisierten Keys aus GambioServices::fetchCustomersPageV3().
     */
    public static function customerToCaoXml(array $row): \SimpleXMLElement
    {
        $x = new \SimpleXMLElement('<CUSTOMERS_DATA/>');

        self::add($x, 'CUSTOMERS_ID',   self::s($row['customers_id'] ?? ''));
        self::add($x, 'CUSTOMERS_CID',  self::s($row['customers_cid'] ?? ''));
        // Kaiser: immer "1"
        self::add($x, 'CUSTOMER_GROUP', '1');

        self::add($x, 'GENDER',   self::s($row['customers_gender'] ?? ''));
        self::add($x, 'COMPANY',  self::s($row['entry_company'] ?? ''));
        self::add($x, 'FIRSTNAME',self::s($row['entry_firstname'] ?? ''));
        self::add($x, 'LASTNAME', self::s($row['entry_lastname'] ?? ''));
        self::add($x, 'STREET',   self::s($row['entry_street_address'] ?? ''));
        self::add($x, 'POSTCODE', self::s($row['entry_postcode'] ?? ''));
        self::add($x, 'CITY',     self::s($row['entry_city'] ?? ''));
        self::add($x, 'SUBURB',   self::s($row['entry_suburb'] ?? ''));
        self::add($x, 'STATE',    self::s($row['entry_state'] ?? ''));
        self::add($x, 'COUNTRY',  self::s($row['countries_iso_code_2'] ?? ''));

        self::add($x, 'TELEPHONE', self::s($row['customers_telephone'] ?? ''));
        self::add($x, 'FAX',       self::s($row['customers_fax'] ?? ''));
        self::add($x, 'EMAIL',     self::s($row['customers_email_address'] ?? ''));

        self::add($x, 'BIRTHDAY',  self::dt($row['customers_dob'] ?? ''));
        self::add($x, 'VAT_ID',    self::s($row['vat_id'] ?? ''));
        self::add($x, 'DATE_ACCOUNT_CREATED', self::dt($row['customers_info_date_account_created'] ?? ''));

        return $x;
    }

    /* ===== Helpers ===== */
    private static function add(\SimpleXMLElement $p, string $name, string $val=''): void { $p->addChild($name, $val); }

    private static function s($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

    

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
