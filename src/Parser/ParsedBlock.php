<?php

declare(strict_types=1);

namespace EightshiftMultilang\Parser;

/**
 * Represents a single parsed Eightshift block with its translatable attributes identified.
 */
final class ParsedBlock
{
	public function __construct(
		/** Sequential index (0-based) within the full content parse. */
		public readonly int $index,
		/** Block name, e.g. 'eightshift-boilerplate/heading'. */
		public readonly string $blockName,
		/** The original block markup as it appears in post_content. */
		public readonly string $rawMarkup,
		/** Character offset where rawMarkup starts in the full post_content. */
		public readonly int $contentOffset,
		/** All decoded attributes (full set). */
		public readonly array $attributes,
		/** Filtered subset: only attributes whose keys match a translatable suffix. key => value */
		public readonly array $translatableAttributes,
		/** Inner HTML between opening and closing tags, or null for self-closing blocks. */
		public readonly ?string $innerContent,
	) {
	}

	/** Whether this is a wrapper block (has opening + closing tags). */
	public function isWrapper(): bool
	{
		return $this->innerContent !== null;
	}
}
