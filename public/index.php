<?php

define('PROXYLIST_FPATH', __DIR__.'/proxies.json');

define('QUEUE_FPATH', __DIR__.'/queue.json');
define('QUEUE_TIMEOUT', mt_rand(7, 160) * 60);

define('CHECKED_FPATH', __DIR__.'/checked.json');
define('CHECKED_TIMEOUT', 180 * 86400);

if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require_once __DIR__.'/../vendor/autoload.php';
} else {
    require_once 'src/ProxyChecker.php';
}

$tmp = strtok($_SERVER['REQUEST_URI'], '?');
$request_path = (0 === strncmp($tmp, $_SERVER['SCRIPT_NAME'], mb_strlen($_SERVER['SCRIPT_NAME']))
    ? mb_substr($tmp, mb_strlen($_SERVER['SCRIPT_NAME']))
    : mb_substr($tmp, mb_strlen(dirname($_SERVER['SCRIPT_NAME']))));

if ('/ping' === $request_path) {
    \FoxyTools\ProxyChecker::pingResponse();
    die();
}

// Ignore connection-closing by the client/user
ignore_user_abort(true);

$proxyList = array();
if (file_exists(PROXYLIST_FPATH)) {
    $proxyList = json_decode(file_get_contents(PROXYLIST_FPATH), true);
}
$old_proxyList = $proxyList;

if (empty($_REQUEST['touch'])):

if ('/fetch' === $request_path) {
    $_REQUEST['proxy'] = fetchFromPublicList();
}
if ($_REQUEST['proxy']) {
    foreach (preg_split('/\s+/', $_REQUEST['proxy'], null, PREG_SPLIT_NO_EMPTY) as $proxy) {
        $proxyInfo = check($proxy);
    }
}

uasort($proxyList, function ($a, $b) {
    return ($a['checked_at'] === $b['checked_at']) ? 0
        : ($a['checked_at'] < $b['checked_at']) ? 1 : -1;
});

if ('/list' === $request_path) {
    header('Content-Type: text/plain');
    $min = time() - 86400;
    foreach ($proxyList as $item) {
        if ($item['checked_at'] < $min) {
            break;
        }
        echo $item['proxy']."\n";
    }
    die;
}

?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml"><head>
    <title>Online Anonymity & Proxy Checker</title>

    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />

    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />

    <link rel="stylesheet"
          href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-beta/css/bootstrap.min.css"
          integrity="sha384-/Y6pD6FV/Vv2HJnA6t+vslU6fwYXjCFtcEpHbNJ0lyAFsXTsjBbfaDjzALeQsN6M"
          crossorigin="anonymous" />
</head><body><div class="container">
    <h1 class="my-5">Online Proxy Checker</h1>
    <div class="row">
        <div class="col">
            <form action="<?= $_SERVER['SCRIPT_NAME']; ?>">
                <div class="form-group row">
                    <label for="proxy" class="col-sm-2 col-form-label text-sm-right">Proxy</label>
                    <div class="col-sm-8 text-left">
                        <input type="text" name="proxy" class="form-control" id="proxy"
                              placeholder="Host:Port" value="" />
                    </div>
                    <div class="col-sm-2">
                        <button type="submit" class="btn btn-primary">Check</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php if (isset($proxyInfo) || isset($error)): ?>
    <div class="row mt-3">
        <div class="col">
            <h2>Checking result</h2>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php else: ?>
                <?= "<!--\n\n".var_export($proxyInfo, true)."\n\n-->"; ?>
                <div class="row">
                    <div class="col-sm-2 text-sm-right">Proxy level</div>
                    <div class="col-sm-10"><?php echo $proxyInfo['proxy_level']; ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="row mt-5">
        <div class="col">
            <?php showLastChecked(); ?>
        </div>
    </div>
</div>
<script>
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
        (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
        m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

    ga('create', 'UA-289367-19', 'auto');
    ga('send', 'pageview');

</script>
</body></html>
<?php
flush();

endif;  // $_REQUEST['touch']

$old_queue = $queue = file_exists(QUEUE_FPATH)
    ? json_decode(file_get_contents(QUEUE_FPATH), true)
    : array();
$old_checked = $checked = file_exists(CHECKED_FPATH)
    ? json_decode(file_get_contents(CHECKED_FPATH), true)
    : array();

$addr = ! empty($_REQUEST['touch']) ? $_REQUEST['touch'] : $_SERVER['REMOTE_ADDR'];
if (empty($queue[$addr]) && (empty($checked[$addr]) || ($checked[$addr] + CHECKED_TIMEOUT <= time()))) {
    $queue[$addr] = time() + (defined('QUEUE_TIMEOUT')
        ? QUEUE_TIMEOUT : mt_rand(7, 160)
    );
    asort($queue);
}

$check_host = null;
if (reset($queue) <= time()) {
    $check_host = key($queue);
    array_shift($queue);
}

if ($queue !== $old_queue) {
    file_put_contents(QUEUE_FPATH, json_encode($queue));
}

if (! empty($check_host)) {
    $proxies = \FoxyTools\ProxyChecker::portscan($check_host);
    foreach ($proxies as $proxy) {
        check($proxy);
    }
} elseif (0 === mt_rand(0, 3)) {
    check(fetchFromPublicList(1));
} else {
    $tmp = $proxyList;
    shuffle($tmp);
    $res = reset($tmp);
    if (empty($res['proxy']) || (false === parse_url($res['proxy']))) {
        unset($proxyList[key($tmp)]);
    } else {
        check($res['proxy']);
    }
}

if ($checked !== $old_checked) {
    file_put_contents(CHECKED_FPATH, json_encode($checked));
}

if ($proxyList !== $old_proxyList) {
    file_put_contents(PROXYLIST_FPATH, json_encode($proxyList));
}

function fetchFromPublicList($count = 12)
{
    $servers = array('https://www.socks-proxy.net/', 'https://free-proxy-list.net/');
    shuffle($servers);
    $content = file_get_contents($servers[0]);
    if (preg_match_all('!<td>(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})</td><td>(\d{2,5})</td>!',
        $content, $matches, PREG_SET_ORDER
    )) {
        $tmp = array();
        array_shift($matches);
        foreach ($matches as $match) {
            $tmp[] = $match[1].':'.$match[2];
        }
        shuffle($tmp);

        return implode(' ', array_slice($tmp, 0, $count));
    }
}

function check($proxy)
{
    global $proxyList, $checked, $error;

    static $checker;

    $parsed = parse_url($proxy);
    if (empty($parsed) || empty($parsed['host'])) {
        return;
    }

    $checked[$parsed['host']] = time();

    if (empty($checker)) {
        $pingUrl = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].'/ping';
        $checker = new \FoxyTools\ProxyChecker(htmlspecialchars($pingUrl));
    }
    try {
        $proxyInfo = $checker->checkProxy($proxy);
        $proxyList[$proxyInfo['proxy']] = array(
            'proxy'        => $proxyInfo['proxy'],
            'checked_at'   => time(),
            'proxy_level'  => $proxyInfo['proxy_level'],
            'request_time' => $proxyInfo['info']['total_time'],
            'allowed'      => $proxyInfo['allowed'],
        );
    } catch (\Exception $ex) {
        $error = $ex->getMessage();

        return;
    }

    return $proxyInfo;
}

function showLastChecked()
{
    global $proxyList; ?>
    <h1>Last checked proxies</h1>
    <p>There are <?= count($proxyList); ?> proxies in the list.</p>
    <table class="table table-striped table-responsive"><thead>
        <tr>
            <th>#</th>
            <th>Proxy</th>
            <th>Proxy level</th>
            <th>Request time</th>
            <th>Last checked</th>
        </tr>
    </thead><tbody>
<?php
    $cnt = min(12, count($proxyList));
    reset($proxyList);
    for ($i = 0; $cnt--; $i++) {
        $info = current($proxyList);
        $checkedAt = new DateTime();
        $checkedAt->setTimestamp($info['checked_at']); ?>
        <tr>
            <th scope="row"><?= $i + 1; ?></th>
            <td><?= $info['proxy']; ?></td>
            <td><?= ucfirst($info['proxy_level']); ?></td>
            <td><?= round($info['request_time'] * 1000); ?>&nbsp;ms</td>
            <td><?= $checkedAt->format('Y-m-d H:i'); ?></td>
        </tr>
<?php

        next($proxyList);
    } ?>
    </tbody></table>
<?php
}
