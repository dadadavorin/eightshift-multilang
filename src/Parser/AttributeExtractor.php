<?php

declare(strict_types=1);

namespace EightshiftMultilang\Parser;

/**
 * Filters a block's attribute map to find translatable values.
 *
 * An attribute is translatable when:
 *  1. Its key ends with one of the configured suffixes (default: 'Content').
 *  2. Its value is a non-empty string.
 *  3. Its value is not a URL, a numeric string, or a boolean-looking value
 *     (these false-positives are filtered out by default; overridable via filter).
 */
final class AttributeExtractor
{
	/**
	 * Extract translatable attributes from a block's attribute map.
	 *
	 * @param array<string, mixed> $attributes  All decoded block attributes.
	 * @param list<string>         $suffixes    Translatable key suffixes, e.g. ['Content', 'Label'].
	 * @return array<string, string>            Matching key => string value pairs only.
	 */
	public function extract(array $attributes, array $suffixes): array
	{
		$translatable = [];

		foreach ($attributes as $key => $value) {
			// Only string values are ever translatable.
			if (! is_string($value)) {
				continue;
			}

			// Skip empty or whitespace-only strings.
			if (trim($value) === '') {
				continue;
			}

			// Check if the attribute key ends with any configured suffix.
			$matchesSuffix = false;
			foreach ($suffixes as $suffix) {
				if (str_ends_with($key, $suffix)) {
					$matchesSuffix = true;
					break;
				}
			}

			if (! $matchesSuffix) {
				continue;
			}

			// Apply the translatable-value heuristic check, overridable via filter.
			$isTranslatable = $this->isTranslatableValue($value, $key);

			/**
			 * Filter: esml_is_translatable_value
			 *
			 * Override whether a specific attribute value should be sent for translation.
			 *
			 * @param bool   $isTranslatable Current decision.
			 * @param string $value          The attribute value.
			 * @param string $attributeName  The attribute key.
			 */
			$isTranslatable = (bool) apply_filters('esml_is_translatable_value', $isTranslatable, $value, $key);

			if ($isTranslatable) {
				$translatable[$key] = $value;
			}
		}

		return $translatable;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Heuristic check to exclude values that happen to match a *Content suffix
	 * but are not human-readable text (URLs, pure numbers, etc.).
	 */
	private function isTranslatableValue(string $value, string $key): bool
	{
		// URLs — even if key ends with 'Content', a URL should not be translated.
		if (preg_match('#^https?://#i', $value)) {
			return false;
		}

		// Numeric-only strings (IDs, pixel values).
		if (is_numeric($value)) {
			return false;
		}

		// Boolean-string values.
		if (in_array(strtolower($value), ['true', 'false'], true)) {
			return false;
		}

		return true;
	}
}
