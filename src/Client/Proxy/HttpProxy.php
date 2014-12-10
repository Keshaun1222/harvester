<?php
namespace Erpk\Harvester\Client\Proxy;

class HttpProxy implements ProxyInterface
{
    public $hostname;
    public $port;
    public $username;
    public $password;
    
    public function __construct($host, $port, $user = null, $pass = null)
    {
        $this->hostname = $host;
        $this->port = $port;
        $this->username = $user;
        $this->password = $pass;
    }

    public function __toString()
    {
        $str = 'tcp://';
        if (isset($this->password)) {
            $str .= $this->username.':'.$this->password.'@';
        }
        $str .= $this->hostname.':'.$this->port;
        return $str;
    }
}
