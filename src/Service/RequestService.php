<?php

namespace Jisc\Service;

use GuzzleHttp\Client;

class RequestService
{
    const HEADERS_DEFAULT = ['Content-Type' => 'application/json'];

    const JIRA_CREATE_URI = '/rest/api/2/issue/';

    const REQUEST_AUTH = 'auth';
    const REQUEST_HEADERS = 'headers';
    const REQUEST_BODY = 'body';

    /** @var \GuzzleHttp\Client */
    private $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $options
     *
     * @throws \GuzzleHttp\Exception\RequestException
     */
    public function httpRequest(string $method, string $url, array $options = array())
    {
        $this->client->request($method, $url, $options);
    }
}
