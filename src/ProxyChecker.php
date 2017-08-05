<?php
/**
 * MIT License
 *
 * @package FoxyTools;
 * @author Andrey Khrolenok <andrey@khrolenok.ru>
 * @author Alexey https://github.com/AlexeyFreelancer
 * @copyright Copyright (c) 2017 Andrey Khrolenok
 * @copyright Copyright (c) 2016 Alexey https://github.com/AlexeyFreelancer
 * @license MIT
 * @link https://github.com/Limych/foxy-tools
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
 * ProxyChecker
 */
class ProxyChecker
{

    const PING_KEY          = '7771977160ce33a19a00d3422e19c6e8';
    const PING_CRYPT        = 'aes-256-ctr';
    const PING_CHECK        = 'ProxyChecker Ping';
    const PING_SEPARATOR    = '=||=';

    protected $config = array(
        'pingUrl'   => null,
        'timeout'   => 10,
        'pingKey'   => self::PING_KEY,
        'check'     => array(
            'get',
            'post',
            'cookie',
            'referer',
            'user_agent'
        ),
        'request'   => array(
            'get_name'      => 'g',
            'get_value'     => 'query',
            'post_name'     => 'p',
            'post_value'    => 'request',
            'cookie_name'   => 'c',
            'cookie_value'  => 'cookie',
            'referer'       => 'http://www.google.com',
            'user_agent'    => 'Mozila/4.0',
        ),
    );

    protected $selfIp;

    public function __construct($proxyCheckUrl, array $config = array())
    {
        $this->config['pingUrl'] = $proxyCheckUrl;

        $this->setConfig($config);
    }

    public function setConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getConfig($option = null)
    {
        if (! empty($option)) {
            return $this->config[$option];
        } else {
            return $this->config;
        }
    }

    public function checkProxies(array $proxies)
    {
        $results = array();

        foreach ($proxies as $proxy) {
            try {
                $results[$proxy] = $this->checkProxy($proxy);
            } catch (\Exception $e) {
                $results[$proxy]['error'] = $e->getMessage();
            }
        }

        return $results;
    }

    public function checkProxy($proxy)
    {
        if (is_array($proxy)) {
            return $this->checkProxies($proxy);
        }

        if (empty($this->config['pingUrl'])
            || (false === filter_var($this->config['pingUrl'], FILTER_VALIDATE_URL))
        ) {
            throw new \InvalidArgumentException('Proxy checker URL MUST be defined.');
        }

        if(empty($this->selfIp)) {
            if (! empty($res = file_get_contents($this->config['pingUrl']))
                && (false !== filter_var($res = strtok($res, self::PING_SEPARATOR), FILTER_VALIDATE_IP))
            ) {
                $this->selfIp = $res;
            } else {
                throw new \Exception('Cannot connect to proxy checker.');
            }
        }

        list ($content, $info) = $this->getProxyContent($proxy);

        return $this->checkProxyContent($content, $info);
    }

    public static function getCurlProxyOptions($proxy)
    {
        $options = array();
        if (preg_match('!^(?:([^:]+)://|//)?(?:([^@]+)@)?([^/]+)!', $proxy, $parsed)) {
            // Proxy type
            switch ($parsed[1]) {
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
            $options[CURLOPT_PROXY] = $parsed[3];

            // Proxy authentification
            if (! empty($parsed[2])) {
                $options[CURLOPT_PROXYAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_PROXYUSERPWD] = $parsed[2];
            }
        }

        return $options;
    }

    protected function getProxyContent($proxy)
    {
        $ch = \curl_init();
        $checker_url = $this->config['pingUrl'];

        // Check query
        if (in_array('get', $this->config['check'])
            && ! empty($this->config['request']['get_name'])
            && ! empty($this->config['request']['get_value'])
        ) {
            $checker_url .= '?' . $this->config['request']['get_name'] . '=' .
                $this->config['request']['get_value'];
        }

        $options = array(
            CURLOPT_URL => $checker_url,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
            CURLOPT_RETURNTRANSFER => true
        );
        $options = array_replace($options, self::getCurlProxyOptions($proxy));

        if (empty($options[CURLOPT_PROXY])) {
            throw new \InvalidArgumentException('Proxy address MUST be defined.');
        }

        // Check post
        if (in_array('post', $this->config['check'])
            && ! empty($this->config['request']['post_name'])
            && ! empty($this->config['request']['post_value'])
        ) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = array(
                $this->config['request']['post_name'] => $this->config['request']['post_value']
            );
        }

        // Check cookie
        if (in_array('cookie', $this->config['check'])
            && ! empty($this->config['request']['cookie_name'])
            && ! empty($this->config['request']['cookie_value'])
        ) {
            $options[CURLOPT_COOKIE] = $this->config['request']['cookie_name'] . '=' .
                $this->config['request']['cookie_value'];
        }

        // Check referer
        if (in_array('referer', $this->config['check'])
            && ! empty($this->config['request']['referer'])
        ) {
            $options[CURLOPT_REFERER] = $this->config['request']['referer'];
        }

        // Check user agent
        if (in_array('user_agent', $this->config['check'])
            && ! empty($this->config['request']['user_agent'])
        ) {
            $options[CURLOPT_USERAGENT] = $this->config['request']['user_agent'];
        }

        \curl_setopt_array($ch, $options);

        $content = \curl_exec($ch);
        $info = \curl_getinfo($ch);

        return array($content, $info);
    }

    protected function checkProxyContent($content, $info)
    {
        if (empty($content)) {
            throw new \Exception('Empty content');
        }

        $content = explode(self::PING_SEPARATOR, $content, 2);
        if (empty($content[1])) {
            throw new \Exception('Wrong content');
        }
        $content = $content[1];

        if (! empty($this->config['pingKey'])) {
            $content = openssl_decrypt($content, self::PING_CRYPT, $this->config['pingKey']);
        }

        $ping = json_decode($content, true);

        if (null === $ping) {
            throw new \Exception('Wrong ping key');
        } elseif (empty($ping['check']) || ($ping['check'] !== self::PING_CHECK)) {
            throw new \Exception('Wrong content');
        }

        if (200 !== $info['http_code']) {
            throw new \Exception('Code invalid: ' . $info['http_code']);
        }

        $allowed = array();
        $disallowed = array();

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
        $proxyLevel = 'transparent';
        if (false === strpos($content, $this->selfIp)) {
            $proxyLevel = 'anonymous';

            $proxyDetection = array(
                'HTTP_X_REAL_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_PROXY_ID',
                'HTTP_VIA',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_FORWARDED_FOR',
                'HTTP_X_FORWARDED',
                'HTTP_FORWARDED',
                'HTTP_CLIENT_IP',
                'HTTP_FORWARDED_FOR_IP',
                'VIA',
                'X_FORWARDED_FOR',
                'FORWARDED_FOR',
                'X_FORWARDED FORWARDED',
                'CLIENT_IP',
                'FORWARDED_FOR_IP',
                'HTTP_PROXY_CONNECTION',
                'HTTP_XROXY_CONNECTION'
            );
            if (empty(array_intersect(array_keys($ping['_SERVER']), $proxyDetection))) {
                $proxyLevel = 'elite';
            }
        }

        return array(
            'allowed' => $allowed,
            'disallowed' => $disallowed,
            'proxy_level' => $proxyLevel,
            'info' => $info
        );
    }

    /**
     *
     * @param string $pingKey
     */
    public static function pingResponse($pingKey = self::PING_KEY)
    {
        @header('Content-Type: text/plain');

        // Noindex header
        @header('X-Robots-Tag: none');

        // Nocache headers
        @header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        @header("Cache-Control: post-check=0, pre-check=0", false);
        @header("Pragma: no-cache");

        $output .= json_encode(array(
            'check'     => self::PING_CHECK,
            '_SERVER'   => $_SERVER,
            '_GET'      => $_GET,
            '_POST'     => $_POST,
            '_COOKIE'   => $_COOKIE,
        ));

        if (! empty($pingKey)) {
            $output = openssl_encrypt($output, self::PING_CRYPT, $pingKey);
        }

        die($_SERVER['REMOTE_ADDR'] . self::PING_SEPARATOR . $output);
    }
}
