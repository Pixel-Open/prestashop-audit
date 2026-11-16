<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Core;

use Pixel\Module\Audit\Api\Registry;
use Pixel\Module\Audit\Api\Snapshot;
use Pixel\Module\Audit\Api\Subject;
use Pixel\Module\Audit\Configuration\Settings;
use Pixel\Module\Audit\Storage\Repository;

/** Public service exposed by Module::getInstanceByName('pixel_audit')->audit(). */
final class Audit
{
    private array $watches = [];
    private int $collectionCycle = 0;
    private array $explicitRecords = [];
    private Buffer $buffer;
    private Comparator $comparator;
    private bool $flushing = false;
    private ?string $operation = null;
    private array $knownOperations = [];
    private \Pixel\Module\Audit\Collector\GeneratedSpecificPrices $generatedPrices;

    public function __construct(
        private Registry $registry,
        private Settings $settings,
        private Repository $repository,
        private array $actor,
        private ?\Pixel\Module\Audit\Correlation\ProductOperation $productOperation = null,
    ) {
        $this->comparator = new Comparator();
        $this->buffer = new Buffer($this->comparator);
        $this->generatedPrices = new \Pixel\Module\Audit\Collector\GeneratedSpecificPrices();
        try {
            $this->productOperation?->prime();
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function collectionCycle(): int
    {
        return $this->collectionCycle;
    }

    /** Groups explicit calls within this request. Never accept operation IDs from HTTP input. */
    public function beginOperation(): string
    {
        $id = bin2hex(random_bytes(16));
        $this->knownOperations[$id] = true;
        $this->operation = $id;
        return $id;
    }

    public function endOperation(): void
    {
        $this->operation = null;
    }

    /** Call BEFORE SQL writes; final snapshots are read when flush() runs. */
    public function watch(Subject $subject, bool $created = false): void
    {
        if ($this->flushing) {
            return;
        }
        try {
            $this->productOperation?->start($subject);
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
        $product = $subject->type === 'product' ? $subject->id : ($subject->rootType === 'product' ? $subject->rootId : null);
        if (!$created && $product !== null && in_array($subject->type, ['product', 'combination'], true) && $this->settings->enabled('prices')) {
            try {
                $this->generatedPrices->capture($product, $this->operation);
            } catch (\Throwable $error) {
                Diagnostics::report($error);
            }
        }
        foreach ($this->registry->providers() as $provider) {
            if (!$created && $subject->type === 'product' && $provider instanceof \Pixel\Module\Audit\Collector\RelationsProvider && $this->settings->enabled($provider->family())) {
                try {
                    $provider->captureProductRelations($subject->id);
                } catch (\Throwable $error) {
                    Diagnostics::report($error);
                }
            }
            if (!$created && $subject->type === 'product' && $provider instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider && $this->settings->enabled($provider->family())) {
                try {
                    $provider->captureProductDefaults($subject->id);
                } catch (\Throwable $error) {
                    Diagnostics::report($error);
                }
            }
            if ($provider instanceof \Pixel\Module\Audit\Api\ExplicitProvider || !$this->settings->enabled($provider->family()) || !$provider->supports($subject)) {
                continue;
            }
            $key = ($this->operation ?? 'auto') . ':' . $subject->key() . ':' . $provider->name();
            if (isset($this->watches[$key])) {
                continue;
            }
            try {
                $before = $created ? new Snapshot(false) : $provider->snapshot($subject);
                if (!$created && ($provider instanceof \Pixel\Module\Audit\Collector\ObjectModelProvider || $provider instanceof \Pixel\Module\Audit\Collector\RelationsProvider)) {
                    $before = $provider->initialSnapshot($subject, $before);
                }
                $this->watches[$key] = [$subject, $provider, $this->comparator->sanitize($before), $this->operation];
            } catch (\Throwable $error) {
                Diagnostics::report($error);
            }
        }
    }

    /** For persisted writes whose data cannot be obtained by a snapshot provider. */
    public function recordChange(Subject $subject, string $provider, string $field, \Pixel\Module\Audit\Api\Field $before, \Pixel\Module\Audit\Api\Field $after, ?string $operation = null): void
    {
        try {
            $registered = $this->registry->providers()[$provider] ?? null;
            if (!$registered instanceof \Pixel\Module\Audit\Api\ExplicitProvider || !$registered->supports($subject)) {
                throw new \InvalidArgumentException('Register the field owner before recording a change.');
            }
            if (!$this->settings->enabled($registered->family())) {
                return;
            }
            $operation ??= $this->operation;
            $this->productOperation?->start($subject);
            if ($operation !== null && !isset($this->knownOperations[$operation])) {
                throw new \InvalidArgumentException('Unknown local audit operation.');
            }
            $old = $this->comparator->sanitize(new Snapshot(true, [$field => $before]));
            $new = $this->comparator->sanitize(new Snapshot(true, [$field => $after]));
            $key = ($operation ?? 'auto') . ':' . $subject->key() . ':' . $provider;
            $this->explicitRecords[$key] ??= [
                'subject' => $subject, 'provider' => $provider, 'family' => $registered->family(),
                'operation' => $operation, 'before' => [], 'after' => [],
            ];
            $this->explicitRecords[$key]['before'] += $old->fields;
            $this->explicitRecords[$key]['after'] = array_replace($this->explicitRecords[$key]['after'], $new->fields);
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
    }

    /** Paired persisted field snapshots for the Configuration bridge (scopes themselves always exist). */
    public function recordFieldSnapshots(Subject $subject, string $provider, Snapshot $before, Snapshot $after): void
    {
        try {
            $registered = $this->registry->providers()[$provider] ?? null;
            if (!$registered instanceof \Pixel\Module\Audit\Configuration\ConfigurationProvider || !$registered->supports($subject)) {
                throw new \InvalidArgumentException('Invalid configuration field owner.');
            }
            $before = $this->comparator->sanitize($before);
            $after = $this->comparator->sanitize($after);
            $key = ($this->operation ?? 'auto') . ':' . $subject->key() . ':' . $provider;
            $this->explicitRecords[$key] ??= [
                'subject' => $subject, 'provider' => $provider, 'family' => $registered->family(),
                'operation' => $this->operation, 'before' => [], 'after' => [],
            ];
            foreach (array_unique(array_merge(array_keys($before->fields), array_keys($after->fields))) as $field) {
                $meta = $after->fields[$field] ?? $before->fields[$field];
                $old = $before->fields[$field] ?? new \Pixel\Module\Audit\Api\Field(null, $meta->label, $meta->type);
                $new = $after->fields[$field] ?? new \Pixel\Module\Audit\Api\Field(null, $meta->label, $meta->type);
                $this->explicitRecords[$key]['before'] += [$field => $old];
                $this->explicitRecords[$key]['after'][$field] = $new;
            }
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
    }

    public function flush(): void
    {
        if ($this->flushing) {
            return;
        }
        $this->flushing = true;
        try {
            try {
                foreach ($this->generatedPrices->changes() as [$subject, $before, $after, $operation]) {
                    $this->buffer->observe($subject, 'native.specific_price', 'prices', $before, $after, $operation);
                }
            } catch (\Throwable $error) {
                Diagnostics::report($error);
            }
            foreach ($this->watches as [$subject, $provider, $before, $operation]) {
                if ($provider->name() === 'native.specific_price' && $this->generatedPrices->handles($subject)) {
                    continue;
                }
                try {
                    $after = $provider->snapshot($subject);
                    if ($provider instanceof \Pixel\Module\Audit\Collector\RelationsProvider) {
                        [$before, $after] = $provider->comparisonSnapshots($subject, $before, $after);
                    }
                    $this->buffer->observe($subject, $provider->name(), $provider->family(), $before, $after, $operation);
                } catch (\Throwable $error) {
                    Diagnostics::report($error);
                }
            }
            foreach ($this->explicitRecords as $recordKey => $record) {
                // A caller explicitly watching a whole configuration scope owns its final snapshot.
                if ($record['provider'] === 'configuration.selected' && isset($this->watches[$recordKey])) {
                    continue;
                }
                $this->buffer->observe(
                    $record['subject'],
                    $record['provider'],
                    $record['family'],
                    new Snapshot(true, $record['before']),
                    new Snapshot(true, $record['after']),
                    $record['operation']
                );
            }
            $fatal = error_get_last();
            $partial = $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
            $events = $this->buffer->events();
            foreach ($events as &$event) {
                if ($this->productOperation?->active() && $this->productOperation->accepts($event['subject'])) {
                    $event['operation'] = $this->productOperation->key();
                    $event['correlated'] = true;
                }
            }
            unset($event);
            $this->repository->append($events, $this->actor, $partial ? 'partial' : 'observed', $this->settings);
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        } finally {
            $this->productOperation?->release();
            $this->watches = [];
            $this->explicitRecords = [];
            $this->buffer->clear();
            $this->generatedPrices->reset();
            ++$this->collectionCycle;
            foreach ($this->registry->providers() as $provider) {
                if ($provider instanceof \Pixel\Module\Audit\Api\ResettableProviderInterface) {
                    try {
                        $provider->reset();
                    } catch (\Throwable $error) {
                        Diagnostics::report($error);
                    }
                }
            }
            $this->flushing = false;
        }
    }
}
