<?php
namespace Erpk\Harvester\Client;

use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SetCookie;

class Session
{
    /**
     * @var string
     */
    protected $savePath;

    /**
     * @var FileCookieJar
     */
    protected $cookieJar;

    /**
     * @var array|mixed
     */
    protected $data = [
        'touch'     =>  null,
        'token'     =>  null,
        'citizen'   => ['id' => null, 'name' => null]
    ];
    
    public function __construct($savePath)
    {
        $this->savePath = $savePath;

        if (file_exists($savePath.'.sess')) {
            $this->data = unserialize(file_get_contents($savePath.'.sess'));
        }

        $this->cookieJar = new FileCookieJar($savePath.'.cookies');
    }

    public function save()
    {
        $this->cookieJar->save($this->savePath.'.cookies');
        file_put_contents($this->savePath.'.sess', serialize($this->data));
    }
    
    public function __destruct()
    {
        $this->save();
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        if ($this->getToken() == null) {
            return false;
        }

        foreach ($this->getCookieJar() as $cookie) {
            /**
             * @var SetCookie $cookie
             */
            if ($cookie->getName() == 'erpk' && $cookie->getDomain() == '.erepublik.com') {
                return $cookie->getExpires() - time() > 30; // more than 30 seconds to expire
            }
        }

        return false;
    }

    /**
     * @return FileCookieJar
     */
    public function getCookieJar()
    {
        return $this->cookieJar;
    }

    /**
     * @return string
     */
    public function getCitizenName()
    {
        return $this->data['citizen']['name'];
    }

    public function setCitizenName($name)
    {
        $this->data['citizen']['name'] = $name;
        return $this;
    }

    /**
     * @return int
     */
    public function getCitizenId()
    {
        return $this->data['citizen']['id'];
    }

    public function setCitizenId($id)
    {
        $this->data['citizen']['id'] = $id;
        return $this;
    }

    /**
     * @return string CSRF Token
     */
    public function getToken()
    {
        return $this->data['token'];
    }
    
    public function setToken($token)
    {
        $this->data['token'] = $token;
        return $this;
    }
}
