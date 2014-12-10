<?php
namespace Erpk\Harvester\Client;

use Erpk\Harvester\Module\Login\LoginModule;
use Erpk\Harvester\Exception;
use Erpk\Harvester\Client\Proxy\ProxyInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Client as GuzzleClient;

class Client implements ClientInterface
{
    private $internalClient;
    private $session;
    private $sessionStorage;
    private $email;
    private $password;

    /**
     * @var Proxy\ProxyInterface
     */
    private $proxy;
    
    public function __construct()
    {
        $this->internalClient = new GuzzleClient([
            'base_url' => 'http://www.erepublik.com',
            'defaults' => [
                'allow_redirects' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:34.0) Gecko/20100101 Firefox/34.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.8'
                ],
                'expect' => false,
                'timeout' => 20,
                'connect_timeout' => 10
            ]
        ]);

        $this->loginModule = new LoginModule($this);
    }

    public function setUserAgent($uaString)
    {
        $headers = $this->internalClient->getDefaultOption('headers');
        $headers['User-Agent'] = $uaString;
        $this->internalClient->setDefaultOption('headers', $headers);
    }

    public function getBaseUrl()
    {
        return $this->internalClient->getBaseUrl();
    }

    public function setEmail($email)
    {
        $this->email = $email;
        $this->initSession();
        return $this;
    }
    
    public function getEmail()
    {
        if (!isset($this->email)) {
            throw new Exception\ConfigurationException('Account e-mail address not specified');
        }
        return $this->email;
    }
    
    public function setPassword($pwd)
    {
        $this->password = $pwd;
        return $this;
    }
    
    public function getPassword()
    {
        if (!isset($this->password)) {
            throw new Exception\ConfigurationException('Account password not specified');
        }
        return $this->password;
    }

    public function setSessionStorage($path)
    {
        if (!is_dir($path)) {
            throw new Exception\ConfigurationException('Session storage path is not a directory');
        } else if (!is_writable($path)) {
            throw new Exception\ConfigurationException('Session storage path is not writable');
        }
        $this->sessionStorage = $path;
    }

    public function getSessionStorage()
    {
        if ($this->sessionStorage) {
            return $this->sessionStorage;
        } else {
            return sys_get_temp_dir();
        }
    }

    protected function initSession()
    {
        if (!isset($this->session)) {
            $sessionId = substr(sha1($this->getEmail()), 0, 7);
            $this->session = new Session(
                $this->getSessionStorage().'/'.'erpk.'.$sessionId
            );

            $this->internalClient->setDefaultOption('cookies', $this->session->getCookieJar());
        }
    }
    
    public function getSession()
    {
        if (!isset($this->session)) {
            throw new Exception\ConfigurationException('Session has not been initialized');
        }
        return $this->session;
    }
    
    public function hasProxy()
    {
        return $this->proxy instanceof ProxyInterface;
    }
    
    public function getProxy()
    {
        return $this->proxy;
    }
    
    public function setProxy(ProxyInterface $proxy)
    {
        $this->proxy = $proxy;
        $this->internalClient->setDefaultOption('proxy', (string)$proxy);
    }
    
    public function removeProxy()
    {
        $this->proxy = null;
        $this->internalClient->setDefaultOption('proxy', null);
    }
    
    public function login()
    {
        $this->loginModule->login();
    }

    public function logout()
    {
        $this->loginModule->logout();
    }

    public function checkLogin()
    {
        if (!$this->getSession()->isValid()) {
            $this->login();
        }
    }

    public function get($url = null)
    {
        return new Request($this->internalClient, $this, 'GET', $url);
    }

    public function post($url = null)
    {
        return new Request($this->internalClient, $this, 'POST', $url);
    }

    public function send(RequestInterface $request)
    {
        return new Response($this->internalClient->send($request));
    }
}
