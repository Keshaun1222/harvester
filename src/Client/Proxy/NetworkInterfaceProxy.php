<?php
namespace Erpk\Harvester\Client\Proxy;

class NetworkInterfaceProxy implements ProxyInterface
{
    /**
     * @var string
     */
    protected $iface;

    /**
     * @param string $iface Outgoing network interface
     */
    public function __construct($iface)
    {
        $this->iface = $iface;
    }

    /**
     * @return string Network interface
     */
    public function getNetworkInterface()
    {
        return $this->iface;
    }
}