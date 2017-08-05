# Foxy-Tools

_“I solemnly swear that I am planning a prank, and only prank…”_

[![Dependency Status](https://www.versioneye.com/user/projects/598595d20fb24f006398ac6a/badge.svg?style=flat-square)](https://www.versioneye.com/user/projects/598595d20fb24f006398ac6a)

Foxy-Tools — is a set of PHP-tools for not quite legal cases on the web.

# Instalation
## Using Composer

[**Composer**](https://getcomposer.org/) is the recommended way to install Foxy-Tools. Alternatively, if you prefer not to use Composer, but want to install Foxy-Tools, you can do so by doing a direct download.

Currently, Foxy-Tools is available at [https://packagist.org](https://packagist.org/packages/limych/foxy-tools). To use it in your project, you need to include it as a dependency in your project composer.json file.

## Instructions
1. Download [Composer](https://getcomposer.org/download/) if not already installed
2. Go to your project directory. If you do not have one, just create a directory and `cd` in.

    ```sh
    $ mkdir project
    $ cd project
    ```
3. Execute `composer require "paypal/rest-api-sdk-php:*" ` on command line. Replace composer with composer.phar if required. It should show something like this:

    ```sh
    $ composer require limych/foxy-tools

    # output:
    ./composer.json has been created
    Loading composer repositories with package information
    Updating dependencies (including require-dev)
    - Installing limych/foxy-tools (v1.0)
    Loading from cache

    Writing lock file
    Generating autoload files
    ```

## Using direct download

If you do not want to use composer, you can grab the zip that contains Foxy-Tools with all its dependencies with it.

## Instructions

1. Download latest/desired release zip file from [Releases Section](https://github.com/Limych/foxy-tools/releases)
2. Go to your project directory. If you do not have one, just create a directory and `cd` in.

```sh
mkdir project
cd project
```
2. Unzip, and copy directory to your project location

# Usage
## Proxy format

    {type}://{user}:{password}@{host}:{port}

Some examples:

    183.95.132.76
    195.5.18.41:8118
    socks5://195.5.18.41:8118
    socks5://user:password@195.5.18.41:8118

## Check one proxy

    $pingUrl = 'http://yourdomain.com/ping.php';
    $proxy = 'xxx.xxx.xxx.xxx:xx';

    $proxyChecker = new ProxyChecker($pingUrl);
    $results = $proxyChecker->checkProxy($proxy);

## Check several proxies

    $pingUrl = 'http://yourdomain.com/ping.php';
    $proxies = array('xxx.xxx.xxx.xxx:xx', 'xxx.xxx.xxx.xxx:xx');

    $proxyChecker = new ProxyChecker($pingUrl);
    $results = $proxyChecker->checkProxies($proxies);

# Result
## Allowed/Disallowed

Array allowed/disallowed operations of proxy (get, post, referer, cookie, user_agent), for example:

    'allowed' => array (
        0 => 'get',
        1 => 'post',
        2 => 'referer',
        3 => 'user_agent'
    )

    'disallowed' => array (
        0 => 'cookie'
    )

## Proxy level

- *elite* — connection looks like a regular client;
- *anonymous* — no ip is forworded but target site could still tell it's a proxy;
- *transparent* — ip is forworded and target site would be able to tell it's a proxy.

    'proxy_level' => 'elite'

## Other info

Other proxy info - time, http code, redirect count, speed etc:

    'info' => array (
      'content_type' => 'text/html',
      'http_code' => 200,
      'header_size' => 237,
      'request_size' => 351,
      'ssl_verify_result' => 0,
      'redirect_count' => 0,
      'total_time' => 1.212548,
      'connect_time' => 0.058647,
      'size_upload' => 143,
      'size_download' => 485,
      'speed_download' => 399,
      'speed_upload' => 117,
      'download_content_length' => 485,
      'upload_content_length' => 143,
      'starttransfer_time' => 1.059746,
      'redirect_time' => 0,
      'certinfo' => array (),
    )
