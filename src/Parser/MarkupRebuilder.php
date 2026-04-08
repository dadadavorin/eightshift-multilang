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
 *
 * Wrapper blocks (opening + closing tags) require special handling:
 * only the opening tag is replaced, never the full rawMarkup. Replacing
 * the full rawMarkup would:
 *   (a) Overwrite already-translated inner blocks (their translations were
 *       applied in earlier iterations at higher offsets), and
 *   (b) Corrupt surrounding markup when the inner translation changed the
 *       byte length, because len(rawMarkup) no longer matches the actual
 *       block span in $result.
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

			if ($block->isWrapper()) {
				// WRAPPER BLOCK: only modify the opening tag (the comment up to and
				// including the first "-->").  The inner content must not be touched
				// here because inner blocks were already translated in earlier loop
				// iterations (higher offsets), and their byte lengths may have changed
				// so len(rawMarkup) no longer matches the actual span in $result.
				$openingLen    = $this->openingTagLength($block->rawMarkup);
				$openingTag    = substr($block->rawMarkup, 0, $openingLen);
				$newOpeningTag = $this->applyTranslationsToMarkup($openingTag, $blockTranslations);

				if ($newOpeningTag !== $openingTag) {
					$result = substr_replace($result, $newOpeningTag, $block->contentOffset, $openingLen);
				}
			} else {
				// SELF-CLOSING BLOCK: the full rawMarkup equals exactly the block
				// span in $result (no inner blocks can have shifted its length).
				$newMarkup = $this->applyTranslationsToMarkup($block->rawMarkup, $blockTranslations);
				$result    = substr_replace($result, $newMarkup, $block->contentOffset, strlen($block->rawMarkup));
			}
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
			$search  = '"' . $attrName . '":' . $encodedOriginal;
			$replace = '"' . $attrName . '":' . $encodedTranslated;

			$newMarkup = str_replace($search, $replace, $markup);

			if ($newMarkup === $markup) {
				// Primary search found nothing.  This happens when the post was saved
				// via a code path that decoded certain JSON unicode escapes back to
				// their literal characters before storage.  The most common cases are:
				//
				//   \u0027  →  '  (apostrophe decoded by the REST API json_decode)
				//   \u0026  →  &  (ampersand decoded similarly)
				//
				// Generate a fallback search that replaces those escape sequences with
				// their literal counterparts and retry.  Note: \u003c / \u003e are
				// always stored as literal escape sequences because the outer JSON
				// double-escapes them (\\u003c → \u003c after wp_unslash), so we do
				// not need a fallback for angle brackets.
				$altOriginal = str_replace(
					['\u0027', '\u0026'],
					["'",      '&'],
					$encodedOriginal
				);

				if ($altOriginal !== $encodedOriginal) {
					$altTranslated = str_replace(
						['\u0027', '\u0026'],
						["'",      '&'],
						$encodedTranslated
					);
					$altSearch  = '"' . $attrName . '":' . $altOriginal;
					$altReplace = '"' . $attrName . '":' . $altTranslated;
					$newMarkup  = str_replace($altSearch, $altReplace, $markup);
				}
			}

			$markup = $newMarkup;
		}

		return $markup;
	}

	/**
	 * Return the byte length of a wrapper block's opening tag — from the start
	 * of the block comment up to and including the first occurrence of "-->".
	 *
	 * Example:
	 *   <!-- wp:eightshift-boilerplate/accordion-simple-item {"accordionSimpleItemLabel":"FAQ"} -->
	 *   ↑                                                                                       ↑
	 *   offset 0                                                                     openingLen-1
	 *
	 * @param string $rawMarkup The raw markup of a wrapper block.
	 * @return int              Byte length of the opening tag (including "-->").
	 */
	private function openingTagLength(string $rawMarkup): int
	{
		$pos = strpos($rawMarkup, '-->');
		return $pos !== false ? $pos + 3 : strlen($rawMarkup);
	}

	/**
	 * JSON-encode a scalar value to its inline JSON representation (with quotes for strings).
	 *
	 * Must match Gutenberg's serializeAttributes() output exactly so that our
	 * str_replace search patterns find the right substrings in post_content.
	 *
	 * Gutenberg uses JSON.stringify (which does NOT escape forward slashes) then
	 * applies four extra character replacements for XSS safety:
	 *   & → \u0026   < → \u003c   > → \u003e   ' → \u0027
	 *
	 * PHP's json_encode defaults differ in two ways:
	 *   1. It DOES escape '/' as '\/' unless JSON_UNESCAPED_SLASHES is set.
	 *   2. It does NOT apply the four Gutenberg XSS replacements.
	 *
	 * @param string $value The original string value.
	 * @return string       The JSON-encoded representation including surrounding quotes.
	 */
	private function jsonEncodeValue(string $value): string
	{
		// JSON_UNESCAPED_UNICODE preserves multi-byte characters (e.g. accented
		// letters) without \uXXXX encoding — matching JSON.stringify behaviour.
		// JSON_UNESCAPED_SLASHES prevents PHP from escaping '/' to '\/' —
		// Gutenberg's JSON.stringify never escapes forward slashes.
		$encoded = json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

		if ($encoded === false) {
			return '"' . addslashes($value) . '"';
		}

		// Replicate Gutenberg's serializeAttributes() post-stringify replacements
		// so the encoded value is byte-for-byte identical to what is stored in
		// post_content.  These replacements are safe to apply to the full encoded
		// string because the only characters that appear outside string literals in
		// a single-value JSON encoding are the surrounding double-quote characters,
		// which none of the four search characters can match.
		return str_replace(
			['&',       '<',       '>',       "'"],
			['\u0026', '\u003c', '\u003e', '\u0027'],
			$encoded
		);
	}
}
