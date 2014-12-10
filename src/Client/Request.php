<?php
namespace Erpk\Harvester\Client;

use GuzzleHttp\Query;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Post\PostBody;
use GuzzleHttp\Client as GuzzleClient;

class Request
{
    /**
     * @var GuzzleClient
     */
    protected $internalClient;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $method;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var array
     */
    protected $options;

    public function __construct(GuzzleClient $internalClient, Client $client, $method, $url)
    {
        $this->url = $url;
        $this->options = [
            'headers' => [], 'query' => new Query(), 'body' => null
        ];
        $this->method = $method;
        $this->client = $client;
        $this->internalClient = $internalClient;
    }

    public function disableCookies()
    {
        $this->options['cookies'] = false;
    }

    public function followRedirects()
    {
        $this->options['allow_redirects'] = true;
    }

    public function markXHR()
    {
        $this->setHeader('X-Requested-With', 'XMLHttpRequest');
    }

    public function setRelativeReferer($url = '')
    {
        $referer = $this->internalClient->getBaseUrl().'/en';
        if (!empty($url)) {
            $referer .= '/'.$url;
        }

        $this->setHeader('Referer', $referer);
    }

    public function getQuery()
    {
        return $this->options['query'];
    }

    public function setHeader($key, $value)
    {
        $this->options['headers'][$key] = $value;
    }

    public function addPostFields($fields)
    {
        if (!($this->options['body'] instanceof PostBodyInterface)) {
            $this->options['body'] = new PostBody();
        }

        foreach ($fields as $key => $value) {
            $this->options['body']->setField($key, $value);
        }
    }

    public function send()
    {
        if (stripos($this->url, 'http') === 0) {
            $url = $this->url;
        } else {
            $url = '/en' . (empty($this->url) ? '' : '/'.$this->url);
        }

        return $this->client->send($this->internalClient->createRequest(
            $this->method,
            $url,
            $this->options
        ));
    }
} 