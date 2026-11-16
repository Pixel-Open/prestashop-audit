<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\{ProductRestorationField, ProductRestorationDefinition, Registry};
use Pixel\Module\Audit\Collector\ObjectModelProvider;
use Pixel\Module\Audit\Restoration\RestoreException;

final class ProductRestorationApiTest extends TestCase
{
    public function testTypedCanonicalValuesAndNullDifferFromEmptyZeroAndFalse(): void
    {
        $field = new ProductRestorationField('extra_count', 'Nombre', 'integer', nullable: true, minimum: 0, maximum: 100);
        self::assertNull($field->normalize(null)); self::assertSame(0, $field->normalize('0')); self::assertSame(7, $field->normalize('7'));
        $boolean = new ProductRestorationField('extra_flag', 'Option', 'boolean');
        self::assertFalse($boolean->normalize('0')); self::assertTrue($boolean->normalize(1));
        foreach (['', '7.1', 'oops', -1, 101, true] as $invalid) {
            try { $field->normalize($invalid); self::fail('Invalid integer accepted'); } catch (RestoreException $error) { self::assertSame(400, $error->status); }
        }
    }

    public function testExactDecimalPrecisionAndEnumConstraints(): void
    {
        $decimal = new ProductRestorationField('extra_quantity', 'Quantity', 'decimal', minimum: 0, precision: 10, scale: 3);
        self::assertSame('25.5', $decimal->normalize('00025.500')); self::assertSame('0', $decimal->normalize('-0.000'));
        foreach (['10000000', '1.0001', '-1', 1.2, '1e3'] as $invalid) {
            try { $decimal->normalize($invalid); self::fail('Invalid decimal accepted'); } catch (RestoreException $error) { self::assertSame(400, $error->status); }
        }
        $unit = new ProductRestorationField('extra_unit', 'Unit', nullable: true, choices: ['L', 'kg']);
        self::assertSame('L', $unit->normalize('L')); self::assertNull($unit->normalize(null));
        $this->expectException(RestoreException::class); $unit->normalize('obsolete');
    }

    public function testScopeAndAvailableKeysAreExplicitAndVersioned(): void
    {
        $field = new ProductRestorationField('extra_label', 'Label', translated: true, scope: 'shop');
        self::assertNull($field->describe('extra_label')); self::assertNull($field->describe('extra_label.lang_0'));
        self::assertSame('shop', $field->describe('extra_label.lang_2')['scope']);
        $definition = new ProductRestorationDefinition('demo.product', 'demo', 'demo', '1', [$field]);
        $changes = ['extra_label.lang_2' => ['type' => 'text', 'before' => 'before', 'after' => 'after'],
            'extra_label.lang_3' => ['type' => 'integer', 'before' => 1, 'after' => 2], 'arbitrary' => ['type' => 'text', 'before' => 'x', 'after' => 'y']];
        self::assertSame(['extra_label.lang_2'], $definition->available($changes));
        self::assertNotSame($definition->fingerprint(), (new ProductRestorationDefinition('demo.product', 'demo', 'demo', '2', [$field]))->fingerprint());
        $this->expectException(RestoreException::class); $definition->select(['arbitrary'], $changes);
    }

    public function testRegistrationChecksAuditOwnershipFamilyAndCollisions(): void
    {
        $registry = new Registry([]);
        $registry->add(new ObjectModelProvider('Product', 'product', 'demo', 'demo.product', 'extra_'), 'Demo');
        $field = new ProductRestorationField('extra_label', 'Label');
        $registry->addProductRestoration(new ProductRestorationDefinition('demo.product', 'demo', 'demo', '1', [$field]));
        self::assertCount(1, $registry->productRestorations());
        $this->expectException(\LogicException::class);
        $registry->addProductRestoration(new ProductRestorationDefinition('demo.product', 'demo', 'demo', '1', [$field]));
    }

    public function testAProviderCannotRestoreAPropertyOutsideItsSnapshotOwnership(): void
    {
        $registry = new Registry([]);
        $registry->add(new ObjectModelProvider('Product', 'product', 'demo', 'demo.product', 'extra_'), 'Demo');
        $this->expectException(\LogicException::class);
        $registry->addProductRestoration(new ProductRestorationDefinition('demo.product', 'demo', 'demo', '1', [new ProductRestorationField('price', 'Prix')]));
    }
    public function testTextStorageLimitsAndRequiredValuesRejectBeforeAnyWrite(): void
    {
        $field = new ProductRestorationField('extra_label', 'Label', maxLength: 3, required: true, maxBytes: 6);
        self::assertSame('ééé', $field->normalize('ééé'));
        foreach (['', '   ', 'abcd', '界界界', null, 0, []] as $invalid) {
            try { $field->normalize($invalid); self::fail('Invalid text accepted'); } catch (RestoreException $error) { self::assertSame(400, $error->status); }
        }
    }

    public function testRegistrationRequiresMatchingFamilyAndRejectsCrossProviderPropertyCollisions(): void
    {
        $registry = new Registry([]);
        $registry->add(new ObjectModelProvider('Product', 'product', 'demo', 'demo.product', 'extra_'), 'Demo');
        $field = new ProductRestorationField('extra_label', 'Label');
        try {
            $registry->addProductRestoration(new ProductRestorationDefinition('demo.product', 'other', 'demo', '1', [$field]));
            self::fail('Mismatched audit family accepted');
        } catch (\LogicException $error) { self::assertCount(0, $registry->productRestorations()); }
        $registry->addProductRestoration(new ProductRestorationDefinition('demo.product', 'demo', 'demo', '1', [$field]));
        $this->expectException(\LogicException::class);
        $registry->add(new ObjectModelProvider('Product', 'product', 'other', 'other.product', 'extra_'), 'Other');
        $registry->addProductRestoration(new ProductRestorationDefinition('other.product', 'other', 'other', '1', [$field]));
    }

}
