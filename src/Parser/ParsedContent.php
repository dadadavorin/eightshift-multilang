<?php

declare(strict_types=1);

namespace EightshiftMultilang\Parser;

/**
 * The result of parsing a post's content.
 * Contains the original markup and all extracted translatable strings.
 */
final class ParsedContent
{
	/**
	 * @param string                                $rawContent          Original post_content.
	 * @param list<ParsedBlock>                     $blocks              All parsed Eightshift blocks (flattened, depth-first).
	 * @param array<string, TranslatableString>     $translatableStrings All strings keyed by their unique identifier.
	 */
	public function __construct(
		public readonly string $rawContent,
		public readonly array $blocks,
		public readonly array $translatableStrings,
	) {
	}

	/** Whether any translatable content was found. */
	public function hasTranslatableContent(): bool
	{
		return ! empty($this->translatableStrings);
	}

	/**
	 * Return a flat key => value map suitable for sending to the AI provider.
	 *
	 * @return array<string, string>
	 */
	public function toStringMap(): array
	{
		$map = [];
		foreach ($this->translatableStrings as $key => $ts) {
			$map[$key] = $ts->value;
		}

		return $map;
	}
}
