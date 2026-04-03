<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

use EightshiftMultilang\Exceptions\TranslationException;

/**
 * Parses the raw text response from an AI provider into a key→translation map.
 *
 * Handles:
 *  - Plain JSON responses.
 *  - JSON wrapped in markdown code fences (```json … ``` or ``` … ```).
 *  - Missing translation keys.
 *  - Non-JSON responses (throws TranslationException).
 */
final class ResponseParser
{
	/**
	 * Parse raw AI response text into a translated string map.
	 *
	 * @param string                $responseText   Raw text from the AI response.
	 * @param array<string, string> $originalStrings The original key→value map sent to the AI.
	 * @return array<string, string>                 Key→translated-value map.
	 * @throws TranslationException                  If the response is not valid JSON or is missing keys.
	 */
	public function parse(string $responseText, array $originalStrings): array
	{
		$text = $this->stripFences($responseText);

		$decoded = json_decode($text, true);

		if (! is_array($decoded)) {
			throw new TranslationException(sprintf(
				'AI response was not valid JSON. json_last_error: %d. Response (first 500 chars): %s',
				json_last_error(),
				substr($text, 0, 500)
			));
		}

		// Validate that every sent key has a translation in the response.
		$missing = array_diff_key($originalStrings, $decoded);
		if (! empty($missing)) {
			throw new TranslationException(sprintf(
				'AI response is missing translations for %d key(s): %s',
				count($missing),
				implode(', ', array_keys($missing))
			));
		}

		// Return only the keys we asked for (ignore any extra keys the AI hallucinated).
		$result = [];
		foreach ($originalStrings as $key => $_) {
			$translatedValue = $decoded[$key];
			if (! is_string($translatedValue)) {
				throw new TranslationException(sprintf(
					'AI response value for key "%s" is not a string (got %s).',
					$key,
					gettype($translatedValue)
				));
			}

			$result[$key] = $translatedValue;
		}

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Strip markdown code fences from the response text if present.
	 *
	 * Handles:
	 *  - ```json\n{...}\n```
	 *  - ```\n{...}\n```
	 *  - Plain {…} (no fences — returned as-is after trimming)
	 */
	private function stripFences(string $text): string
	{
		$text = trim($text);

		// Remove opening fence: optional ```json or just ``` at the start.
		$text = preg_replace('/^```(?:json)?\s*/s', '', $text) ?? $text;

		// Remove closing fence: ``` at the end.
		$text = preg_replace('/\s*```\s*$/s', '', $text) ?? $text;

		return trim($text);
	}
}
