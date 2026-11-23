<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Dns;

/**
 * Timeout, network error or malformed DNS message.
 */
class DnsProtocolException extends \RuntimeException
{
}
