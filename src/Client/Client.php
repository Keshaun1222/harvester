<?php
namespace Erpk\Harvester\Client;

use Erpk\Harvester\Module\Login\LoginModule;
use Erpk\Harvester\Exception;
use Erpk\Harvester\Client\Proxy\ProxyInterface;
use Guzzle\Plugin\Cookie\CookiePlugin;
use Guzzle\Http\Client as GuzzleClient;

class Client extends GuzzleClient implements ClientInterface
{
    private $session;
    private $sessionStorage;
    private $email;
    private $password;
    private $proxy;
    
    public function __construct()
    {
        parent::__construct(
            'http://www.erepublik.com/en',
            ['redirect.disable' => true]
        );
        
        $this->getConfig()->set('curl.options', [
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20
        ]);
        
        $this->getDefaultHeaders()
            ->set('Expect', '')
            ->set('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
            ->set('Accept-Language', 'en-US,en;q=0.8');

        $this->setUserAgent('Mozilla/5.0 (Windows NT 6.3; WOW64; rv:31.0) Gecko/20100101 Firefox/31.0');

        $this->loginModule = new LoginModule($this);
    }

    public function setEmail($email)
    {
        $this->email = $email;
        $this->initSession();
        return $this;
    }
    
    public function getEmail()
    {
        if (isset($this->email)) {
            return $this->email;
        } else {
            throw new Exception\ConfigurationException('Account e-mail address not specified');
        }
    }
    
    public function setPassword($pwd)
    {
        $this->password = $pwd;
        return $this;
    }
    
    public function getPassword()
    {
        if (isset($this->password)) {
            return $this->password;
        } else {
            throw new Exception\ConfigurationException('Account password not specified');
        }
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
                $this->getSessionStorage().'/'.'erpk.'.$sessionId.'.sess'
            );
            $cookiePlugin = new CookiePlugin($this->session->getCookieJar());
            $this->getEventDispatcher()->addSubscriber($cookiePlugin);
        }
    }
    
    public function getSession()
    {
        if (isset($this->session)) {
            return $this->session;
        } else {
            throw new Exception\ConfigurationException('Session has not been initialized');
        }
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
        if ($this->hasProxy()) {
            $this->proxy->remove($this);
        }
        
        $this->proxy = $proxy;
        $this->proxy->apply($this);
        return $this;
    }
    
    public function removeProxy()
    {
        $this->proxy->remove($this);
        $this->proxy = null;
    }
    
    public function login()
    {
        return $this->loginModule->login();
    }

    public function logout()
    {
        return $this->loginModule->logout();
    }

    public function checkLogin()
    {
        if (!$this->getSession()->isValid()) {
            $this->login();
        }
    }

    public function send($requests)
    {
        $responses = parent::send($requests);
        if (is_array($responses)) {
            return array_map(function ($request) {
                return new ResponseWrapper($request->getResponse());
            }, $requests);
        } else {
            return new ResponseWrapper($responses);
        }
    }
}
