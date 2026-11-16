<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Privacy\CustomerExport;

final class CustomerExportTest extends TestCase
{
    public function testOneFlatRowPerChangedField(): void
    {
        $rows = CustomerExport::rows([[
            'date_add' => '2026-11-01 10:00:00', 'subject_type' => 'customer', 'subject_id' => '12', 'action' => 'update',
            'changes' => json_encode([
                'email' => ['label' => 'Email', 'type' => 'string', 'before' => 'old@example.com', 'after' => 'new@example.com'],
                'groups' => ['label' => 'Groups', 'type' => 'json', 'before' => null, 'after' => [3, 4]],
            ], JSON_THROW_ON_ERROR),
        ]]);
        self::assertSame([
            ['date_utc' => '2026-11-01 10:00:00', 'object' => 'customer #12', 'action' => 'update', 'field' => 'Email', 'before' => 'old@example.com', 'after' => 'new@example.com'],
            ['date_utc' => '2026-11-01 10:00:00', 'object' => 'customer #12', 'action' => 'update', 'field' => 'Groups', 'before' => '', 'after' => '[3,4]'],
        ], $rows);
    }

    public function testUnreadableEventsAreSkipped(): void
    {
        self::assertSame([], CustomerExport::rows([[
            'date_add' => '2026-11-01 10:00:00', 'subject_type' => 'address', 'subject_id' => 4, 'action' => 'delete', 'changes' => '{',
        ]]));
    }
}
