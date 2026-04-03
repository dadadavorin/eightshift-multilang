<?php

declare(strict_types=1);

namespace EightshiftMultilang\Translations;

use EightshiftMultilang\Cache\CacheManager;

/**
 * Read-only queries against the translations table, with object-cache integration.
 */
final class TranslationRepository
{
	/** @var string Full table name including DB prefix. */
	private string $table;

	public function __construct(
		private readonly \wpdb $db,
		private readonly CacheManager $cache,
	) {
		$this->table = $this->db->prefix . 'es_multilang_translations';
	}

	/**
	 * Get the translation group UUID for a given post.
	 * Returns null if the post is not linked to any group.
	 *
	 * @param int $postId WordPress post ID.
	 */
	public function getGroupId(int $postId): ?string
	{
		$cacheKey = $this->cache->keyPostLanguage($postId);
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			/** @var array{group_id: string|null, lang: string|null} $cached */
			return $cached['group_id'];
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT translation_group, language_code FROM {$this->table} WHERE post_id = %d",
				$postId
			),
			\ARRAY_A
		);

		$payload = [
			'group_id' => $row ? (string) $row['translation_group'] : null,
			'lang'     => $row ? (string) $row['language_code'] : null,
		];

		$this->cache->set($cacheKey, $payload);

		return $payload['group_id'];
	}

	/**
	 * Get the language code for a given post.
	 * Returns null if the post has no language assigned.
	 *
	 * @param int $postId WordPress post ID.
	 */
	public function getLanguageCode(int $postId): ?string
	{
		$cacheKey = $this->cache->keyPostLanguage($postId);
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			/** @var array{group_id: string|null, lang: string|null} $cached */
			return $cached['lang'];
		}

		// Trigger getGroupId which populates the same cache key.
		$this->getGroupId($postId);

		/** @var array{group_id: string|null, lang: string|null}|null $refreshed */
		$refreshed = $this->cache->get($cacheKey);

		return $refreshed['lang'] ?? null;
	}

	/**
	 * Get all post IDs that belong to a translation group.
	 *
	 * @param string $groupId Translation group UUID.
	 * @return list<int>
	 */
	public function getPostIdsByGroup(string $groupId): array
	{
		$cacheKey = $this->cache->keyGroup($groupId);
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached; // @phpstan-ignore-line
		}

		$rows = $this->db->get_col(
			$this->db->prepare(
				"SELECT post_id FROM {$this->table} WHERE translation_group = %s",
				$groupId
			)
		);

		$postIds = array_map('intval', $rows ?? []);
		$this->cache->set($cacheKey, $postIds);

		return $postIds;
	}

	/**
	 * Get all Translation records for a given group.
	 *
	 * @param string $groupId Translation group UUID.
	 * @return list<Translation>
	 */
	public function getByGroup(string $groupId): array
	{
		$cacheKey = $this->cache->keyTranslations(0) . '_group_' . $groupId;
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached; // @phpstan-ignore-line
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE translation_group = %s ORDER BY id ASC",
				$groupId
			),
			\ARRAY_A
		);

		$translations = array_map(Translation::fromRow(...), $rows ?? []);
		$this->cache->set($cacheKey, $translations);

		return $translations;
	}

	/**
	 * Get all Translation records for the group a post belongs to.
	 * Returns an empty array if the post is not linked.
	 *
	 * @param int $postId WordPress post ID.
	 * @return list<Translation>
	 */
	public function getByPost(int $postId): array
	{
		$cacheKey = $this->cache->keyTranslations($postId);
		$cached = $this->cache->get($cacheKey);
		if ($cached !== null) {
			return $cached; // @phpstan-ignore-line
		}

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT t.* FROM {$this->table} t
                 INNER JOIN {$this->table} pivot ON pivot.translation_group = t.translation_group
                 WHERE pivot.post_id = %d
                 ORDER BY t.id ASC",
				$postId
			),
			\ARRAY_A
		);

		$translations = array_map(Translation::fromRow(...), $rows ?? []);
		$this->cache->set($cacheKey, $translations);

		return $translations;
	}

	/**
	 * Get the post ID for a specific language translation of a given post.
	 * Returns null if no translation exists for that language.
	 *
	 * @param int    $sourcePostId The post whose group we search.
	 * @param string $languageCode Target language code.
	 */
	public function getTranslatedPostId(int $sourcePostId, string $languageCode): ?int
	{
		foreach ($this->getByPost($sourcePostId) as $translation) {
			if ($translation->languageCode === $languageCode) {
				return $translation->postId;
			}
		}

		return null;
	}

	/**
	 * Get the Translation record for a specific post.
	 *
	 * @param int $postId WordPress post ID.
	 */
	public function getForPost(int $postId): ?Translation
	{
		foreach ($this->getByPost($postId) as $translation) {
			if ($translation->postId === $postId) {
				return $translation;
			}
		}

		return null;
	}
}
