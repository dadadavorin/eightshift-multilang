<?php

declare(strict_types=1);

namespace EightshiftMultilang\Exceptions;

/**
 * Thrown when the translation process fails at any stage:
 * source post not found, AI provider error, markup rebuild failure, etc.
 */
class TranslationException extends \RuntimeException
{
}
