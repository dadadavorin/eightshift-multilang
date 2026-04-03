<?php

declare(strict_types=1);

namespace EightshiftMultilang\Translations;

/**
 * Detects whether a translated post is out of sync with its source.
 *
 * A translation is considered "out of sync" when the source post's
 * post_modified timestamp is newer than the updated_at timestamp stored
 * in the translations table for the translated post.
 */
final class SyncDetector
{
	public function __construct(
		private readonly TranslationRepository $repository,
	) {
	}

	/**
	 * Check whether a translated post is out of sync with its source.
	 *
	 * @param int $translatedPostId The post ID of the translation (not the source).
	 * @return bool True if the source has been updated since the last translation sync.
	 */
	public function isOutOfSync(int $translatedPostId): bool
	{
		$translation = $this->repository->getForPost($translatedPostId);

		if ($translation === null || $translation->isSource) {
			// Post is not linked or is the source itself — never out of sync.
			return false;
		}

		$sourcePostId = $this->findSourcePostId($translation->translationGroup);

		if ($sourcePostId === null) {
			return false;
		}

		$sourcePost = get_post($sourcePostId);

		if ($sourcePost === null) {
			return false;
		}

		// Compare source post_modified (UTC) to the translation's updated_at (UTC).
		$sourceModified = new \DateTimeImmutable($sourcePost->post_modified_gmt . ' UTC');

		return $sourceModified > $translation->updatedAt;
	}

	/**
	 * Get the out-of-sync status for all translations in a group.
	 *
	 * @param string $groupId Translation group UUID.
	 * @return array<int, bool> Map of post_id → out_of_sync.
	 */
	public function getGroupSyncStatus(string $groupId): array
	{
		$translations = $this->repository->getByGroup($groupId);
		$result = [];

		$sourcePostId = null;
		$sourceModified = null;

		foreach ($translations as $translation) {
			if ($translation->isSource) {
				$sourcePostId = $translation->postId;
				break;
			}
		}

		if ($sourcePostId !== null) {
			$sourcePost = get_post($sourcePostId);
			if ($sourcePost !== null) {
				$sourceModified = new \DateTimeImmutable($sourcePost->post_modified_gmt . ' UTC');
			}
		}

		foreach ($translations as $translation) {
			if ($translation->isSource || $sourceModified === null) {
				$result[$translation->postId] = false;
				continue;
			}

			$result[$translation->postId] = $sourceModified > $translation->updatedAt;
		}

		return $result;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Find the source post ID within a translation group.
	 *
	 * @param string $groupId Translation group UUID.
	 */
	private function findSourcePostId(string $groupId): ?int
	{
		foreach ($this->repository->getByGroup($groupId) as $translation) {
			if ($translation->isSource) {
				return $translation->postId;
			}
		}

		return null;
	}
}
