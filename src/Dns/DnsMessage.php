<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

/**
 * Minimal DNS message codec (RFC 1035) for A/AAAA lookups.
 *
 * Only what is needed to ask a recursive resolver for the addresses of one name: no EDNS, no DNSSEC.
 * Answers are accepted only for the queried name or a CNAME chain starting at it, so a response cannot
 * smuggle in records of other names.
 */
final class DnsMessage
{
    public const TYPE_A = 1;
    public const TYPE_CNAME = 5;
    public const TYPE_AAAA = 28;

    public const RCODE_NOERROR = 0;
    public const RCODE_SERVFAIL = 2;
    public const RCODE_NXDOMAIN = 3;

    private const CLASS_IN = 1;
    private const MAX_POINTER_JUMPS = 32;

    public static function typeFromName(string $recordType): int
    {
        return match (\strtoupper($recordType)) {
            'A' => self::TYPE_A,
            'AAAA' => self::TYPE_AAAA,
            default => throw new \InvalidArgumentException(\sprintf('Unsupported record type "%s".', $recordType)),
        };
    }

    public static function buildQuery(int $id, string $name, int $type): string
    {
        // ID, flags (RD), QDCOUNT=1, ANCOUNT, NSCOUNT, ARCOUNT
        $header = \pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);

        return $header . self::encodeName($name) . \pack('nn', $type, self::CLASS_IN);
    }

    public static function parseResponse(string $packet, int $expectedId, string $name, int $type): DnsResponse
    {
        if (\strlen($packet) < 12) {
            throw new DnsProtocolException('DNS response too short.');
        }

        /** @var array{id: int, flags: int, qd: int, an: int} $header */
        $header = \unpack('nid/nflags/nqd/nan', $packet);

        if ($header['id'] !== $expectedId) {
            throw new DnsProtocolException('DNS response ID does not match the query.');
        }

        if (0 === ($header['flags'] & 0x8000)) {
            throw new DnsProtocolException('DNS message is not a response.');
        }

        $rcode = $header['flags'] & 0x000F;
        $truncated = 0 !== ($header['flags'] & 0x0200);

        if (1 !== $header['qd']) {
            throw new DnsProtocolException('DNS response does not contain exactly one question.');
        }

        $offset = 12;
        $questionName = self::readName($packet, $offset);
        $question = self::unpackAt('nqtype/nqclass', $packet, $offset, 4);
        $offset += 4;

        if (\strtolower($questionName) !== self::canonicalName($name) || $question['qtype'] !== $type || self::CLASS_IN !== $question['qclass']) {
            throw new DnsProtocolException('DNS response question does not match the query.');
        }

        if (self::RCODE_NOERROR !== $rcode || $truncated) {
            return new DnsResponse($rcode, $truncated, []);
        }

        $records = [];
        for ($i = 0; $i < $header['an']; ++$i) {
            $owner = \strtolower(self::readName($packet, $offset));
            $meta = self::unpackAt('ntype/nclass/Nttl/nlength', $packet, $offset, 10);
            $offset += 10;

            if ($offset + $meta['length'] > \strlen($packet)) {
                throw new DnsProtocolException('DNS record exceeds the response.');
            }

            $data = \substr($packet, $offset, $meta['length']);
            $dataOffset = $offset;
            $offset += $meta['length'];

            if (self::CLASS_IN !== $meta['class']) {
                continue;
            }

            $records[] = match ($meta['type']) {
                self::TYPE_CNAME => [ 'owner' => $owner, 'type' => self::TYPE_CNAME, 'value' => \strtolower(self::readName($packet, $dataOffset)) ],
                self::TYPE_A, self::TYPE_AAAA => [ 'owner' => $owner, 'type' => $meta['type'], 'value' => $data ],
                default => null,
            };
        }

        return new DnsResponse($rcode, false, self::collectAddresses(\array_values(\array_filter($records)), self::canonicalName($name), $type));
    }

    /**
     * @param list<array{owner: string, type: int, value: string}> $records
     *
     * @return list<string>
     */
    private static function collectAddresses(array $records, string $name, int $type): array
    {
        // Follow the CNAME chain from the queried name, independent of the record order
        $names = [ $name => true ];
        do {
            $added = false;
            foreach ($records as $record) {
                if (self::TYPE_CNAME === $record['type'] && isset($names[$record['owner']]) && !isset($names[$record['value']])) {
                    $names[$record['value']] = true;
                    $added = true;
                }
            }
        } while ($added && \count($names) <= self::MAX_POINTER_JUMPS);

        $expectedLength = self::TYPE_A === $type ? 4 : 16;
        $addresses = [];
        foreach ($records as $record) {
            if ($record['type'] !== $type || !isset($names[$record['owner']]) || \strlen($record['value']) !== $expectedLength) {
                continue;
            }

            $address = \inet_ntop($record['value']);
            if (false !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private static function encodeName(string $name): string
    {
        $encoded = '';
        foreach (\explode('.', self::canonicalName($name)) as $label) {
            $length = \strlen($label);
            if (0 === $length || $length > 63) {
                throw new \InvalidArgumentException(\sprintf('Invalid DNS name "%s".', $name));
            }

            $encoded .= \chr($length) . $label;
        }

        return $encoded . "\0";
    }

    private static function canonicalName(string $name): string
    {
        return \rtrim(\strtolower($name), '.');
    }

    private static function readName(string $packet, int &$offset): string
    {
        $labels = [];
        $position = $offset;
        $jumps = 0;
        $length = \strlen($packet);

        while (true) {
            if ($position >= $length) {
                throw new DnsProtocolException('DNS name exceeds the response.');
            }

            $labelLength = \ord($packet[$position]);

            if (0 === $labelLength) {
                ++$position;
                break;
            }

            if (0xC0 === ($labelLength & 0xC0)) {
                if ($position + 1 >= $length) {
                    throw new DnsProtocolException('DNS name pointer exceeds the response.');
                }

                if (0 === $jumps) {
                    $offset = $position + 2;
                }

                if (++$jumps > self::MAX_POINTER_JUMPS) {
                    throw new DnsProtocolException('DNS name compression loop.');
                }

                $position = (($labelLength & 0x3F) << 8) | \ord($packet[$position + 1]);

                continue;
            }

            if ($labelLength > 63 || $position + 1 + $labelLength > $length) {
                throw new DnsProtocolException('Invalid DNS label.');
            }

            $labels[] = \substr($packet, $position + 1, $labelLength);
            $position += 1 + $labelLength;
        }

        if (0 === $jumps) {
            $offset = $position;
        }

        return \implode('.', $labels);
    }

    /**
     * @return array<string, int>
     */
    private static function unpackAt(string $format, string $packet, int $offset, int $size): array
    {
        if ($offset + $size > \strlen($packet)) {
            throw new DnsProtocolException('DNS response truncated.');
        }

        /** @var array<string, int> $values */
        $values = \unpack($format, $packet, $offset);

        return $values;
    }
}
