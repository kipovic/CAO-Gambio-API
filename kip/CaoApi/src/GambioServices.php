<?php
declare(strict_types=1);

final class GambioServices
{
    public function __construct(private GambioApiClient $api) {}

    /* =======================
     * Orders: ID-Range (CAO-like)
     * ======================= */

    /**
     * Holt Bestellungen wie im Alt-Skript (order_from/order_to[/order_status]).
     * v2-first; wenn v3 angefordert & 404 → Fallback auf v2.
     * Zusätzliche Filter:
     *  - $status        : einzelner Status (wie früher via order_status)
     *  - $q             : Textsuche (Kunde, E-Mail, Zahlart, Versand, Kommentar)
     *  - $statusInCsv   : CSV-Liste mehrerer Status (z. B. "1,163,175")
     * Default (wie kaiser): wenn KEIN status/status_in → (orders_status < 30) ODER (orders_status > 50)
     */
    public function fetchOrdersByIdRange(
        ?int $from,
        ?int $to,
        string $status = '',
        string $q = '',
        string $statusInCsv = ''
    ): array {
        if ($this->api->getApiVersion() === 'v3') {
            try {
                return $this->fetchOrdersByIdRangeV3($from, $to, $status, $q, $statusInCsv);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                    if (function_exists('cao_api_log')) {
                        cao_api_log('v3 endpoint missing, falling back to v2', $GLOBALS['config']['logFile'] ?? null);
                    }
                    return $this->fetchOrdersByIdRangeV2($from, $to, $status, $q, $statusInCsv);
                }
                throw $e;
            }
        }
        return $this->fetchOrdersByIdRangeV2($from, $to, $status, $q, $statusInCsv);
    }

    private function fetchOrdersByIdRangeV3(
        ?int $from, ?int $to, string $status = '', string $q = '', string $statusInCsv = ''
    ): array {
        $page = 1; $perPage = 100; $all = [];
        $params = ['sort' => 'id', 'page' => $page, 'per-page' => $perPage];

        $filters = [];
        if ($from) { $filters[] = 'gte|' . (int)$from; }
        if ($to)   { $filters[] = 'lte|' . (int)$to;   }
        if ($filters)       { $params['filter[id]'] = implode(',', $filters); }
        if ($status !== '') { $params['filter[statusId]'] = $status; }

        do {
            $res   = $this->api->get('orders', $params);
            $chunk = $res['data'] ?? [];
            $all   = array_merge($all, $chunk);
            $hasMore = count($chunk) === $perPage && (!$to || (int)end($chunk)['id'] < $to);
            $params['page']++;
        } while ($hasMore);

        // Default wie kaiser (nur wenn kein expliziter Status kam): statusId < 30 ODER > 50
        if ($status === '' && $statusInCsv === '') {
            if (function_exists('cao_api_log')) {
                cao_api_log('apply default status filter (<30 or >50) on v3 (client-side)', $GLOBALS['config']['logFile'] ?? null);
            }
            $all = array_values(array_filter($all, static function ($o) {
                $sid = (int)($o['statusId'] ?? ($o['status']['id'] ?? 0));
                return ($sid < 30) || ($sid > 50);
            }));
        }

        // Mehrere Status (CSV) – clientseitig whitelisten
        if ($statusInCsv !== '') {
            $whitelist = array_values(array_filter(array_map('trim', explode(',', $statusInCsv)), 'strlen'));
            if ($whitelist) {
                $all = array_values(array_filter($all, static function ($o) use ($whitelist) {
                    $sid = (string)($o['statusId'] ?? $o['status']['id'] ?? '');
                    return in_array($sid, $whitelist, true);
                }));
            }
        }

        // Textsuche (clientseitig)
        if ($q !== '') {
            $term = self::lower($q);
            $all = array_values(array_filter($all, static function ($o) use ($term) {
                $hay = [
                    $o['customerName']          ?? '',
                    $o['customerEmail']         ?? '',
                    $o['paymentType']['title']  ?? ($o['payment']['title'] ?? ''),
                    $o['paymentType']['module'] ?? ($o['payment']['code']  ?? ''),
                    self::cleanHtmlStatic($o['shippingType']['title'] ?? ($o['shipping']['title'] ?? '')),
                    $o['shippingType']['module']?? ($o['shipping']['code'] ?? ''),
                    $o['comment']               ?? ($o['comments'] ?? ''),
                ];
                $hay = self::lower(implode(' ', $hay));
                return strpos($hay, $term) !== false;
            }));
        }

        // Optional: Details für v3 nachladen (bei dir derzeit nicht aktiv)
        // $all = $this->enrichOrdersDetailsV3($all);

        return ['data' => $all];
    }

    private function fetchOrdersByIdRangeV2(
        ?int $from, ?int $to, string $status = '', string $q = '', string $statusInCsv = ''
    ): array {
        $offset = 0; 
        $limit  = 200;
        $all    = [];

        $search = [];

        // ID-Range
        if ($from !== null) { $search['geq']['orders.orders_id'] = (string)$from; }
        if ($to   !== null) { $search['leq']['orders.orders_id'] = (string)$to;   }

        // einzelner Status (wie früher)
        if ($status !== '') {
            $search['match']['orders.orders_status'] = (string)$status;
        }

        // mehrere Status via CSV
        if ($statusInCsv !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $statusInCsv)), 'strlen'));
            if ($ids) {
                $search['in']['orders.orders_status'] = $ids;
            }
        }

        // Default wie kaiser: (orders_status < 30) ODER (orders_status > 50)
        // nur wenn KEIN expliziter Status gesetzt wurde
        if ($status === '' && $statusInCsv === '') {
            $search['should'][] = ['lower'   => ['orders.orders_status' => 30]]; // <
            $search['should'][] = ['greater' => ['orders.orders_status' => 50]]; // >
            if (function_exists('cao_api_log')) {
                cao_api_log('apply default status filter (<30 or >50) on v2 (server-side)', $GLOBALS['config']['logFile'] ?? null);
            }
        }

        // Textsuche (serverseitig geht nur eingeschränkt; wir machen sie clientseitig nach dem Enrichment,
        // damit AND-Logik mit Status & Range garantiert ist)
        do {
            // Einige Builds erwarten limit/offset als Queryparams
            $res = $this->api->post('orders/search', ['search' => $search], [
                'limit'  => $limit,
                'offset' => $offset,
            ]);

            // v2 kann nacktes Array sein
            $chunk = [];
            if (is_array($res)) {
                $keys   = array_keys($res);
                $isList = $keys === range(0, count($keys) - 1);
                $chunk  = $isList ? $res : ($res['orders'] ?? $res['data'] ?? []);
            }

            $all    = array_merge($all, $chunk);
            $got    = count($chunk);
            $offset += $got;
        } while ($got === $limit);

        // Details (items + totals) nachladen
        $all = $this->enrichOrdersDetailsV2($all);

        // Textsuche (clientseitig, damit AND-Logik mit Status greift)
        if ($q !== '') {
            $term = self::lower($q);
            $all = array_values(array_filter($all, static function ($o) use ($term) {
                $hay = [
                    // v2-Felder
                    $o['customers_name']           ?? '',
                    $o['customers_email_address']  ?? '',
                    $o['payment_method']           ?? '',
                    $o['payment_class']            ?? '',
                    self::cleanHtmlStatic($o['shipping_method'] ?? ''),
                    $o['shipping_class']           ?? '',
                    $o['comments']                 ?? '',
                    // angereicherte REST-Felder
                    $o['customerName']             ?? '',
                    $o['customerEmail']            ?? '',
                    $o['paymentType']['title']     ?? ($o['payment']['title'] ?? ''),
                    $o['paymentType']['module']    ?? ($o['payment']['code']  ?? ''),
                    self::cleanHtmlStatic($o['shippingType']['title'] ?? ($o['shipping']['title'] ?? '')),
                    $o['shippingType']['module']   ?? ($o['shipping']['code'] ?? ''),
                ];
                $hay = self::lower(implode(' ', $hay));
                return strpos($hay, $term) !== false;
            }));
        }

        return ['orders' => $all];
    }

    /**
     * v2-Detailanreicherung: lädt pro Order ggf. /orders/{id}, /orders/{id}/items, /orders/{id}/totals
     */
    private function enrichOrdersDetailsV2(array $orders): array
    {
        foreach ($orders as &$o) {
            $id = (int)($o['id'] ?? $o['orders_id'] ?? 0);
            if ($id <= 0) continue;

            // schon vollständig?
            if (!empty($o['items']) && !empty($o['totals'])) continue;

            // 1) Hauptdetails
            try {
                $detail = $this->api->get('orders/' . $id);
                $data   = is_array($detail) && isset($detail['data']) ? $detail['data'] : $detail;
                if (is_array($data)) {
                    $o = array_replace_recursive($o, $data);
                }
            } catch (\Throwable $e) { /* ignorieren */ }

            // 2) Items
            if (empty($o['items'])) {
                try {
                    $items = $this->api->get('orders/' . $id . '/items');
                    $items = is_array($items) && isset($items['data']) ? $items['data'] : $items;
                    if (is_array($items)) $o['items'] = $items;
                } catch (\Throwable $e) { /* ignorieren */ }
            }

            // 3) Totals
            if (empty($o['totals'])) {
                try {
                    $tot = $this->api->get('orders/' . $id . '/totals');
                    $tot = is_array($tot) && isset($tot['data']) ? $tot['data'] : $tot;
                    if (is_array($tot)) {
                        $t = [];
                        foreach ($tot as $row) {
                            if (!is_array($row)) continue;
                            $class = $row['class'] ?? $row['code'] ?? '';
                            $val   = $row['value'] ?? $row['amount'] ?? $row['price'] ?? null;
                            if (!is_null($val)) {
                                switch ($class) {
                                    case 'ot_subtotal': $t['subtotal']      = (float)$val; break;
                                    case 'ot_shipping': $t['shippingTotal'] = (float)$val; break;
                                    case 'ot_tax':      $t['taxTotal']      = (float)$val; break;
                                    case 'ot_total':    $t['grandTotal']    = (float)$val; break;
                                }
                            }
                        }
                        if ($t) $o['totals'] = $t;
                    }
                } catch (\Throwable $e) { /* ignorieren */ }
            }
        }
        return $orders;
    }

    /* =======================
     * Orders: seit Datum
     * ======================= */

    /**
     * Holt Bestellungen seit $since (v3: GET + filter[datePurchased], v2: POST /orders/search geq auf orders.date_purchased).
     * $since: v3 kann ISO „YYYY-MM-DDTHH:MM:SS“, v2 MUSS „YYYY-MM-DD HH:MM:SS“ sein.
     */
    public function fetchOrdersSince(string $since): array
    {
        if ($this->api->getApiVersion() === 'v3') {
            try {
                $page = 1; $perPage = 100; $all = [];
                do {
                    $res = $this->api->get('orders', [
                        'filter[datePurchased]' => 'gte|' . $since,
                        'sort'                  => '-id',
                        'page'                  => $page,
                        'per-page'              => $perPage,
                    ]);
                    $chunk = $res['data'] ?? [];
                    $all   = array_merge($all, $chunk);
                    $page++;
                } while (count($chunk) === $perPage);

                // Optional: Details für v3
                // $all = $this->enrichOrdersDetailsV3($all);

                return ['data' => $all];
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                    if (function_exists('cao_api_log')) {
                        cao_api_log('v3 endpoint missing, falling back to v2 (fetchOrdersSince)', $GLOBALS['config']['logFile'] ?? null);
                    }
                    // Fallback auf v2 weiter unten
                } else {
                    throw $e;
                }
            }
        }

        // v2
        $offset = 0; $limit = 200; $all = [];
        do {
            $res = $this->api->post('orders/search', [
                'search' => ['geq' => ['orders.date_purchased' => $since]],
            ], [
                'limit'  => $limit,
                'offset' => $offset,
            ]);

            $chunk = [];
            if (is_array($res)) {
                $keys   = array_keys($res);
                $isList = $keys === range(0, count($keys) - 1);
                $chunk  = $isList ? $res : ($res['orders'] ?? $res['data'] ?? []);
            }

            $all  = array_merge($all, $chunk);
            $got  = count($chunk);
            $offset += $got;
        } while ($got === $limit);

        // Details nachladen
        $all = $this->enrichOrdersDetailsV2($all);

        return ['orders' => $all];
    }

	/* =======================
	 * Products / Artikel (v2)
	 * ======================= */

	/**
 * Holt alle Produkte paginiert (v2 GET /products) und reichert sie an
 * - Details (v2 GET /products/{id})
 * - Steuersatz (v2 GET /tax_classes/{id})
 * - Herstellername (v2 GET /manufacturers/{id})
 * - Bilder (aus Details), Kategorien (optional)
 */
public function fetchAllProductsV2(int $limit = 200): array
{
    $page = 1;
    $all  = [];

    do {
        $res = $this->api->get('products', [
            'page'     => $page,
            'per-page' => $limit,
            'limit'    => $limit,
            'offset'   => ($page - 1) * $limit,
        ]);

        $list = [];
        if (is_array($res)) {
            $list = $res['data'] ?? $res['products'] ?? (array_values($res) === $res ? $res : []);
        }

        foreach ($list as $base) {
            $p = is_array($base) ? $base : [];
            $id = (int)($p['id'] ?? $p['products_id'] ?? 0);
            if ($id <= 0) continue;

            // Details
            try {
                $det = $this->api->get('products/' . $id);
                $det = is_array($det) && isset($det['data']) ? $det['data'] : $det;
                if (is_array($det)) {
                    $p = array_replace_recursive($p, $det);
                }
            } catch (\Throwable $e) {}

            // Steuer
            if (!empty($p['taxClassId'])) {
                try {
                    $tx = $this->api->get('tax_classes/' . (int)$p['taxClassId']);
                    $tx = is_array($tx) && isset($tx['data']) ? $tx['data'] : $tx;
                    if (is_array($tx) && isset($tx['rate'])) {
                        $p['prices']['tax_rate'] = (float)$tx['rate'];
                    }
                } catch (\Throwable $e) {}
            }

            // Herstellername
            if (!empty($p['manufacturerId'])) {
                try {
                    $mf = $this->api->get('manufacturers/' . (int)$p['manufacturerId']);
                    $mf = is_array($mf) && isset($mf['data']) ? $mf['data'] : $mf;
                    if (is_array($mf) && isset($mf['name'])) {
                        $p['manufacturer'] = ['id' => $p['manufacturerId'], 'name' => $mf['name']];
                    }
                } catch (\Throwable $e) {}
            }

            // Bilder aus Details liegen unter images[]
            // Kategorien optional:
            // try { $cats = $this->api->get('products/' . $id . '/categories'); ... } catch (...) {}

            $all[] = $p;
        }

        $got = count($list);
        $page++;
    } while ($got === $limit);

    return ['data' => $all];
}


	/**
	 * Reichert eine Produktliste mit Details an:
	 *  - GET /products/{id}          (Grunddetails inkl. name/desc je Sprache)
	 *  - GET /products/{id}/prices   (Netto/Brutto/Steuerklasse)
	 *  - GET /products/{id}/stock    (Bestand)
	 *  - GET /products/{id}/images   (Bilder)
	 *  - GET /products/{id}/categories (Kategorien)
	 * Fehler werden geschluckt → niemals 500.
	 */
	public function enrichProductsV2(array $rows): array
	{
		foreach ($rows as &$p) {
			$id = (int)($p['products_id'] ?? $p['id'] ?? 0);
			if ($id <= 0) continue;

			// Basisdetails
			if (empty($p['data'])) {
				try {
					$det = $this->api->get('products/' . $id);
					$det = is_array($det) && isset($det['data']) ? $det['data'] : $det;
					if (is_array($det)) {
						$p = array_replace_recursive($p, $det);
					}
				} catch (\Throwable $e) {/* ok */}
			}

			// Preise
			if (empty($p['prices'])) {
				try {
					$pr = $this->api->get('products/' . $id . '/prices');
					$pr = is_array($pr) && isset($pr['data']) ? $pr['data'] : $pr;
					if (is_array($pr)) $p['prices'] = $pr;
				} catch (\Throwable $e) {/* ok */}
			}

			// Bestand
			if (!isset($p['stock'])) {
				try {
					$st = $this->api->get('products/' . $id . '/stock');
					$st = is_array($st) && isset($st['data']) ? $st['data'] : $st;
					if (is_array($st)) $p['stock'] = $st;
				} catch (\Throwable $e) {/* ok */}
			}

			// Bilder
			if (empty($p['images'])) {
				try {
					$im = $this->api->get('products/' . $id . '/images');
					$im = is_array($im) && isset($im['data']) ? $im['data'] : $im;
					if (is_array($im)) $p['images'] = $im;
				} catch (\Throwable $e) {/* ok */}
			}

			// Kategorien
			if (empty($p['categories'])) {
				try {
					$cats = $this->api->get('products/' . $id . '/categories');
					$cats = is_array($cats) && isset($cats['data']) ? $cats['data'] : $cats;
					if (is_array($cats)) $p['categories'] = $cats;
				} catch (\Throwable $e) {/* ok */}
			}
		}
		return $rows;
	}

	public function fetchProductsPage(int $page = 1, int $perPage = 200): array
	{
		if ($this->api->getApiVersion() === 'v3') {
			try {
				return $this->fetchProductsPageV3($page, $perPage);
			} catch (\Throwable $e) {
				if (strpos($e->getMessage(), 'HTTP 404') !== false) {
					if (function_exists('cao_api_log')) {
						cao_api_log('v3 products endpoint missing, falling back to v2', $GLOBALS['config']['logFile'] ?? null);
					}
					return $this->fetchProductsPageV2($page, $perPage);
				}
				throw $e;
			}
		}

		return $this->fetchProductsPageV2($page, $perPage);
	}

	private function fetchProductsPageV2(int $page = 1, int $perPage = 200): array
	{
		// Gambio deckelt i. d. R. auf 200
		if ($perPage > 200) { $perPage = 200; }
		if ($perPage < 1)   { $perPage = 50; }

		$res = $this->api->get('products', [
			'page'     => $page,
			'per-page' => $perPage,
			'limit'    => $perPage,                 // einige Installationen nutzen limit/offset
			'offset'   => ($page - 1) * $perPage,
		]);

		// Liste normalisieren
		$list = [];
		if (is_array($res)) {
			if (isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			} elseif (isset($res['products']) && is_array($res['products'])) {
				$list = $res['products'];
			} elseif (array_values($res) === $res) {
				$list = $res;
			}
		}

		// Optional: bereits hier Hersteller/Steuer anreichern (pro Seite)
		$list = $this->enrichProductsV2($list);

		return ['data' => $list];
	}

	private function fetchProductsPageV3(int $page = 1, int $perPage = 200): array
	{
		if ($perPage > 200) { $perPage = 200; }
		if ($perPage < 1)   { $perPage = 50; }

		$res = $this->api->get('products', [
			'page'     => $page,
			'per-page' => $perPage,
			'limit'    => $perPage,
			'offset'   => ($page - 1) * $perPage,
			'sort'     => 'id',
		]);

		$list = [];
		if (is_array($res)) {
			if (isset($res['data']) && is_array($res['data'])) {
				$list = $res['data'];
			} elseif (array_values($res) === $res) {
				$list = $res;
			}
		}

		$list = $this->enrichProductsV2($list);

		return ['data' => $list];
	}

/**
 * Holt EINE Seite Kunden (v3 GET /customers) und normalisiert auf das Kaiser-Schema.
 * Rückgabe: ['data' => [ [..kunden..], ... ]]
 */
public function fetchCustomersPageV3(int $page = 1, int $perPage = 50): array
{
    if ($perPage > 100) { $perPage = 100; }

    $res = $this->api->withVersion('v3')->get('customers', [
        'page'     => $page,
        'per-page' => $perPage,
        'limit'    => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'sort'     => 'id',
    ]);

    $list = [];
    if (is_array($res)) {
        $list = $res['data'] ?? (array_values($res) === $res ? $res : []);
    }

    $norm = [];
    foreach ($list as $c) {
        if (!is_array($c)) continue;

        // Oberste Ebene
        $cid   = $c['id'] ?? null;
        $group = $c['customerGroup'] ?? '';
        $custNo = $c['personalInformation']['customerNumber'] ?? '';

        // personalInformation
        $gender    = $c['personalInformation']['gender'] ?? '';
        $firstname = $c['personalInformation']['firstName'] ?? '';
        $lastname  = $c['personalInformation']['lastName'] ?? '';
        $dob       = $c['personalInformation']['dateOfBirth'] ?? '';

        // contactInformation
        $email     = $c['contactInformation']['email'] ?? '';
        $phone     = $c['contactInformation']['phoneNumber'] ?? '';
        $fax       = $c['contactInformation']['faxNumber'] ?? '';

        // businessInformation
        $company   = $c['businessInformation']['companyName'] ?? '';
        $vatId     = $c['businessInformation']['vatId'] ?? '';

        // account-Datum (in v3 meist "created" oder "createdAt")
        $created   = $c['created'] ?? ($c['createdAt'] ?? '');

        $norm[] = [
            'customers_id'   => $cid,
            'customers_cid'  => $custNo,
            'customers_group'=> $group,
            'customers_gender' => $gender,
            'entry_company'  => $company,
            'entry_firstname'=> $firstname,
            'entry_lastname' => $lastname,
            'entry_street_address' => '',  // nicht im JSON enthalten
            'entry_postcode' => '',
            'entry_city'     => '',
            'entry_suburb'   => '',
            'entry_state'    => '',
            'countries_iso_code_2' => '',  // Adressen fehlen → leer
            'customers_telephone'  => $phone,
            'customers_fax'        => $fax,
            'customers_email_address' => $email,
            'customers_dob'        => $dob,
            'vat_id'               => $vatId,
            'customers_info_date_account_created' => $created,
        ];
    }

    return ['data' => $norm];
}


	/**
	 * Liefert ALLE Kunden seitenweise (Iterator/Streaming im Controller verwenden!)
	 */
	public function streamAllCustomersV3(int $perPage = 50): \Generator
	{
		$page = 1;
		do {
			$res   = $this->fetchCustomersPageV3($page, $perPage);
			$items = $res['data'] ?? [];
			foreach ($items as $row) {
				yield $row;
			}
			$count = count($items);
			$page++;
		} while ($count === $perPage);
	}


/**
 * Holt EINE Seite Kategorien (v2 GET /categories) und normalisiert Felder.
 * Rückgabe: ['data' => [ ... ]]
 */
public function fetchCategoriesPage(int $page = 1, int $perPage = 200): array
{
    if ($this->api->getApiVersion() === 'v3') {
        try {
            return $this->fetchCategoriesPageV3($page, $perPage);
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                if (function_exists('cao_api_log')) {
                    cao_api_log('v3 categories endpoint missing, falling back to v2', $GLOBALS['config']['logFile'] ?? null);
                }
                return $this->fetchCategoriesPageV2($page, $perPage);
            }
            throw $e;
        }
    }

    return $this->fetchCategoriesPageV2($page, $perPage);
}

private function fetchCategoriesPageV2(int $page = 1, int $perPage = 200): array
{
    if ($perPage > 200) { $perPage = 200; }
    if ($perPage < 1)   { $perPage = 50; }

    $res = $this->api->get('categories', [
        'page'     => $page,
        'per-page' => $perPage,
        'limit'    => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'sort'     => 'id',
    ]);

    $list = [];
    if (is_array($res)) {
        if (isset($res['data']) && is_array($res['data'])) {
            $list = $res['data'];
        } elseif (array_values($res) === $res) {
            $list = $res;
        }
    }

    $norm = [];
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $norm[] = $this->normalizeCategoryV2($c);
    }

    return ['data' => $norm];
		}

private function fetchCategoriesPageV3(int $page = 1, int $perPage = 200): array
{
    if ($perPage > 200) { $perPage = 200; }
    if ($perPage < 1)   { $perPage = 50; }

    $res = $this->api->get('categories', [
        'page'     => $page,
        'per-page' => $perPage,
        'limit'    => $perPage,
        'offset'   => ($page - 1) * $perPage,
        'sort'     => 'id',
    ]);

    $list = [];
    if (is_array($res)) {
        if (isset($res['data']) && is_array($res['data'])) {
            $list = $res['data'];
        } elseif (array_values($res) === $res) {
            $list = $res;
        }
    }

    $norm = [];
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $norm[] = $this->normalizeCategoryV2($c);
    }

    return ['data' => $norm];
}

		/**
		 * Generator über ALLE Kategorien (seitenweise)
		 */
		public function streamAllCategoriesV2(int $perPage = 200): \Generator
		{
			$page = 1;
			do {
				$res   = $this->fetchCategoriesPageV2($page, $perPage);
				$chunk = $res['data'] ?? [];
				foreach ($chunk as $row) {
					yield $row;
				}
				$count = count($chunk);
				$page++;
			} while ($count === $perPage);
		}

		/**
		 * Normalisiert eine einzelne Kategorie (v2) auf Kaiser-nahe Keys.
		 */
		private function normalizeCategoryV2(array $c): array
		{
			return [
				'categories_id'     => $c['id'] ?? $c['categories_id'] ?? null,
				'parent_id'         => $c['parentId'] ?? $c['parent_id'] ?? 0,
				'sort_order'        => $c['sortOrder'] ?? $c['sort_order'] ?? 0,
				'date_added'        => $c['dateAdded'] ?? $c['date_added'] ?? '',
				'last_modified'     => $c['lastModified'] ?? $c['last_modified'] ?? '',
				// sprachlich (können Map oder String sein)
				'name'              => $c['name'] ?? null,
				'description'       => $c['description'] ?? null,
				'meta_title'        => $c['metaTitle'] ?? null,
				'meta_description'  => $c['metaDescription'] ?? null,
				'meta_keywords'     => $c['metaKeywords'] ?? null,
				'url'               => $c['url'] ?? null,
			];
		}

		/**
		 * Holt alle Kinder einer Kategorie (v2 GET /categories/{id}/children).
		 * Gibt eine normalisierte Liste zurück.
		 */
		public function fetchCategoryChildrenV2(int $parentId): array
		{
			$res = $this->api->get("categories/{$parentId}/children", []);
			$list = [];

			if (is_array($res)) {
				if (isset($res['data']) && is_array($res['data'])) {
					$list = $res['data'];
				} elseif (array_values($res) === $res) {
					$list = $res;
				}
			}

			$norm = [];
			foreach ($list as $c) {
				if (!is_array($c)) continue;
				$n = $this->normalizeCategoryV2($c);
				// Falls der Endpunkt parentId nicht mitliefert, setzen wir ihn aus Kontext
				if (!isset($n['parent_id']) || $n['parent_id'] === null || $n['parent_id'] === '') {
					$n['parent_id'] = $parentId;
				}
				$norm[] = $n;
			}

			return ['data' => $norm];
		}


    /* =======================
     * Weitere Funktionen (Minimal)
     * ======================= */

    public function getManufacturers(int $page = 1, int $perPage = 200): array
    {
        if ($this->api->getApiVersion() === 'v3') {
            try {
                return $this->getManufacturersV3($page, $perPage);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'HTTP 404') !== false) {
                    if (function_exists('cao_api_log')) {
                        cao_api_log('v3 manufacturers endpoint missing, falling back to v2', $GLOBALS['config']['logFile'] ?? null);
                    }
                    return $this->getManufacturersV2($page, $perPage);
                }
                throw $e;
            }
        }

        return $this->getManufacturersV2($page, $perPage);
    }

    private function getManufacturersV2(int $page = 1, int $perPage = 200): array
    {
        $res = $this->api->get('manufacturers', [
            'page'     => $page,
            'per-page' => $perPage,
            'limit'    => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ]);

        cao_api_log('Manufacturers API response: ' . json_encode($res), $GLOBALS['config']['logFile'] ?? null);

        if (!is_array($res)) {
            $res = is_object($res) ? json_decode(json_encode($res), true) : [];
        }

        return ['data' => $this->extractList($res, ['data','manufacturers'])];
    }

    private function getManufacturersV3(int $page = 1, int $perPage = 200): array
    {
        $res = $this->api->get('manufacturers', [
            'page'     => $page,
            'per-page' => $perPage,
            'limit'    => $perPage,
            'offset'   => ($page - 1) * $perPage,
            'sort'     => 'id',
        ]);

        if (!is_array($res)) {
            $res = is_object($res) ? json_decode(json_encode($res), true) : [];
        }

        return ['data' => $this->extractList($res, ['data','manufacturers'])];
    }

    public function enrichManufacturersV2(array $rows): array
    {
        cao_api_log('Starting enrichManufacturersV2 for ' . count($rows) . ' manufacturers', $GLOBALS['config']['logFile'] ?? null);
        foreach ($rows as &$m) {
            $id = (int)($m['manufacturers_id'] ?? $m['id'] ?? 0);
            if ($id <= 0) continue;

            try {
                $det  = $this->api->get('manufacturers/' . $id);
                $data = is_array($det) && isset($det['data']) ? $det['data'] : $det;
                if (is_array($data)) {
                    $m = array_replace_recursive($m, $data);
                }
                cao_api_log('Manufacturer detail API response for ID=' . $id . ': ' . json_encode($det), $GLOBALS['config']['logFile'] ?? null);
            } catch (\Throwable $e) {
                cao_api_log('Manufacturer detail ERROR for ID=' . $id . ': ' . $e->getMessage(), $GLOBALS['config']['logFile'] ?? null);
            }

            cao_api_log('Enriched manufacturer ID=' . $id . ': ' . json_encode($m), $GLOBALS['config']['logFile'] ?? null);
        }
        return $rows;
    }

	
	public function setOrderStatus(int $orderId, int $statusId, string $comment = '', bool $notify = false): void
    {
        if ($this->api->getApiVersion() === 'v3') {
            $this->api->patch("orders/{$orderId}", [
                'statusId'       => $statusId,
                'comment'        => $comment,
                'notifyCustomer' => $notify ? 1 : 0,
            ]);
            return;
        }

        // v2
        $this->api->patch("orders/{$orderId}/status", [
            'status_id'       => $statusId,
            'comments'        => $comment,
            'notify_customer' => $notify ? 1 : 0,
        ]);
    }

    public function addTrackingCode(int $orderId, string $code, string $carrier = ''): void
    {
        if ($this->api->getApiVersion() === 'v3') {
            $this->api->post('tracking-codes', [
                'orderId' => $orderId,
                'code'    => $code,
                'carrier' => $carrier,
            ]);
            return;
        }

        // v2
        $this->api->post("orders/{$orderId}/tracking_codes", [
            'tracking_code' => $code,
            'carrier'       => $carrier,
        ]);
    }

    public function upsertProductFromCaoXml(\SimpleXMLElement $xml): void
    {
        // TODO: Implementieren (für Bestellabruf nicht erforderlich)
        if (function_exists('cao_api_log')) {
            cao_api_log('upsertProductFromCaoXml called (not implemented yet)', $GLOBALS['config']['logFile'] ?? null);
        }
    }

    public function updateStock(string $sku, int $qty): void
    {
        // TODO: Optional implementieren
        if (function_exists('cao_api_log')) {
            cao_api_log("updateStock sku={$sku} qty={$qty} (noop)", $GLOBALS['config']['logFile'] ?? null);
        }
    }

    public function updatePrice(string $sku, float $price): void
    {
        // TODO: Optional implementieren
        if (function_exists('cao_api_log')) {
            cao_api_log("updatePrice sku={$sku} price={$price} (noop)", $GLOBALS['config']['logFile'] ?? null);
        }
    }

    /* =======================
     * (Optional) v3-Details
     * ======================= */

    private function enrichOrdersDetailsV3(array $orders): array
    {
        // Bei Bedarf analog zu v2 implementieren.
        return $orders;
    }

    /* =======================
     * Helpers
     * ======================= */

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }

    private static function cleanHtmlStatic(string $s): string
    {
        $flags = defined('ENT_HTML5') ? (ENT_QUOTES | ENT_HTML5) : ENT_QUOTES;
        return strip_tags(html_entity_decode($s, $flags, 'UTF-8'));
    }
	
	private function extractList(array $res, array $candidates = ['data','manufacturers']): array
	{
		foreach ($candidates as $k) {
			if (isset($res[$k]) && is_array($res[$k])) {
				return $res[$k];
			}
		}
		// reine Liste?
		if ($res && array_keys($res) === range(0, count($res) - 1)) {
			return $res;
		}
		// letzte Chance: andere gängige Keys
		foreach (['items','rows','result'] as $k) {
			if (isset($res[$k]) && is_array($res[$k])) {
				return $res[$k];
			}
		}
		return [];
	}

}
