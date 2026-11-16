<?php

declare(strict_types=1);
namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\{Registry, ExplicitProvider, RestorationStorageInterface, ProductRestorationDefinition, ProductRestorationField};
use Pixel\Module\Audit\Collector\ObjectModelProvider;

final class StorageRegistrationTest extends TestCase
{
    private function storage(array $tables): RestorationStorageInterface
    {
        return new class($tables) implements RestorationStorageInterface {
            public function __construct(private array $names) {}
            public function subjectType(): string { return 'combination'; }
            public function tables(): array { return $this->names; }
            public function lockProduct(int $product): void {}
            public function check(int $product,int $subject,int $shop,bool $lock): void {}
            public function read(int $subject,array $fields,ProductRestorationDefinition $definition): array { return []; }
            public function write(array $plan): void {}
            public function afterCommit(int $product): void {}
        };
    }

    public function testGenericStorageRequiresOwnershipAndRejectsSqlIdentifiers(): void
    {
        $registry=new Registry([]);
        $registry->add(new ExplicitProvider('example.capacity','example_capacities','combination'),'Capacities');
        $definition=new ProductRestorationDefinition('example.capacity','example_capacities','example','1',[new ProductRestorationField('capacity','Capacity','decimal',nullable:true)]);
        try {
            $registry->addRestorationStorage($definition,$this->storage(['example; DROP TABLE product']));
            self::fail('Unsafe table accepted');
        } catch (\LogicException $error) { self::assertCount(0,$registry->productRestorations()); }
        $storage=$this->storage(['example_capacity']); $registry->addRestorationStorage($definition,$storage);
        self::assertSame($storage,$registry->restorationStorage('example.capacity'));
        self::assertNull($registry->restorationStorage('other.capacity'));
    }

    public function testOverlappingSnapshotPrefixesCannotDuplicateOwnership(): void
    {
        $registry=new Registry([]);
        $registry->add(new ObjectModelProvider('Product','product','products','native.product'),'Products');
        $registry->add(new ObjectModelProvider('Product','product','example','example.product','example_'),'Example');
        $this->expectException(\LogicException::class);
        $registry->add(new ObjectModelProvider('Product','product','other','other.product','example_nested_'),'Other');
    }
}
