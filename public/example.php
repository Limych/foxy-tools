<?php
require_once __DIR__ . '/../vendor/autoload.php';

$pingUrl = include __DIR__ . '/pingUrl.php';

$fetcher = new FoxyTools\Fetcher([
    'proxylistUrl'  => 'https://github.com/opsxcq/proxy-list/raw/master/list.txt',
    'proxyPingUrl'  => $pingUrl,
]);
$results = $fetcher->getProxy();

echo '<pre>';
var_export($results);
echo '</pre>';
