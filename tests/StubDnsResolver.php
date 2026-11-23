<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests;

use Devable\MaintenanceDnsBundle\Dns\DnsResolverInterface;
use Devable\MaintenanceDnsBundle\Dns\DnsResult;

/**
 * @internal
 */
final class StubDnsResolver implements DnsResolverInterface
{
    /**
     * @var array<string, int>
     */
    public array $calls = [];

    /**
     * @param array<string, DnsResult> $results
     */
    public function __construct(private array $results = [])
    {
    }

    /**
     * @param list<string> $ips
     */
    public function succeed(string $hostname, array $ips): self
    {
        $this->results[$hostname] = DnsResult::success($hostname, $ips);

        return $this;
    }

    public function fail(string $hostname, string $error = 'timeout'): self
    {
        $this->results[$hostname] = DnsResult::failure($hostname, $error);

        return $this;
    }

    public function nxDomain(string $hostname): self
    {
        $this->results[$hostname] = DnsResult::nxDomain($hostname);

        return $this;
    }

    public function resolve(string $hostname): DnsResult
    {
        $this->calls[$hostname] = ($this->calls[$hostname] ?? 0) + 1;

        return $this->results[$hostname] ?? DnsResult::failure($hostname, 'timeout');
    }

    public function totalCalls(): int
    {
        return \array_sum($this->calls);
    }
}
