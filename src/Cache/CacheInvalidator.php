<?php

declare(strict_types=1);

namespace EightshiftMultilang\Cache;

use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Hooks into WordPress and plugin actions to surgically invalidate stale
 * cache entries. Only the minimum affected keys are cleared on each write.
 */
final class CacheInvalidator
{
	public function __construct(
		private readonly CacheManager $cacheManager,
		private readonly TranslationRepository $translationRepository,
	) {
	}

	/**
	 * Register all invalidation hooks with WordPress.
	 */
	public function register(): void
	{
		add_action('save_post', [$this, 'onPostSave']);
		add_action('before_delete_post', [$this, 'onPostDelete']);
		add_action('esml_translation_linked', [$this, 'onTranslationLinked'], 10, 3);
		add_action('esml_translation_unlinked', [$this, 'onTranslationUnlinked'], 10, 2);
		add_action('esml_languages_updated', [$this, 'onLanguagesUpdated']);
		add_action('esml_settings_saved', [$this, 'onSettingsSaved']);
	}

	/**
	 * When a post is saved: clear its own translation cache and the hreflang
	 * cache for every post in the same translation group.
	 *
	 * @param int $postId The saved post ID.
	 */
	public function onPostSave(int $postId): void
	{
		$this->invalidatePostAndGroup($postId);
	}

	/**
	 * When a post is permanently deleted: clear its caches.
	 * The DB row is removed by the TranslationManager cascade.
	 *
	 * @param int $postId The deleted post ID.
	 */
	public function onPostDelete(int $postId): void
	{
		$this->invalidatePostAndGroup($postId);
	}

	/**
	 * When a new translation link is created.
	 *
	 * @param int    $postId  The newly-linked post ID.
	 * @param string $groupId The translation group UUID.
	 * @param string $langCode The language code.
	 */
	public function onTranslationLinked(int $postId, string $groupId, string $langCode): void
	{
		$this->cacheManager->delete($this->cacheManager->keyTranslations($postId));
		$this->cacheManager->delete($this->cacheManager->keyPostLanguage($postId));
		$this->cacheManager->delete($this->cacheManager->keyGroup($groupId));
		$this->invalidateGroupHreflang($groupId);
	}

	/**
	 * When a translation link is removed.
	 *
	 * @param int    $postId  The unlinked post ID.
	 * @param string $groupId The translation group UUID.
	 */
	public function onTranslationUnlinked(int $postId, string $groupId): void
	{
		$this->cacheManager->delete($this->cacheManager->keyTranslations($postId));
		$this->cacheManager->delete($this->cacheManager->keyPostLanguage($postId));
		$this->cacheManager->delete($this->cacheManager->keyGroup($groupId));
		$this->invalidateGroupHreflang($groupId);
	}

	/**
	 * When language configuration changes: flush all language-related caches.
	 */
	public function onLanguagesUpdated(): void
	{
		$this->cacheManager->delete(CacheManager::KEY_LANGUAGES_ACTIVE);
		$this->cacheManager->delete(CacheManager::KEY_LANGUAGE_DEFAULT);
		$this->cacheManager->delete(CacheManager::KEY_LANGUAGES_ALL);
	}

	/**
	 * When plugin settings are saved: clear the suffixes cache.
	 */
	public function onSettingsSaved(): void
	{
		$this->cacheManager->delete(CacheManager::KEY_SUFFIXES);
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Clear the per-post cache and the group-level hreflang cache for every
	 * post that belongs to the same translation group as the given post.
	 */
	private function invalidatePostAndGroup(int $postId): void
	{
		$this->cacheManager->delete($this->cacheManager->keyTranslations($postId));
		$this->cacheManager->delete($this->cacheManager->keyPostLanguage($postId));
		$this->cacheManager->delete($this->cacheManager->keyHreflang($postId));

		$groupId = $this->translationRepository->getGroupId($postId);
		if ($groupId !== null) {
			$this->cacheManager->delete($this->cacheManager->keyGroup($groupId));
			$this->invalidateGroupHreflang($groupId);
		}
	}

	/**
	 * Flush hreflang caches for every post in a translation group.
	 */
	private function invalidateGroupHreflang(string $groupId): void
	{
		$postIds = $this->translationRepository->getPostIdsByGroup($groupId);
		foreach ($postIds as $id) {
			$this->cacheManager->delete($this->cacheManager->keyHreflang($id));
		}
	}
}
