<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

/**
 * Resolves the active AI provider from the plugin settings.
 *
 * The active provider identifier is stored in the 'esml_ai_provider' option.
 * ProviderFactory reads that value and delegates instantiation to ProviderRegistry.
 *
 * This is intentionally a static factory so it can be called at any point after
 * the registry has been populated, without requiring DI wiring.
 */
final class ProviderFactory
{
	/**
	 * Instantiate the currently-configured AI provider.
	 *
	 * @param ProviderRegistry $registry The populated registry.
	 * @return ProviderInterface         The active provider instance.
	 */
	public static function make(ProviderRegistry $registry): ProviderInterface
	{
		$identifier = (string) get_option('esml_ai_provider', 'claude');

		return $registry->make($identifier);
	}
}
