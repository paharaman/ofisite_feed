<?php

set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);

$baseUrl = 'https://b2b.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML_API';
$user = getenv('ALSO_USER');
$pass = getenv('ALSO_PASS');

$maxCategory = 21;
$maxGroup = 14;
$maxProperty = 27;

// safety limit, за да не виси безкрайно
$maxRequests = 1200;

// ако искаш за тест, намали на 200
// $maxRequests = 200;

if (!$user || !$pass) {
    fwrite(STDERR, "Missing ALSO_USER or ALSO_PASS environment variables\n");
    exit(1);
}

function logLine(string $message): void
{
    $time = date('H:i:s');
    fwrite(STDERR, "[{$time}] {$message}\n");
}

function fetchXml(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Philips-Aggregator/1.0',
        CURLOPT_HTTPGET => true,
    ]);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        logLine("cURL error {$errno}: {$error}");
        return null;
    }

    if ($httpCode !== 200) {
        logLine("HTTP {$httpCode}");
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
$totalRequests = 0;
$totalValidFeeds = 0;
$totalEmptyFeeds = 0;
$totalMissingFeeds = 0;

logLine("START");

for ($c = 1; $c <= $maxCategory; $c++) {
    $categoryHadAnyValidFeed = false;
    logLine("Category {$c} start");

    for ($g = 1; $g <= $maxGroup; $g++) {
        $groupHadAnyValidFeed = false;
        logLine("  Group {$g} start");

        for ($p = 1; $p <= $maxProperty; $p++) {
            if ($totalRequests >= $maxRequests) {
                logLine("Reached maxRequests={$maxRequests}, stopping");
                break 3;
            }

            $propertyId = sprintf('X%02d%03d%03d', $c, $g, $p);

            $url = $baseUrl
                . '?j_u=' . urlencode($user)
                . '&j_p=' . urlencode($pass)
                . '&propertyId=' . urlencode($propertyId);

            $totalRequests++;
            logLine("    Request {$totalRequests}: {$propertyId}");

            $xml = fetchXml($url);

            if ($xml === null || $xml === '') {
                logLine("    {$propertyId} -> null/empty response");
                continue;
            }

            if (isMissingFeed($xml)) {
                $totalMissingFeeds++;
                logLine("    {$propertyId} -> missing feed, break property loop");
                break;
            }

            libxml_use_internal_errors(true);
            $sx = simplexml_load_string($xml);
            $parseErrors = libxml_get_errors();
            libxml_clear_errors();

            if ($sx === false) {
                logLine("    {$propertyId} -> invalid XML, continue");
                continue;
            }

            if (isEmptyFeed($sx)) {
                $totalEmptyFeeds++;
                logLine("    {$propertyId} -> empty feed");
                continue;
            }

            $groupHadAnyValidFeed = true;
            $categoryHadAnyValidFeed = true;
            $totalValidFeeds++;

            $philipsProducts = getPhilipsProductsXml($sx);
            $count = count($philipsProducts);

            logLine("    {$propertyId} -> valid feed, Philips products: {$count}");

            foreach ($philipsProducts as $productXml) {
                $productXmlList[] = $productXml;
                $totalProducts++;
            }
        }

        if (!$groupHadAnyValidFeed) {
            logLine("  Group {$g} had no valid non-empty feeds -> break group loop");
            break;
        }
    }

    if (!$categoryHadAnyValidFeed) {
        logLine("Category {$c} had no valid non-empty feeds -> break category loop");
        break;
    }
}

$output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$output .= "<productSet version=\"1.7\" ItemsCollected=\"{$totalProducts}\">\n";
$output .= implode("\n", $productXmlList);
$output .= "\n</productSet>\n";

if (!is_dir(__DIR__ . '/../docs')) {
    mkdir(__DIR__ . '/../docs', 0777, true);
}

file_put_contents(__DIR__ . '/../docs/feed.xml', $output);

logLine("DONE");
logLine("Requests: {$totalRequests}");
logLine("Valid feeds: {$totalValidFeeds}");
logLine("Empty feeds: {$totalEmptyFeeds}");
logLine("Missing feeds: {$totalMissingFeeds}");
logLine("Products: {$totalProducts}");
