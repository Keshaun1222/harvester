<?php
namespace Erpk\Harvester\Client;

use cURL;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;

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
    protected $uri;

    /**
     * @var array
     */
    protected $options = ['headers' => []];

    /**
     * @var Query
     */
    protected $query;

    /**
     * @var bool
     */
    protected $autologin = true;

    /**
     * @param ClientInterface $internalClient
     * @param Client $client
     * @param $method
     * @param string $uri
     */
    public function __construct(ClientInterface $internalClient, Client $client, $method, $uri)
    {
        $this->uri = $uri;
        $this->method = $method;
        $this->client = $client;
        $this->internalClient = $internalClient;
        $this->query = new Query();
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
        return $this->xhr();
    }

    /**
     * @return Request|$this
     */
    public function xhr()
    {
        $this->setHeader('X-Requested-With', 'XMLHttpRequest');
        return $this;
    }

    /**
     * @param string $rel
     */
    public function setRelativeReferer($rel = '')
    {
        $this->setHeader(
            'Referer',
            (string)Uri::resolve($this->client->getBaseUri(), $rel)
        );
    }

    /**
     * @return Query
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function setHeader($key, $value)
    {
        $this->options['headers'][$key] = $value;
    }

    /**
     * @param string[] $fields
     */
    public function addPostFields(array $fields)
    {
        $form = &$this->options['form_params'];
        if (!isset($form)) {
            $form = [];
        }

        foreach ($fields as $key => $value) {
            $form[$key] = $value;
        }
    }

    /**
     * @return Request $this
     */
    public function csrf()
    {
        $this->addPostFields([
            '_token' => $this->client->getSession()->getToken()
        ]);

        return $this;
    }

    public function disableAutologin()
    {
        $this->autologin = false;
    }

    /**
     * @return string
     */
    protected function getAbsoluteUri()
    {
        $base = $this->client->getBaseUri();
        return !empty($this->uri)
            ? Uri::resolve(new Uri($base . '/'), $this->uri)
            : $base;
    }

    /**
     * @param callable $callback The function called after request is complete.
     *                           It has 1 argument, which is Erpk\Harvester\Client\Response
     * @return \cURL\Request
     * @todo Implement proxy binding to cURL\Request
     */
    public function createCurlRequest(callable $callback)
    {
        /*$internalRequest = $this->createInternalRequest();

        // intercepting final request with all headers set
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

        return $ch;*/
    }

    /**
     * @return Response
     */
    public function send()
    {
        if ($this->autologin) {
            $this->client->checkLogin();
        }

        $this->options['query'] = $this->query->toArray();
        return new Response($this->internalClient->request(
            $this->method,
            $this->getAbsoluteUri(),
            $this->options
        ));
    }
}
