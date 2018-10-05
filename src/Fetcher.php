<?php
/**
 * MIT License.
 *
 * @author Andrey Khrolenok <andrey@khrolenok.ru>
 * @copyright Copyright (c) 2017 Andrey Khrolenok
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

use Doctrine\Common\Cache\Cache;

class Fetcher
{
    protected $config = array(
        'timeout'               => 10,
        'proxies'               => array(),
        'requireProxy'          => true,
        'proxylistUrl'          => null,
        'proxyPingUrl'          => null,
        'allowedProxyLevels'    => array('anonymous', 'elite'),
        'userAgent'             => null,
        'cache'                 => null,
        'cacheMinLifetime'      => 86400,   // 1 day
    );

    protected $curl;

    public function __construct(array $config = array())
    {
        $this->setConfig($config);

        $this->curl = \curl_init();
    }

    public function __destruct()
    {
        \curl_close($this->curl);
    }

    public function setConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);

        if (! empty($config['proxylistUrl'])) {
            $list = $config['proxylistUrl'];
            if (! \is_array($list)) {
                $list = array($list);
            }
            foreach ($list as $url) {
                $proxies = @file_get_contents($url);
                if (! empty($proxies)) {
                    $proxies = preg_split('/\s+/', $proxies, null, PREG_SPLIT_NO_EMPTY);
                    $this->config['proxies'] = array_unique(array_merge($this->config['proxies'], $proxies));
                }
            }
        }
    }

    /**
     * @param null $option
     *
     * @return array|mixed
     */
    public function getConfig($option = null)
    {
        if (! empty($option)) {
            return $this->config[$option];
        }

        return $this->config;
    }

    protected $proxyHits = 0;

    public function getProxy()
    {
        static $proxy;

        if ($this->proxyHits <= 0) {
            do {
                $cnt = \count($this->config['proxies']);
                if (! empty($cnt)) {
                    $key = rand(0, $cnt - 1);
                    $proxy = $this->config['proxies'][$key];
                    $this->proxyHits = rand(3, 30);

                    // Check proxy
                    if (! empty($this->config['proxyPingUrl'])) {
                        if (empty($proxyChecker)) {
                            $proxyChecker = new ProxyChecker($this->config['proxyPingUrl']);
                        }
                        try {
                            $res = $proxyChecker->checkProxy($proxy);
                            if (! \in_array($res['proxy_level'], $this->config['allowedProxyLevels'], true)) {
                                array_splice($this->config['proxies'], $key, 1);
                                continue;
                            }
                        } catch (\Exception $ex) {
                            array_splice($this->config['proxies'], $key, 1);
                            continue;
                        }
                    }
                } else {
                    $proxy = null;
                }
            } while (false);
        }

        $this->proxyHits--;

        return $proxy;
    }

    public function forceChangeIdentity()
    {
        $this->proxyHits = 0;
    }

    protected $lastProxy;
    protected $lastUserAgent;
    protected $identityHits;

    /**
     * @throws \Exception
     *
     * @return array
     */
    public function getCurlIdentity()
    {
        $options = array();

        $proxy = $this->getProxy();
        if (! empty($proxy)) {
            $options = array_replace($options, ProxyChecker::getCurlProxyOptions($proxy));
        } elseif (! empty($this->config['requireProxy'])) {
            throw new \Exception('There are no working proxies in list!');
        }
        if ($this->lastProxy !== $proxy) {
            $this->lastProxy = $proxy;
            $this->identityHits = 0;
            if (! empty($this->config['userAgent'])) {
                $this->lastUserAgent = $this->config['userAgent'];
            } else {
                $this->lastUserAgent = UserAgents::getRandom();
            }
        }
        $options[CURLOPT_USERAGENT] = $this->lastUserAgent;

        $this->identityHits++;

        return $options;
    }

    /**
     * @return mixed
     */
    public function httpCode()
    {
        return \curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
    }

    /**
     * @param $url
     * @param array $postFields
     *
     * @throws \Exception
     *
     * @return bool|mixed|string
     */
    public function fetch($url, array $postFields = array())
    {
        static $errors = 0;

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Page URL MUST be defined.');
        }

        $cache_id = hash('sha256', $url.json_encode($postFields));

        /** @var FetcherCacheInterface $cache */
        $cache = empty($this->config['cache']) ? null : $this->config['cache'];
        if (! $cache instanceof FetcherCacheInterface && ! $cache instanceof Cache) {
            $cache = null;
        }
        if ($cache) {
            $content = $cache->fetch($cache_id);
            if (false !== $content) {
                return $content;
            }
        }

        $options = array(
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,

            // Follow 'Location:'
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_MAXREDIRS      => 20,
        );
        $options = array_replace($options, $this->getCurlIdentity());

        if (! empty($postFields)) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $postFields;
            $options[CURLOPT_POSTREDIR] = 3;
        }

        \curl_setopt_array($this->curl, $options);

        if ($this->identityHits <= 1) {
            $errors = 0;
        }
        if (false === $content = \curl_exec($this->curl)) {
            if (++$errors >= 3) {
                $this->forceChangeIdentity();
            }
            throw new \Exception(\curl_error($this->curl), \curl_errno($this->curl));
        }

        $headers_size = curl_getinfo($this->curl, CURLINFO_HEADER_SIZE);
        $headers = mb_substr($content, 0, $headers_size);
        $content = mb_substr($content, $headers_size);

        $headers = $this->parseCurlHeaders($headers);
        $headers = end($headers);

        if ($cache) {
            $lifetime = array($this->config['cacheMinLifetime']);
            if (isset($headers['cache-control'])
                && preg_match('/(s-)?max-age=(\d+)/', $headers['cache-control'], $matches)
            ) {
                $lifetime[] = $matches[2];
            }
            if (isset($headers['expires']) && isset($headers['date'])) {
                $now = \DateTime::createFromFormat(\DateTime::RFC2822, $headers['date'])->getTimestamp();
                $exp = \DateTime::createFromFormat(\DateTime::RFC2822, $headers['expires'])->getTimestamp();

                $lifetime[] = $exp - $now;
            }

            $cache->save($cache_id, $content, max($lifetime));
        }

        return $content;
    }

    /**
     * Fetch content by URL.
     *
     * @param string $url
     * @param array  $postFields
     * @param array  $config
     *
     * @return string
     */
    public static function fetchUrl($url, array $postFields = array(), array $config = array())
    {
        $fetcher = new self($config);

        return $fetcher->fetch($url, $postFields);
    }

    protected function parseCurlHeaders($headerContent)
    {
        $headers = array();

        // Split the string on every "double" new line.
        $arrRequests = explode("\r\n\r\n", $headerContent);

        // Loop of response headers. The "count() - 1" is to
        // avoid an empty row for the extra line break before the body of the response.
        for ($index = 0; $index < \count($arrRequests) - 1; $index++) {
            foreach (explode("\r\n", $arrRequests[$index]) as $i => $line) {
                if (0 === $i) {
                    $headers[$index]['http_code'] = $line;
                } else {
                    list($key, $value) = explode(': ', $line);
                    $headers[$index][mb_strtolower($key)] = $value;
                }
            }
        }

        return $headers;
    }
}
