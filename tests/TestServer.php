<?php

namespace Creativestyle\AppHttpServerMock\Tests;

use Creativestyle\AppHttpServerMock\Server;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TestServer extends Server
{
    const SERVER_NAME = 'TestTestServerMetaMetaMeta';
    const TEST_STRING = 'just-a-test-string 🍺';
    const TEST_ARRAY = [
        'data' => [
            'type' => 'json',
            'number' => 42,
        ],
        'array' => [1, 2, 3, 4]
    ];

    protected function registerRequestHandlers()
    {
        $this->registerRequestHandler('GET', '/', function(Request $request) {
            return new Response('Hello');
        });

        $this->registerRequestHandler('POST', '/post-back', function(Request $request) {
            return new Response($request->getContent());
        });

        $this->registerRequestHandler('GET', '/pattern/(?<number>\d+)/match', function(Request $request, array $params) {
            return new Response(json_encode(['lucky_number' => (int)$params['number']]), 200, [
                'Content-Type' => 'application/json'
            ]);
        });

        $this->registerRequestHandler('GET', '/invalid-handler', function(Request $request, array $params) {
            return new Request();
        });

        $this->registerRequestHandler('GET', '/handler/return/array', function(Request $request, array $params) {
            return self::TEST_ARRAY;
        });

        $this->registerRequestHandler('GET', '/handler/return/string', function(Request $request, array $params) {
            return self::TEST_STRING;
        });

        $this->registerRequestHandler('GET', '/handler/return/no-content', function(Request $request, array $params) {
            return null;
        });

        $this->registerRequestHandler(['GET', 'POST', 'PUT'], '/handler/multiple-methods', function(Request $request, array $params) {
            return $request->getMethod();
        });

        $this->registerRequestHandler(['GET', 'HEAD'], '/explicit-head', function(Request $request, array $params) {
            return new Response('Shall be stripped', 200, [
                'X-Returned-From' => 'Request-Handler-Closure'
            ]);
        });

        $this->registerRequestHandler(['GET'], '/implicit-head', function(Request $request, array $params) {
            return new Response('Shall be stripped', 200, [
                'X-Handler-For' => 'GET'
            ]);
        });

    }
}