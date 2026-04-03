<?php

declare(strict_types=1);

namespace EightshiftMultilang\Translations;

use EightshiftMultilang\Exceptions\TranslationException;

/**
 * Low-level CRUD operations for translation groups and group membership.
 *
 * Higher-level orchestration (linking source + translated posts together)
 * lives in TranslationLinker. This class manages individual rows.
 */
final class TranslationManager
{
	/** @var string Full table name including DB prefix. */
	private string $table;

	public function __construct(
		private readonly \wpdb $db,
		private readonly TranslationRepository $repository,
	) {
		$this->table = $this->db->prefix . 'es_multilang_translations';
	}

	/**
	 * Generate a new translation group UUID.
	 *
	 * @return string UUID v4.
	 */
	public function createGroup(): string
	{
		return wp_generate_uuid4();
	}

	/**
	 * Link a post to a translation group with a given language code.
	 *
	 * @param int    $postId      WordPress post ID.
	 * @param string $groupId     Translation group UUID.
	 * @param string $languageCode ISO language code (e.g. 'en', 'de').
	 * @param bool   $isSource    Whether this post is the original source.
	 * @throws TranslationException If the post is already linked or insert fails.
	 */
	public function linkPost(int $postId, string $groupId, string $languageCode, bool $isSource = false): void
	{
		$existing = $this->repository->getGroupId($postId);
		if ($existing !== null) {
			throw new TranslationException(
				sprintf('Post #%d is already linked to translation group "%s".', $postId, $existing)
			);
		}

		$result = $this->db->insert(
			$this->table,
			[
				'translation_group' => $groupId,
				'post_id'           => $postId,
				'language_code'     => $languageCode,
				'is_source'         => $isSource ? 1 : 0,
			],
			['%s', '%d', '%s', '%d']
		);

		if ($result === false) {
			throw new TranslationException(
				'Failed to link post to translation group: ' . $this->db->last_error
			);
		}

		do_action('esml_translation_linked', $postId, $groupId, $languageCode);
	}

	/**
	 * Unlink a post from its translation group.
	 * The group itself is not removed — other posts remain linked.
	 *
	 * @param int $postId WordPress post ID.
	 * @throws TranslationException If the post is not linked.
	 */
	public function unlinkPost(int $postId): void
	{
		$groupId = $this->repository->getGroupId($postId);

		if ($groupId === null) {
			throw new TranslationException(
				sprintf('Post #%d is not linked to any translation group.', $postId)
			);
		}

		$this->db->delete($this->table, ['post_id' => $postId], ['%d']);

		do_action('esml_translation_unlinked', $postId, $groupId);
	}

	/**
	 * Mark a post as the source (canonical) within its translation group.
	 * Clears the is_source flag on all other posts in the group first.
	 *
	 * @param int $postId WordPress post ID.
	 * @throws TranslationException If the post is not linked.
	 */
	public function setSource(int $postId): void
	{
		$groupId = $this->repository->getGroupId($postId);

		if ($groupId === null) {
			throw new TranslationException(
				sprintf('Post #%d is not linked to any translation group.', $postId)
			);
		}

		// Clear existing source flag within the group.
		$this->db->update(
			$this->table,
			['is_source' => 0],
			['translation_group' => $groupId],
			['%d'],
			['%s']
		);

		// Set this post as the source.
		$this->db->update(
			$this->table,
			['is_source' => 1],
			['post_id' => $postId],
			['%d'],
			['%d']
		);

		do_action('esml_translation_linked', $postId, $groupId, $this->repository->getLanguageCode($postId) ?? '');
	}

	/**
	 * Touch the updated_at timestamp for a post's translation row.
	 * Call this when a translation is re-synced with its source.
	 *
	 * @param int $postId WordPress post ID.
	 */
	public function touchUpdatedAt(int $postId): void
	{
		$this->db->update(
			$this->table,
			['updated_at' => current_time('mysql', true)],
			['post_id' => $postId],
			['%s'],
			['%d']
		);
	}

	/**
	 * Remove all translation links for posts that have been permanently deleted.
	 * Called from a before_delete_post hook to keep the table clean.
	 *
	 * @param int $postId The deleted post ID.
	 */
	public function cleanupDeletedPost(int $postId): void
	{
		$groupId = $this->repository->getGroupId($postId);
		if ($groupId === null) {
			return;
		}

		$this->db->delete($this->table, ['post_id' => $postId], ['%d']);
		do_action('esml_translation_unlinked', $postId, $groupId);
	}
}
