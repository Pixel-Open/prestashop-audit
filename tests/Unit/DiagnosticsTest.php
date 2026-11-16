<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Core\Diagnostics;

final class DiagnosticsTest extends TestCase
{
    public function testOnlyRecentCollectionErrorsAreSurfaced(): void
    {
        $now = strtotime('2026-11-11 12:00:00 UTC');
        $recent = '2026-11-10 08:00:00 UTC - LengthException';
        $this->assertSame($recent, Diagnostics::recent($recent, $now));
        $this->assertNull(Diagnostics::recent('2026-11-01 08:00:00 UTC - LengthException', $now));
        $this->assertNull(Diagnostics::recent(null, $now));
        $this->assertNull(Diagnostics::recent('garbage', $now));
    }
}
