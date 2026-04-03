<?php

declare(strict_types=1);

namespace EightshiftMultilang\Parser;

/**
 * Represents a single translatable string extracted from a block.
 *
 * The key is the lookup identifier used when sending strings to the AI
 * and when re-injecting translations back into the markup.
 */
final class TranslatableString
{
	/** Context value for attribute-based strings. */
	public const CONTEXT_BLOCK_ATTRIBUTE = 'block_attribute';

	/** Context value for text extracted from inner block content. */
	public const CONTEXT_INNER_CONTENT = 'inner_content';

	public function __construct(
		/** Unique key: "block_{blockIndex}_{attributeName}" */
		public readonly string $key,
		/** Original text value. */
		public readonly string $value,
		/** Index of the block this string belongs to. */
		public readonly int $blockIndex,
		/** Attribute name within the block (e.g. 'headingHeadingContent'). */
		public readonly string $attributeName,
		/** How the string was extracted — block attribute or inner HTML. */
		public readonly string $context,
	) {
	}
}
