<?php

use React\Stream\Stream;
use Clue\React\Socks\Client;
use Clue\React\Socks\Server\Server;
use Clue\React\Block;

class FunctionalTest extends TestCase
{
    private $loop;
    private $client;
    private $server;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();

        $socket = $this->createSocketServer();
        $port = $socket->getPort();
        $this->assertNotEquals(0, $port);

        $this->server = new Server($this->loop, $socket);
        $this->client = new Client('127.0.0.1:' . $port, $this->loop);
    }

    public function testConnection()
    {
        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionSocks4()
    {
        $this->server->setProtocolVersion(4);
        $this->client->setProtocolVersion(4);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionSocks5()
    {
        $this->server->setProtocolVersion(5);
        $this->client->setProtocolVersion(5);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidSocks4aRemote()
    {
        $this->client->setProtocolVersion('4a');
        $this->client->setResolveLocal(false);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionSocks5Remote()
    {
        $this->client->setProtocolVersion(5);
        $this->client->setResolveLocal(false);

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client->setAuth('name', 'pass');

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionAuthenticationUnused()
    {
        $this->client->setAuth('name', 'pass');

        $this->assertResolveStream($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidProtocolMismatch()
    {
        $this->server->setProtocolVersion(5);
        $this->client->setProtocolVersion(4);

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidNoAuthentication()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client->setProtocolVersion(5);

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectionInvalidAuthenticationMismatch()
    {
        $this->server->setAuthArray(array('name' => 'pass'));
        $this->client->setAuth('user', 'other');

        $this->assertRejectPromise($this->client->createConnection('www.google.com', 80));
    }

    public function testConnectorOkay()
    {
        $tcp = $this->client->createConnector();

        $this->assertResolveStream($tcp->create('www.google.com', 80));
    }

    public function testConnectorInvalidDomain()
    {
        $tcp = $this->client->createConnector();

        $this->assertRejectPromise($tcp->create('www.google.commm', 80));
    }

    public function testConnectorCancelConnection()
    {
        $tcp = $this->client->createConnector();

        $promise = $tcp->create('www.google.com', 80);
        $promise->cancel();

        $this->assertRejectPromise($promise);
    }

    public function testConnectorInvalidUnboundPortTimeout()
    {
        $this->client->setTimeout(0.1);
        $tcp = $this->client->createConnector();

        $this->assertRejectPromise($tcp->create('www.google.com', 8080));
    }

    public function testSecureConnectorOkay()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector();

        $this->assertResolveStream($ssl->create('www.google.com', 443));
    }

    public function testSecureConnectorToBadSslWithVerifyFails()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector(array('verify_peer' => true));

        $this->assertRejectPromise($ssl->create('self-signed.badssl.com', 443));
    }

    public function testSecureConnectorToBadSslWithoutVerifyWorks()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector(array('verify_peer' => false));

        $this->assertResolveStream($ssl->create('self-signed.badssl.com', 443));
    }

    public function testSecureConnectorInvalidPlaintextIsNotSsl()
    {
        if (!function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('Required function does not exist in your environment (HHVM?)');
        }

        $ssl = $this->client->createSecureConnector();

        $this->assertRejectPromise($ssl->create('www.google.com', 80));
    }

    public function testSecureConnectorInvalidUnboundPortTimeout()
    {
        $this->client->setTimeout(0.1);
        $ssl = $this->client->createSecureConnector();

        $this->assertRejectPromise($ssl->create('www.google.com', 8080));
    }

    private function createSocketServer()
    {
        $socket = new React\Socket\Server($this->loop);
        $socket->listen(0);

        return $socket;
    }

    private function assertResolveStream($promise)
    {
        $this->expectPromiseResolve($promise);

        $promise->then(function ($stream) {
            $stream->close();
        });

        Block\await($promise, $this->loop, 2.0);
    }

    private function assertRejectPromise($promise)
    {
        $this->expectPromiseReject($promise);

        $this->setExpectedException('Exception');

        Block\await($promise, $this->loop, 2.0);
    }
}
