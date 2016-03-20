<?php
namespace Erpk\Harvester\Module;

use Erpk\Common\EntityManager;
use Erpk\Harvester\Client\Client;
use Erpk\Harvester\Client\Session;

abstract class Module
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return Session
     */
    public function getSession()
    {
        return $this->client->getSession();
    }

    /**
     * @return EntityManager
     */
    public function getEntityManager()
    {
        return EntityManager::getInstance();
    }
}
