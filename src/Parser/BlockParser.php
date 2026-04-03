<?php

declare(strict_types=1);

namespace EightshiftMultilang\Parser;

/**
 * Parses Eightshift block markup from post_content.
 *
 * Handles:
 *  - Self-closing blocks:  <!-- wp:eightshift-ns/block {...} /-->
 *  - Wrapper blocks:       <!-- wp:eightshift-ns/block {...} --> ... <!-- /wp:eightshift-ns/block -->
 *  - Nested Eightshift blocks inside wrapper inner content (recursive, up to maxDepth).
 *  - Malformed JSON: block is skipped with a warning logged in WP_DEBUG mode.
 *  - Mixed Eightshift + core Gutenberg blocks.
 *  - Blocks with no translatable attributes.
 *
 * The parser does NOT modify content; it only extracts structure.
 * Injection of translated strings is handled by MarkupRebuilder.
 */
final class BlockParser
{
	/** Default maximum recursion depth for nested wrapper blocks. */
	public const DEFAULT_MAX_DEPTH = 10;

	/**
	 * Matches the start of any Eightshift block comment and captures the block name.
	 * We then extract the JSON separately with a balanced-brace reader.
	 *
	 * Group 1: block namespace/name (e.g. 'eightshift-boilerplate/heading')
	 */
	private const BLOCK_OPEN_PATTERN = '/<!--\s+wp:(eightshift-[\w-]+\/[\w-]+)\s+\{/';

	public function __construct(
		private readonly AttributeExtractor $attributeExtractor,
	) {
	}

	/**
	 * Parse full post_content and return all discovered translatable strings.
	 *
	 * @param string       $rawContent Post content (raw Gutenberg markup).
	 * @param list<string> $suffixes   Translatable attribute suffixes (e.g. ['Content']).
	 * @param int          $maxDepth   Maximum wrapper block nesting depth to traverse.
	 * @return ParsedContent
	 */
	public function parseContent(
		string $rawContent,
		array $suffixes,
		int $maxDepth = self::DEFAULT_MAX_DEPTH,
	): ParsedContent {
		$blocks = [];
		$translatableStrings = [];
		$blockIndex = 0;

		$this->parseLevel($rawContent, 0, $suffixes, $blocks, $translatableStrings, $blockIndex, 0, $maxDepth);

		return new ParsedContent($rawContent, $blocks, $translatableStrings);
	}

	// ---------------------------------------------------------------------------
	// Core parsing
	// ---------------------------------------------------------------------------

	/**
	 * Recursively parse one level of content.
	 *
	 * @param string                                $content     The markup to scan.
	 * @param int                                   $baseOffset  Character offset of $content within the root rawContent.
	 * @param list<string>                          $suffixes    Translatable suffixes.
	 * @param list<ParsedBlock>                     $blocks      Output: discovered blocks (appended).
	 * @param array<string, TranslatableString>     $strings     Output: all translatable strings (appended).
	 * @param int                                   $blockIndex  Running block counter (passed by reference).
	 * @param int                                   $depth       Current recursion depth.
	 * @param int                                   $maxDepth    Max allowed depth.
	 */
	private function parseLevel(
		string $content,
		int $baseOffset,
		array $suffixes,
		array &$blocks,
		array &$strings,
		int &$blockIndex,
		int $depth,
		int $maxDepth,
	): void {
		if ($depth >= $maxDepth) {
			return;
		}

		$pos = 0;
		$contentLength = strlen($content);

		while ($pos < $contentLength) {
			// Find the next Eightshift block opening.
			if (! preg_match(self::BLOCK_OPEN_PATTERN, $content, $match, \PREG_OFFSET_CAPTURE, $pos)) {
				break;
			}

			$blockName = $match[1][0];
			// Position of the `{` that starts the JSON (end of the full match - 1).
			$jsonBracePos = $match[0][1] + strlen($match[0][0]) - 1;
			$commentStart = $match[0][1];

			// Extract balanced JSON starting at the `{`.
			$jsonSpan = $this->extractBalancedJson($content, $jsonBracePos);

			if ($jsonSpan === null) {
				// Could not find balanced JSON — skip past this match.
				$pos = $commentStart + strlen($match[0][0]);
				continue;
			}

			[$jsonStart, $jsonEnd] = $jsonSpan;
			$jsonString = substr($content, $jsonStart, $jsonEnd - $jsonStart);

			$attributes = json_decode($jsonString, true);

			if (! is_array($attributes)) {
				// Malformed JSON — log in debug mode and skip.
				if (defined('WP_DEBUG') && WP_DEBUG) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(sprintf(
						'[Eightshift Multilang] Malformed JSON in block "%s" at offset %d. Skipping.',
						$blockName,
						$baseOffset + $commentStart
					));
				}

				$pos = $commentStart + strlen($match[0][0]);
				continue;
			}

			// Determine block type: self-closing or wrapper.
			// After the JSON we expect either " /-->" (self-closing) or " -->" (wrapper opening).
			$afterJson = substr($content, $jsonEnd);

			if (preg_match('/^\s+\/-->/', $afterJson, $closingMatch)) {
				// --- Self-closing block ---
				$blockEnd = $jsonEnd + strlen($closingMatch[0]);
				$rawMarkup = substr($content, $commentStart, $blockEnd - $commentStart);

				$this->processBlock(
					$blockIndex++,
					$blockName,
					$rawMarkup,
					$baseOffset + $commentStart,
					$attributes,
					null,
					$suffixes,
					$blocks,
					$strings
				);

				$pos = $blockEnd;
			} elseif (preg_match('/^\s+-->/', $afterJson, $openingCloseMatch)) {
				// --- Wrapper block ---
				$innerStart = $jsonEnd + strlen($openingCloseMatch[0]);
				$closingTag = '<!-- /wp:' . $blockName . ' -->';

				// Find the matching closing tag (accounting for nested same-name blocks).
				$closingPos = $this->findClosingTag($content, $innerStart, $blockName);

				if ($closingPos === null) {
					// Unmatched opening tag — skip.
					$pos = $innerStart;
					continue;
				}

				$innerContent = substr($content, $innerStart, $closingPos - $innerStart);
				$blockEnd = $closingPos + strlen($closingTag);
				$rawMarkup = substr($content, $commentStart, $blockEnd - $commentStart);

				$thisIndex = $blockIndex++;

				$this->processBlock(
					$thisIndex,
					$blockName,
					$rawMarkup,
					$baseOffset + $commentStart,
					$attributes,
					$innerContent,
					$suffixes,
					$blocks,
					$strings
				);

				// Recurse into inner content.
				$this->parseLevel(
					$innerContent,
					$baseOffset + $innerStart,
					$suffixes,
					$blocks,
					$strings,
					$blockIndex,
					$depth + 1,
					$maxDepth
				);

				$pos = $blockEnd;
			} else {
				// Unexpected format — advance past this match.
				$pos = $commentStart + strlen($match[0][0]);
			}
		}
	}

	/**
	 * Build a ParsedBlock and append its TranslatableStrings to the output arrays.
	 *
	 * @param list<ParsedBlock>                 $blocks   Appended in place.
	 * @param array<string, TranslatableString> $strings  Appended in place.
	 */
	private function processBlock(
		int $index,
		string $blockName,
		string $rawMarkup,
		int $contentOffset,
		array $attributes,
		?string $innerContent,
		array $suffixes,
		array &$blocks,
		array &$strings,
	): void {
		/**
		 * Filter: esml_skip_block_types
		 * Block types listed here are excluded from translation entirely.
		 *
		 * @param list<string> $skipTypes Block names to skip.
		 */
		$skipTypes = (array) apply_filters('esml_skip_block_types', []);
		if (in_array($blockName, $skipTypes, true)) {
			return;
		}

		$translatableAttributes = $this->attributeExtractor->extract($attributes, $suffixes);

		$block = new ParsedBlock(
			index: $index,
			blockName: $blockName,
			rawMarkup: $rawMarkup,
			contentOffset: $contentOffset,
			attributes: $attributes,
			translatableAttributes: $translatableAttributes,
			innerContent: $innerContent,
		);

		$blocks[] = $block;

		// Build TranslatableString entries for each translatable attribute.
		foreach ($translatableAttributes as $attrName => $value) {
			$key = "block_{$index}_{$attrName}";

			/**
			 * Filter: esml_pre_translate_string
			 * Modify a string before it is sent for translation.
			 *
			 * @param string $value     Original string value.
			 * @param string $attrName  Attribute name.
			 * @param string $blockName Block type name.
			 */
			$value = (string) apply_filters('esml_pre_translate_string', $value, $attrName, $blockName);

			$strings[$key] = new TranslatableString(
				key: $key,
				value: $value,
				blockIndex: $index,
				attributeName: $attrName,
				context: TranslatableString::CONTEXT_BLOCK_ATTRIBUTE,
			);
		}
	}

	// ---------------------------------------------------------------------------
	// Low-level helpers
	// ---------------------------------------------------------------------------

	/**
	 * Extract the span of a balanced JSON object starting at $startPos.
	 *
	 * Handles nested objects and arrays, and string literals containing braces.
	 *
	 * @param string $content  The full content string.
	 * @param int    $startPos Position of the opening `{`.
	 * @return array{int, int}|null [startPos, endPos] exclusive, or null on failure.
	 */
	private function extractBalancedJson(string $content, int $startPos): ?array
	{
		$depth = 0;
		$inString = false;
		$escape = false;
		$len = strlen($content);

		for ($i = $startPos; $i < $len; $i++) {
			$char = $content[$i];

			if ($escape) {
				$escape = false;
				continue;
			}

			if ($char === '\\' && $inString) {
				$escape = true;
				continue;
			}

			if ($char === '"') {
				$inString = ! $inString;
				continue;
			}

			if ($inString) {
				continue;
			}

			if ($char === '{' || $char === '[') {
				$depth++;
			} elseif ($char === '}' || $char === ']') {
				$depth--;
				if ($depth === 0) {
					return [$startPos, $i + 1];
				}
			}
		}

		return null;
	}

	/**
	 * Find the position of the matching closing tag for a wrapper block,
	 * correctly accounting for nested blocks of the same type.
	 *
	 * @param string $content    The markup to search within.
	 * @param int    $startPos   Character position to start searching from (after the opening tag).
	 * @param string $blockName  The block name (e.g. 'eightshift-boilerplate/card').
	 * @return int|null Position where the closing tag starts, or null if not found.
	 */
	private function findClosingTag(string $content, int $startPos, string $blockName): ?int
	{
		$openingPattern = '/<!--\s+wp:' . preg_quote($blockName, '/') . '\s+\{/';
		$closingTag = '<!-- /wp:' . $blockName . ' -->';
		$closingTagLen = strlen($closingTag);

		$depth = 1; // We are already inside one opening tag.
		$pos = $startPos;
		$contentLen = strlen($content);

		while ($pos < $contentLen) {
			// Look for the next opening or closing occurrence, whichever comes first.
			$nextOpen = null;
			$nextClose = strpos($content, $closingTag, $pos);

			if (preg_match($openingPattern, $content, $m, \PREG_OFFSET_CAPTURE, $pos)) {
				$nextOpen = $m[0][1];
			}

			if ($nextClose === false) {
				// No closing tag found.
				return null;
			}

			if ($nextOpen !== null && $nextOpen < $nextClose) {
				// Found another opening of the same block type first.
				$depth++;
				$pos = $nextOpen + strlen($m[0][0]);
			} else {
				// Found a closing tag.
				$depth--;
				if ($depth === 0) {
					return $nextClose;
				}

				$pos = $nextClose + $closingTagLen;
			}
		}

		return null;
	}
}
