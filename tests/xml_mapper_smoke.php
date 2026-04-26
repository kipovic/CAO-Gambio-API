<?php
declare(strict_types=1);

require_once __DIR__ . '/../kip/CaoApi/bootstrap.php';

function assertSameValue(string $label, string $expected, string $actual): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual:   %s\n", $label, $expected, $actual));
        exit(1);
    }
}

function assertXmlValue(string $label, SimpleXMLElement $xml, string $xpath, string $expected): void
{
    $nodes = $xml->xpath($xpath);
    if (!$nodes || !isset($nodes[0])) {
        fwrite(STDERR, sprintf("FAIL: %s\nMissing XPath: %s\nXML:\n%s\n", $label, $xpath, $xml->asXML()));
        exit(1);
    }

    assertSameValue($label, $expected, (string)$nodes[0]);
}

assertSameValue(
    'PayPal open order maps to CAO status 15',
    '15',
    CaoStatusMapper::map(['orders_status' => 1, 'payment' => ['code' => 'paypal']])
);
assertSameValue(
    'Unknown order status falls back to registered status 20',
    '20',
    CaoStatusMapper::map(['status' => ['id' => 999], 'paymentClass' => 'unknown'])
);

if (!class_exists(SimpleXMLElement::class)) {
    echo "OK: status mapper smoke; XML mapper checks skipped because ext-simplexml is missing\n";
    exit(0);
}

$product = CaoProductXmlMapperClassic::productToCaoClassic([
    'id' => 42,
    'quantity' => 7,
    'productModel' => 'ABC-123',
    'price' => '12.5',
    'taxClassId' => 1,
    'tax_rate' => '19.0',
    'weight' => '1.25',
    'isActive' => true,
    'manufacturerId' => 9,
    'dateAdded' => '2026-04-26T12:34:56+02:00',
    'name' => ['de' => 'Testprodukt'],
    'description' => ['de' => 'Beschreibung'],
    'shortDescription' => ['de' => 'Kurz'],
    'images' => [['filename' => 'produkt.jpg']],
]);
assertXmlValue('Product ID', $product, '/PRODUCT_INFO/PRODUCT_DATA/PRODUCT_ID', '42');
assertXmlValue('Product model', $product, '/PRODUCT_INFO/PRODUCT_DATA/PRODUCT_MODEL', 'ABC-123');
assertXmlValue('Product price', $product, '/PRODUCT_INFO/PRODUCT_DATA/PRODUCT_PRICE', '12.5000');
assertXmlValue('Product name', $product, '/PRODUCT_INFO/PRODUCT_DATA/PRODUCT_DESCRIPTION/NAME', 'Testprodukt');

$customer = CaoCustomerXmlMapper::customerToCaoXml([
    'customers_id' => 5,
    'entry_firstname' => 'Max',
    'entry_lastname' => 'Mustermann',
    'customers_email_address' => 'max@example.org',
    'customers_info_date_account_created' => '2026-04-26',
]);
assertXmlValue('Customer email', $customer, '/CUSTOMERS_DATA/EMAIL', 'max@example.org');
assertXmlValue('Customer created date', $customer, '/CUSTOMERS_DATA/DATE_ACCOUNT_CREATED', '2026-04-26 00:00:00');

$manufacturer = CaoManufacturerXmlMapper::manufacturerToCaoXml([
    'id' => 12,
    'name' => 'Widmann',
    'image' => 'widmann.png',
    'urls' => ['DE' => 'https://mv-widmann.de'],
]);
assertXmlValue('Manufacturer name', $manufacturer, '/MANUFACTURERS_DATA/NAME', 'Widmann');
assertXmlValue('Manufacturer URL', $manufacturer, '/MANUFACTURERS_DATA/MANUFACTURERS_DESCRIPTION/URL', 'https://mv-widmann.de');

$category = CaoCategoryXmlMapperClassic::categoryToCaoXml([
    'id' => 3,
    'parentId' => 0,
    'sortOrder' => 10,
    'name' => ['de' => 'Schule'],
    'description' => ['de' => 'Schulausstattung'],
]);
assertXmlValue('Category ID', $category, '/CATEGORIES_DATA/ID', '3');
assertXmlValue('Category name', $category, '/CATEGORIES_DATA/CATEGORIES_DESCRIPTION/NAME', 'Schule');

echo "OK: XML mapper smoke\n";
