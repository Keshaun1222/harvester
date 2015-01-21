<?php
namespace Erpk\Harvester\Client;

use cURL;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Message\Response as GuzzleResponse;
use GuzzleHttp\Query;
use GuzzleHttp\Post\PostBodyInterface;
use GuzzleHttp\Post\PostBody;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Stream\Stream;

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

    /**
     * @return Query
     */
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

    protected function getAbsoluteUrl()
    {
        if (stripos($this->url, 'http') === 0) {
            $url = $this->url;
        } else {
            $url = '/en' . (empty($this->url) ? '' : '/'.$this->url);
        }

        return $url;
    }

    /**
     * @param callable $callback The function called after request is complete.
     *                           It has 1 argument, which is Erpk\Harvester\Client\Response
     * @return \cURL\Request
     * @todo Implement proxy binding to cURL\Request
     */
    public function createCurlRequest(callable $callback)
    {
        $internalRequest = $this->createInternalRequest();

        // intercepting final request with all headers set
        /**
         * @var \GuzzleHttp\Message\Request $request
         */
        $request = null;
        $internalRequest->getEmitter()->on('before', function (BeforeEvent $event) use (&$request) {
            $request = $event->getRequest();
            $event->intercept(new GuzzleResponse(200)); // cancel request sending
        });

        $response = $this->client->send($internalRequest);

        // preparing cURL\Request instance
        $config = $request->getConfig();
        $requestHeaders = [];
        foreach ($request->getHeaders() as $key => $val) {
            $requestHeaders[] = "$key: $val[0]";
        }

        $rawHeaders = [];
        $ch = new cURL\Request($request->getUrl());
        $ch->getOptions()
            ->set(CURLOPT_RETURNTRANSFER, true)
            ->set(CURLOPT_CONNECTTIMEOUT, $config['connect_timeout'])
            ->set(CURLOPT_TIMEOUT, $config['timeout'])
            ->set(CURLOPT_FOLLOWLOCATION, isset($config['redirect']))
            ->set(CURLOPT_HTTPHEADER, $requestHeaders)
            ->set(CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$rawHeaders) {
                $rawHeaders[] = $line;
                return strlen($line);
            })
        ;

        if ($request->getMethod() == 'POST') {
            $ch->getOptions()
                ->set(CURLOPT_POST, true)
                ->set(CURLOPT_POSTFIELDS, $request->getBody()->getFields());
        }

        // adding processing callback function
        $ch->addListener('complete', function (cURL\Event $event) use ($callback, &$rawHeaders, $request) {
            $info = $event->response->getInfo();
            $headers = Core::headersFromLines($rawHeaders);
            $headers = array_filter($headers, function ($val) {
                return $val[0] != null;
            });
            $internalResponse = new GuzzleResponse(
                $info['http_code'],
                $headers,
                Stream::factory($event->response->getContent())
            );

            $this->client->getSession()->getCookieJar()->extractCookies($request, $internalResponse);

            $callback(new Response($internalResponse));
        });

        return $ch;
    }

    protected function createInternalRequest()
    {
        return $this->internalClient->createRequest(
            $this->method,
            $this->getAbsoluteUrl(),
            $this->options
        );
    }

    public function send()
    {
        return $this->client->send($this->createInternalRequest());
    }
} 