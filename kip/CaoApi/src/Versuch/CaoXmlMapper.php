<?php
declare(strict_types=1);

final class CaoXmlMapper
{
    public static function createOrdersRoot(): \SimpleXMLElement
    {
        return new \SimpleXMLElement('<ORDERS/>');
    }

    public static function orderToCaoXml(array $row): \SimpleXMLElement
    {
        $d = $row['data'] ?? $row;
        if (is_object($d)) $d = json_decode(json_encode($d), true);

        $ox = new \SimpleXMLElement('<ORDER_INFO/>');

        // --- ORDER_HEADER (Reihenfolge wie Kaiser) ---
        $h = $ox->addChild('ORDER_HEADER');
        CaoXmlHelpers::add($h, 'ORDER_ID',             CaoXmlHelpers::s($d['id'] ?? ''));
        CaoXmlHelpers::add($h, 'CUSTOMER_ID',          '');
        CaoXmlHelpers::add($h, 'CUSTOMER_CID',         '');
        CaoXmlHelpers::add($h, 'CUSTOMER_GROUP',       '');
        CaoXmlHelpers::add($h, 'ORDER_DATE',           CaoXmlHelpers::dt($d['purchaseDate'] ?? ''));
        CaoXmlHelpers::add($h, 'ORDER_CURRENCY',       'EUR');
        CaoXmlHelpers::add($h, 'ORDER_CURRENCY_VALUE', '1');
        $statusId  = (int)($d['statusId'] ?? 0);
        $caoStatus = CaoStatusMapper::mapToCao($statusId);
        CaoXmlHelpers::add($h, 'ORDER_STATUS',         (string)$caoStatus);
        CaoXmlHelpers::add($h, 'ORDER_IP',             '');

        // --- BILLING_ADDRESS ---
        $b = $d['billingAddress']  ?? [];
        $ba = $ox->addChild('BILLING_ADDRESS');
        CaoXmlHelpers::add($ba, 'VAT_ID',     CaoXmlHelpers::s($b['vatId'] ?? ''));
        CaoXmlHelpers::add($ba, 'COMPANY',    CaoXmlHelpers::s($b['company'] ?? ''));
        $bf = trim((string)($b['firstName'] ?? ''));
        $bl = trim((string)($b['lastName']  ?? ''));
        CaoXmlHelpers::add($ba, 'NAME',       CaoXmlHelpers::s(trim("$bf $bl")));
        CaoXmlHelpers::add($ba, 'FIRSTNAME',  CaoXmlHelpers::s($bf));
        CaoXmlHelpers::add($ba, 'LASTNAME',   CaoXmlHelpers::s($bl));
        $bstreet = trim(((string)($b['street'] ?? '')) . ' ' . ((string)($b['houseNumber'] ?? '')));
        CaoXmlHelpers::add($ba, 'STREET',     CaoXmlHelpers::s($bstreet));
        CaoXmlHelpers::add($ba, 'POSTCODE',   CaoXmlHelpers::s($b['postcode'] ?? ''));
        CaoXmlHelpers::add($ba, 'CITY',       CaoXmlHelpers::s($b['city'] ?? ''));
        CaoXmlHelpers::add($ba, 'SUBURB',     '');
        CaoXmlHelpers::add($ba, 'STATE',      CaoXmlHelpers::s($b['state'] ?? ''));
        CaoXmlHelpers::add($ba, 'COUNTRY',    CaoXmlHelpers::s($b['country'] ?? ''));
        CaoXmlHelpers::add($ba, 'TELEPHONE',  '');
        CaoXmlHelpers::add($ba, 'EMAIL',      CaoXmlHelpers::s($d['customerEmail'] ?? ''));
        CaoXmlHelpers::add($ba, 'BIRTHDAY',   '');
        CaoXmlHelpers::add($ba, 'GENDER',     CaoXmlHelpers::s($b['gender'] ?? ''));

        // --- DELIVERY_ADDRESS ---
        $a = $d['deliveryAddress'] ?? [];
        $da = $ox->addChild('DELIVERY_ADDRESS');
        CaoXmlHelpers::add($da, 'COMPANY',    CaoXmlHelpers::s($a['company'] ?? ''));
        $af = trim((string)($a['firstName'] ?? ''));
        $al = trim((string)($a['lastName']  ?? ''));
        CaoXmlHelpers::add($da, 'NAME',       CaoXmlHelpers::s(trim("$af $al")));
        CaoXmlHelpers::add($da, 'FIRSTNAME',  CaoXmlHelpers::s($af));
        CaoXmlHelpers::add($da, 'LASTNAME',   CaoXmlHelpers::s($al));
        $astreet = trim(((string)($a['street'] ?? '')) . ' ' . ((string)($a['houseNumber'] ?? '')));
        CaoXmlHelpers::add($da, 'STREET',     CaoXmlHelpers::s($astreet));
        CaoXmlHelpers::add($da, 'POSTCODE',   CaoXmlHelpers::s($a['postcode'] ?? ''));
        CaoXmlHelpers::add($da, 'CITY',       CaoXmlHelpers::s($a['city'] ?? ''));
        CaoXmlHelpers::add($da, 'SUBURB',     '');
        CaoXmlHelpers::add($da, 'STATE',      CaoXmlHelpers::s($a['state'] ?? ''));
        CaoXmlHelpers::add($da, 'COUNTRY',    CaoXmlHelpers::s($a['country'] ?? ''));

        // --- PAYMENT ---
        $p = $d['paymentType'] ?? [];
        $pay = $ox->addChild('PAYMENT');
        CaoXmlHelpers::add($pay, 'PAYMENT_METHOD',          CaoXmlHelpers::s($p['title'] ?? ''));
        CaoXmlHelpers::add($pay, 'PAYMENT_CLASS',           CaoXmlHelpers::s($p['module'] ?? ''));
        CaoXmlHelpers::add($pay, 'PAYMENT_BANKTRANS_BNAME', '');
        CaoXmlHelpers::add($pay, 'PAYMENT_BANKTRANS_BLZ',   '');
        CaoXmlHelpers::add($pay, 'PAYMENT_BANKTRANS_NUMBER','');
        CaoXmlHelpers::add($pay, 'PAYMENT_BANKTRANS_OWNER', '');
        CaoXmlHelpers::add($pay, 'PAYMENT_BANKTRANS_STATUS','');

        // --- SHIPPING ---
        $s = $d['shippingType'] ?? [];
        $sh = $ox->addChild('SHIPPING');
        CaoXmlHelpers::add($sh, 'SHIPPING_METHOD', CaoXmlHelpers::s($s['title'] ?? ''));
        CaoXmlHelpers::add($sh, 'SHIPPING_CLASS',  CaoXmlHelpers::s($s['module'] ?? ''));

        // --- Pflichtknoten vorhanden lassen (leer ist ok) ---
        $ox->addChild('ORDER_PRODUCTS');
        $ox->addChild('ORDER_TOTAL');

        return $ox;
    }
}
