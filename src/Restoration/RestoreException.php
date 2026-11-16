<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\Restoration;

/** Safe, user-facing messages only. Never wrap SQL or unvalidated field values here. */
final class RestoreException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct(\Pixel\Module\Audit\Presentation\I18n::trans($message));
    }
}
