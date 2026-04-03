<?php

declare(strict_types=1);

namespace EightshiftMultilang\Languages;

use EightshiftMultilang\Cache\CacheManager;

/**
 * Read-only queries against the languages table, with object-cache integration.
 *
 * All public methods return cached results. Cache is invalidated by
 * CacheInvalidator when language configuration changes.
 */
final class LanguageRepository
{
	/** @var string Full table name including DB prefix. */
	private string $table;

	public function __construct(
		private readonly \wpdb $db,
		private readonly CacheManager $cache,
	) {
		$this->table = $this->db->prefix . 'es_multilang_languages';
	}

	/**
	 * Get all languages (active and inactive), ordered by sort_order.
	 *
	 * @return list<Language>
	 */
	public function getAll(): array
	{
		$cached = $this->cache->get(CacheManager::KEY_LANGUAGES_ALL);
		if ($cached !== null) {
			return $cached; // @phpstan-ignore-line
		}

		$rows = $this->db->get_results(
			"SELECT * FROM {$this->table} ORDER BY sort_order ASC, id ASC",
			\ARRAY_A
		);

		$languages = array_map(Language::fromRow(...), $rows ?? []);
		$this->cache->set(CacheManager::KEY_LANGUAGES_ALL, $languages);

		return $languages;
	}

	/**
	 * Get only active languages, ordered by sort_order.
	 *
	 * @return list<Language>
	 */
	public function getActive(): array
	{
		$cached = $this->cache->get(CacheManager::KEY_LANGUAGES_ACTIVE);
		if ($cached !== null) {
			return $cached; // @phpstan-ignore-line
		}

		$rows = $this->db->get_results(
			"SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC",
			\ARRAY_A
		);

		$languages = array_map(Language::fromRow(...), $rows ?? []);
		$this->cache->set(CacheManager::KEY_LANGUAGES_ACTIVE, $languages);

		return $languages;
	}

	/**
	 * Get language codes for all active languages.
	 *
	 * @return list<string>
	 */
	public function getActiveCodes(): array
	{
		return array_map(static fn(Language $l) => $l->code, $this->getActive());
	}

	/**
	 * Find a language by its ISO code.
	 *
	 * @param string $code ISO 639-1 language code (e.g. 'en', 'de').
	 */
	public function getByCode(string $code): ?Language
	{
		foreach ($this->getAll() as $language) {
			if ($language->code === $code) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Get the default language.
	 */
	public function getDefault(): ?Language
	{
		$cached = $this->cache->get(CacheManager::KEY_LANGUAGE_DEFAULT);
		if ($cached !== null) {
			return $cached; // @phpstan-ignore-line
		}

		$row = $this->db->get_row(
			"SELECT * FROM {$this->table} WHERE is_default = 1 LIMIT 1",
			\ARRAY_A
		);

		if ($row === null) {
			return null;
		}

		$language = Language::fromRow($row);
		$this->cache->set(CacheManager::KEY_LANGUAGE_DEFAULT, $language);

		return $language;
	}

	/**
	 * Get the ISO code of the default language.
	 */
	public function getDefaultCode(): ?string
	{
		return $this->getDefault()?->code;
	}

	/**
	 * Check whether any languages have been configured.
	 */
	public function isEmpty(): bool
	{
		$count = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table}");

		return $count === 0;
	}
}
