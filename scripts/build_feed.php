<?php

set_time_limit(0);

$baseUrl = 'https://b2b.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML_API';
$user = getenv('ALSO_USER');
$pass = getenv('ALSO_PASS');

$maxCategory = 21;
$maxGroup = 14;
$maxProperty = 27;

function fetchXml(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    return trim($response);
}

function isMissingFeed(string $xml): bool
{
    return stripos($xml, "<error>Can't find any materials for propertyId") !== false;
}

function isEmptyFeed(SimpleXMLElement $sx): bool
{
    $attrs = $sx->attributes();
    return isset($attrs['ItemsCollected']) && (string)$attrs['ItemsCollected'] === '0';
}

function getPhilipsProductsXml(SimpleXMLElement $sx): array
{
    $products = [];

    foreach ($sx->product as $product) {
        $attrs = $product->attributes();
        $groupId = isset($attrs['groupId']) ? trim((string)$attrs['groupId']) : '';

        if (strcasecmp($groupId, 'Philips') === 0) {
            $products[] = $product->asXML();
        }
    }

    return $products;
}

$productXmlList = [];
$totalProducts = 0;

for ($c = 1; $c <= $maxCategory; $c++) {
    $categoryHadAnyValidFeed = false;

    for ($g = 1; $g <= $maxGroup; $g++) {
        $groupHadAnyValidFeed = false;

        for ($p = 1; $p <= $maxProperty; $p++) {
            $propertyId = sprintf('X%02d%03d%03d', $c, $g, $p);

            $url = $baseUrl
                . '?j_u=' . urlencode($user)
                . '&j_p=' . urlencode($pass)
                . '&propertyId=' . urlencode($propertyId);

            $xml = fetchXml($url);

            if ($xml === null || $xml === '') {
                continue;
            }

            if (isMissingFeed($xml)) {
                break;
            }

            libxml_use_internal_errors(true);
            $sx = simplexml_load_string($xml);
            libxml_clear_errors();

            if ($sx === false) {
                continue;
            }

            if (isEmptyFeed($sx)) {
                continue;
            }

            $groupHadAnyValidFeed = true;
            $categoryHadAnyValidFeed = true;

            foreach (getPhilipsProductsXml($sx) as $productXml) {
                $productXmlList[] = $productXml;
                $totalProducts++;
            }

            usleep(150000);
        }

        if (!$groupHadAnyValidFeed) {
            break;
        }
    }

    if (!$categoryHadAnyValidFeed) {
        break;
    }
}

$output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$output .= "<productSet version=\"1.7\" ItemsCollected=\"$totalProducts\">\n";
$output .= implode("\n", $productXmlList);
$output .= "\n</productSet>\n";

file_put_contents(__DIR__ . '/../docs/feed.xml', $output);
