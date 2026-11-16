<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Api\ConfigurationParameter;
use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Configuration\ConfigurationSnapshot;
use Pixel\Module\Audit\Configuration\SettingsValidator;
use Pixel\Module\Audit\Core\Comparator;

final class ConfigurationTest extends TestCase
{
    public function testLoginHistoryRequiresExplicitOptInIndependentlyOfEmployees(): void
    {
        $validator = new SettingsValidator();
        $families = ['employees' => 'Employees and permissions'];
        $enabled = $validator->validate(['days' => '180', 'login_history' => '1'], $families, []);
        self::assertTrue($enabled['login_history']);
        self::assertFalse($enabled['families']['employees']);
        $disabled = $validator->validate(['days' => '180', 'families' => ['employees' => '1']], $families, $enabled);
        self::assertFalse($disabled['login_history']);
        self::assertTrue($disabled['families']['employees']);
        $invalid = $validator->validate(['days' => '180', 'login_history' => ['1']], $families, []);
        self::assertFalse($invalid['login_history']);
    }

    public function testSecretsWildcardsAndInternalParametersCannotBeRegistered(): void
    {
        foreach (['PS_MAIL_PASSWD', 'MODULE_API_KEY', 'MODULE_ACCESS_KEY', 'MY_TOKEN', 'PIXEL_AUDIT_SETTINGS', 'PS_*'] as $key) {
            try {
                new ConfigurationParameter($key, 'example', 'Test');
                self::fail('Unsafe key accepted: ' . $key);
            } catch (\InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    public function testOnlyOneModuleOwnsAConfigurationKey(): void
    {
        $registry = new Registry([]);
        $registry->addConfigurationParameter(new ConfigurationParameter('EXAMPLE_OPTION', 'one', 'Test'));
        $this->expectException(\LogicException::class);
        $registry->addConfigurationParameter(new ConfigurationParameter('EXAMPLE_OPTION', 'two', 'Test'));
    }

    public function testRowAbsentAndFalseOverrideAreDistinct(): void
    {
        $parameters = ['EXAMPLE_ENABLED' => new ConfigurationParameter('EXAMPLE_ENABLED', 'example', 'Actif', 'boolean')];
        $reader = new ConfigurationSnapshot();
        $before = $reader->build($parameters, []);
        $after = $reader->build($parameters, [['name' => 'EXAMPLE_ENABLED', 'value' => '0', 'id_lang' => null, 'translated_value' => null]]);
        $changes = (new Comparator())->diff($before, $after);
        self::assertFalse($changes['EXAMPLE_ENABLED.defined']['before']);
        self::assertTrue($changes['EXAMPLE_ENABLED.defined']['after']);
        self::assertNull($changes['EXAMPLE_ENABLED']['before']);
        self::assertFalse($changes['EXAMPLE_ENABLED']['after']);
    }

    public function testOnlyDeclaredTranslationsAreReadAndComparedSeparately(): void
    {
        $parameters = ['EXAMPLE_TEXT' => new ConfigurationParameter('EXAMPLE_TEXT', 'example', 'Texte', 'text', true)];
        $snapshot = (new ConfigurationSnapshot())->build($parameters, [
            ['name' => 'EXAMPLE_TEXT', 'value' => null, 'id_lang' => 1, 'translated_value' => 'Bonjour'],
            ['name' => 'EXAMPLE_TEXT', 'value' => null, 'id_lang' => 2, 'translated_value' => 'Hello'],
            ['name' => 'UNDECLARED_KEY', 'value' => 'must-not-be-retained', 'id_lang' => null, 'translated_value' => null],
        ]);
        self::assertSame('Bonjour', $snapshot->fields['EXAMPLE_TEXT.lang_1']->value);
        self::assertSame('Hello', $snapshot->fields['EXAMPLE_TEXT.lang_2']->value);
        self::assertArrayNotHasKey('UNDECLARED_KEY', $snapshot->fields);
    }

    public function testJsonMapOrderAndSetOrderAreNormalized(): void
    {
        $parameter = new ConfigurationParameter('EXAMPLE_IDS', 'example', 'Groupes', 'set');
        $one = $parameter->field('[3,1,3]', 'Groupes');
        $two = $parameter->field('[1,3]', 'Groupes');
        $comparator = new Comparator();
        self::assertSame([], $comparator->diff(new \Pixel\Module\Audit\Api\Snapshot(true, ['ids' => $one]), new \Pixel\Module\Audit\Api\Snapshot(true, ['ids' => $two])));
    }

    public function testStructuredSecretsAndMalformedJsonNeverEnterTheBuffer(): void
    {
        $parameter = new ConfigurationParameter('EXAMPLE_RULES', 'example', 'Rules', 'json');
        $comparator = new Comparator();
        $valid = $parameter->field('{"enabled":true,"api_key":"hidden","nested":{"credentials":"hidden","label":"visible"}}', 'Rules');
        $safe = $comparator->sanitize(new \Pixel\Module\Audit\Api\Snapshot(true, ['rules' => $valid]));
        self::assertSame(['enabled' => true, 'nested' => ['label' => 'visible']], $safe->fields['rules']->value);
        $invalid = $parameter->field('{"api_key":"hidden",', 'Rules');
        self::assertSame('[Invalid JSON value hidden]', $invalid->value);
    }

    public function testUnknownSubmittedKeysAreIgnoredAndUnavailableModuleSelectionsPreserved(): void
    {
        $parameter = new ConfigurationParameter('EXAMPLE_OPTION', 'example', 'Test');
        $result = (new SettingsValidator())->validate([
            'days' => '90', 'configuration_keys_present' => '1',
            'configuration_keys' => ['EXAMPLE_OPTION' => '1', 'PS_MAIL_PASSWD' => '1'],
        ], [], ['configuration_keys' => ['UNAVAILABLE_MODULE_OPTION' => true]], ['EXAMPLE_OPTION' => $parameter]);
        self::assertTrue($result['configuration_keys']['EXAMPLE_OPTION']);
        self::assertTrue($result['configuration_keys']['UNAVAILABLE_MODULE_OPTION']);
        self::assertArrayNotHasKey('PS_MAIL_PASSWD', $result['configuration_keys']);
    }

    public function testOldSettingsFormsDoNotSilentlyDisableNewTrackingOptions(): void
    {
        $result = (new SettingsValidator())->validate(['days' => '90'], [], ['configuration_keys' => ['EXAMPLE_OPTION' => true]], ['EXAMPLE_OPTION' => new ConfigurationParameter('EXAMPLE_OPTION', 'example', 'Test')]);
        self::assertTrue($result['configuration_keys']['EXAMPLE_OPTION']);
    }
}
