<?php

declare(strict_types=1);

namespace EightshiftMultilang\Parser;

/**
 * Re-injects translated strings into the original Gutenberg markup.
 *
 * Strategy: targeted string replacement within each block's JSON attribute
 * section. We do NOT re-encode the full JSON — this preserves key ordering,
 * whitespace, and avoids round-trip differences. Blocks are processed from
 * last to first (by content offset) so that earlier offsets are not shifted
 * by replacements made in later blocks.
 */
final class MarkupRebuilder
{
	/**
	 * Rebuild post_content with translated values injected.
	 *
	 * @param ParsedContent        $parsed       The original parsed content.
	 * @param array<string,string> $translations Map of TranslatableString key → translated value.
	 * @return string                            Translated markup ready for wp_insert_post().
	 */
	public function rebuild(ParsedContent $parsed, array $translations): string
	{
		// Work on a mutable copy.
		$result = $parsed->rawContent;

		// Sort blocks from last to first so that substr_replace offsets remain valid.
		$blocks = $parsed->blocks;
		usort($blocks, static fn(ParsedBlock $a, ParsedBlock $b) => $b->contentOffset - $a->contentOffset);

		foreach ($blocks as $block) {
			if (empty($block->translatableAttributes)) {
				continue;
			}

			// Collect all translations that apply to this block.
			$blockTranslations = [];
			foreach ($block->translatableAttributes as $attrName => $originalValue) {
				$key = "block_{$block->index}_{$attrName}";
				if (! isset($translations[$key])) {
					continue;
				}

				$translatedValue = $translations[$key];

				/**
				 * Filter: esml_post_translate_string
				 * Modify a translated string before it is injected into the markup.
				 *
				 * @param string $translatedValue The AI-translated value.
				 * @param string $originalValue   The original source value.
				 * @param string $languageCode    Target language code (not available here; passed as '').
				 */
				$translatedValue = (string) apply_filters(
					'esml_post_translate_string',
					$translatedValue,
					$originalValue,
					''
				);

				$blockTranslations[$attrName] = [
					'original'   => $originalValue,
					'translated' => $translatedValue,
				];
			}

			if (empty($blockTranslations)) {
				continue;
			}

			// Replace attribute values inside this block's markup.
			$newMarkup = $this->applyTranslationsToMarkup($block->rawMarkup, $blockTranslations);

			// Swap the original block markup in the result using the known offset.
			$result = substr_replace(
				$result,
				$newMarkup,
				$block->contentOffset,
				strlen($block->rawMarkup)
			);
		}

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Apply a set of attribute translations to a single block's raw markup.
	 *
	 * Performs targeted `"attrName":"originalValue"` → `"attrName":"translatedValue"`
	 * replacement within the JSON section of the block comment.
	 *
	 * @param string                                               $markup       Raw block markup.
	 * @param array<string, array{original: string, translated: string}> $translations Attr name → original/translated pair.
	 */
	private function applyTranslationsToMarkup(string $markup, array $translations): string
	{
		foreach ($translations as $attrName => $pair) {
			$encodedOriginal   = $this->jsonEncodeValue($pair['original']);
			$encodedTranslated = $this->jsonEncodeValue($pair['translated']);

			// Build an exact search string that matches the attribute as it appears
			// in the JSON: "attrName":"originalValue"
			// We allow optional whitespace around the colon to be safe.
			$search = '"' . $attrName . '":' . $encodedOriginal;
			$replace = '"' . $attrName . '":' . $encodedTranslated;

			// Use a single str_replace within the block markup. The attribute name
			// + original value combination is unique within a block's JSON.
			$markup = str_replace($search, $replace, $markup);

			// Fallback: try with a space after the colon (some formatters add one).
			if ($markup === str_replace($search, $replace, $markup)) {
				$searchWithSpace = '"' . $attrName . '": ' . $encodedOriginal;
				$replaceWithSpace = '"' . $attrName . '": ' . $encodedTranslated;
				$markup = str_replace($searchWithSpace, $replaceWithSpace, $markup);
			}
		}

		return $markup;
	}

	/**
	 * JSON-encode a scalar value to its inline JSON representation (with quotes for strings).
	 *
	 * e.g. 'Hello "World"' → '"Hello \"World\""'
	 *
	 * @param string $value The original string value.
	 * @return string       The JSON-encoded representation including surrounding quotes.
	 */
	private function jsonEncodeValue(string $value): string
	{
		$encoded = json_encode($value, \JSON_UNESCAPED_UNICODE);

		// json_encode never returns false for a string, but satisfy static analysis.
		return $encoded !== false ? $encoded : '"' . addslashes($value) . '"';
	}
}
