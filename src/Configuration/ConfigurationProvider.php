<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Configuration;

use Db;
use Pixel\Module\Audit\Api\ResettableProviderInterface;
use Pixel\Module\Audit\Api\ConfigurationParameter;
use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;

final class ConfigurationProvider implements ResettableProviderInterface, \Pixel\Module\Audit\Api\FieldLabelProviderInterface
{
    private ?array $parameters = null;

    public function reset(): void
    {
        $this->parameters = null;
    }

    public function __construct(private Registry $registry, private Settings $settings)
    {
    }
    public function name(): string
    {
        return 'configuration.selected';
    }
    public function family(): string
    {
        return 'configuration';
    }
    public function supports(Subject $subject): bool
    {
        return in_array($subject->type, ['configuration', 'configuration_group', 'configuration_shop'], true);
    }

    public function fieldLabel(string $field): ?string
    {
        $key = explode('.', $field, 2)[0];
        $parameter = $this->registry->configurationParameters()[$key] ?? null;
        if ($parameter === null) {
            return null;
        }
        return \Pixel\Module\Audit\Presentation\I18n::trans($parameter->label)
            . (str_ends_with($field, '.defined') ? \Pixel\Module\Audit\Presentation\I18n::trans(' - explicit value defined') : '');
    }

    public function snapshot(Subject $subject): Snapshot
    {
        $this->parameters ??= array_filter($this->registry->configurationParameters(), fn ($parameter) => $this->settings->tracks($parameter));
        $parameters = $this->parameters;
        return $this->read($subject, $parameters);
    }

    public function snapshotParameter(Subject $subject, ConfigurationParameter $parameter): Snapshot
    {
        return $this->read($subject, [$parameter->key => $parameter]);
    }

    private function read(Subject $subject, array $parameters): Snapshot
    {
        if (!$parameters) {
            return new Snapshot(true);
        }
        $keys = implode(',', array_map(static fn ($key) => "'" . pSQL($key) . "'", array_keys($parameters)));
        $scope = match ($subject->type) {
            'configuration' => 'COALESCE(c.id_shop, 0) = 0 AND COALESCE(c.id_shop_group, 0) = 0',
            'configuration_group' => 'COALESCE(c.id_shop, 0) = 0 AND c.id_shop_group = ' . $subject->id,
            'configuration_shop' => 'c.id_shop = ' . $subject->id,
            default => throw new \InvalidArgumentException('Unsupported configuration scope.'),
        };
        $rows = Db::getInstance()->executeS('SELECT c.name, c.value, cl.id_lang, cl.value AS translated_value
            FROM `' . _DB_PREFIX_ . 'configuration` c
            LEFT JOIN `' . _DB_PREFIX_ . 'configuration_lang` cl ON cl.id_configuration = c.id_configuration
            WHERE c.name IN (' . $keys . ') AND ' . $scope . ' ORDER BY c.id_configuration, cl.id_lang', true, false);
        if ($rows === false) {
            throw new \RuntimeException('Cannot read tracked configuration parameters.');
        }
        return (new ConfigurationSnapshot())->build($parameters, $rows);
    }
}
