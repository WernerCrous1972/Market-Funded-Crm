<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class TemplateRequiredException extends RuntimeException
{
    public function __construct(string $personId)
    {
        parent::__construct(
            "Cannot send free-form message to person {$personId}: outside 24-hour service window. Provide a template name."
        );
    }
}
