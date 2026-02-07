<?php
declare(strict_types=1);

// Falls nicht global via Autoloader geladen:
if (!class_exists('CaoStatusMapper')) {
    require_once __DIR__ . '/CaoStatusMapper.php';
}

/**
 * Baut CAO-kompatibles XML wie in kaiser_cao-faktura.php:
 * <ORDER>
 *   <ORDER_INFO>
 *     <ORDER_HEADER>…</ORDER_HEADER>
 *     <BILLING_ADDRESS>…</BILLING_ADDRESS>
 *     <DELIVERY_ADDRESS>…</DELIVERY_ADDRESS>
 *     <PAYMENT>…</PAYMENT>
 *     <SHIPPING>…</SHIPPING>
 *     <ORDER_PRODUCTS>…</ORDER_PRODUCTS>
 *     <ORDER_TOTAL>…</ORDER_TOTAL>
 *     <ORDER_COMMENTS>…</ORDER_COMMENTS>
 *   </ORDER_INFO>
 * </ORDER>
 */
final class CaoXmlMapper
{
    /** Root wie im Alt-Skript */
    public static function createOrdersRoot(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<ORDER/>');
    }

    /** API → CAO-XML: Einzelbestellung als <ORDER_INFO> */
    public static function orderToCaoXml(array $order): \SimpleXMLElement
    {
        $d = $order['data'] ?? $order;

        $ox = new \SimpleXMLElement('<ORDER_INFO/>');

        // ===== ORDER_HEADER =====
        $h = $ox->addChild('ORDER_HEADER');
        self::add($h, 'ORDER_ID',             self::s(self::pick($d, ['id','orders_id'])));
        self::add($h, 'CUSTOMER_ID',          self::s(self::pick($d, ['customer.id','customers_id'])));
        self::add($h, 'CUSTOMER_CID',         self::s(self::pick($d, ['customer.customerNumber','customers_cid'])));
        self::add($h, 'CUSTOMER_GROUP',       self::s(self::pick($d, ['customer.statusId','customers_status'])));
        self::add($h, 'ORDER_DATE',           self::dt(self::pick($d, ['date_purchased','datePurchased','purchaseDate','createdAt'])));
        self::add($h, 'ORDER_CURRENCY',       self::s(self::pick($d, ['currency']) ?? 'EUR'));
        self::add($h, 'ORDER_CURRENCY_VALUE', self::s(self::pick($d, ['currency_value','currencyValue']) ?? '1'));

        // CAO-Status aus REST-Status + Zahlart gemappt wie im Alt-Skript
        $mappedStatus = CaoStatusMapper::map($d);
        self::add($h, 'ORDER_STATUS', $mappedStatus);

        self::add($h, 'ORDER_IP',             self::s(self::pick($d, ['customer.ip','customers_ip'])));

        // ===== BILLING_ADDRESS =====
        $bill = self::arr(self::pick($d, ['billingAddress','billing'], []));
        $bx = $ox->addChild('BILLING_ADDRESS');
        self::add($bx, 'VAT_ID',     self::s(self::pick($d, ['customer.vatId','customers_vat_id'])));
        self::add($bx, 'COMPANY',    self::s($bill['company'] ?? ''));
        self::add($bx, 'NAME',       self::s(trim((string)self::scalar($bill['firstName'] ?? '') . ' ' . (string)self::scalar($bill['lastName'] ?? ''))));
        self::add($bx, 'FIRSTNAME',  self::s($bill['firstName'] ?? ''));
        self::add($bx, 'LASTNAME',   self::s($bill['lastName'] ?? ''));
        self::add($bx, 'STREET',     self::s(self::street($bill)));
        self::add($bx, 'POSTCODE',   self::s($bill['postcode'] ?? ''));
        self::add($bx, 'CITY',       self::s($bill['city'] ?? ''));
        self::add($bx, 'SUBURB',     self::s($bill['suburb'] ?? ''));
        self::add($bx, 'STATE',      self::s($bill['state'] ?? ''));
        self::add($bx, 'COUNTRY',    self::s(self::country($bill)));
        self::add($bx, 'TELEPHONE',  self::s(self::pick($d, ['customer.telephone','customers_telephone'])));
        self::add($bx, 'EMAIL',      self::s(self::pick($d, ['customer.email','customers_email_address'])));
        self::add($bx, 'BIRTHDAY',   self::s(self::pick($d, ['customer.dateOfBirth'])));
        self::add($bx, 'GENDER',     self::s(self::pick($d, ['customer.gender'])));

        // ===== DELIVERY_ADDRESS =====
        $shipAddr = self::arr(self::pick($d, ['deliveryAddress','shipping'], []));
        $sx = $ox->addChild('DELIVERY_ADDRESS');
        self::add($sx, 'COMPANY',   self::s($shipAddr['company'] ?? ''));
        self::add($sx, 'NAME',      self::s(trim((string)self::scalar($shipAddr['firstName'] ?? '') . ' ' . (string)self::scalar($shipAddr['lastName'] ?? ''))));
        self::add($sx, 'FIRSTNAME', self::s($shipAddr['firstName'] ?? ''));
        self::add($sx, 'LASTNAME',  self::s($shipAddr['lastName'] ?? ''));
        self::add($sx, 'STREET',    self::s(self::street($shipAddr)));
        self::add($sx, 'POSTCODE',  self::s($shipAddr['postcode'] ?? ''));
        self::add($sx, 'CITY',      self::s($shipAddr['city'] ?? ''));
        self::add($sx, 'SUBURB',    self::s($shipAddr['suburb'] ?? ''));
        self::add($sx, 'STATE',     self::s($shipAddr['state'] ?? ''));
        self::add($sx, 'COUNTRY',   self::s(self::country($shipAddr)));

        // ===== PAYMENT ===== (paymentType.* / payment.* / DB-Fallbacks)
        $px = $ox->addChild('PAYMENT');
        self::add($px, 'PAYMENT_METHOD', self::s(self::pick($d, [
            'payment_method','paymentType.title','payment.title','paymentType','paymentType.name','paymentTypeTitle','paymentTypeName'
        ])));
        self::add($px, 'PAYMENT_CLASS',  self::s(self::pick($d, [
            'payment_class','paymentType.module','payment.code','paymentClass'
        ])));
        self::add($px, 'PAYMENT_BANKTRANS_BNAME','');
        self::add($px, 'PAYMENT_BANKTRANS_BLZ','');
        self::add($px, 'PAYMENT_BANKTRANS_NUMBER','');
        self::add($px, 'PAYMENT_BANKTRANS_OWNER','');
        self::add($px, 'PAYMENT_BANKTRANS_STATUS','');

        // ===== SHIPPING ===== (shippingType.* / shipping.* / DB-Fallbacks) + HTML bereinigen
        $shx = $ox->addChild('SHIPPING');
        $shipTitle = self::pick($d, ['shipping_method','shippingType.title','shipping.title']);
        $shipClass = self::pick($d, ['shipping_class','shippingType.module','shipping.code']);
        self::add($shx, 'SHIPPING_METHOD', self::clean($shipTitle));
        self::add($shx, 'SHIPPING_CLASS',  self::s($shipClass));

        // ===== ORDER_PRODUCTS =====
        $itemsNode = $ox->addChild('ORDER_PRODUCTS');
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $x = $itemsNode->addChild('PRODUCT');

            self::add($x, 'PRODUCTS_ID',       self::s(self::pick($it, ['id','products_id'])));
            $qty = (float) self::num(self::pick($it, ['quantity.value','products_quantity','quantity'], 1));
            self::add($x, 'PRODUCTS_QUANTITY', self::s(self::nf($qty, 4)));
            self::add($x, 'PRODUCTS_MODEL',    self::s(self::pick($it, ['products_model','model'])));
            self::add($x, 'PRODUCTS_NAME',     self::s(self::pick($it, ['products_name','name'])));

            // final_price (Brutto gesamt) / qty ⇒ Einzelpreis brutto
            $final = (float) self::num(self::pick($it, ['final_price','price.value','finalPrice'], 0.0));
            $unit  = $qty > 0 ? ($final / $qty) : $final;
            self::add($x, 'PRODUCTS_PRICE', self::s(self::nf($unit)));

            $tax   = (float) self::num(self::pick($it, ['products_tax','tax.rate'], 0.0));
            self::add($x, 'PRODUCTS_TAX',      self::s(self::nf($tax)));

            // prefer allow_tax (wie im Alt-Skript), sonst Heuristik
            $allow = self::pick($it, ['allow_tax']);
            $flag  = ($allow !== null && $allow !== '') ? (string)$allow : ($tax > 0 ? '1' : '0');
            self::add($x, 'PRODUCTS_TAX_FLAG', $flag);
        }

        // ===== ORDER_TOTAL ===== (Totals + Fallback aus totalSum)
        $totals = self::arr($d['totals'] ?? []);
        $totalsNode = $ox->addChild('ORDER_TOTAL');

        $subtotal = self::num($totals['subtotal']      ?? null);
        $shipping = self::num($totals['shippingTotal'] ?? null);
        $taxTotal = self::num($totals['taxTotal']      ?? null);
        $grand    = self::num($totals['grandTotal']    ?? null);

        if ($grand === null) {
            $grandFromStr = self::moneyToFloat(self::pick($d, ['totalSum']));
            if ($grandFromStr !== null) $grand = $grandFromStr;
        }

        $list = [
            ['title' => 'Zwischensumme:', 'value' => $subtotal, 'class' => 'ot_subtotal', 'sort' => '10', 'prefix' => '', 'tax' => ''],
            ['title' => 'Versandkosten:', 'value' => $shipping, 'class' => 'ot_shipping', 'sort' => '20', 'prefix' => '', 'tax' => ''],
            ['title' => 'MwSt.:',         'value' => $taxTotal, 'class' => 'ot_tax',      'sort' => '30', 'prefix' => '', 'tax' => ''],
            ['title' => 'Gesamtsumme:',   'value' => $grand,    'class' => 'ot_total',    'sort' => '99', 'prefix' => '', 'tax' => ''],
        ];
        foreach ($list as $t) {
            if ($t['value'] === null) continue;
            $tx = $totalsNode->addChild('TOTAL');
            self::add($tx, 'TOTAL_TITLE',      self::s($t['title']));
            self::add($tx, 'TOTAL_VALUE',      self::s(self::nf((float)$t['value'])));
            self::add($tx, 'TOTAL_CLASS',      $t['class']);
            self::add($tx, 'TOTAL_SORT_ORDER', $t['sort']);
            self::add($tx, 'TOTAL_PREFIX',     $t['prefix']);
            self::add($tx, 'TOTAL_TAX',        $t['tax']);
        }

        // Kommentare optional (falls vorhanden)
        $comments = self::pick($d, ['comments','comment']);
        if (!empty($comments)) {
            self::add($ox, 'ORDER_COMMENTS', self::s($comments));
        }

        return $ox;
    }

    /* ================= Helpers ================ */

    private static function add(\SimpleXMLElement $p, string $name, string $val=''): void
    {
        $p->addChild($name, $val);
    }

    private static function s($v): string
    {
        if (is_array($v)) $v = self::scalar($v);
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function clean($v): string
    {
        if (is_array($v)) $v = self::scalar($v);
        $s = html_entity_decode((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        return trim($s);
    }

    private static function nf(float $n, int $dec=2): string
    {
        $s = number_format($n, $dec, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function num($v, $default = null)
    {
        if ($v === null) return $default;
        if (is_array($v)) $v = self::scalar($v);
        if (is_string($v)) $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float)$v : $default;
    }

    private static function dt($v): string
    {
        if (is_array($v)) $v = self::scalar($v);
        $s = (string)$v;
        if ($s === '') return '';
        $s = str_replace('T',' ',$s);
        $s = preg_replace('/\s*(Z|[+-]\d{2}:\d{2})$/','',$s);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)) return $s.' 00:00:00';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',$s)) return $s;
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : $s;
    }

    private static function moneyToFloat($v): ?float
    {
        if ($v === null) return null;
        if (is_array($v)) $v = self::scalar($v);
        $s = (string)$v;
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        if (preg_match('/,\d{2}$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float)$s : null;
    }

    private static function street(array $a): string
    {
        $street = (string) self::scalar($a['street']      ?? '');
        $hnr    = (string) self::scalar($a['houseNumber'] ?? '');
        return trim($street . ' ' . $hnr);
    }

    private static function country(array $a): string
    {
        $iso2 = (string) self::scalar($a['countryIsoCode2'] ?? $a['countryIsoCode'] ?? '');
        $nm   = (string) self::scalar($a['country'] ?? '');
        return $iso2 !== '' ? $iso2 : $nm;
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
        foreach (['value','name','title','code','id','module'] as $k) {
            if (isset($v[$k]) && !is_array($v[$k])) return $v[$k];
        }
        foreach ($v as $vv) {
            if (!is_array($vv)) return $vv;
        }
        return '';
    }

    private static function arr($v): array
    {
        return is_array($v) ? $v : [];
    }
}
