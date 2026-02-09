<?php
declare(strict_types=1);

/**
 * admin/cao-faktura.php – CAO-kompatibler Entry-Point
 * - Actions (GET): version, orders_export
 * - Optional Ops (GET): get_orders_since, set_order_status, add_tracking, upsert_product, set_stock, set_price
 * Abhängigkeiten:
 *   GXModules/kip/CaoApi/bootstrap.php         -> lädt Config & Logging-Helfer
 *   GXModules/kip/CaoApi/src/GambioApiClient.php
 *   GXModules/kip/CaoApi/src/GambioServices.php
 *   GXModules/kip/CaoApi/src/CaoXmlMapper.php  -> erzeugt CAO-XML (ORDER/ORDER_INFO …)
 *   GXModules/kip/CaoApi/src/CaoStatusMapper.php (vom Mapper genutzt)
 */

// 1) Bootstrap & Config laden
$config = require __DIR__ . '/../GXModules/kip/CaoApi/bootstrap.php';
$GLOBALS['config'] = $config; // optional für Logging in Services

enforceAccessPolicy($config);

// 2) Klassen laden (ggf. Autoloader verwenden)
require_once __DIR__ . '/../GXModules/kip/CaoApi/src/GambioApiClient.php';
require_once __DIR__ . '/../GXModules/kip/CaoApi/src/GambioServices.php';
require_once __DIR__ . '/../GXModules/kip/CaoApi/src/CaoStatusMapper.php';
require_once __DIR__ . '/../GXModules/kip/CaoApi/src/CaoXmlMapper.php';

// 3) API-Version ggf. über Request übersteuern (?api=v2|v3)
$requestedApi = isset($_REQUEST['api']) ? (string)$_REQUEST['api'] : (string)($config['apiVersion'] ?? 'v2');
$apiVersion   = in_array($requestedApi, ['v2', 'v3'], true) ? $requestedApi : 'v2';

// 4) Client & Service
$client = (new GambioApiClient($config))->withVersion($apiVersion);
$svc    = new GambioServices($client);

// 5) CAO-kompatible GET-Actions behandeln
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    try {
        switch ($action) {
            case 'version':
                header('Content-Type: text/xml; charset=utf-8');
                echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                   . '<STATUS>' . "\n"
                   . '  <STATUS_DATA>' . "\n"
                   . '    <ACTION>version</ACTION>' . "\n"
                   . '    <CODE>111</CODE>' . "\n"
                   . '    <SCRIPT_VER>2.0</SCRIPT_VER>' . "\n"
                   . '    <SCRIPT_DATE>' . date('Y-m-d') . '</SCRIPT_DATE>' . "\n"
                   . '  </STATUS_DATA>' . "\n"
                   . '</STATUS>';
                exit;

			case 'orders_export':
			{
				try {
					if (function_exists('set_time_limit')) { @set_time_limit(0); }
					$from   = isset($_GET['order_from'])   ? (int)$_GET['order_from']   : 1;
					$to     = isset($_GET['order_to'])     ? (int)$_GET['order_to']     : 999999;
					$status = isset($_GET['order_status']) ? (string)$_GET['order_status'] : '';

					$res    = $svc->fetchOrdersByIdRange($from, $to, $status);
					$orders = $res['data'] ?? $res['orders'] ?? [];

					$root = CaoXmlMapper::createOrdersRoot();
					foreach ($orders as $o) {
						$node = CaoXmlMapper::orderToCaoXml(['data' => $o]);
						appendSimpleXml($root, $node);
					}

					$xml = $root->asXML();

					// Ausgabe + Datei
					if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
					header('Content-Type: text/xml; charset=utf-8');
					echo $xml;

					$logDir = dirname($config['logFile'] ?? (__DIR__.'/../GXModules/kip/CaoApi/logs/cao_api.log'));
					@mkdir($logDir, 0775, true);
					@file_put_contents($logDir.'/orders_export_'.date('Ymd_His').'.xml', $xml);

				} catch (\Throwable $e) {
					header('Content-Type: text/xml; charset=utf-8');
					echo '<ERROR>'.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_XML1, 'UTF-8').'</ERROR>';
				}
				exit;
			}


			case 'manufacturers_export':
			{
				try {
					if (function_exists('set_time_limit')) { @set_time_limit(0); }

					$page    = isset($_GET['page'])     ? max(1,(int)$_GET['page'])     : 1;
					$perPage = isset($_GET['per_page']) ? max(1,(int)$_GET['per_page']) : 200;

					$all = [];
					do {
					$res   = $svc->getManufacturers($page, $perPage); // liefert ['data'=>[…]]
						$chunk = $res['data'] ?? [];
						$all   = array_merge($all, $chunk);
						$hasMore = count($chunk) === $perPage;
						$page++;
					} while ($hasMore);

					$root = CaoManufacturerXmlMapper::createManufacturersRoot();
					foreach ($all as $m) {
						$node = CaoManufacturerXmlMapper::manufacturerToCaoXml(['data' => $m]);
						appendSimpleXml($root, $node);
					}

					$xml = $root->asXML();

					if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
					header('Content-Type: text/xml; charset=utf-8');
					echo $xml;

					$logDir = dirname($config['logFile'] ?? (__DIR__.'/../GXModules/kip/CaoApi/logs/cao_api.log'));
					@mkdir($logDir, 0775, true);
					@file_put_contents($logDir.'/manufacturers_export_'.date('Ymd_His').'.xml', $xml);

				} catch (\Throwable $e) {
					header('Content-Type: text/xml; charset=utf-8');
					echo '<ERROR>'.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_XML1, 'UTF-8').'</ERROR>';
				}
				exit;
			}


			case 'products_export':
			{
				try {
					if (function_exists('set_time_limit')) { @set_time_limit(0); }

					$page    = isset($_GET['page'])     ? max(1,(int)$_GET['page'])     : 1;
					$perPage = isset($_GET['per_page']) ? max(1,(int)$_GET['per_page']) : 50; // v2 deckelt oft auf 50

					$root = CaoProductXmlMapperClassic::createRoot();

					do {
						$res   = $svc->fetchProductsPage($page, $perPage);  // deine Service-Methode
						$chunk = $res['data'] ?? [];
						foreach ($chunk as $p) {
							$node = CaoProductXmlMapperClassic::productToCaoClassic(['data' => $p], 'de','Deutsch','2');
							appendSimpleXml($root, $node);
						}
						$count = count($chunk);
						$page++;
					} while ($count === $perPage);

					$xml = $root->asXML();

					if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
					header('Content-Type: text/xml; charset=utf-8');
					echo $xml;

					$logDir = dirname($config['logFile'] ?? (__DIR__.'/../GXModules/kip/CaoApi/logs/cao_api.log'));
					@mkdir($logDir, 0775, true);
					@file_put_contents($logDir.'/products_export_'.date('Ymd_His').'.xml', $xml);

				} catch (\Throwable $e) {
					header('Content-Type: text/xml; charset=utf-8');
					echo '<ERROR>'.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_XML1, 'UTF-8').'</ERROR>';
				}
				exit;
			}


			case 'customers_export':
			{
				try {
					if (function_exists('set_time_limit')) { @set_time_limit(0); }

					$page    = isset($_GET['page'])     ? max(1,(int)$_GET['page'])     : 1;
					$perPage = isset($_GET['per_page']) ? max(1,min(100,(int)$_GET['per_page'])) : 100; // v3 erlaubt meist bis 100

					$root = CaoCustomerXmlMapper::createRoot();

					do {
						$res   = $svc->fetchCustomersPageV3($page, $perPage);
						$chunk = $res['data'] ?? [];
						foreach ($chunk as $c) {
							$node = CaoCustomerXmlMapper::customerToCaoXml($c);
							appendSimpleXml($root, $node);
						}
						$count = count($chunk);
						$page++;
					} while ($count === $perPage);

					$xml = $root->asXML();

					if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
					header('Content-Type: text/xml; charset=utf-8');
					echo $xml;

					$logDir = dirname($config['logFile'] ?? (__DIR__.'/../GXModules/kip/CaoApi/logs/cao_api.log'));
					@mkdir($logDir, 0775, true);
					@file_put_contents($logDir.'/customers_export_'.date('Ymd_His').'.xml', $xml);

				} catch (\Throwable $e) {
					header('Content-Type: text/xml; charset=utf-8');
					echo '<ERROR>'.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_XML1, 'UTF-8').'</ERROR>';
				}
				exit;
			}

				
			case 'categories_export':
			{
				try {
					if (function_exists('set_time_limit')) { @set_time_limit(0); }

					$perPage  = isset($_GET['per_page'])   ? max(1,min(200,(int)$_GET['per_page'])) : 200;
					$pageFrom = isset($_GET['page_start']) ? max(1,(int)$_GET['page_start'])        : 1;

					$root    = CaoCategoryXmlMapperClassic::createRoot();
					$visited = [];

					$page = $pageFrom;
					do {
						$res     = $svc->fetchCategoriesPage($page, $perPage);
						$parents = $res['data'] ?? [];
						$count   = count($parents);

						// Eltern ausgeben
						foreach ($parents as $cat) {
							$id = (int)($cat['categories_id'] ?? $cat['id'] ?? 0);
							if ($id <= 0) continue;

							if (empty($visited[$id])) {
								$visited[$id] = true;
								$node = CaoCategoryXmlMapperClassic::categoryToCaoXml($cat, 'de','Deutsch','2');
								appendSimpleXml($root, $node);
							}

							// Children rekursiv (BFS)
							$queue = [$id];
							while (!empty($queue)) {
								$parentId = array_shift($queue);

								$cres = $svc->fetchCategoryChildrenV2($parentId);
								$kids = $cres['data'] ?? [];

								foreach ($kids as $child) {
									$cid = (int)($child['categories_id'] ?? $child['id'] ?? 0);
									if ($cid <= 0) continue;
									if (!empty($visited[$cid])) continue;

									$visited[$cid] = true;

									// Parent-ID sicherstellen
									if (!isset($child['parent_id']) || $child['parent_id']==='' || $child['parent_id']===null) {
										$child['parent_id'] = $parentId;
									}

									$cnode = CaoCategoryXmlMapperClassic::categoryToCaoXml($child, 'de','Deutsch','2');
									appendSimpleXml($root, $cnode);

									$queue[] = $cid; // tiefer traversieren
								}
							}
						}

						$page++;
					} while ($count === $perPage);

					$xml = $root->asXML();

					if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
					header('Content-Type: text/xml; charset=utf-8');
					echo $xml;

					$logDir = dirname($config['logFile'] ?? (__DIR__.'/../GXModules/kip/CaoApi/logs/cao_api.log'));
					@mkdir($logDir, 0775, true);
					@file_put_contents($logDir.'/categories_export_'.date('Ymd_His').'.xml', $xml);

				} catch (\Throwable $e) {
					header('Content-Type: text/xml; charset=utf-8');
					echo '<ERROR>'.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_XML1, 'UTF-8').'</ERROR>';
				}
				exit;
			}





            default:
                header('Content-Type: text/xml; charset=utf-8');
                echo '<ERROR>Unknown action</ERROR>';
                exit;
        }
    } catch (Throwable $e) {
        cao_api_log('cao-faktura action error: ' . $e->getMessage(), $config['logFile'] ?? null);
        http_response_code(500);
        header('Content-Type: text/xml; charset=utf-8');
        echo '<ERROR>' . htmlspecialchars($e->getMessage()) . '</ERROR>';
        exit;
    }
}

// 6) Optionale eigene Ops
$op       = $_REQUEST['op'] ?? '';
$xmlInput = file_get_contents('php://input');

header('Content-Type: application/xml; charset=utf-8');

try {
    switch ($op) {
        case 'get_orders_since':
            $sinceParam = $_REQUEST['since'] ?? date('Y-m-d\TH:i:s', strtotime('-1 day'));
            $sinceForApi = $sinceParam;
            if ($client->getApiVersion() === 'v2') {
                $ts = strtotime(str_replace('T', ' ', $sinceParam));
                $sinceForApi = date('Y-m-d H:i:s', $ts ?: time());
            }

            $result = $svc->fetchOrdersSince($sinceForApi);
            $orders = $result['data'] ?? $result['orders'] ?? [];

            $root = CaoXmlMapper::createOrdersRoot();
            foreach ($orders as $o) {
                $orderXml = CaoXmlMapper::orderToCaoXml(['data' => $o]);
                appendSimpleXml($root, $orderXml);
            }
            echo $root->asXML();
            break;

        case 'set_order_status':
            $orderId  = (int)($_REQUEST['order_id'] ?? 0);
            $statusId = (int)($_REQUEST['status_id'] ?? 0);
            $notify   = (bool)($_REQUEST['notify'] ?? false);
            $comment  = $_REQUEST['comment'] ?? '';
            $svc->setOrderStatus($orderId, $statusId, $comment, $notify);
            echo '<RESULT><OK/></RESULT>';
            break;

        case 'add_tracking':
            $orderId = (int)($_REQUEST['order_id'] ?? 0);
            $code    = (string)($_REQUEST['code'] ?? '');
            $carrier = (string)($_REQUEST['carrier'] ?? '');
            $svc->addTrackingCode($orderId, $code, $carrier);
            echo '<RESULT><OK/></RESULT>';
            break;

        case 'upsert_product':
            if (!$xmlInput) throw new InvalidArgumentException('Missing product XML');
            $productXml = parseXmlInput($xmlInput, $config);
            $svc->upsertProductFromCaoXml($productXml);
            $out = new SimpleXMLElement('<RESULT/>');
            $out->addChild('STATUS', 'OK');
            $out->addChild('API_VERSION', htmlspecialchars($apiVersion));
            echo $out->asXML();
            break;

        case 'set_stock':
            $sku = (string)($_REQUEST['sku'] ?? '');
            $qty = (int)($_REQUEST['qty'] ?? 0);
            $svc->updateStock($sku, $qty);
            echo '<RESULT><OK/></RESULT>';
            break;

        case 'set_price':
            $sku   = (string)($_REQUEST['sku'] ?? '');
            $price = (float)($_REQUEST['price'] ?? 0);
            $svc->updatePrice($sku, $price);
            echo '<RESULT><OK/></RESULT>';
            break;

        case '':
            echo '<?xml version="1.0" encoding="UTF-8"?><STATUS><INFO>ready</INFO></STATUS>';
            break;

        default:
            echo '<ERROR>Unknown operation</ERROR>';
    }
} catch (Throwable $e) {
    cao_api_log('cao-faktura error: ' . $e->getMessage(), $config['logFile'] ?? null);
    http_response_code(500);
    echo '<ERROR>' . htmlspecialchars($e->getMessage()) . '</ERROR>';
}

exit;

function appendSimpleXml(SimpleXMLElement $to, SimpleXMLElement $from): void {
    $toDom   = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

function enforceAccessPolicy(array $config): void
{
    $allowedIps = $config['allowedIps'] ?? [];
    if (is_array($allowedIps) && $allowedIps) {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($remoteIp, $allowedIps, true)) {
            http_response_code(403);
            header('Content-Type: text/xml; charset=utf-8');
            echo '<ERROR>Forbidden</ERROR>';
            exit;
        }
    }

    $token = $config['accessToken'] ?? null;
    if ($token) {
        $headerToken = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $headerToken = $headers['X-CAO-Token'] ?? $headers['X-Api-Key'] ?? '';
        }
        $queryToken = $_GET['token'] ?? $_POST['token'] ?? $_REQUEST['token'] ?? '';
        $provided   = $headerToken !== '' ? $headerToken : $queryToken;
        if (!hash_equals((string)$token, (string)$provided)) {
            http_response_code(401);
            header('Content-Type: text/xml; charset=utf-8');
            echo '<ERROR>Unauthorized</ERROR>';
            exit;
        }
    }
}

function parseXmlInput(string $xmlInput, array $config): SimpleXMLElement
{
    $maxBytes = (int)($config['maxXmlBytes'] ?? (2 * 1024 * 1024));
    if ($maxBytes > 0 && strlen($xmlInput) > $maxBytes) {
        throw new InvalidArgumentException('XML payload too large.');
    }

    $prev = null;
    if (function_exists('libxml_disable_entity_loader')) {
        $prev = libxml_disable_entity_loader(true);
    }
    libxml_use_internal_errors(true);
    $options = LIBXML_NONET | LIBXML_NOCDATA;
    $xml = simplexml_load_string($xmlInput, 'SimpleXMLElement', $options);
    if ($prev !== null && function_exists('libxml_disable_entity_loader')) {
        libxml_disable_entity_loader($prev);
    }
    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $message = $errors ? trim($errors[0]->message) : 'Invalid XML';
        throw new InvalidArgumentException($message);
    }
    return $xml;
}
