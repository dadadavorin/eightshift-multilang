<?php

declare(strict_types=1);

namespace EightshiftMultilang\Translations;

/**
 * Immutable value object representing a single translation row.
 */
final class Translation
{
	public function __construct(
		public readonly int $id,
		public readonly string $translationGroup,
		public readonly int $postId,
		public readonly string $languageCode,
		public readonly bool $isSource,
		public readonly \DateTimeImmutable $createdAt,
		public readonly \DateTimeImmutable $updatedAt,
	) {
	}

	/**
	 * Hydrate from a database row array.
	 *
	 * @param array<string, mixed> $row Database result row.
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			id: (int) $row['id'],
			translationGroup: (string) $row['translation_group'],
			postId: (int) $row['post_id'],
			languageCode: (string) $row['language_code'],
			isSource: (bool) $row['is_source'],
			createdAt: new \DateTimeImmutable((string) $row['created_at']),
			updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
		);
	}
}
