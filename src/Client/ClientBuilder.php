<?php
namespace Erpk\Harvester\Client;

use Erpk\Harvester\Client\Proxy\HttpProxy;
use Erpk\Harvester\Client\Proxy\NetworkInterfaceProxy;
use Erpk\Harvester\Exception;
use Erpk\Harvester\Client\Proxy\ProxyInterface;

class ClientBuilder
{
    /**
     * @var array
     */
    private $config = [
        'base_uri' => 'http://www.erepublik.com/en',
        'allow_redirects' => false,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:42.0) Gecko/20100101 Firefox/42.0',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.8'
        ],
        'expect' => false,
        'timeout' => 20,
        'connect_timeout' => 10
    ];

    /**
     * @var string
     */
    private $email;

    /**
     * @var string
     */
    private $password;

    /**
     * @var Proxy\ProxyInterface
     */
    private $proxy;

    /**
     * @var string
     */
    private $sessionStorage;

    /**
     * @param string $uaString
     */
    public function setUserAgent($uaString)
    {
        $this->config['headers']['User-Agent'] = $uaString;
    }

    /**
     * @param string $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * @param string $pwd
     */
    public function setPassword($pwd)
    {
        $this->password = $pwd;
    }

    /**
     * @param string $path
     * @throws Exception\ConfigurationException
     */
    public function setSessionStorage($path)
    {
        if (!is_dir($path)) {
            throw new Exception\ConfigurationException('Session storage path is not a directory');
        } elseif (!is_writable($path)) {
            throw new Exception\ConfigurationException('Session storage path is not writable');
        }
        $this->sessionStorage = $path;
    }

    /**
     * @return string
     */
    protected function getSessionStorage()
    {
        if ($this->sessionStorage) {
            return $this->sessionStorage;
        } else {
            return sys_get_temp_dir();
        }
    }

    /**
     * @return Session
     * @throws Exception\ConfigurationException
     */
    protected function initSession()
    {
        if (!isset($this->email)) {
            throw new Exception\ConfigurationException("You need to set account's email.");
        }

        if (!isset($this->password)) {
            throw new Exception\ConfigurationException("You need to set account's password.");
        }

        $sessionId = substr(sha1($this->email), 0, 7);
        $session = new Session(
            $this->getSessionStorage().'/'.'erpk.'.$sessionId,
            $this->email,
            $this->password
        );
        $this->config['cookies'] = $session->getCookieJar();

        return $session;
    }

    /**
     * @param ProxyInterface $proxy
     */
    public function setProxy(ProxyInterface $proxy)
    {
        if ($proxy instanceof HttpProxy) {
            $this->config['proxy'] = (string)$proxy;
        } elseif ($proxy instanceof NetworkInterfaceProxy) {
            $this->config[CURLOPT_INTERFACE] = $proxy->getNetworkInterface();
        } else {
            throw new \InvalidArgumentException("This type of proxy is not supported");
        }
        $this->proxy = $proxy;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        $session = $this->initSession(); // Careful, it modifies $this->config.
        return new Client($this->config, $session);
    }
}
