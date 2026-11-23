<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Dns;

use Devable\MaintenanceDnsBundle\Dns\DnsMessage;
use Devable\MaintenanceDnsBundle\Dns\DnsProtocolException;
use Devable\MaintenanceDnsBundle\Dns\DnsTransportInterface;

/**
 * Answers queries from a table: "nameserver|name|type" or "name|type" => callable(DnsResponseBuilder): (DnsResponseBuilder|string)
 * A string result is thrown as DnsProtocolException (e.g. timeout).
 *
 * @internal
 */
final class FakeDnsTransport implements DnsTransportInterface
{
    /**
     * @var list<array{nameserver: string, name: string, type: int, timeout: float}>
     */
    public array $queries = [];

    /**
     * @param array<string, \Closure(DnsResponseBuilder): (DnsResponseBuilder|string)> $answers
     */
    public function __construct(private array $answers = [])
    {
    }

    /**
     * @param \Closure(DnsResponseBuilder): (DnsResponseBuilder|string) $answer
     */
    public function on(string $name, string $type, \Closure $answer, string|null $nameserver = null): self
    {
        $this->answers[$this->key($name, DnsMessage::typeFromName($type), $nameserver)] = $answer;

        return $this;
    }

    public function query(string $nameserver, string $packet, float $timeoutSeconds): string
    {
        $builder = DnsResponseBuilder::forQuery($packet);
        $parsed = \unpack('ntype', $packet, \strlen($packet) - 4);
        \assert(\is_array($parsed));
        $type = (int) $parsed['type'];
        $name = $this->questionName($packet);

        $this->queries[] = [ 'nameserver' => $nameserver, 'name' => $name, 'type' => $type, 'timeout' => $timeoutSeconds ];

        $answer = $this->answers[$this->key($name, $type, $nameserver)] ?? $this->answers[$this->key($name, $type)] ?? null;
        if (null === $answer) {
            throw new DnsProtocolException(\sprintf('DNS query to %s timed out after %.1fs.', $nameserver, $timeoutSeconds));
        }

        $result = $answer($builder);
        if (\is_string($result)) {
            throw new DnsProtocolException($result);
        }

        return $result->build();
    }

    private function key(string $name, int $type, string|null $nameserver = null): string
    {
        return ($nameserver ?? '*') . '|' . \strtolower($name) . '|' . $type;
    }

    private function questionName(string $packet): string
    {
        $labels = [];
        $offset = 12;
        while (0 !== ($length = \ord($packet[$offset]))) {
            $labels[] = \substr($packet, $offset + 1, $length);
            $offset += 1 + $length;
        }

        return \implode('.', $labels);
    }
}
