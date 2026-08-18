<?php

set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
ob_implicit_flush(true);

$baseUrl = 'https://b2b.also.com/invoke/ActDelivery_HTTP.Inbound/receiveXML_API';

$user = getenv('ALSO_USER');
$pass = getenv('ALSO_PASS');

if (!$user || !$pass) {
    fwrite(STDERR, "Missing ALSO_USER or ALSO_PASS environment variables\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| INDEX URL
|--------------------------------------------------------------------------
|
| Това е индексният endpoint, който вече използваме.
|
| CatalogGroupId=PHILIPS тук НЕ го приемаме като brand filter.
| Просто запазваме известния работещ индексен URL.
|
*/

$indexUrl = $baseUrl
    . '?CatalogCategory=true'
    . '&CatalogGroupId=PHILIPS'
    . '&j_u=' . urlencode($user)
    . '&j_p=' . urlencode($pass);


/*
|--------------------------------------------------------------------------
| TARGET RULES
|--------------------------------------------------------------------------
|
| target:
|   Името, което търсим в XML индекса.
|
| entity_brands:
|   Стойности, които търсим в groupId и vendor.
|
| name_brands:
|   Стойности, които търсим в <name>.
|
| Важно:
| TP Vision / MMD продуктите често се продават с PHILIPS в името.
|
*/

$targetRules = [

    'Телевизори' => [
        'entity_brands' => [
            'TP VISION',
            'TCL',
        ],
        'name_brands' => [
            'PHILIPS',
            'TP VISION',
            'TCL',
        ],
    ],

    'Консюмър и гейминг слушалки' => [
        'entity_brands' => [
            'TP VISION',
        ],
        'name_brands' => [
            'PHILIPS',
            'TP VISION',
        ],
    ],

    'Бизнес монитори' => [
        'entity_brands' => [
            'MMD',
            'AOC',
        ],
        'name_brands' => [
            'PHILIPS',
            'MMD',
            'AOC',
        ],
    ],

    'Консюмър и гейминг монитори' => [
        'entity_brands' => [
            'MMD',
            'AOC',
        ],
        'name_brands' => [
            'PHILIPS',
            'MMD',
            'AOC',
        ],
    ],

    'Уреди за лична грижа' => [
        'entity_brands' => [
            'PHILIPS',
        ],
        'name_brands' => [
            'PHILIPS',
        ],
    ],

    'Уреди за дома' => [
        'entity_brands' => [
            'PHILIPS',
        ],
        'name_brands' => [
            'PHILIPS',
        ],
    ],

];


/*
|--------------------------------------------------------------------------
| LOG
|--------------------------------------------------------------------------
*/

function logLine(string $message): void
{
    $time = date('H:i:s');
    fwrite(STDERR, "[{$time}] {$message}\n");
}


/*
|--------------------------------------------------------------------------
| HTTP
|--------------------------------------------------------------------------
*/

function fetchXml(string $url): ?string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT =>
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/122.0 Safari/537.36',

        CURLOPT_HTTPGET => true,
        CURLOPT_ENCODING => '',

        CURLOPT_HTTPHEADER => [
            'Accept: application/xml,text/xml,*/*',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
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


/*
|--------------------------------------------------------------------------
| BASIC XML CHECKS
|--------------------------------------------------------------------------
*/

function isLoginError(string $xml): bool
{
    return stripos(
        $xml,
        'Login error. Please check provided login'
    ) !== false;
}

function isMissingFeed(string $xml): bool
{
    return stripos(
        $xml,
        "Can't find any materials for propertyId"
    ) !== false;
}

function getItemsCollectedFromXml(string $xml): int
{
    if (preg_match('/ItemsCollected="(\d+)"/i', $xml, $m)) {
        return (int) $m[1];
    }

    return -1;
}


/*
|--------------------------------------------------------------------------
| TEXT NORMALIZATION
|--------------------------------------------------------------------------
|
| TP_VISION
| TP-VISION
| TP VISION
|
| стават:
|
| TPVISION
|
*/

function normalizeBrand(string $value): string
{
    $value = strtoupper(trim($value));

    return preg_replace(
        '/[^A-Z0-9]+/',
        '',
        $value
    );
}


/*
|--------------------------------------------------------------------------
| NORMALIZE CATEGORY/GROUP LABEL
|--------------------------------------------------------------------------
*/

function normalizeLabel(string $value): string
{
    $value = trim($value);

    // collapse whitespace
    $value = preg_replace('/\s+/u', ' ', $value);

    return mb_strtolower($value, 'UTF-8');
}


/*
|--------------------------------------------------------------------------
| FIND TARGET FEEDS FROM INDEX
|--------------------------------------------------------------------------
|
| Връща:
|
| [
|   URL => [
|       'targets' => [...],
|       'entity_brands' => [...],
|       'name_brands' => [...]
|   ]
| ]
|
*/

function getTargetFeedsFromIndex(
    string $xml,
    array $targetRules
): array {

    $dom = new DOMDocument();

    libxml_use_internal_errors(true);

    $loaded = $dom->loadXML(
        $xml,
        LIBXML_NOCDATA | LIBXML_NONET
    );

    libxml_clear_errors();

    if (!$loaded) {
        logLine("Could not parse index XML");
        return [];
    }

    /*
     * Нормализираме target имената предварително.
     */

    $normalizedRules = [];

    foreach ($targetRules as $target => $rule) {
        $normalizedRules[normalizeLabel($target)] = [
            'original_name' => $target,
            'entity_brands' => $rule['entity_brands'],
            'name_brands' => $rule['name_brands'],
        ];
    }

    $feedMap = [];

    /*
     * Минаваме по всеки productGroup.
     */

    $productGroups = $dom->getElementsByTagName('productGroup');

    foreach ($productGroups as $productGroup) {

        if (!($productGroup instanceof DOMElement)) {
            continue;
        }

        $groupName = $productGroup->getAttribute('name');
        $normalizedGroupName = normalizeLabel($groupName);

        /*
         * В XML структурата имаме:
         *
         * <propertyGroupId>...</propertyGroupId>
         * <atom:link href="..." rel="list"/>
         *
         * затова пазим последния propertyGroupId.
         */

        $currentPropertyName = null;

        foreach ($productGroup->childNodes as $child) {

            if (!($child instanceof DOMElement)) {
                continue;
            }

            /*
             * propertyGroupId
             */

            if ($child->localName === 'propertyGroupId') {

                $currentPropertyName = trim(
                    $child->textContent
                );

                continue;
            }

            /*
             * atom:link
             */

            if ($child->localName !== 'link') {
                continue;
            }

            if ($child->getAttribute('rel') !== 'list') {
                continue;
            }

            $href = trim(
                $child->getAttribute('href')
            );

            if ($href === '') {
                continue;
            }

            /*
             * Проверяваме дали target rule съвпада:
             *
             * 1. с propertyGroupId
             * ИЛИ
             * 2. с productGroup name
             *
             * Това ни прави по-гъвкави при
             * "Уреди за дома" / "Уреди за лична грижа".
             */

            $normalizedPropertyName = $currentPropertyName !== null
                ? normalizeLabel($currentPropertyName)
                : '';

            $matchedRule = null;

            if (
                isset(
                    $normalizedRules[
                        $normalizedPropertyName
                    ]
                )
            ) {
                $matchedRule =
                    $normalizedRules[
                        $normalizedPropertyName
                    ];

            } elseif (
                isset(
                    $normalizedRules[
                        $normalizedGroupName
                    ]
                )
            ) {
                $matchedRule =
                    $normalizedRules[
                        $normalizedGroupName
                    ];
            }

            if ($matchedRule === null) {
                continue;
            }

            /*
             * Ако един URL попадне в повече от едно правило,
             * обединяваме brand правилата.
             */

            if (!isset($feedMap[$href])) {
                $feedMap[$href] = [
                    'targets' => [],
                    'entity_brands' => [],
                    'name_brands' => [],
                ];
            }

            $feedMap[$href]['targets'][] =
                $matchedRule['original_name'];

            foreach (
                $matchedRule['entity_brands']
                as $brand
            ) {
                $feedMap[$href]['entity_brands'][] =
                    $brand;
            }

            foreach (
                $matchedRule['name_brands']
                as $brand
            ) {
                $feedMap[$href]['name_brands'][] =
                    $brand;
            }
        }
    }

    /*
     * Премахваме дублирани brand rules.
     */

    foreach ($feedMap as &$feed) {

        $feed['targets'] = array_values(
            array_unique(
                $feed['targets']
            )
        );

        $feed['entity_brands'] = array_values(
            array_unique(
                $feed['entity_brands']
            )
        );

        $feed['name_brands'] = array_values(
            array_unique(
                $feed['name_brands']
            )
        );
    }

    unset($feed);

    return $feedMap;
}

/*
|--------------------------------------------------------------------------
| PRODUCT FILTER
|--------------------------------------------------------------------------
|
| Проверяваме:
|
| groupId
| vendor
| name
|
*/

function getMatchingProductsXml(
    string $xml,
    array $entityBrands,
    array $nameBrands
): array {

    $products = [];

    $dom = new DOMDocument();

    libxml_use_internal_errors(true);

    $loaded = $dom->loadXML(
        $xml,
        LIBXML_NOCDATA | LIBXML_NONET
    );

    libxml_clear_errors();

    if (!$loaded) {
        return [];
    }

    /*
     * Нормализираме allowed brands.
     */

    $normalizedEntityBrands = [];

    foreach ($entityBrands as $brand) {
        $normalizedEntityBrands[] =
            normalizeBrand($brand);
    }

    $normalizedNameBrands = [];

    foreach ($nameBrands as $brand) {
        $normalizedNameBrands[] =
            normalizeBrand($brand);
    }

    $nodes = $dom->getElementsByTagName('product');

    foreach ($nodes as $node) {

        if (!($node instanceof DOMElement)) {
            continue;
        }

        /*
         * groupId
         */

        $groupId = normalizeBrand(
            $node->getAttribute('groupId')
        );

        /*
         * vendor
         */

        $vendor = '';

        $vendorNodes =
            $node->getElementsByTagName('vendor');

        if ($vendorNodes->length > 0) {
            $vendor = normalizeBrand(
                $vendorNodes
                    ->item(0)
                    ->textContent
            );
        }

        /*
         * name
         */

        $name = '';

        $nameNodes =
            $node->getElementsByTagName('name');

        if ($nameNodes->length > 0) {
            $name = normalizeBrand(
                $nameNodes
                    ->item(0)
                    ->textContent
            );
        }

        $matched = false;

        /*
         * Match groupId/vendor
         */

        foreach (
            $normalizedEntityBrands
            as $brand
        ) {

            if (
                $groupId === $brand ||
                $vendor === $brand
            ) {
                $matched = true;
                break;
            }
        }

        /*
         * Ако няма match,
         * проверяваме product name.
         */

        if (!$matched) {

            foreach (
                $normalizedNameBrands
                as $brand
            ) {

                if (
                    $brand !== '' &&
                    strpos($name, $brand) !== false
                ) {
                    $matched = true;
                    break;
                }
            }
        }

        if ($matched) {
            $products[] =
                $dom->saveXML($node);
        }
    }

    return $products;
}


/*
|--------------------------------------------------------------------------
| START
|--------------------------------------------------------------------------
*/

logLine("START");
logLine("Loading catalog index...");

$indexXml = fetchXml($indexUrl);

if (
    $indexXml === null ||
    $indexXml === ''
) {
    logLine("Could not load catalog index");
    exit(1);
}

if (isLoginError($indexXml)) {
    logLine("LOGIN ERROR while loading index");
    exit(1);
}


/*
|--------------------------------------------------------------------------
| BUILD TARGET FEED LIST
|--------------------------------------------------------------------------
*/

$feeds = getTargetFeedsFromIndex(
    $indexXml,
    $targetRules
);

if (count($feeds) === 0) {
    logLine(
        "ERROR: no target feeds found in catalog index"
    );

    /*
     * Много важно:
     *
     * Не генерираме празен feed при проблем
     * с индекса.
     */

    exit(1);
}

logLine(
    "Target feeds found: "
    . count($feeds)
);


/*
|--------------------------------------------------------------------------
| PROCESS TARGET FEEDS
|--------------------------------------------------------------------------
*/

$productXmlList = [];

$totalProducts = 0;
$totalRequests = 0;
$totalValidFeeds = 0;
$totalEmptyFeeds = 0;
$totalFailedFeeds = 0;

foreach ($feeds as $url => $feedRule) {

    $totalRequests++;

    $targets = implode(
        ', ',
        $feedRule['targets']
    );

    logLine(
        "Request {$totalRequests}: "
        . $targets
    );

    $xml = fetchXml($url);

    if (
        $xml === null ||
        $xml === ''
    ) {
        $totalFailedFeeds++;

        logLine(
            "    -> null/empty response"
        );

        continue;
    }

    if (isLoginError($xml)) {
        logLine(
            "    -> LOGIN ERROR, stopping"
        );

        exit(1);
    }

    if (isMissingFeed($xml)) {
        $totalFailedFeeds++;

        logLine(
            "    -> feed no longer exists"
        );

        continue;
    }

    $itemsCollected =
        getItemsCollectedFromXml($xml);

    if ($itemsCollected === 0) {

        $totalEmptyFeeds++;

        logLine(
            "    -> empty feed"
        );

        continue;
    }

    if ($itemsCollected < 0) {

        $totalFailedFeeds++;

        logLine(
            "    -> could not read ItemsCollected"
        );

        continue;
    }

    $totalValidFeeds++;

    $matchedProducts =
        getMatchingProductsXml(
            $xml,
            $feedRule['entity_brands'],
            $feedRule['name_brands']
        );

    $count = count(
        $matchedProducts
    );

    logLine(
        "    -> ItemsCollected={$itemsCollected}, "
        . "matched products={$count}"
    );

    foreach (
        $matchedProducts
        as $productXml
    ) {
        $productXmlList[] =
            $productXml;

        $totalProducts++;
    }
}


/*
|--------------------------------------------------------------------------
| SAFETY CHECK
|--------------------------------------------------------------------------
|
| Ако не намерим НИТО един продукт,
| не искаме случайно да заменим
| работещия feed с празен.
|
*/

if ($totalProducts === 0) {

    logLine(
        "ERROR: 0 matching products found. "
        . "feed.xml will NOT be overwritten."
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| CREATE OUTPUT XML
|--------------------------------------------------------------------------
*/

$output =
    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";

$output .=
    "<productSet version=\"1.7\" "
    . "ItemsCollected=\"{$totalProducts}\">\n";

$output .= implode(
    "\n",
    $productXmlList
);

$output .=
    "\n</productSet>\n";


/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/

$docsDir =
    __DIR__ . '/../docs';

if (!is_dir($docsDir)) {

    mkdir(
        $docsDir,
        0777,
        true
    );
}

$targetFile =
    $docsDir . '/feed.xml';

$result = file_put_contents(
    $targetFile,
    $output
);

if ($result === false) {
    logLine(
        "ERROR: could not write feed.xml"
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

logLine("DONE");

logLine(
    "Index target feeds: "
    . count($feeds)
);

logLine(
    "Feed requests: {$totalRequests}"
);

logLine(
    "Valid feeds: {$totalValidFeeds}"
);

logLine(
    "Empty feeds: {$totalEmptyFeeds}"
);

logLine(
    "Failed feeds: {$totalFailedFeeds}"
);

logLine(
    "Products: {$totalProducts}"
);
