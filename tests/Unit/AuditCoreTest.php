<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\CallbackProvider;
use Pixel\Module\Audit\Api\Field;
use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Configuration\SettingsValidator;
use Pixel\Module\Audit\Core\Buffer;
use Pixel\Module\Audit\Core\Comparator;

final class AuditCoreTest extends TestCase
{
    private function snapshot(mixed $value, string $type = 'text'): Snapshot
    {
        return new Snapshot(true, ['price' => new Field($value, 'Prix', $type)]);
    }

    public function testFiveSavesProduceOneEventWithInitialAndFinalValues(): void
    {
        $buffer = new Buffer(new Comparator());
        for ($i = 0; $i < 5; ++$i) {
            $buffer->observe(Subject::product(480, 1), 'native.product', 'products', $this->snapshot($i), $this->snapshot($i + 1));
        }
        $events = $buffer->events();
        self::assertCount(1, $events);
        self::assertSame('0', $events[0]['changes']['price']['before']);
        self::assertSame('5', $events[0]['changes']['price']['after']);
    }

    public function testCancelledChangeProducesNoEvent(): void
    {
        $buffer = new Buffer(new Comparator());
        $subject = Subject::product(480, 1);
        $buffer->observe($subject, 'native.product', 'products', $this->snapshot('12'), $this->snapshot('14'));
        $buffer->observe($subject, 'native.product', 'products', $this->snapshot('14'), $this->snapshot('12'));
        self::assertSame([], $buffer->events());
    }

    public function testDecimalComparisonDoesNotUseFloatPrecision(): void
    {
        $comparator = new Comparator();
        self::assertSame([], $comparator->diff($this->snapshot('0012.000000', 'decimal'), $this->snapshot('12', 'decimal')));
        self::assertNotEmpty($comparator->diff($this->snapshot('9999999999999999.000001', 'decimal'), $this->snapshot('9999999999999999.000002', 'decimal')));
        self::assertSame([], $comparator->diff($this->snapshot('-0.0', 'decimal'), $this->snapshot('0', 'decimal')));
    }

    public function testReorderedSetsAndMapsDoNotCreateFalseChanges(): void
    {
        $comparator = new Comparator();
        self::assertSame([], $comparator->diff($this->snapshot([3, 1, 3], 'set'), $this->snapshot([1, 3], 'set')));
        self::assertSame([], $comparator->diff($this->snapshot(['b' => 2, 'a' => 1], 'json'), $this->snapshot(['a' => 1, 'b' => 2], 'json')));
    }

    public function testNullAndZeroRemainDistinct(): void
    {
        self::assertNotEmpty((new Comparator())->diff($this->snapshot(null, 'integer'), $this->snapshot(0, 'integer')));
    }

    public function testSensitiveValuesNeverReachTheSanitizedSnapshot(): void
    {
        $snapshot = (new Comparator())->sanitize(new Snapshot(true, [
            'passwd' => new Field('hash', 'Mot de passe'),
            'api_token' => new Field('secret', 'Token'),
            'internal' => new Field('private data', 'Private', 'text', true),
            'name' => new Field('Produit', 'Nom'),
        ]));
        self::assertSame(['name'], array_keys($snapshot->fields));
    }

    public function testCreationThenDeletionInSameRequestLeavesNoEvent(): void
    {
        $buffer = new Buffer(new Comparator());
        $subject = Subject::product(480, 1);
        $buffer->observe($subject, 'native.product', 'products', new Snapshot(false), $this->snapshot('12'));
        $buffer->observe($subject, 'native.product', 'products', $this->snapshot('12'), new Snapshot(false));
        self::assertSame([], $buffer->events());
    }

    public function testDeletionRetainsInitialValues(): void
    {
        $buffer = new Buffer(new Comparator());
        $buffer->observe(Subject::product(480, 1), 'native.product', 'products', $this->snapshot('12'), new Snapshot(false));
        self::assertSame('delete', $buffer->events()[0]['action']);
        self::assertSame('12', $buffer->events()[0]['changes']['price']['before']);
        self::assertNull($buffer->events()[0]['changes']['price']['after']);
    }

    public function testProductAndCombinationShareOperationButRetainTheirSubjects(): void
    {
        $buffer = new Buffer(new Comparator());
        $buffer->observe(Subject::product(480, 1), 'native.product', 'products', $this->snapshot('1'), $this->snapshot('2'));
        $buffer->observe(new Subject('combination', 40, 1, 'product', 480), 'native.combination', 'combinations', $this->snapshot('3'), $this->snapshot('4'));
        $events = $buffer->events();
        self::assertCount(2, $events);
        self::assertSame($events[0]['operation'], $events[1]['operation']);
        self::assertNotSame($events[0]['subject']->key(), $events[1]['subject']->key());
    }

    public function testDifferentRequestBuffersNeverShareOperationIds(): void
    {
        $one = new Buffer(new Comparator());
        $two = new Buffer(new Comparator());
        foreach ([$one, $two] as $buffer) {
            $buffer->observe(Subject::product(480, 1), 'native.product', 'products', $this->snapshot('1'), $this->snapshot('2'));
        }
        self::assertNotSame($one->events()[0]['operation'], $two->events()[0]['operation']);
    }

    public function testShopStatesRemainSeparate(): void
    {
        $buffer = new Buffer(new Comparator());
        foreach ([1, 2] as $shop) {
            $buffer->observe(Subject::product(480, $shop), 'native.product', 'products', $this->snapshot('1'), $this->snapshot((string) $shop));
        }
        self::assertCount(1, $buffer->events());
        self::assertSame(2, $buffer->events()[0]['subject']->shopId);
    }

    public function testExplicitOperationGroupsDifferentRoots(): void
    {
        $buffer = new Buffer(new Comparator());
        foreach ([480, 481] as $id) {
            $buffer->observe(Subject::product($id, 1), 'native.product', 'products', $this->snapshot('1'), $this->snapshot('2'), 'local-operation');
        }
        self::assertSame($buffer->events()[0]['operation'], $buffer->events()[1]['operation']);
    }

    public function testDuplicateProviderOwnershipIsRejected(): void
    {
        $registry = new Registry([]);
        $provider = new CallbackProvider('example.product', 'example', 'product', static fn () => new Snapshot(false));
        $registry->add($provider, 'Exemple');
        $this->expectException(\LogicException::class);
        $registry->add($provider, 'Exemple');
    }

    public function testConfigurationPreservesMissingModulesAndDisablesUncheckedFamilies(): void
    {
        $result = (new SettingsValidator())->validate(['days' => '90', 'families' => ['products' => '1'], 'retention' => ['products' => '30']], ['products' => 'Produits', 'customers' => 'Clients'], ['families' => ['absent_module' => true], 'retention' => ['absent_module' => 10]]);
        self::assertSame(90, $result['days']);
        self::assertTrue($result['families']['products']);
        self::assertFalse($result['families']['customers']);
        self::assertTrue($result['families']['absent_module']);
        self::assertSame(30, $result['retention']['products']);
    }

    public function testUnlimitedOrInvalidRetentionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SettingsValidator())->validate(['days' => '0'], [], []);
    }

    public function testNestedSecretsAreRemovedFromJson(): void
    {
        $snapshot = (new Comparator())->sanitize(new Snapshot(true, [
            'settings' => new Field(['enabled' => true, 'nested' => ['access_token' => 'secret', 'qty' => 5]], 'Settings', 'json'),
        ]));
        self::assertSame(['enabled' => true, 'nested' => ['qty' => 5]], $snapshot->fields['settings']->value);
    }

    public function testUnsupportedValuesAreRejectedInsteadOfSilentlyTruncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Comparator())->sanitize(new Snapshot(true, ['value' => new Field(new \stdClass(), 'Valeur')]));
    }

    public function testAllShopContextDoesNotExposeOtherEmployeeShops(): void
    {
        self::assertSame([2], \Pixel\Module\Audit\Core\ShopScope::allowed([1, 2, 3], [2], false));
        self::assertSame([], \Pixel\Module\Audit\Core\ShopScope::allowed([1], [2], false));
        self::assertSame([1, 2, 3], \Pixel\Module\Audit\Core\ShopScope::allowed([1, 2, 3], [], true));
    }

    public function testSharedPaymentKeepsEachOrderRoot(): void
    {
        $buffer = new Buffer(new Comparator());
        foreach ([100, 101] as $orderId) {
            $buffer->observe(new Subject('order_payment', 7, 1, 'order', $orderId), 'native.order_payment', 'orders', $this->snapshot('10'), $this->snapshot('20'));
        }
        $events = $buffer->events();
        self::assertCount(2, $events);
        self::assertNotSame($events[0]['operation'], $events[1]['operation']);
        self::assertSame(100, $events[0]['subject']->rootId);
        self::assertSame(101, $events[1]['subject']->rootId);
    }

    public function testOrderAndItsLineShareOperation(): void
    {
        $buffer = new Buffer(new Comparator());
        $buffer->observe(new Subject('order', 100, 1), 'native.order', 'orders', $this->snapshot('10'), $this->snapshot('20'));
        $buffer->observe(new Subject('order_detail', 5, 1, 'order', 100), 'native.order_detail', 'orders', $this->snapshot('10'), $this->snapshot('20'));
        self::assertSame($buffer->events()[0]['operation'], $buffer->events()[1]['operation']);
    }

    public function testDisplayDoesNotShowJsonQuotesAndKeepsValuesAsPlainText(): void
    {
        self::assertSame('Texte', \Pixel\Module\Audit\Presentation\Presenter::value('Texte', 'text'));
        self::assertSame('No', \Pixel\Module\Audit\Presentation\Presenter::value(false, 'boolean'));
        self::assertSame('Yes', \Pixel\Module\Audit\Presentation\Presenter::value(true, 'boolean'));
        self::assertSame('0', \Pixel\Module\Audit\Presentation\Presenter::value(0, 'integer'));
        self::assertSame('∅', \Pixel\Module\Audit\Presentation\Presenter::value(null, 'text'));
        self::assertSame('<script>', \Pixel\Module\Audit\Presentation\Presenter::value('<script>', 'text'));
        self::assertSame('Product #480, Order #12', \Pixel\Module\Audit\Presentation\Presenter::subjects('product #480, order #12'));
    }
}
