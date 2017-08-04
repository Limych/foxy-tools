<?php
/**
 * MIT License
 *
 * @package FoxyTools;
 * @author Andrey Khrolenok <andrey@khrolenok.ru>
 * @copyright Copyright (c) 2017 Andrey Khrolenok
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

class Fetcher
{

    protected $config = array(
        'timeout'               => 10,
        'proxies'               => array(),
        'requireProxy'          => true,
        'proxylistUrl'          => null,
        'proxyPingUrl'          => null,
        'allowedProxyLevels'    => array('anonymous', 'elite'),
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
            if (! is_array($list)) {
                $list = array($list);
            }
            foreach ($list as $url) {
                if (! empty($proxies = @file_get_contents($url))) {
                    $proxies = preg_split('/\s+/', $proxies, null, PREG_SPLIT_NO_EMPTY);
                    $this->config['proxies'] = array_unique(array_merge($this->config['proxies'], $proxies));
                }
            }
        }
    }

    public function getConfig($option = null)
    {
        if (! empty($option)) {
            return $this->config[$option];
        } else {
            return $this->config;
        }
    }

    protected $proxyHits = 0;

    public function getProxy()
    {
        static $FoxyTools;
        static $proxy;

        if ($this->proxyHits <= 0) {
            do {
                if (! empty($cnt = count($this->config['proxies']))) {
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
                            if (! in_array($res['proxy_level'], $this->config['allowedProxyLevels'])) {
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
            $this->lastUserAgent = UserAgents::getRandom();
            $this->identityHits = 0;
        }
        $options[CURLOPT_USERAGENT] = $this->lastUserAgent;

        $this->identityHits++;

        return $options;
    }

    public function fetch($url)
    {
        static $errors = 0;

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Page URL MUST be defined.');
        }

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['timeout'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '',

            // Follow 'Location:'
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_MAXREDIRS => 20,
        );
        $options = array_replace($options, $this->getCurlIdentity());

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

        return $content;
    }
}
