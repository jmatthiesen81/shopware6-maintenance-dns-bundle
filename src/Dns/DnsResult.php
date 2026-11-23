<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

final class DnsResult
{
    /**
     * @param list<string> $ips validated, normalized and unique IP addresses, only filled for DnsStatus::Success
     */
    private function __construct(
        public readonly string $hostname,
        public readonly DnsStatus $status,
        public readonly array $ips,
        public readonly string|null $error = null,
    ) {
    }

    /**
     * @param list<string> $ips
     */
    public static function success(string $hostname, array $ips): self
    {
        if ([] === $ips) {
            return self::noData($hostname);
        }

        return new self($hostname, DnsStatus::Success, $ips);
    }

    public static function nxDomain(string $hostname): self
    {
        return new self($hostname, DnsStatus::NxDomain, [], 'NXDOMAIN');
    }

    public static function noData(string $hostname, string $error = 'no address records'): self
    {
        return new self($hostname, DnsStatus::NoData, [], $error);
    }

    public static function failure(string $hostname, string $error): self
    {
        return new self($hostname, DnsStatus::Failure, [], $error);
    }

    public function isSuccess(): bool
    {
        return DnsStatus::Success === $this->status;
    }
}
