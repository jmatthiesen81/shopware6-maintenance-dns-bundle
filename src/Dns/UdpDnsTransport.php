<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

/**
 * Sends one DNS query via UDP and waits at most the given time for the matching answer.
 *
 * Unlike dns_get_record() / gethostbyname(), this enforces a hard timeout and reports NXDOMAIN, SERVFAIL and
 * timeouts separately.
 */
final class UdpDnsTransport implements DnsTransportInterface
{
    private const MAX_PACKET = 4096;

    public function __construct(private readonly int $port = 53)
    {
    }

    public function query(string $nameserver, string $packet, float $timeoutSeconds): string
    {
        $deadline = \microtime(true) + $timeoutSeconds;
        $host = \str_contains($nameserver, ':') ? '[' . $nameserver . ']' : $nameserver;

        $socket = @\stream_socket_client(\sprintf('udp://%s:%d', $host, $this->port), $errorCode, $errorMessage, $timeoutSeconds);
        if (false === $socket) {
            throw new DnsProtocolException(\sprintf('Cannot open UDP socket to %s: %s', $nameserver, $errorMessage));
        }

        try {
            if (\strlen($packet) !== @\fwrite($socket, $packet)) {
                throw new DnsProtocolException(\sprintf('Cannot send DNS query to %s.', $nameserver));
            }

            $expectedId = \substr($packet, 0, 2);

            // Skip stray datagrams (e.g. late answers to earlier queries) until the matching ID arrives
            while (($remaining = $deadline - \microtime(true)) > 0) {
                $seconds = (int) $remaining;
                $read = [ $socket ];
                $write = $except = null;

                $ready = @\stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
                if (false === $ready) {
                    throw new DnsProtocolException(\sprintf('Waiting for DNS answer from %s failed.', $nameserver));
                }

                if (0 === $ready) {
                    break;
                }

                $response = @\fread($socket, self::MAX_PACKET);
                if (false === $response || '' === $response) {
                    // ICMP port unreachable etc.
                    throw new DnsProtocolException(\sprintf('No DNS answer from %s.', $nameserver));
                }

                if (\str_starts_with($response, $expectedId)) {
                    return $response;
                }
            }

            throw new DnsProtocolException(\sprintf('DNS query to %s timed out after %.1fs.', $nameserver, $timeoutSeconds));
        } finally {
            \fclose($socket);
        }
    }
}
