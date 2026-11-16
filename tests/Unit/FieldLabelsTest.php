<?php

declare(strict_types=1);
namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\{Registry, ProviderInterface, FieldLabelProviderInterface, Subject, Snapshot};
use Pixel\Module\Audit\Presentation\FieldLabels;

final class FieldLabelsTest extends TestCase
{
    public function testCurrentProviderLabelsAndLanguageNamesReplaceTechnicalHistoricalLabels(): void
    {
        $registry = new Registry([]);
        $registry->add(new class implements ProviderInterface, FieldLabelProviderInterface {
            public function name(): string { return 'demo.product'; }
            public function family(): string { return 'demo'; }
            public function supports(Subject $subject): bool { return true; }
            public function snapshot(Subject $subject): Snapshot { return new Snapshot(false); }
            public function fieldLabel(string $field): ?string { return $field === 'example_label' ? 'Advisor name and role' : null; }
        }, 'Demo');
        $labels = new FieldLabels($registry, [1 => 'Français (French)', 2 => 'English']);
        self::assertSame('Advisor name and role [Français (French)]', $labels->label('demo.product', 'example_label.lang_1', 'example_label [langue 1]'));
        self::assertSame('Previous label [English]', $labels->label('absent', 'old.lang_2', 'Previous label [langue #2]'));
        self::assertSame('Previous label [Deleted language (#3)]', $labels->label('absent', 'old.lang_3', 'Previous label [langue 3]'));
        self::assertSame('Previous label', $labels->label('absent', 'old', 'Previous label'));
    }
    public function testNativeConfigurationAndRelationLabelsUseTheSamePublicResolver(): void
    {
        $registry = new Registry([]);
        $registry->addConfigurationParameter(new \Pixel\Module\Audit\Api\ConfigurationParameter('EXAMPLE_ENABLED','example','Example enabled','boolean'));
        $registry->add(new \Pixel\Module\Audit\Configuration\ConfigurationProvider($registry,new \Pixel\Module\Audit\Configuration\Settings()),'Configuration');
        $registry->add(new \Pixel\Module\Audit\Collector\RelationsProvider('product','products'),'Products');
        $labels = new FieldLabels($registry,[1=>'English']);
        self::assertSame('Example enabled - explicit value defined',$labels->label('configuration.selected','EXAMPLE_ENABLED.defined','old'));
        self::assertSame('Supplier #2, combination #3 - Reference',$labels->label('native.product.relations','supplier.2.3.reference','old'));
        self::assertSame('Feature #4 - value #5 [English]',$labels->label('native.product.relations','feature.4.5.lang_1','old [langue 1]'));
    }

}
