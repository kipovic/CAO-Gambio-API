<?php
declare(strict_types=1);

final class CaoStatusMapper
{
    public static function map(array $order): string
    {
        // Status tolerant lesen (v2/v3)
        $statusRaw  = self::pickScalar([
            $order['orders_status'] ?? null,
            $order['statusId'] ?? null,
            $order['status']['id'] ?? null,
        ]);

        // Payment tolerant lesen (kann in v3/v2 verschachtelt sein)
        $paymentRaw = strtolower(self::pickScalar([
            $order['payment']['code']   ?? null,
            $order['paymentClass']      ?? null,
            $order['paymentType']       ?? null,
            $order['payment']['method'] ?? null,
        ]));

        switch ($statusRaw) {
            case '1': // Offen
                return match ($paymentRaw) {
                    'paypal3', 'paypal', 'paypalplus', 'paypal_express' => '15',
                    'amazon_pay', 'amazonpay'                           => '35',
                    'invoice', 'rechnung'                               => '20',
                    'moneyorder', 'vorkasse', 'prepayment', 'banktransfer' => '25',
                    default => '20',
                };

            case '163': return '5';   // Sofort schwebend
            case '170': return '10';  // Sofort abgebrochen
            case '175': return '15';  // Sofort bestätigt
            default:    return '20';  // registriert
        }
    }

    // Nimmt eine Kandidatenliste entgegen, liefert den ersten brauchbaren Skalar als String
    private static function pickScalar(array $candidates): string
    {
        foreach ($candidates as $v) {
            if ($v === null) continue;

            if (is_scalar($v)) {
                return (string)$v;
            }

            if (is_array($v)) {
                // typische Gambio-Felder priorisieren
                foreach (['id','code','name','title','value',0] as $k) {
                    if (array_key_exists($k, $v)) {
                        $vv = $v[$k];
                        if (is_scalar($vv)) return (string)$vv;
                    }
                }
                // Fallback: erstes skalares Element
                foreach ($v as $vv) {
                    if (is_scalar($vv)) return (string)$vv;
                }
            }
        }
        return '';
    }
}
