<?php
require_once __DIR__ . '/../vendor/autoload.php';

$config = include __DIR__ . '/config.php';

$fetcher = new FoxyTools\Fetcher($config);
$results = $fetcher->getProxy();

echo "<pre>" . var_export($results, true) . "</pre>\n";
