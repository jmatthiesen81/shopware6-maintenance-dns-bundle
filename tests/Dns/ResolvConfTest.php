<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Dns;

use Devable\MaintenanceDnsBundle\Dns\ResolvConf;
use PHPUnit\Framework\TestCase;

class ResolvConfTest extends TestCase
{
    public function testParse(): void
    {
        $content = <<<'CONF'
            # Generated
            search example.net
            nameserver 127.0.0.53
              nameserver   2001:4860:4860::8888
            nameserver fe80::1%eth0
            nameserver not-an-ip
            ;nameserver 192.0.2.1
            options edns0 trust-ad
            nameserver 127.0.0.53
            CONF;

        static::assertSame([ '127.0.0.53', '2001:4860:4860::8888' ], ResolvConf::parse($content));
    }

    public function testMissingFile(): void
    {
        static::assertSame([], ResolvConf::nameservers('/does/not/exist'));
    }
}
