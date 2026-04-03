<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

/**
 * Result of an AI provider connection validation.
 */
final class ProviderStatus
{
	public function __construct(
		public readonly bool $isConnected,
		public readonly string $message,
		public readonly ?string $model = null,
	) {
	}

	public static function ok(string $model): self
	{
		return new self(true, __('Connection successful.', 'eightshift-multilang'), $model);
	}

	public static function error(string $message): self
	{
		return new self(false, $message);
	}
}
