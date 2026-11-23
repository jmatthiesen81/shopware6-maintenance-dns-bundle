<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

use Devable\MaintenanceDnsBundle\Ip\IpAddressFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves a hostname to validated IP addresses.
 *
 * - `timeoutSeconds` is the total budget per hostname, for all record types and name servers.
 * - A failure of any record type makes the whole result a failure, so a temporary AAAA problem cannot
 *   be mistaken for "the host has no IPv6 address any more".
 */
final class UdpDnsResolver implements DnsResolverInterface
{
    /**
     * @param list<string>    $recordTypes 'A' and/or 'AAAA'
     * @param list<string>    $nameservers empty = name servers of /etc/resolv.conf
     * @param \Closure(): list<string>|null $systemNameservers
     */
    public function __construct(
        private readonly DnsTransportInterface $transport,
        private readonly IpAddressFilter $ipFilter,
        private readonly array $recordTypes = [ 'A' ],
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $maxRecords = 10,
        private readonly array $nameservers = [],
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly \Closure|null $systemNameservers = null,
    ) {
    }

    public function resolve(string $hostname): DnsResult
    {
        $nameservers = $this->getNameservers();
        if ([] === $nameservers) {
            return $this->log(DnsResult::failure($hostname, 'no name server configured or found in /etc/resolv.conf'));
        }

        $deadline = \microtime(true) + $this->timeoutSeconds;
        $addresses = [];
        $nxDomain = false;

        foreach ($this->recordTypes as $recordType) {
            $type = DnsMessage::typeFromName($recordType);
            $response = $this->query($nameservers, $hostname, $type, $deadline, $error);

            if (null === $response) {
                return $this->log(DnsResult::failure($hostname, \sprintf('%s query failed: %s', $recordType, $error)));
            }

            if (DnsMessage::RCODE_NXDOMAIN === $response->rcode) {
                $nxDomain = true;

                continue;
            }

            \array_push($addresses, ...$response->addresses);
        }

        if ($nxDomain && [] === $addresses) {
            return $this->log(DnsResult::nxDomain($hostname));
        }

        if ([] === $addresses) {
            return $this->log(DnsResult::noData($hostname));
        }

        $ips = $this->ipFilter->filter($addresses, $hostname);

        if ([] === $ips) {
            return $this->log(DnsResult::noData($hostname, 'all addresses were rejected'));
        }

        if (\count($ips) > $this->maxRecords) {
            $this->logger->warning('DNS answer of {hostname} contains {count} addresses, only the first {max} are used.', [
                'hostname' => $hostname,
                'count' => \count($ips),
                'max' => $this->maxRecords,
            ]);
            $ips = \array_slice($ips, 0, $this->maxRecords);
        }

        return $this->log(DnsResult::success($hostname, $ips));
    }

    /**
     * Asks the name servers in order until one gives a usable answer (NOERROR or NXDOMAIN).
     *
     * @param list<string> $nameservers
     */
    private function query(array $nameservers, string $hostname, int $type, float $deadline, string|null &$error = null): DnsResponse|null
    {
        $error = 'timeout';

        foreach ($nameservers as $nameserver) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                $error = \sprintf('timeout after %.1fs', $this->timeoutSeconds);

                break;
            }

            $id = \random_int(0, 0xFFFF);

            try {
                $packet = $this->transport->query($nameserver, DnsMessage::buildQuery($id, $hostname, $type), $remaining);
                $response = DnsMessage::parseResponse($packet, $id, $hostname, $type);
            } catch (DnsProtocolException $exception) {
                $error = $exception->getMessage();

                continue;
            }

            if ($response->truncated) {
                $error = \sprintf('truncated answer from %s', $nameserver);

                continue;
            }

            if (DnsMessage::RCODE_NOERROR === $response->rcode || DnsMessage::RCODE_NXDOMAIN === $response->rcode) {
                return $response;
            }

            $error = \sprintf('%s answered with RCODE %d', $nameserver, $response->rcode);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function getNameservers(): array
    {
        if ([] !== $this->nameservers) {
            return $this->nameservers;
        }

        return null !== $this->systemNameservers ? ($this->systemNameservers)() : ResolvConf::nameservers();
    }

    private function log(DnsResult $result): DnsResult
    {
        if ($result->isSuccess()) {
            $this->logger->debug('Resolved {hostname} to {ips}.', [ 'hostname' => $result->hostname, 'ips' => \implode(', ', $result->ips) ]);
        } else {
            $this->logger->warning('Could not resolve {hostname} ({status}): {error}', [
                'hostname' => $result->hostname,
                'status' => $result->status->value,
                'error' => $result->error,
            ]);
        }

        return $result;
    }
}
