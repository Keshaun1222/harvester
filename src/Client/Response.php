<?php
namespace Erpk\Harvester\Client;

use GuzzleHttp\Message\Response as GuzzleResponse;
use XPathSelector\Selector;

class Response
{
    /**
     * @var GuzzleResponse
     */
    protected $internalResponse;

    public function __construct($response)
    {
        $this->internalResponse = $response;
    }

    public function xpath()
    {
        return Selector::loadHTML($this->getBody(true));
    }

    public function getBody($text = true)
    {
        $body = $this->internalResponse->getBody();
        return $text ? $body->getContents() : $body;
    }

    public function isRedirect()
    {
        $status = $this->internalResponse->getStatusCode();
        return $status >= 300 && $status < 400;
    }

    public function getLocation()
    {
        return $this->internalResponse->getHeader('Location');
    }

    public function json()
    {
        return $this->internalResponse->json();
    }
}
