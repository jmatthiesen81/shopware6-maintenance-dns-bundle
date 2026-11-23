<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Dns;

use Devable\MaintenanceDnsBundle\Dns\DnsMessage;

/**
 * Builds DNS response packets for tests.
 *
 * @internal
 */
final class DnsResponseBuilder
{
    /**
     * @var list<string>
     */
    private array $answers = [];

    private int $rcode = DnsMessage::RCODE_NOERROR;

    private bool $truncated = false;

    private bool $response = true;

    private function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly int $type,
    ) {
    }

    public static function for(int $id, string $name, int $type = DnsMessage::TYPE_A): self
    {
        return new self($id, $name, $type);
    }

    /**
     * Builds a response for the query packet, using its ID, name and type.
     */
    public static function forQuery(string $query): self
    {
        /** @var array{id: int} $header */
        $header = \unpack('nid', $query);

        $labels = [];
        $offset = 12;
        while (0 !== ($length = \ord($query[$offset]))) {
            $labels[] = \substr($query, $offset + 1, $length);
            $offset += 1 + $length;
        }

        /** @var array{type: int} $question */
        $question = \unpack('ntype', $query, $offset + 1);

        return new self($header['id'], \implode('.', $labels), $question['type']);
    }

    public function a(string $ip, string|null $owner = null): self
    {
        $this->answers[] = $this->record($owner ?? $this->name, DnsMessage::TYPE_A, (string) \inet_pton($ip));

        return $this;
    }

    public function aaaa(string $ip, string|null $owner = null): self
    {
        $this->answers[] = $this->record($owner ?? $this->name, DnsMessage::TYPE_AAAA, (string) \inet_pton($ip));

        return $this;
    }

    public function cname(string $target, string|null $owner = null): self
    {
        $this->answers[] = $this->record($owner ?? $this->name, DnsMessage::TYPE_CNAME, self::name($target));

        return $this;
    }

    public function raw(string $answer): self
    {
        $this->answers[] = $answer;

        return $this;
    }

    public function rcode(int $rcode): self
    {
        $this->rcode = $rcode;

        return $this;
    }

    public function truncated(): self
    {
        $this->truncated = true;

        return $this;
    }

    public function notAResponse(): self
    {
        $this->response = false;

        return $this;
    }

    public function build(): string
    {
        // QR, RD, RA, TC and RCODE
        $flags = ($this->response ? 0x8000 : 0) | 0x0180 | ($this->truncated ? 0x0200 : 0) | $this->rcode;

        return \pack('nnnnnn', $this->id, $flags, 1, \count($this->answers), 0, 0)
            . self::name($this->name) . \pack('nn', $this->type, 1)
            . \implode('', $this->answers);
    }

    public static function name(string $name): string
    {
        $encoded = '';
        foreach (\explode('.', $name) as $label) {
            $encoded .= \chr(\strlen($label)) . $label;
        }

        return $encoded . "\0";
    }

    private function record(string $owner, int $type, string $data): string
    {
        // Owner names that equal the question are written as compression pointer to offset 12
        $ownerName = \strtolower($owner) === \strtolower($this->name) ? "\xC0\x0C" : self::name($owner);

        return $ownerName . \pack('nnNn', $type, 1, 300, \strlen($data)) . $data;
    }
}
