<?php
namespace Erpk\Harvester\Client;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use XPathSelector\Selector;

class Response
{
    /**
     * @var ResponseInterface
     */
    protected $internalResponse;

    /**
     * @param ResponseInterface $response
     */
    public function __construct(ResponseInterface $response)
    {
        $this->internalResponse = $response;
    }

    /**
     * @return Selector
     */
    public function xpath()
    {
        return Selector::loadHTML($this->getBody(true));
    }

    /**
     * @param bool|true $text
     * @return StreamInterface|string
     */
    public function getBody($text = true)
    {
        $body = $this->internalResponse->getBody();
        return $text ? $body->getContents() : $body;
    }

    /**
     * @return bool
     */
    public function isRedirect()
    {
        $status = $this->internalResponse->getStatusCode();
        return $status >= 300 && $status < 400;
    }

    /**
     * @return string[]
     */
    public function getLocation()
    {
        return $this->internalResponse->getHeader('Location');
    }

    /**
     * @return mixed
     */
    public function json()
    {
        return json_decode($this->getBody(), true);
    }
}
