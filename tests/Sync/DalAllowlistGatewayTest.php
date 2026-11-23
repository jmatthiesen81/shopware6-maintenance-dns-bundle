<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Sync;

use Devable\MaintenanceDnsBundle\Sync\DalAllowlistGateway;
use Devable\MaintenanceDnsBundle\Sync\SalesChannelNotFoundException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StorageAware;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class DalAllowlistGatewayTest extends TestCase
{
    private const SC = '0190a0b0c0d0e0f00000000000000001';

    public function testUsesAllowlistFieldWhenAvailable(): void
    {
        $gateway = new DalAllowlistGateway($this->repository(new SalesChannelDefinition()), $this->createStub(Connection::class));

        static::assertSame(DalAllowlistGateway::FIELD_ALLOWLIST, $gateway->getField());
    }

    public function testFallsBackToWhitelistFieldBefore6713(): void
    {
        $gateway = new DalAllowlistGateway($this->repository(new LegacySalesChannelDefinition()), $this->createStub(Connection::class));

        static::assertSame(DalAllowlistGateway::FIELD_WHITELIST, $gateway->getField());
    }

    public function testSaveWritesThroughTheDal(): void
    {
        $repository = $this->repositoryMock(new LegacySalesChannelDefinition());
        $repository->expects($this->once())
            ->method('update')
            ->with([ [ 'id' => self::SC, 'maintenanceIpWhitelist' => [ '81.2.69.10' ] ] ], static::isInstanceOf(Context::class));

        (new DalAllowlistGateway($repository, $this->createStub(Connection::class)))->save(self::SC, [ '81.2.69.10' ]);
    }

    public function testLockAndLoadLocksRowAndReadsAllowlist(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(static::stringContains('FOR UPDATE'), [ 'id' => Uuid::fromHexToBytes(self::SC) ])
            ->willReturn('1');

        $entity = new SalesChannelEntity();
        $entity->setId(self::SC);
        $entity->setMaintenanceIpWhitelist([ '1.1.1.1', '81.2.69.10' ]);

        $repository = $this->repository(new SalesChannelDefinition());
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult('sales_channel', 1, new SalesChannelCollection([ $entity ]), null, $criteria, $context),
        );

        static::assertSame([ '1.1.1.1', '81.2.69.10' ], (new DalAllowlistGateway($repository, $connection))->lockAndLoad(self::SC));
    }

    public function testLockAndLoadThrowsForMissingSalesChannel(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $this->expectException(SalesChannelNotFoundException::class);

        (new DalAllowlistGateway($this->repository(new SalesChannelDefinition()), $connection))->lockAndLoad(self::SC);
    }

    public function testTransactionalUsesConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willReturnCallback(static fn (\Closure $callback): mixed => $callback());

        $result = (new DalAllowlistGateway($this->repository(new SalesChannelDefinition()), $connection))->transactional(static fn (): string => 'done');

        static::assertSame('done', $result);
    }

    private function repository(EntityDefinition $definition): EntityRepository&Stub
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('getDefinition')->willReturn($this->compile($definition));

        return $repository;
    }

    private function repositoryMock(EntityDefinition $definition): EntityRepository&MockObject
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('getDefinition')->willReturn($this->compile($definition));

        return $repository;
    }

    private function compile(EntityDefinition $definition): EntityDefinition
    {
        $definition->compile((new \ReflectionClass(DefinitionInstanceRegistry::class))->newInstanceWithoutConstructor());

        return $definition;
    }
}

/**
 * Sales channel definition of Shopware < 6.7.13, without the `maintenanceIpAllowlist` field.
 *
 * @internal
 */
class LegacySalesChannelDefinition extends SalesChannelDefinition
{
    protected function defineFields(): FieldCollection
    {
        return new FieldCollection(\array_filter(
            parent::defineFields()->getElements(),
            static fn (mixed $field): bool => !$field instanceof StorageAware || 'maintenance_ip_allowlist' !== $field->getStorageName(),
        ));
    }
}
