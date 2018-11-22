<?php

namespace Creativestyle\AppHttpServerMock\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase
{
    /**
     * @var TestServer
     */
    private static $testServer;

    public static function setUpBeforeClass()
    {
        self::$testServer = new TestServer();
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

    public function testSimplePage()
    {
        $response = $this->getClient()->get('/');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello', $response->getBody()->getContents());
        $this->assertEquals([TestServer::SERVER_NAME], $response->getHeader('X-Server'));
    }

    public function testPostBack()
    {
        $payload = date('dmYHis');

        $response = $this->getClient()->post('/post-back', [
            'body' => $payload
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($payload, $response->getBody()->getContents());
    }

    public function testPatternMatch()
    {
        $number = rand(888, 999);
        $response = $this->getClient()->get(sprintf('/pattern/%d/match', $number));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['application/json'], $response->getHeader('Content-Type'));
        $this->assertEquals(['lucky_number' => $number], json_decode($response->getBody()->getContents(), true));
    }

    public function test404()
    {
        $response = $this->getClient()->get('/pattern/not/match');

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testInvalidHandler()
    {
        $response = $this->getClient()->get('/invalid-handler');

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertContains('value that cannot be transformed into response', $response->getBody()->getContents(), true);
    }

    public function testHandlerCanReturnArray()
    {
        $response = $this->getClient()->get('/handler/return/array');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['application/json'], $response->getHeader('Content-Type'));
        $this->assertEquals(TestServer::TEST_ARRAY, json_decode($response->getBody()->getContents(), true));
    }

    public function testHandlerCanReturnString()
    {
        $response = $this->getClient()->get('/handler/return/string');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(1, $response->getHeader('Content-Type'));
        $this->assertContains('text/plain', $response->getHeader('Content-Type')[0]);
        $this->assertEquals(TestServer::TEST_STRING, $response->getBody()->getContents());
    }

    public function testHandlerCanReturnNull()
    {
        $response = $this->getClient()->get('/handler/return/no-content');

        $this->assertEquals(204, $response->getStatusCode());
        $this->assertEmpty($response->getBody()->getContents());
    }

    public function testUnknownMethod()
    {
        $response = $this->getClient()->patch('/unsupported-method');

        $this->assertEquals(405, $response->getStatusCode());
        $this->assertContains('PATCH', $response->getBody()->getContents(), true);
    }

    public function testThatExplicitHeadHandlingTakesPriority()
    {
        $response = $this->getClient()->head('/explicit-head');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['Request-Handler-Closure'], $response->getHeader('X-Returned-From'));
        $this->assertEmpty($response->getBody()->getContents());
    }

    public function testImplicitHeadSupport()
    {
        $response = $this->getClient()->head('/implicit-head');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['GET'], $response->getHeader('X-Handler-For'));
        $this->assertEmpty($response->getBody()->getContents());
    }

    public function testThatHandlerCanBeDefinedForMultipleMethods()
    {
        foreach (['GET', 'POST', 'PUT'] as $method) {
            $response = $this->getClient()->request($method, '/handler/multiple-methods');

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals($method, $response->getBody()->getContents());
        }
    }

    public function testThatServerCanBeStopped()
    {
        $server = new TestServer();
        $client = new Client(['base_uri' => $server->getBaseUrl()]);

        $server->start();
        $this->assertEquals(200, $client->get('/')->getStatusCode());

        $server->stop();
        $this->expectException(ConnectException::class);
        $client->get('/')->getStatusCode();
    }
}