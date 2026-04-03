<?php

declare(strict_types=1);

namespace EightshiftMultilang\Exceptions;

/**
 * Thrown when the monthly AI API call limit has been reached.
 */
class RateLimitException extends TranslationException
{
}
