<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Runtime;

final class MaintenanceState
{
    public function __construct(
        public readonly string $salesChannelId,
        public readonly bool $active,
    ) {
    }
}
