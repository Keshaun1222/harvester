<?php
namespace Erpk\Harvester\Client;

use Erpk\Harvester\Module\Login\LoginModule;
use Erpk\Harvester\Exception;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\UriInterface;

class Client
{
    /**
     * @var GuzzleClient
     */
    private $internalClient;

    /**
     * @var Session
     */
    private $session;

    /**
     * @param array $internalConfig
     * @param Session $session
     */
    public function __construct(array $internalConfig, Session $session)
    {
        $this->internalClient = new GuzzleClient($internalConfig);
        $this->session = $session;
        $this->loginModule = new LoginModule($this);
    }

    /**
     * @return UriInterface
     */
    public function getBaseUri()
    {
        return $this->internalClient->getConfig('base_uri');
    }

    public function getBaseUrl()
    {
    }

    /**
     * @return Session
     * @throws Exception\ConfigurationException
     */
    public function getSession()
    {
        return $this->session;
    }

    /**
     * @param string $url
     * @return Request
     */
    public function get($url = null)
    {
        return new Request($this->internalClient, $this, 'GET', $url);
    }

    /**
     * @param string $url
     * @return Request
     */
    public function post($url = null)
    {
        return new Request($this->internalClient, $this, 'POST', $url);
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
}
