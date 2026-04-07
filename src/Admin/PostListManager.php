<?php

declare(strict_types=1);

namespace EightshiftMultilang\Admin;

use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Translations\SyncDetector;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Enhances the WordPress admin post list with language-awareness.
 *
 * Features:
 *  - Adds a "Language" column to post lists for translatable post types,
 *    showing the language name badge and a sync-status indicator.
 *  - Adds a "Filter by language" dropdown above the list (All / per-language
 *    code / "Not translated"). The dropdown emits ?esml_language_filter={code}.
 *  - Applies a JOIN + WHERE to the main admin query when the filter is active,
 *    scoping the list to the selected language (or to unlinked posts).
 *  - Falls back to the admin bar language context (AdminLanguageSwitcher) when
 *    no explicit filter is set, so the list automatically shows the language the
 *    user picked in the admin bar.
 */
final class PostListManager
{
	// No per-instance filter state needed — closures capture values directly.

	public function __construct(
		private readonly TranslationRepository $translationRepository,
		private readonly LanguageRepository $languageRepository,
		private readonly SyncDetector $syncDetector,
		private readonly AdminLanguageSwitcher $adminLanguageSwitcher,
	) {
	}

	public function register(): void
	{
		add_action('admin_init', [$this, 'registerPostTypeHooks']);
	}

	/**
	 * Register per-post-type column hooks and the shared filter/query hooks.
	 * Called on admin_init so get_option() is available but before any output.
	 */
	public function registerPostTypeHooks(): void
	{
		$postTypes = $this->translatablePostTypes();

		foreach ($postTypes as $postType) {
			add_filter("manage_{$postType}_posts_columns", [$this, 'addLanguageColumn']);
			add_action("manage_{$postType}_posts_custom_column", [$this, 'renderLanguageColumn'], 10, 2);
		}

		add_action('restrict_manage_posts', [$this, 'renderLanguageFilterDropdown']);
		add_action('pre_get_posts', [$this, 'applyLanguageFilter']);
	}

	// ---------------------------------------------------------------------------
	// Column
	// ---------------------------------------------------------------------------

	/**
	 * Insert an "esml_language" column after the "title" column.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function addLanguageColumn(array $columns): array
	{
		$result = [];

		foreach ($columns as $key => $label) {
			$result[$key] = $label;

			if ($key === 'title') {
				$result['esml_language'] = __('Language', 'eightshift-multilang');
			}
		}

		return $result;
	}

	/**
	 * Render the language badge (and optional sync indicator) for a given post.
	 */
	public function renderLanguageColumn(string $column, int $postId): void
	{
		if ($column !== 'esml_language') {
			return;
		}

		$langCode = $this->translationRepository->getLanguageCode($postId);

		if ($langCode === null) {
			echo '<span class="esml-badge esml-badge--unlinked" title="'
				. esc_attr__('Not translated', 'eightshift-multilang')
				. '">—</span>';
			return;
		}

		$language = $this->languageRepository->getByCode($langCode);
		$name     = $language ? esc_html($language->name) : esc_html($langCode);
		$class    = $language?->isDefault ? ' esml-badge--default' : '';

		echo '<span class="esml-badge' . esc_attr($class) . '">' . $name . '</span>';

		// Sync indicator for non-source translations.
		$groupId = $this->translationRepository->getGroupId($postId);

		if ($groupId !== null) {
			try {
				$outOfSync = $this->syncDetector->isOutOfSync($postId);

				if ($outOfSync) {
					echo ' <span class="esml-sync-dot esml-sync-dot--stale" title="'
						. esc_attr__('Out of sync — source post was updated', 'eightshift-multilang')
						. '">●</span>';
				}
			} catch (\Exception) {
				// SyncDetector may throw if the post has no source; silently skip.
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Language filter dropdown
	// ---------------------------------------------------------------------------

	/**
	 * Render a "Filter by language" <select> above the post list.
	 *
	 * @param string $postType The current post type.
	 */
	public function renderLanguageFilterDropdown(string $postType): void
	{
		if (! in_array($postType, $this->translatablePostTypes(), true)) {
			return;
		}

		$languages = $this->languageRepository->getActive();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current   = sanitize_key($_GET['esml_language_filter'] ?? '');

		echo '<select name="esml_language_filter" id="esml-language-filter">';

		printf(
			'<option value="">%s</option>',
			esc_html__('All languages', 'eightshift-multilang'),
		);

		printf(
			'<option value="unlinked"%s>%s</option>',
			selected($current, 'unlinked', false),
			esc_html__('Not translated', 'eightshift-multilang'),
		);

		foreach ($languages as $lang) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($lang->code),
				selected($current, $lang->code, false),
				esc_html($lang->name),
			);
		}

		echo '</select>';
	}

	// ---------------------------------------------------------------------------
	// Query filtering
	// ---------------------------------------------------------------------------

	/**
	 * Detect the active language filter and attach a posts_clauses hook.
	 *
	 * Priority order:
	 *  1. Explicit ?esml_language_filter param (dropdown selection, including "All").
	 *  2. Admin bar language context stored in user meta (AdminLanguageSwitcher).
	 *
	 * Called on pre_get_posts.
	 *
	 * We use posts_clauses (not separate posts_join + posts_where) because for the
	 * admin pages hierarchy WordPress applies posts_where before posts_join in some
	 * internal sub-queries, which broke the shared-state approach. posts_clauses
	 * receives all SQL clauses in one callback, avoiding the ordering problem.
	 *
	 * The filter state is captured in a closure so there is no mutable instance
	 * state to worry about. The closure self-removes after firing once.
	 */
	public function applyLanguageFilter(\WP_Query $query): void
	{
		if (! is_admin()) {
			return;
		}

		// Only act on post-list screens (base = 'edit').
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if ($screen?->base !== 'edit') {
			return;
		}

		// Only act on queries for translatable post types.
		$queryPostType    = $query->get('post_type');
		$translatableTypes = $this->translatablePostTypes();

		// post_type may be a string or an array — normalise.
		$queryTypes = is_array($queryPostType) ? $queryPostType : [$queryPostType];

		if (empty(array_intersect($queryTypes, $translatableTypes))) {
			return;
		}

		$defaultCode = $this->languageRepository->getDefaultCode() ?? '';

		// Determine the active filter code.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (array_key_exists('esml_language_filter', $_GET)) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter = sanitize_key($_GET['esml_language_filter']);

			if ($filter === '') {
				return; // "All languages" explicitly selected — show everything.
			}
		} else {
			// Fall back to admin bar language context.
			$filter = $this->adminLanguageSwitcher->getCurrentAdminLanguage();

			if ($filter === '') {
				return;
			}
		}

		$isDefault = ($filter === $defaultCode);

		// Register a self-removing posts_clauses closure that modifies both the
		// JOIN and WHERE in a single atomic callback, capturing all needed values.
		$callback = null;
		$callback = static function (array $clauses) use ($filter, $isDefault, &$callback): array {
			remove_filter('posts_clauses', $callback, 10);

			global $wpdb;
			$table = $wpdb->prefix . 'es_multilang_translations';

			if ($filter === 'unlinked' || $isDefault) {
				$clauses['join'] .= " LEFT JOIN {$table} esml_admin_t ON esml_admin_t.post_id = {$wpdb->posts}.ID ";
			} else {
				$clauses['join'] .= " INNER JOIN {$table} esml_admin_t ON esml_admin_t.post_id = {$wpdb->posts}.ID ";
			}

			if ($filter === 'unlinked') {
				$clauses['where'] .= ' AND esml_admin_t.post_id IS NULL ';
			} elseif ($isDefault) {
				$clauses['where'] .= $wpdb->prepare(
					' AND (esml_admin_t.language_code = %s OR esml_admin_t.language_code IS NULL) ',
					$filter,
				);
			} else {
				$clauses['where'] .= $wpdb->prepare(
					' AND esml_admin_t.language_code = %s ',
					$filter,
				);
			}

			return $clauses;
		};

		add_filter('posts_clauses', $callback, 10, 1);
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * @return string[]
	 */
	private function translatablePostTypes(): array
	{
		$raw   = get_option('esml_translatable_post_types', '["post","page"]');
		$types = json_decode((string) $raw, true);

		return is_array($types) ? $types : ['post', 'page'];
	}
}
