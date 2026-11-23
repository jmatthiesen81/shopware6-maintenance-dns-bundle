<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Dns;

use Devable\MaintenanceDnsBundle\Dns\DnsMessage;
use Devable\MaintenanceDnsBundle\Dns\DnsProtocolException;
use Devable\MaintenanceDnsBundle\Dns\UdpDnsTransport;
use PHPUnit\Framework\TestCase;

/**
 * Talks to a local UDP socket on 127.0.0.1.
 */
class UdpDnsTransportTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $server;

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \fclose($this->server);
        }
    }

    public function testTimeoutIsEnforced(): void
    {
        $port = $this->startServer();

        $start = \microtime(true);

        try {
            (new UdpDnsTransport($port))->query('127.0.0.1', DnsMessage::buildQuery(1, 'office.example.net', DnsMessage::TYPE_A), 0.3);
            static::fail('Expected a timeout');
        } catch (DnsProtocolException $exception) {
            static::assertStringContainsString('timed out', $exception->getMessage());
        }

        $elapsed = \microtime(true) - $start;
        static::assertGreaterThanOrEqual(0.25, $elapsed);
        static::assertLessThan(1.5, $elapsed);
    }

    public function testReturnsMatchingAnswerAndSkipsStrayPackets(): void
    {
        if (!\function_exists('pcntl_fork')) {
            static::markTestSkipped('pcntl is required to answer in a child process');
        }

        $port = $this->startServer();
        $query = DnsMessage::buildQuery(0x4242, 'office.example.net', DnsMessage::TYPE_A);

        $pid = \pcntl_fork();
        if (0 === $pid) {
            \assert(\is_resource($this->server));
            $packet = \stream_socket_recvfrom($this->server, 512, 0, $peer);
            \stream_socket_sendto($this->server, DnsResponseBuilder::for(0x1111, 'office.example.net')->a('85.10.20.1')->build(), 0, $peer);
            \stream_socket_sendto($this->server, DnsResponseBuilder::forQuery((string) $packet)->a('81.2.69.10')->build(), 0, $peer);
            exit(0);
        }

        $response = (new UdpDnsTransport($port))->query('127.0.0.1', $query, 2.0);
        \pcntl_waitpid($pid, $status);

        static::assertSame([ '81.2.69.10' ], DnsMessage::parseResponse($response, 0x4242, 'office.example.net', DnsMessage::TYPE_A)->addresses);
    }

    private function startServer(): int
    {
        $server = \stream_socket_server('udp://127.0.0.1:0', $errorCode, $errorMessage, \STREAM_SERVER_BIND);
        if (false === $server) {
            static::markTestSkipped('Cannot bind a local UDP socket: ' . $errorMessage);
        }

        $this->server = $server;
        $name = (string) \stream_socket_get_name($server, false);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }
}
