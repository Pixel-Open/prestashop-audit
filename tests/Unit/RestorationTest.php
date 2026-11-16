<?php

declare(strict_types=1);

namespace Pixel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use Pixel\Module\Audit\Restoration\{ProductFields, PreviewToken, RestoreException};

final class RestorationTest extends TestCase
{
    public function testOnlyExplicitNativeTextFieldsAndTranslationsCanBeSelected(): void
    {
        self::assertSame(2, ProductFields::describe('name.lang_2')['language']);
        foreach (['price', 'stock', 'link_rewrite.lang_1', 'example_name', 'name', 'name.lang_0', 'passwd', 'reference.lang_1'] as $key) { self::assertNull(ProductFields::describe($key)); }
        $changes = ['reference' => ['type' => 'text', 'before' => 'old', 'after' => 'new'], 'name.lang_1' => ['type' => 'text', 'before' => null, 'after' => 'name']];
        self::assertSame(['reference'], ProductFields::available($changes));
        self::assertSame(['reference'], ProductFields::select(['reference', 'reference'], $changes));
        $this->expectException(RestoreException::class); ProductFields::select(['price'], $changes);
    }

    public function testPreviewIsBoundToSessionActorAndExpiryWithoutValues(): void
    {
        $signer = new PreviewToken('secret');
        $claims = ['product' => 480, 'event' => 12, 'fields' => ['reference'], 'employee' => 2,
            'session' => hash('sha256', 'session'), 'expires' => 1300, 'nonce' => str_repeat('a', 32), 'fingerprint' => 'digest'];
        $token = $signer->issue($claims);
        self::assertSame($claims, $signer->verify($token, 2, 'session', 1000));
        foreach ([[$token, 3, 'session', 1000], [$token, 2, 'another', 1000], [$token, 2, 'session', 1300], [$token . 'x', 2, 'session', 1000]] as $args) {
            try { $signer->verify(...$args); self::fail('An invalid preview was accepted'); } catch (RestoreException $error) { self::assertGreaterThanOrEqual(400, $error->status); }
        }
    }
}
