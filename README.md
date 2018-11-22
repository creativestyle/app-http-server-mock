![Travis Build Status](https://travis-ci.org/creativestyle/app-http-server-mock.svg?branch=master)

PHP HTTP App Server for use in tests
====================================

This library allows to query HTTP endpoints in your unit/integration tests without spinning up a whole webserver.

It uses PHP's built-in web-server underneath, but it's completely opaque and you don't have to worry about anything.

Usage
=====

_WARNING_ This has to be installed as a composer dependency - it may not work if you just drop it in.

```
composer require --dev creativestyle/app-http-server-mock
``` 

Now you need to subclass `Creativestyle\AppHttpServerMock\Server` and implement the only abstract method `registerRequestHandlers`:

```php
<?php

namespace YourNamespace;

use Creativestyle\AppHttpServerMock\Server;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class YourTestServer extends Server
{
    protected function registerRequestHandlers()
    {
        $this->registerRequestHandler('GET', '/', function(Request $request) {
            return new Response('Hello');
        });
        
        $this->registerRequestHandler(['PUT', 'POST'], '/number/(?<num>\d+)/test', function(Request $request, array $args) {
            return [
                'arrays' => [
                    'are',
                    'transformed',
                    'into',
                    'json' => ['how' => 'automatically']
                ],
                'your_method' => $request->getMethod(),
                'your_number' => $args['num']
            ];
        });
    }
}
```

And now you can just use your server in the tests:

```php
<?php

namespace YouNamespace\Tests;

use YourNamespace\YourTestServer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;

class YourTest extends TestCase
{
    /**
     * @var YourTestServer
     */
    private static $testServer;

    public static function setUpBeforeClass()
    {
        self::$testServer = new YourTestServer();
        self::$testServer->start();
    }

    public static function tearDownAfterClass()
    {
        self::$testServer->stop();
    }

    private function getClient()
    {
        return new Client([
            'base_uri' => self::$testServer->getBaseUrl(),
            'http_errors' => false
        ]);
    }

    public function testSomething()
    {
        $response = $this->getClient()->get('/');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello', $response->getBody()->getContents());
    }
}
```

Of course you could use the server in the `setUp()` and `tearDown()` methods but it's non-optimal from the perf.
perspective as the server would be started/stopped before/after each test.

To get more usage examples and see what's possible see the `/tests` subdirectory of this package - it should be all
self-explanatory.