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
| Използваме познатия индексен XML.
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
| Структура:
|
| productCategory
|   -> productGroup
|       -> propertyGroupId
|           -> brand rules
|
| '*' означава "всички productGroup/propertyGroupId в тази productCategory".
|
| entity_brands:
|   търсим в groupId и vendor
|
| name_brands:
|   търсим в името на продукта
|
*/

$targetRules = [

    'Аудио, Видео, Дисплеи и Телевизори' => [

        'Телевизори' => [

            '*' => [
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

        ],

        'Слушалки и Микрофони' => [

            'Консюмър и гейминг слушалки' => [
                'entity_brands' => [
                    'TP VISION',
                ],
                'name_brands' => [
                    'PHILIPS',
                    'TP VISION',
                ],
            ],

        ],

        'Дисплеи' => [

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

        ],

    ],

    /*
     * За тези две productCategory вземаме всички реални feed-ове
     * от категорията и вътре търсим Philips.
     */

    'Уреди за личнa грижa' => [

        '*' => [

            '*' => [
                'entity_brands' => [
                    'PHILIPS',
                ],
                'name_brands' => [
                    'PHILIPS',
                ],
            ],

        ],

    ],

    'Уреди за дома' => [

        '*' => [

            '*' => [
                'entity_brands' => [
                    'PHILIPS',
                ],
                'name_brands' => [
                    'PHILIPS',
                ],
            ],

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
| XML RESPONSE CHECKS
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
| NORMALIZATION
|--------------------------------------------------------------------------
*/

function normalizeLabel(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);

    return mb_strtolower($value, 'UTF-8');
}


/*
 * TP_VISION
 * TP-VISION
 * TP VISION
 *
 * стават TPVISION
 */

function normalizeBrand(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');

    return preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
}


/*
|--------------------------------------------------------------------------
| RULE LOOKUP
|--------------------------------------------------------------------------
*/

function getRuleForPath(
    array $targetRules,
    string $categoryName,
    string $groupName,
    string $propertyName
): ?array {

    $normalizedCategory = normalizeLabel($categoryName);
    $normalizedGroup = normalizeLabel($groupName);
    $normalizedProperty = normalizeLabel($propertyName);

    /*
     * Търсим category.
     */

    $categoryRule = null;

    foreach ($targetRules as $category => $rule) {
        if (normalizeLabel($category) === $normalizedCategory) {
            $categoryRule = $rule;
            break;
        }
    }

    if ($categoryRule === null) {
        return null;
    }

    /*
     * Първо търсим точен productGroup.
     */

    $groupRule = null;

    foreach ($categoryRule as $group => $rule) {

        if ($group === '*') {
            continue;
        }

        if (normalizeLabel($group) === $normalizedGroup) {
            $groupRule = $rule;
            break;
        }
    }

    /*
     * Ако няма точен group, използваме wildcard.
     */

    if ($groupRule === null && isset($categoryRule['*'])) {
        $groupRule = $categoryRule['*'];
    }

    if ($groupRule === null) {
        return null;
    }

    /*
     * Търсим точен propertyGroupId.
     */

    foreach ($groupRule as $property => $rule) {

        if ($property === '*') {
            continue;
        }

        if (normalizeLabel($property) === $normalizedProperty) {
            return $rule;
        }
    }

    /*
     * Wildcard propertyGroupId.
     */

    if (isset($groupRule['*'])) {
        return $groupRule['*'];
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| READ INDEX
|--------------------------------------------------------------------------
|
| Връща само реалните feed URL-и, които отговарят
| на зададената productCategory/productGroup/propertyGroupId структура.
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
        logLine("ERROR: Could not parse index XML");
        return [];
    }

    $feeds = [];

    /*
     * Обхождаме productCategory.
     */

    $categories = $dom->getElementsByTagName('productCategory');

    foreach ($categories as $categoryNode) {

        if (!($categoryNode instanceof DOMElement)) {
            continue;
        }

        $categoryName = trim(
            $categoryNode->getAttribute('name')
        );

        /*
         * Само директните productGroup children.
         */

        foreach ($categoryNode->childNodes as $groupNode) {

            if (!($groupNode instanceof DOMElement)) {
                continue;
            }

            if ($groupNode->localName !== 'productGroup') {
                continue;
            }

            $groupName = trim(
                $groupNode->getAttribute('name')
            );

            /*
             * Форматът е:
             *
             * <propertyGroupId>...</propertyGroupId>
             * <atom:link .../>
             *
             * Затова пазим последния propertyGroupId.
             */

            $currentPropertyName = null;

            foreach ($groupNode->childNodes as $child) {

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

                if ($currentPropertyName === null) {
                    continue;
                }

                $rule = getRuleForPath(
                    $targetRules,
                    $categoryName,
                    $groupName,
                    $currentPropertyName
                );

                if ($rule === null) {
                    continue;
                }

                $href = trim(
                    $child->getAttribute('href')
                );

                if ($href === '') {
                    continue;
                }

                /*
                 * Не използваме credentials от XML href-а.
                 * Вземаме само propertyId и конструираме URL сами.
                 */

                $query = parse_url(
                    html_entity_decode($href),
                    PHP_URL_QUERY
                );

                parse_str($query ?? '', $params);

                if (empty($params['propertyId'])) {
                    continue;
                }

                $propertyId = trim(
                    $params['propertyId']
                );

                /*
                 * propertyId е уникален ключ.
                 */

                $feeds[$propertyId] = [
                    'category' => $categoryName,
                    'group' => $groupName,
                    'property' => $currentPropertyName,

                    'entity_brands' =>
                        $rule['entity_brands'] ?? [],

                    'name_brands' =>
                        $rule['name_brands'] ?? [],
                ];
            }
        }
    }

    return $feeds;
}


/*
|--------------------------------------------------------------------------
| PRODUCT FILTER
|--------------------------------------------------------------------------
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
     * Нормализираме brand rules предварително.
     */

    $normalizedEntityBrands = [];

    foreach ($entityBrands as $brand) {

        $normalized = normalizeBrand($brand);

        if ($normalized !== '') {
            $normalizedEntityBrands[] = $normalized;
        }
    }

    $normalizedEntityBrands = array_unique(
        $normalizedEntityBrands
    );


    $normalizedNameBrands = [];

    foreach ($nameBrands as $brand) {

        $normalized = normalizeBrand($brand);

        if ($normalized !== '') {
            $normalizedNameBrands[] = $normalized;
        }
    }

    $normalizedNameBrands = array_unique(
        $normalizedNameBrands
    );


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
         * 1. groupId / vendor
         */

        foreach ($normalizedEntityBrands as $brand) {

            if (
                $groupId === $brand ||
                $vendor === $brand
            ) {
                $matched = true;
                break;
            }
        }


        /*
         * 2. product name
         */

        if (!$matched) {

            foreach ($normalizedNameBrands as $brand) {

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
logLine("Loading ALSO catalog index...");

$indexXml = fetchXml($indexUrl);

if ($indexXml === null || $indexXml === '') {

    logLine(
        "ERROR: Could not load catalog index"
    );

    exit(1);
}


if (isLoginError($indexXml)) {

    logLine(
        "ERROR: Login error while loading catalog index"
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| BUILD FEED MAP
|--------------------------------------------------------------------------
*/

$feeds = getTargetFeedsFromIndex(
    $indexXml,
    $targetRules
);


if (count($feeds) === 0) {

    logLine(
        "ERROR: No matching feeds found in catalog index"
    );

    exit(1);
}


logLine(
    "Target feeds found: " . count($feeds)
);


/*
|--------------------------------------------------------------------------
| PROCESS FEEDS
|--------------------------------------------------------------------------
*/

$productXmlList = [];

$totalProducts = 0;
$totalRequests = 0;
$totalValidFeeds = 0;
$totalEmptyFeeds = 0;
$totalFailedFeeds = 0;


foreach ($feeds as $propertyId => $feedRule) {

    $totalRequests++;

    logLine(
        "Request {$totalRequests}: {$propertyId}"
    );

    logLine(
        "    "
        . $feedRule['category']
        . " > "
        . $feedRule['group']
        . " > "
        . $feedRule['property']
    );


    $url = $baseUrl
        . '?j_u=' . urlencode($user)
        . '&j_p=' . urlencode($pass)
        . '&propertyId=' . urlencode($propertyId);


    $xml = fetchXml($url);


    if ($xml === null || $xml === '') {

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


    $count =
        count($matchedProducts);


    logLine(
        "    -> ItemsCollected={$itemsCollected}, "
        . "matched={$count}"
    );


    foreach ($matchedProducts as $productXml) {

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
| Не публикуваме празен feed при проблем.
|
*/

if ($totalProducts === 0) {

    logLine(
        "ERROR: 0 matching products found."
    );

    logLine(
        "feed.xml will NOT be overwritten."
    );

    exit(1);
}


/*
|--------------------------------------------------------------------------
| CREATE OUTPUT
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
        "ERROR: Could not write feed.xml"
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
    "Target feeds: "
    . count($feeds)
);

logLine(
    "Requests: {$totalRequests}"
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
