<?php

set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);

$baseUrl = 'https://b2b.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML_API';
$user = getenv('ALSO_USER');
$pass = getenv('ALSO_PASS');

logLine("SCRIPT VERSION 3");

// start from first known useful range
$startCategory = 6;
$startGroup = 1;
$startProperty = 1;

$maxCategory = 21;
$maxGroup = 14;
$maxProperty = 27;

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

function getItemsCollectedFromXml(string $xml): int
{
    if (preg_match('/ItemsCollected="(\d+)"/i', $xml, $m)) {
        return (int)$m[1];
    }
    return -1;
}

function getPhilipsProductsXml(string $xml): array
{
    $products = [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);

    $loaded = $dom->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET);

    if (!$loaded) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        fwrite(STDERR, "DEBUG DOM loadXML failed\n");
        return [];
    }

    libxml_clear_errors();

    $nodes = $dom->getElementsByTagName('product');
    fwrite(STDERR, "DEBUG DOM parsed products: " . $nodes->length . "\n");

    $i = 0;
    foreach ($nodes as $node) {
        /** @var DOMElement $node */
        $groupId = strtoupper(trim($node->getAttribute('groupId')));

        $vendor = '';
        $vendorNodes = $node->getElementsByTagName('vendor');
        if ($vendorNodes->length > 0) {
            $vendor = strtoupper(trim($vendorNodes->item(0)->textContent));
        }

        if ($i < 5) {
            fwrite(STDERR, "DEBUG product {$i}: groupId=[{$groupId}] vendor=[{$vendor}]\n");
        }
        $i++;

        if ($groupId === 'PHILIPS' || $vendor === 'PHILIPS') {
            $products[] = $dom->saveXML($node);
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

            if ($xml !== null) {
                $preview = substr($xml, 0, 500);
                $preview = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $preview);
                logLine("{$propertyId} -> RAW PREVIEW: {$preview}");
                logLine("{$propertyId} -> RAW LENGTH: " . strlen($xml));
            }

            if ($xml === null || $xml === '') {
                logLine("    {$propertyId} -> null/empty response");
                continue;
            }

            if (isMissingFeed($xml)) {
                $totalMissingFeeds++;
                logLine("    {$propertyId} -> missing feed, break property loop");
                break;
            }

            $itemsCollected = getItemsCollectedFromXml($xml);
            if ($itemsCollected === 0) {
                $totalEmptyFeeds++;
                logLine("    {$propertyId} -> empty feed");
                continue;
            }

            if ($itemsCollected < 0) {
                logLine("    {$propertyId} -> could not read ItemsCollected");
            }

            $groupHadAnyValidFeed = true;
            $categoryHadAnyValidFeed = true;
            $totalValidFeeds++;

            $philipsProducts = getPhilipsProductsXml($xml);
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
