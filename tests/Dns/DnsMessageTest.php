<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Dns;

use Devable\MaintenanceDnsBundle\Dns\DnsMessage;
use Devable\MaintenanceDnsBundle\Dns\DnsProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DnsMessageTest extends TestCase
{
    private const HOST = 'office.example.net';

    public function testBuildQuery(): void
    {
        $query = DnsMessage::buildQuery(0x1234, 'Office.Example.NET.', DnsMessage::TYPE_AAAA);

        static::assertSame(
            "\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x06office\x07example\x03net\x00\x00\x1C\x00\x01",
            $query,
        );
    }

    public function testBuildQueryRejectsEmptyLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DnsMessage::buildQuery(1, 'office..example.net', DnsMessage::TYPE_A);
    }

    public function testTypeFromName(): void
    {
        static::assertSame(DnsMessage::TYPE_A, DnsMessage::typeFromName('a'));
        static::assertSame(DnsMessage::TYPE_AAAA, DnsMessage::typeFromName('AAAA'));

        $this->expectException(\InvalidArgumentException::class);
        DnsMessage::typeFromName('MX');
    }

    public function testParsesMultipleARecords(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)->a('81.2.69.10')->a('85.10.20.20')->build();

        $response = DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A);

        static::assertSame(DnsMessage::RCODE_NOERROR, $response->rcode);
        static::assertFalse($response->truncated);
        static::assertSame([ '81.2.69.10', '85.10.20.20' ], $response->addresses);
    }

    public function testParsesAaaaRecords(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST, DnsMessage::TYPE_AAAA)->aaaa('2001:DB8::1')->build();

        $response = DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_AAAA);

        static::assertSame([ '2001:db8::1' ], $response->addresses);
    }

    public function testQuestionNameIsCaseInsensitive(): void
    {
        $packet = DnsResponseBuilder::for(7, 'OFFICE.example.net')->a('81.2.69.10')->build();

        static::assertSame([ '81.2.69.10' ], DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A)->addresses);
    }

    public function testFollowsCnameChainInAnyOrder(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)
            ->a('81.2.69.30', 'target.dyndns.example')
            ->cname('middle.example.org')
            ->cname('target.dyndns.example', 'middle.example.org')
            ->build();

        static::assertSame([ '81.2.69.30' ], DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A)->addresses);
    }

    public function testIgnoresRecordsOfOtherNames(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)
            ->a('81.2.69.10')
            ->a('85.10.20.66', 'attacker.example.com')
            ->build();

        static::assertSame([ '81.2.69.10' ], DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A)->addresses);
    }

    public function testIgnoresRecordsOfOtherTypes(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)
            ->aaaa('2001:db8::1')
            ->raw("\xC0\x0C" . \pack('nnNn', 16, 1, 300, 4) . "\x03abc") // TXT
            ->a('81.2.69.10')
            ->build();

        static::assertSame([ '81.2.69.10' ], DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A)->addresses);
    }

    public function testIgnoresARecordWithWrongLength(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)
            ->raw("\xC0\x0C" . \pack('nnNn', DnsMessage::TYPE_A, 1, 300, 5) . "\x01\x02\x03\x04\x05")
            ->build();

        static::assertSame([], DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A)->addresses);
    }

    public function testNxDomain(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)->rcode(DnsMessage::RCODE_NXDOMAIN)->build();

        $response = DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A);

        static::assertSame(DnsMessage::RCODE_NXDOMAIN, $response->rcode);
        static::assertSame([], $response->addresses);
    }

    public function testServfailIgnoresAnswers(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)->rcode(DnsMessage::RCODE_SERVFAIL)->a('81.2.69.10')->build();

        $response = DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A);

        static::assertSame(DnsMessage::RCODE_SERVFAIL, $response->rcode);
        static::assertSame([], $response->addresses);
    }

    public function testTruncatedAnswerIsNotUsed(): void
    {
        $packet = DnsResponseBuilder::for(7, self::HOST)->truncated()->a('81.2.69.10')->build();

        $response = DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A);

        static::assertTrue($response->truncated);
        static::assertSame([], $response->addresses);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPacketProvider(): iterable
    {
        yield 'too short' => [ "\x00\x07\x81\x80" ];
        yield 'wrong id' => [ DnsResponseBuilder::for(8, self::HOST)->a('81.2.69.10')->build() ];
        yield 'not a response' => [ DnsResponseBuilder::for(7, self::HOST)->notAResponse()->build() ];
        yield 'other question name' => [ DnsResponseBuilder::for(7, 'other.example.net')->a('81.2.69.10')->build() ];
        yield 'other question type' => [ DnsResponseBuilder::for(7, self::HOST, DnsMessage::TYPE_AAAA)->build() ];
        yield 'record exceeds packet' => [ DnsResponseBuilder::for(7, self::HOST)->raw("\xC0\x0C" . \pack('nnNn', 1, 1, 300, 16) . "\x01\x02")->build() ];
        // The answer starts at offset 36 (0x24) and points to itself
        yield 'compression loop' => [ DnsResponseBuilder::for(7, self::HOST)->raw("\xC0\x24" . \pack('nnNn', 1, 1, 300, 4) . "\x01\x02\x03\x04")->build() ];
        yield 'pointer beyond packet' => [ DnsResponseBuilder::for(7, self::HOST)->raw("\xC0\xFF")->build() ];
        yield 'missing record header' => [ DnsResponseBuilder::for(7, self::HOST)->raw("\xC0\x0C\x00\x01")->build() ];
    }

    #[DataProvider('invalidPacketProvider')]
    public function testRejectsInvalidPackets(string $packet): void
    {
        $this->expectException(DnsProtocolException::class);

        DnsMessage::parseResponse($packet, 7, self::HOST, DnsMessage::TYPE_A);
    }
}
