<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

use EightshiftMultilang\Exceptions\RateLimitException;
use EightshiftMultilang\Exceptions\TranslationException;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Parser\BlockParser;
use EightshiftMultilang\Parser\MarkupRebuilder;
use EightshiftMultilang\Translations\TranslationLinker;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Orchestrates the full AI translation flow for a single post.
 *
 * The 11-step process:
 *  1.  Validate source post, target language, and existing translations.
 *  2.  Parse post_content to extract translatable strings.
 *  3.  Build the AI system prompt.
 *  4.  Translate strings + post slug via the AI provider.
 *  5.  Rebuild markup with translated strings injected.
 *  6.  Extract the translated slug from the AI response.
 *  7.  Create a new draft post with the translated content.
 *  8.  Copy post meta from source to translation.
 *  9.  Link the new post to the source in the translation group.
 * 10.  Increment API usage counter.
 * 11.  Fire 'esml_post_translated' action.
 */
final class TranslationEngine
{
	/** Meta keys excluded from the source→translation copy. */
	private const EXCLUDED_META_KEYS = [
		'_edit_lock',
		'_edit_last',
	];

	public function __construct(
		private readonly BlockParser $blockParser,
		private readonly MarkupRebuilder $markupRebuilder,
		private readonly ProviderInterface $provider,
		private readonly PromptBuilder $promptBuilder,
		private readonly LanguageRepository $languageRepository,
		private readonly TranslationRepository $translationRepository,
		private readonly TranslationLinker $translationLinker,
		private readonly UsageTracker $usageTracker,
	) {
	}

	/**
	 * Translate a post to the given target language.
	 *
	 * @param int    $sourcePostId        WordPress post ID of the source post.
	 * @param string $targetLanguageCode  ISO language code to translate into (e.g. 'de').
	 * @return int                        The newly created draft post ID.
	 * @throws TranslationException       On validation, AI, or post-creation failures.
	 * @throws RateLimitException         When the monthly API limit is exceeded.
	 */
	public function translatePost(int $sourcePostId, string $targetLanguageCode): int
	{
		// -----------------------------------------------------------------------
		// Step 1 — Validate
		// -----------------------------------------------------------------------
		$sourcePost = get_post($sourcePostId);
		if ($sourcePost === null) {
			throw new TranslationException(sprintf('Source post #%d not found.', $sourcePostId));
		}

		$targetLanguage = $this->languageRepository->getByCode($targetLanguageCode);
		if ($targetLanguage === null) {
			throw new TranslationException(
				sprintf('Target language "%s" is not configured.', $targetLanguageCode)
			);
		}

		$existing = $this->translationRepository->getTranslatedPostId($sourcePostId, $targetLanguageCode);
		if ($existing !== null) {
			throw new TranslationException(
				sprintf(
					'A translation for "%s" already exists (post #%d).',
					$targetLanguageCode,
					$existing
				)
			);
		}

		// -----------------------------------------------------------------------
		// Step 2 — Parse
		// -----------------------------------------------------------------------
		$suffixes = $this->getTranslatableSuffixes();
		$parsed = $this->blockParser->parseContent($sourcePost->post_content, $suffixes);

		if (! $parsed->hasTranslatableContent()) {
			throw new TranslationException(
				sprintf('No translatable content found in post #%d.', $sourcePostId)
			);
		}

		// -----------------------------------------------------------------------
		// Step 3 — Build prompt
		// -----------------------------------------------------------------------
		$sourceLanguageCode = $this->translationRepository->getLanguageCode($sourcePostId);
		$sourceLanguage     = $sourceLanguageCode !== null
			? ($this->languageRepository->getByCode($sourceLanguageCode)?->name ?? 'English')
			: 'English';

		$systemPrompt = $this->promptBuilder->build($sourceLanguage, $targetLanguage->name);

		// -----------------------------------------------------------------------
		// Step 4 — Translate via AI (post slug included)
		// -----------------------------------------------------------------------
		$stringMap = $parsed->toStringMap();
		$stringMap['__post_slug'] = $sourcePost->post_name;

		$translations = $this->provider->translate(
			$stringMap,
			$sourceLanguage,
			$targetLanguage->name,
			$systemPrompt,
		);

		// -----------------------------------------------------------------------
		// Step 5 — Rebuild markup
		// -----------------------------------------------------------------------
		$translatedContent = $this->markupRebuilder->rebuild($parsed, $translations);

		// -----------------------------------------------------------------------
		// Step 6 — Extract translated slug
		// -----------------------------------------------------------------------
		$translatedSlug = isset($translations['__post_slug']) && $translations['__post_slug'] !== ''
			? sanitize_title($translations['__post_slug'])
			: $sourcePost->post_name;

		// Remove the slug entry so it is not used during title lookup below.
		unset($translations['__post_slug']);

		// -----------------------------------------------------------------------
		// Step 7 — Create post
		// -----------------------------------------------------------------------
		$translatedTitle = $this->findTranslatedTitle($sourcePost->post_title, $translations, $parsed);

		/**
		 * Filter: esml_translated_post_args
		 * Modify the wp_insert_post arguments before the translated post is created.
		 *
		 * @param array<string, mixed> $args       The post arguments.
		 * @param int                  $sourceId   Source post ID.
		 * @param string               $langCode   Target language code.
		 */
		$postArgs = (array) apply_filters(
			'esml_translated_post_args',
			[
				'post_type'    => $sourcePost->post_type,
				'post_status'  => 'draft',
				'post_title'   => $translatedTitle,
				'post_content' => wp_kses_post($translatedContent),
				'post_name'    => $translatedSlug,
				'post_parent'  => $this->resolveTranslatedParent(
					(int) $sourcePost->post_parent,
					$targetLanguageCode
				),
			],
			$sourcePostId,
			$targetLanguageCode
		);

		$newPostId = wp_insert_post($postArgs, true);

		if (is_wp_error($newPostId)) {
			throw new TranslationException(
				'Failed to create translated post: ' . $newPostId->get_error_message()
			);
		}

		// -----------------------------------------------------------------------
		// Step 8 — Copy post meta
		// -----------------------------------------------------------------------
		$this->copyPostMeta($sourcePostId, $newPostId);

		// -----------------------------------------------------------------------
		// Step 9 — Link translation
		// -----------------------------------------------------------------------
		$this->translationLinker->link(
			$sourcePostId,
			$newPostId,
			$targetLanguageCode,
			$sourceLanguageCode
		);

		// -----------------------------------------------------------------------
		// Step 10 — Increment API usage
		// -----------------------------------------------------------------------
		$this->usageTracker->increment();

		// -----------------------------------------------------------------------
		// Step 11 — Fire action
		// -----------------------------------------------------------------------

		/**
		 * Action: esml_post_translated
		 * Fired after a translation draft has been created.
		 *
		 * @param int    $newPostId      The new translated post ID.
		 * @param int    $sourcePostId   The source post ID.
		 * @param string $targetLangCode Target language code.
		 */
		do_action('esml_post_translated', $newPostId, $sourcePostId, $targetLanguageCode);

		return $newPostId;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Get the active translatable suffixes from settings, merged with the filter.
	 *
	 * @return list<string>
	 */
	private function getTranslatableSuffixes(): array
	{
		$stored = json_decode((string) get_option('esml_translatable_suffixes', '["Content"]'), true);
		$suffixes = is_array($stored) ? $stored : ['Content'];

		/**
		 * Filter: esml_translatable_attribute_suffixes
		 * Extend or override the list of translatable attribute suffixes.
		 *
		 * @param list<string> $suffixes Current suffix list.
		 */
		return (array) apply_filters('esml_translatable_attribute_suffixes', $suffixes);
	}

	/**
	 * Find the best translated title by looking for a key whose original value
	 * matches the source post title, then returning its translation.
	 * Falls back to the source title if no match is found.
	 *
	 * @param array<string, string> $translations Key→translated map.
	 */
	private function findTranslatedTitle(
		string $sourceTitle,
		array $translations,
		\EightshiftMultilang\Parser\ParsedContent $parsed,
	): string {
		foreach ($parsed->translatableStrings as $key => $ts) {
			if ($ts->value === $sourceTitle && isset($translations[$key])) {
				return $translations[$key];
			}
		}

		return $sourceTitle;
	}

	/**
	 * Find the translated version of a parent post for hierarchical post types.
	 * Returns 0 if the parent has no translation in the target language.
	 */
	private function resolveTranslatedParent(int $parentId, string $targetLanguageCode): int
	{
		if ($parentId === 0) {
			return 0;
		}

		return $this->translationRepository->getTranslatedPostId($parentId, $targetLanguageCode) ?? 0;
	}

	/**
	 * Copy all post meta from source to translated post.
	 *
	 * Featured image (_thumbnail_id) is shared across translations by default.
	 * Excluded keys: _edit_lock, _edit_last (WP internals that should not be copied).
	 * Developers can control which keys are copied via the esml_copy_post_meta_keys filter.
	 */
	private function copyPostMeta(int $sourcePostId, int $newPostId): void
	{
		$allMeta = get_post_meta($sourcePostId);

		if (empty($allMeta)) {
			return;
		}

		/**
		 * Filter: esml_copy_post_meta_keys
		 * Control which meta keys are copied from the source post to the translation.
		 * Return an empty array to copy nothing; return the full list to copy everything.
		 *
		 * @param list<string> $keys      All meta keys found on the source post.
		 * @param int          $sourceId  The source post ID.
		 */
		$allKeys  = array_keys($allMeta);
		$copyKeys = (array) apply_filters('esml_copy_post_meta_keys', $allKeys, $sourcePostId);

		$excludeKeys = self::EXCLUDED_META_KEYS;

		foreach ($copyKeys as $key) {
			if (in_array($key, $excludeKeys, true)) {
				continue;
			}

			if (! isset($allMeta[$key])) {
				continue;
			}

			// get_post_meta returns an array of values per key; copy each.
			foreach ($allMeta[$key] as $value) {
				$unserialized = maybe_unserialize($value);
				add_post_meta($newPostId, $key, $unserialized);
			}
		}
	}
}
