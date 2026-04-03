<?php

declare(strict_types=1);

namespace EightshiftMultilang\Languages;

/**
 * Immutable value object representing a language record.
 */
final class Language
{
	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly string $locale,
		public readonly string $name,
		public readonly string $nativeName,
		public readonly string $flagCode,
		public readonly bool $isDefault,
		public readonly bool $isActive,
		public readonly int $sortOrder,
		public readonly ?string $dateFormat,
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
			code: (string) $row['code'],
			locale: (string) $row['locale'],
			name: (string) $row['name'],
			nativeName: (string) $row['native_name'],
			flagCode: (string) $row['flag_code'],
			isDefault: (bool) $row['is_default'],
			isActive: (bool) $row['is_active'],
			sortOrder: (int) $row['sort_order'],
			dateFormat: isset($row['date_format']) && $row['date_format'] !== '' ? (string) $row['date_format'] : null,
		);
	}
}
