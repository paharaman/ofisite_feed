<?php

set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);

$baseUrl = 'https://b2b.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML_API';
$user = getenv('ALSO_USER');
$pass = getenv('ALSO_PASS');

// start from first known Philips-containing feed
$startCategory = 6;
$startGroup = 1;
$startProperty = 1;

$maxCategory = 21;
$maxGroup = 14;
$maxProperty = 27;

// safety cap for GitHub Actions runtime
$maxRequests = 200;

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
    return isset($attrs['ItemsCollected']) && (string) $attrs['ItemsCollected'] === '0';
}

function getPhilipsProductsXml(SimpleXMLElement $sx): array
{
    $products = [];

    foreach ($sx->product as $product) {
        $attrs = $product->attributes();

        $groupId = '';
        if (isset($attrs['groupId'])) {
            $groupId = trim((string)$attrs['groupId']);
        }

        $vendor = '';
        if (isset($product->vendor)) {
            $vendor = trim((string)$product->vendor);
        }

        $groupIdNormalized = strtoupper($groupId);
        $vendorNormalized = strtoupper($vendor);

        fwrite(STDERR, "DEBUG groupId=[{$groupId}] vendor=[{$vendor}]\n");

        if ($groupIdNormalized === 'PHILIPS' || $vendorNormalized === 'PHILIPS') {
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
logLine("Starting from X" . sprintf('%02d%03d%03d', $startCategory, $startGroup, $startProperty));

for ($c = $startCategory; $c <= $maxCategory; $c++) {
    $categoryHadAnyValidFeed = false;
    logLine("Category {$c} start");

    $groupStart = ($c === $startCategory) ? $startGroup : 1;

    for ($g = $groupStart; $g <= $maxGroup; $g++) {
        $groupHadAnyValidFeed = false;
        logLine("  Group {$g} start");

        $propertyStart = ($c === $startCategory && $g === $startGroup) ? $startProperty : 1;

        for ($p = $propertyStart; $p <= $maxProperty; $p++) {
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

$docsDir = __DIR__ . '/../docs';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0777, true);
}

file_put_contents($docsDir . '/feed.xml', $output);

logLine("DONE");
logLine("Requests: {$totalRequests}");
logLine("Valid feeds: {$totalValidFeeds}");
logLine("Empty feeds: {$totalEmptyFeeds}");
logLine("Missing feeds: {$totalMissingFeeds}");
logLine("Products: {$totalProducts}");
