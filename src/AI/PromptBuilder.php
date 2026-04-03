<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

/**
 * Builds the system prompt sent to the AI translation provider.
 *
 * The prompt instructs the model to:
 *  - Return a JSON object with the same keys as the input.
 *  - Translate only values, never keys.
 *  - Preserve HTML tags, placeholder tokens, and formality level.
 *  - Translate the special '__post_slug' key to a URL-safe slug.
 *  - Use the provided glossary for specific terms (Phase 2: populated from DB).
 */
final class PromptBuilder
{
	private const BASE_SYSTEM_PROMPT = <<<'PROMPT'
You are a professional translator. Translate the following JSON object's values from {source} to {target}. The keys are identifiers — do NOT translate them.

Rules:
- Preserve HTML tags exactly as they appear. Do not translate tag names or attributes.
- Preserve placeholder tokens (e.g. {{name}}, {count}, %s, %d) exactly as they appear.
- Maintain the same tone, register, and formality level as the source text.
- Do not add, remove, or explain content. Return only the translated JSON.
- The key "__post_slug" contains a URL slug. Translate it into a URL-safe, lowercase, hyphen-separated slug in the target language (e.g. "about-us" → "ueber-uns" for German).
- If a term appears in the glossary below, use the specified translation exactly.

{glossary_section}
Respond with ONLY a valid JSON object mapping the same keys to their translated values. No markdown fences, no preamble, no trailing text.
PROMPT;

	/**
	 * Build the complete system prompt.
	 *
	 * @param string               $sourceLanguage Human-readable source language name (e.g. 'English').
	 * @param string               $targetLanguage Human-readable target language name (e.g. 'German').
	 * @param array<string,string> $glossary       Term → translation pairs for the target language (empty in Phase 1).
	 * @return string                              Full system prompt ready to send to the AI.
	 */
	public function build(string $sourceLanguage, string $targetLanguage, array $glossary = []): string
	{
		$prompt = str_replace(
			['{source}', '{target}'],
			[$sourceLanguage, $targetLanguage],
			self::BASE_SYSTEM_PROMPT
		);

		// Inject glossary section if entries are present.
		if (! empty($glossary)) {
			$lines = ["Glossary:\n"];
			foreach ($glossary as $term => $translation) {
				$lines[] = sprintf('- "%s" → "%s"', $term, $translation);
			}

			$glossarySection = implode("\n", $lines) . "\n";
		} else {
			$glossarySection = '';
		}

		$prompt = str_replace('{glossary_section}', $glossarySection, $prompt);

		// Append user-defined custom instructions from settings.
		$custom = trim((string) get_option('esml_ai_custom_prompt', ''));
		if ($custom !== '') {
			$prompt .= "\n\nAdditional instructions:\n" . $custom;
		}

		/**
		 * Filter: esml_ai_system_prompt
		 * Modify the full system prompt before it is sent to the AI provider.
		 *
		 * @param string $prompt         The built prompt.
		 * @param string $sourceLanguage Source language name.
		 * @param string $targetLanguage Target language name.
		 */
		return (string) apply_filters('esml_ai_system_prompt', $prompt, $sourceLanguage, $targetLanguage);
	}
}
