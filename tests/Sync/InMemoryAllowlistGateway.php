<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Sync;

use Devable\MaintenanceDnsBundle\Sync\AllowlistGatewayInterface;
use Devable\MaintenanceDnsBundle\Sync\SalesChannelNotFoundException;

/**
 * @internal
 */
final class InMemoryAllowlistGateway implements AllowlistGatewayInterface
{
    public int $saves = 0;

    public int $transactions = 0;

    public bool $failOnSave = false;

    /**
     * @param array<string, list<string>|null> $allowlists
     */
    public function __construct(public array $allowlists = [])
    {
    }

    public function transactional(\Closure $callback): mixed
    {
        ++$this->transactions;
        $snapshot = $this->allowlists;

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->allowlists = $snapshot;

            throw $exception;
        }
    }

    public function lockAndLoad(string $salesChannelId): array|null
    {
        if (!\array_key_exists($salesChannelId, $this->allowlists)) {
            throw SalesChannelNotFoundException::forId($salesChannelId);
        }

        return $this->allowlists[$salesChannelId];
    }

    public function save(string $salesChannelId, array|null $allowlist): void
    {
        if ($this->failOnSave) {
            throw new \RuntimeException('Deadlock found when trying to get lock');
        }

        ++$this->saves;
        $this->allowlists[$salesChannelId] = $allowlist;
    }
}
