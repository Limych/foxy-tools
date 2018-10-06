<?php
/**
 * MIT License.
 *
 * @author Andrey Khrolenok <andrey@khrolenok.ru>
 * @author Alexey https://github.com/AlexeyFreelancer
 * @copyright Copyright (c) 2017 Andrey Khrolenok
 * @copyright Copyright (c) 2016 Alexey https://github.com/AlexeyFreelancer
 * @license MIT
 *
 * @see https://github.com/Limych/foxy-tools
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace FoxyTools;

/**
 * ProxyChecker.
 */
class ProxyChecker
{
    const PING_KEY = '7771977160ce33a19a00d3422e19c6e8';
    const PING_CRYPT = 'aes-256-ctr';
    const PING_CHECK = 'ProxyChecker Ping';
    const PING_SEPARATOR = '=||=';

    const DETECTING_TIMEOUT = 1;

    protected $config = [
        'pingUrl'   => null,
        'timeout'   => 10,
        'pingKey'   => self::PING_KEY,
        'check'     => [
            'get',
            'post',
            'cookie',
            'referer',
            'user_agent',
        ],
        'request'   => [
            'get_name'      => 'g',
            'get_value'     => 'query',
            'post_name'     => 'p',
            'post_value'    => 'request',
            'cookie_name'   => 'c',
            'cookie_value'  => 'cookie',
            'referer'       => 'http://www.google.com',
            'user_agent'    => 'Mozila/4.0',
        ],
    ];

    protected $selfIp;

    /**
     * ProxyChecker constructor.
     * @param $proxyCheckUrl
     * @param array $config
     */
    public function __construct($proxyCheckUrl, array $config = [])
    {
        $this->config['pingUrl'] = $proxyCheckUrl;

        $this->setConfig($config);
    }

    /**
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * @param null $option
     * @return array|mixed
     */
    public function getConfig($option = null)
    {
        if (! empty($option)) {
            return $this->config[$option];
        }

        return $this->config;
    }

    /**
     * @param array $proxies
     * @return array
     */
    public function checkProxies(array $proxies)
    {
        $results = [];

        foreach ($proxies as $proxy) {
            try {
                $results[$proxy] = $this->checkProxy($proxy);
            } catch (\Exception $e) {
                $results[$proxy]['error'] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * @param $proxy
     * @throws \Exception
     * @return array
     */
    public function checkProxy($proxy)
    {
        if (\is_array($proxy)) {
            return $this->checkProxies($proxy);
        }

        if (empty($this->config['pingUrl'])
            || (false === filter_var($this->config['pingUrl'], FILTER_VALIDATE_URL))
        ) {
            throw new \InvalidArgumentException('Proxy checker URL MUST be defined.');
        }

        if (empty($this->selfIp)) {
            $res = file_get_contents($this->config['pingUrl']);
            if (! empty($res)
                && (false !== filter_var($res = strtok($res, self::PING_SEPARATOR), FILTER_VALIDATE_IP))
            ) {
                $this->selfIp = $res;
            } else {
                throw new \Exception('Cannot connect to proxy checker.');
            }
        }

        $parsed = self::parseProxyUrl($proxy);
        if ($parsed) {
            if (empty($parsed['scheme'])) {
                $parsed['scheme'] = self::detectProxyType($parsed['host'], $parsed['port']);
            }
            if (empty($parsed['scheme'])) {
                $parsed['scheme'] = 'http';
            }
            $proxy = $parsed['scheme'].'://'.
                (empty($parsed['user_pass']) ? '' : $parsed['user_pass'].'@').
                $parsed['host_port'];
        }

        list($content, $info) = $this->getProxyContent($proxy);

        $proxyInfo = $this->checkProxyContent($content, $info);
        $proxyInfo['proxy'] = $proxy;

        return $proxyInfo;
    }

    /**
     * @param $url
     * @return array|mixed
     */
    protected static function parseProxyUrl($url)
    {
        $parsed = parse_url($url);
        if (\is_array($parsed)) {
            if (isset($parsed['user']) && isset($parsed['pass'])) {
                $parsed['user_pass'] = $parsed['user'].':'.$parsed['pass'];
            }
            if (isset($parsed['host']) && isset($parsed['port'])) {
                $parsed['host_port'] = $parsed['host'].':'.$parsed['port'];
            }
        }

        return $parsed;
    }

    /**
     * @param $proxy
     * @return array
     */
    public static function getCurlProxyOptions($proxy)
    {
        $options = [];
        $parsed = self::parseProxyUrl($proxy);
        if ($parsed) {
            if (empty($parsed['scheme'])) {
                $parsed['scheme'] = self::detectProxyType($parsed['host'], $parsed['port']);
            }
            // Proxy type
            switch ($parsed['scheme']) {
                case 'http':
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
                    break;
                case 'socks4':
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
                    break;
                case 'socks5':
                    $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
                    break;
            }

            // Proxy host
            $options[CURLOPT_PROXY] = $parsed['host_port'];

            // Proxy authentification
            if (! empty($parsed['user_pass'])) {
                $options[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_PROXYUSERPWD] = $parsed['user_pass'];
            }
        }

        return $options;
    }

    /**
     * @param $proxy
     * @return array
     */
    protected function getProxyContent($proxy)
    {
        $ch = \curl_init();
        $checker_url = $this->config['pingUrl'];

        // Check query
        if (\in_array('get', $this->config['check'], true)
            && ! empty($this->config['request']['get_name'])
            && ! empty($this->config['request']['get_value'])
        ) {
            $checker_url .= '?'.$this->config['request']['get_name'].'='.
                $this->config['request']['get_value'];
        }

        $options = [
            CURLOPT_URL            => $checker_url,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
            CURLOPT_RETURNTRANSFER => true,
        ];
        $options = array_replace($options, self::getCurlProxyOptions($proxy));

        if (empty($options[CURLOPT_PROXY])) {
            throw new \InvalidArgumentException('Proxy address MUST be defined.');
        }

        // Check post
        if (\in_array('post', $this->config['check'], true)
            && ! empty($this->config['request']['post_name'])
            && ! empty($this->config['request']['post_value'])
        ) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = [
                $this->config['request']['post_name'] => $this->config['request']['post_value'],
            ];
        }

        // Check cookie
        if (\in_array('cookie', $this->config['check'], true)
            && ! empty($this->config['request']['cookie_name'])
            && ! empty($this->config['request']['cookie_value'])
        ) {
            $options[CURLOPT_COOKIE] = $this->config['request']['cookie_name'].'='.
                $this->config['request']['cookie_value'];
        }

        // Check referer
        if (\in_array('referer', $this->config['check'], true)
            && ! empty($this->config['request']['referer'])
        ) {
            $options[CURLOPT_REFERER] = $this->config['request']['referer'];
        }

        // Check user agent
        if (\in_array('user_agent', $this->config['check'], true)
            && ! empty($this->config['request']['user_agent'])
        ) {
            $options[CURLOPT_USERAGENT] = $this->config['request']['user_agent'];
        }

        \curl_setopt_array($ch, $options);

        $content = \curl_exec($ch);
        $info = \curl_getinfo($ch);

        return [$content, $info];
    }

    /**
     * @param null $headers
     * @return bool
     */
    public static function hasProxyHeaders($headers = null)
    {
        if (empty($headers)) {
            $headers = $_SERVER;
        }

        $found = array_intersect(array_keys($headers), explode(
            ' ',
            'HTTP_X_FORWARDED_FOR HTTP_X_FORWARDED HTTP_FORWARDED_FOR HTTP_FORWARDED HTTP_X_REAL_IP '.
            'HTTP_CLIENT_IP HTTP_CF_CONNECTING_IP CLIENT_IP HTTP_X_PROXY_ID X_FORWARDED_FOR '.
            'FORWARDED_FOR HTTP_FORWARDED_FOR_IP FORWARDED_FOR_IP HTTP_X_CLUSTER_CLIENT_IP '.
            'HTTP_PROXY_CONNECTION X_FORWARDED HTTP_VIA VIA FORWARDED'
        ));

        return ! empty($found);
    }

    /**
     * @param $content
     * @param $info
     * @throws \Exception
     * @return array
     */
    protected function checkProxyContent($content, $info)
    {
        if (empty($content)) {
            throw new \Exception('Empty content');
        }

        $res = explode(self::PING_SEPARATOR, $content, 2);
        if (empty($res[1])) {
            throw new \Exception('Wrong content');
        }
        $content = $res[1];

        if (! empty($this->config['pingKey'])) {
            $content = @openssl_decrypt($content, self::PING_CRYPT, $this->config['pingKey']);
        }

        $ping = json_decode($content, true);

        if (null === $ping) {
            throw new \Exception('Wrong ping key');
        } elseif (empty($ping['check']) || (self::PING_CHECK !== $ping['check'])) {
            throw new \Exception('Wrong ping check code');
        }

        if (200 !== $info['http_code']) {
            throw new \Exception('Code invalid: '.$info['http_code']);
        }

        $allowed = [];
        $disallowed = [];

        // Detect allowed methods
        foreach ($this->config['check'] as $test) {
            $found = false;
            switch ($test) {
                case 'get':
                    $value = @$ping['_GET'][$this->config['request']['get_name']];
                    $found = (! empty($value) && ($value === $this->config['request']['get_value']));
                    break;
                case 'post':
                    $value = @$ping['_POST'][$this->config['request']['post_name']];
                    $found = (! empty($value) && ($value === $this->config['request']['post_value']));
                    break;
                case 'cookie':
                    $value = @$ping['_COOKIE'][$this->config['request']['cookie_name']];
                    $found = (! empty($value) && ($value === $this->config['request']['cookie_value']));
                    break;
                case 'referer':
                    $value = @$ping['_SERVER']['HTTP_REFERER'];
                    $found = (! empty($value) && ($value === $this->config['request']['referer']));
                    break;
                case 'user_agent':
                    $value = @$ping['_SERVER']['HTTP_USER_AGENT'];
                    $found = (! empty($value) && ($value === $this->config['request']['user_agent']));
                    break;
            }
            if ($found) {
                $allowed[] = $test;
            } else {
                $disallowed[] = $test;
            }
        }

        // Detect proxy level
        $proxyLevel = (false !== mb_strpos($content, $this->selfIp)
            ? 'transparent'
            : (self::hasProxyHeaders($ping['_SERVER'])
                ? 'anonymous'
                : 'elite'
            )
        );

        return [
            'allowed'     => $allowed,
            'disallowed'  => $disallowed,
            'proxy_level' => $proxyLevel,
            'info'        => $info,
        ];
    }

    /**
     * @param string $pingKey
     */
    public static function pingResponse($pingKey = self::PING_KEY)
    {
        @header('Content-Type: text/plain');

        // Noindex header
        @header('X-Robots-Tag: none');

        // Nocache headers
        @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        @header('Cache-Control: post-check=0, pre-check=0', false);
        @header('Pragma: no-cache');

        $output = json_encode([
            'check'     => self::PING_CHECK,
            '_SERVER'   => $_SERVER,
            '_GET'      => $_GET,
            '_POST'     => $_POST,
            '_COOKIE'   => $_COOKIE,
        ]);

        if (! empty($pingKey)) {
            $output = @openssl_encrypt($output, self::PING_CRYPT, $pingKey);
        }

        die($_SERVER['REMOTE_ADDR'].self::PING_SEPARATOR.$output);
    }

    /**
     * @param $host
     * @param int $port
     * @param int $timeout
     * @return bool
     */
    public static function isSocks5($host, $port = 1080, $timeout = self::DETECTING_TIMEOUT)
    {
        if (false !== mb_strpos($host, ':')) {
            $parsed = self::parseProxyUrl($host);
            $host = $parsed['host'];
            $port = $parsed['port'];
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (! $fp) {
            return false;
        }

        stream_set_timeout($fp, $timeout);

        fwrite($fp, "\x05\x01\x00");

        $res = fread($fp, 2);
        fclose($fp);

        if (empty($res)) {
            return false;
        }

        return "\x05\x00" === $res;
    }

    /**
     * @param $host
     * @param int $port
     * @param int $timeout
     * @return bool
     */
    public static function isSocks4($host, $port = 1080, $timeout = self::DETECTING_TIMEOUT)
    {
        if (false !== mb_strpos($host, ':')) {
            $parsed = self::parseProxyUrl($host);
            $host = $parsed['host'];
            $port = $parsed['port'];
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (! $fp) {
            return false;
        }

        stream_set_timeout($fp, $timeout);

        fwrite($fp, "\x04\x01".pack('n', $port).pack('H*', dechex(ip2long($host)))."\x00");

        $res = fread($fp, 9);
        fclose($fp);

        return ! empty($res) && (8 === \mb_strlen($res)) && "\x00" === $res[0] && ("\x5A" === $res[1]);
    }

    /**
     * @param $host
     * @param int $port
     * @param int $timeout
     * @return bool
     */
    public static function isHttp($host, $port = 3128, $timeout = self::DETECTING_TIMEOUT)
    {
        if (false !== mb_strpos($host, ':')) {
            $parsed = self::parseProxyUrl($host);
            $host = $parsed['host'];
            $port = $parsed['port'];
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (! $fp) {
            return false;
        }

        stream_set_timeout($fp, $timeout);

        fwrite($fp, "GET / HTTP/1.1\r\nHost: wikipedia.org\r\n\r\n");

        $res = fread($fp, 9);
        fclose($fp);

        return ! empty($res) && (9 === \mb_strlen($res)) && ('HTTP/1.1 ' === $res);
    }

    /**
     * @param $host
     * @param int $port
     * @param int $timeout
     * @return string
     */
    public static function detectProxyType($host, $port = 3128, $timeout = self::DETECTING_TIMEOUT)
    {
        if (false !== mb_strpos($host, ':')) {
            $parsed = self::parseProxyUrl($host);
            $host = $parsed['host'];
            $port = $parsed['port'];
        }

        if (self::isSocks5($host, $port, $timeout)) {
            return 'socks5';
        } elseif (self::isSocks4($host, $port, $timeout)) {
            return 'socks4';
        } elseif (self::isHttp($host, $port, $timeout)) {
            return 'http';
        }
    }

    /**
     * @param null $host
     * @param int $timeout
     * @return array
     */
    public static function portscan($host = null, $timeout = self::DETECTING_TIMEOUT)
    {
        if (empty($host)) {
            $host = $_SERVER['REMOTE_ADDR'];
        }

        $proxies = [];

        $ports = [8080, 80, 81, 1080, 6588, 8000, 3128, 553, 554, 4480];
        shuffle($ports);
        foreach ($ports as $port) {
            $type = self::detectProxyType($host, $port, $timeout);
            if ($type) {
                $proxies[] = "$type://$host:$port";
            }
        }

        return $proxies;
    }
}
