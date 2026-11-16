<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Api;

final class Registry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];
    /** @var array<string, ProductRestorationDefinition> */
    private array $productRestorations = [];
    private array $restorationProperties = [];
    private array $restorationStorages = [];
    /** @var array<string, string> */
    private array $families;
    /** @var array<string, ConfigurationParameter> */
    private array $configurationParameters = [];

    public function __construct(array $families)
    {
        $this->families = $families;
    }

    public function add(ProviderInterface $provider, string $familyLabel): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,95}$/D', $provider->name())
            || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provider->family())) {
            throw new \InvalidArgumentException('Invalid provider namespace or family.');
        }
        if (isset($this->providers[$provider->name()])) {
            throw new \LogicException('Duplicate audit provider: ' . $provider->name());
        }
        if ($provider instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider) {
            foreach ($this->providers as $other) {
                if ($other instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider && $provider->conflictsWith($other)) {
                    throw new \LogicException('Overlapping ObjectModel field ownership.');
                }
            }
        }
        $this->families[$provider->family()] = $familyLabel;
        $this->providers[$provider->name()] = $provider;
        if ($provider instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider) {
            foreach ($this->providers as $other) {
                if ($other instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider) {
                    $other->excludeOwnedFields($provider);
                    $provider->excludeOwnedFields($other);
                }
            }
        }
    }

    public function addProductRestoration(ProductRestorationDefinition $definition): void
    {
        $provider = $this->providers[$definition->provider] ?? null;
        if (!$provider instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider || $provider->family() !== $definition->family
            || isset($this->productRestorations[$definition->provider])) {
            throw new \LogicException('Register one matching Product snapshot provider before its restoration definition.');
        }
        foreach ($definition->fields as $field) {
            if (!$provider->ownsRestorationProperty($field->field, $definition->owner === 'prestashop') || isset($this->restorationProperties[$field->field])) {
                throw new \LogicException('Restoration field ownership collision or invalid Product property owner.');
            }
        }
        foreach ($definition->fields as $field) {
            $this->restorationProperties[$field->field] = $definition->provider;
        }
        $this->productRestorations[$definition->provider] = $definition;
    }

    public function addRestorationStorage(ProductRestorationDefinition $definition, RestorationStorageInterface $storage): void
    {
        $provider = $this->providers[$definition->provider] ?? null;
        if ($provider === null || $provider->family() !== $definition->family || $definition->owner === 'prestashop'
            || isset($this->productRestorations[$definition->provider]) || $storage->subjectType() !== 'combination' || !$storage->tables()) {
            throw new \LogicException('Register a matching combination provider and transactional storage.');
        }
        foreach ($storage->tables() as $table) {
            if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table)) {
                throw new \LogicException('Invalid restoration table name.');
            }
        }
        foreach ($definition->fields as $field) {
            if ($field->scope !== 'global' || $field->translated) {
                throw new \LogicException('Combination storage currently supports global scalar fields only.');
            }
        }
        $this->productRestorations[$definition->provider] = $definition;
        $this->restorationStorages[$definition->provider] = $storage;
    }

    public function restorationStorage(string $provider): ?RestorationStorageInterface
    {
        return $this->restorationStorages[$provider] ?? null;
    }

    /** @return array<string, ProductRestorationDefinition> */
    public function productRestorations(): array
    {
        return $this->productRestorations;
    }

    public function addConfigurationParameter(ConfigurationParameter $parameter): void
    {
        if (isset($this->configurationParameters[$parameter->key])) {
            throw new \LogicException('Duplicate configuration key owner: ' . $parameter->key);
        }
        $this->configurationParameters[$parameter->key] = $parameter;
    }

    /** @return array<string, ConfigurationParameter> */
    public function configurationParameters(): array
    {
        return $this->configurationParameters;
    }

    /** @return array<string, string> */
    public function families(): array
    {
        return $this->families;
    }
    /** @return array<string, ProviderInterface> */
    public function providers(): array
    {
        return $this->providers;
    }
}
