<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Sync;

class SalesChannelNotFoundException extends \RuntimeException
{
    public static function forId(string $salesChannelId): self
    {
        return new self(\sprintf('Sales channel %s does not exist.', $salesChannelId));
    }
}
