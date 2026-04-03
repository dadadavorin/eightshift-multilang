<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

use EightshiftMultilang\Exceptions\TranslationException;

/**
 * Contract that all AI translation provider adapters must implement.
 */
interface ProviderInterface
{
	/**
	 * Translate a batch of strings.
	 *
	 * @param array<string, string> $strings        Key → original string map.
	 * @param string                $sourceLanguage ISO language name (e.g. 'English').
	 * @param string                $targetLanguage ISO language name (e.g. 'German').
	 * @param string                $systemPrompt   Full system prompt (built by PromptBuilder).
	 * @return array<string, string>                Key → translated string map (same keys as input).
	 * @throws TranslationException                 On any API or parsing error.
	 */
	public function translate(
		array $strings,
		string $sourceLanguage,
		string $targetLanguage,
		string $systemPrompt,
	): array;

	/**
	 * Verify the provider is configured and reachable.
	 * Used by the "Test API Key" button in the settings page.
	 */
	public function validateConnection(): ProviderStatus;

	/**
	 * Return a short provider identifier (e.g. 'claude', 'openai').
	 */
	public function getIdentifier(): string;
}
