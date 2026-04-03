<?php

declare(strict_types=1);

namespace EightshiftMultilang\Translations;

use EightshiftMultilang\Exceptions\TranslationException;

/**
 * High-level orchestration for linking a newly translated post to the same
 * translation group as its source.
 *
 * Handles the "first translation" case by creating a new group and enrolling
 * the source post (using the site's default language as a fallback).
 */
final class TranslationLinker
{
	public function __construct(
		private readonly TranslationManager $manager,
		private readonly TranslationRepository $repository,
	) {
	}

	/**
	 * Link a translated post to the same group as the source post.
	 *
	 * If the source post is not yet in any group, a new group is created and the
	 * source post is enrolled first (marked as is_source = true), using the
	 * provided source language code (or falling back to the default language).
	 *
	 * @param int    $sourcePostId        The original source post ID.
	 * @param int    $translatedPostId    The newly created translated post ID.
	 * @param string $targetLanguageCode  The target language code (e.g. 'de').
	 * @param string|null $sourceLanguageCode  The source language code. Defaults to the
	 *                                         language already assigned to the source post,
	 *                                         or the default language if unassigned.
	 * @throws TranslationException If the translated post is already linked.
	 */
	public function link(
		int $sourcePostId,
		int $translatedPostId,
		string $targetLanguageCode,
		?string $sourceLanguageCode = null,
	): void {
		$groupId = $this->repository->getGroupId($sourcePostId);

		if ($groupId === null) {
			// Source post has never been translated before. Create a new group.
			$groupId = $this->manager->createGroup();
			$sourceLang = $sourceLanguageCode
				?? $this->repository->getLanguageCode($sourcePostId)
				?? $this->getDefaultLanguageCode();

			$this->manager->linkPost($sourcePostId, $groupId, $sourceLang, isSource: true);
		}

		$this->manager->linkPost($translatedPostId, $groupId, $targetLanguageCode, isSource: false);
	}

	/**
	 * Unlink a post from its translation group.
	 *
	 * @param int $postId Post to unlink.
	 * @throws TranslationException If the post is not linked.
	 */
	public function unlink(int $postId): void
	{
		$this->manager->unlinkPost($postId);
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Look up the default language code from wp_options.
	 * Used as a last-resort fallback when the source post has no language assigned.
	 */
	private function getDefaultLanguageCode(): string
	{
		$option = get_option('esml_default_language_code', 'en');

		return (string) $option;
	}
}
