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
	/** The active filter value for the current request. Null = no filtering. */
	private ?string $activeFilter = null;

	/** Whether the active filter targets the default language (requires LEFT JOIN). */
	private bool $isDefaultFilter = false;

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
	 * Detect the active language filter and attach JOIN + WHERE hooks.
	 *
	 * Priority order:
	 *  1. Explicit ?esml_language_filter param (dropdown selection, including "All").
	 *  2. Admin bar language context stored in user meta (AdminLanguageSwitcher).
	 *
	 * Called on pre_get_posts.
	 */
	public function applyLanguageFilter(\WP_Query $query): void
	{
		if (! is_admin() || ! $query->is_main_query()) {
			return;
		}

		// Only act on post-list screens (base = 'edit').
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if ($screen?->base !== 'edit') {
			return;
		}

		$defaultCode = $this->languageRepository->getDefaultCode() ?? '';

		// If the filter dropdown was submitted, honour it (even if value is empty
		// = "All languages"), and do not apply the admin language fallback.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (array_key_exists('esml_language_filter', $_GET)) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter = sanitize_key($_GET['esml_language_filter']);

			if ($filter === '') {
				return; // "All languages" explicitly selected — show everything.
			}

			$this->activeFilter    = $filter;
			$this->isDefaultFilter = ($filter === $defaultCode);

			add_filter('posts_join',  [$this, 'postsJoinForFilter'],  10, 2);
			add_filter('posts_where', [$this, 'postsWhereForFilter'], 10, 2);

			return;
		}

		// No explicit filter — fall back to admin bar language context.
		$adminLang = $this->adminLanguageSwitcher->getCurrentAdminLanguage();

		if ($adminLang === '') {
			return;
		}

		$this->activeFilter    = $adminLang;
		$this->isDefaultFilter = ($adminLang === $defaultCode);

		add_filter('posts_join',  [$this, 'postsJoinForFilter'],  10, 2);
		add_filter('posts_where', [$this, 'postsWhereForFilter'], 10, 2);
	}

	/**
	 * Add a JOIN to the translations table.
	 *
	 * - "unlinked"        → LEFT JOIN (posts with no translation record)
	 * - default language  → LEFT JOIN (posts in default lang OR unlinked)
	 * - other language    → INNER JOIN (posts explicitly in that language)
	 */
	public function postsJoinForFilter(string $join, \WP_Query $query): string
	{
		// Remove self immediately so we don't pollute subsequent queries.
		remove_filter('posts_join', [$this, 'postsJoinForFilter'], 10);

		if ($this->activeFilter === null) {
			return $join;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'es_multilang_translations';

		if ($this->activeFilter === 'unlinked' || $this->isDefaultFilter) {
			$join .= " LEFT JOIN {$table} esml_admin_t ON esml_admin_t.post_id = {$wpdb->posts}.ID ";
		} else {
			$join .= " INNER JOIN {$table} esml_admin_t ON esml_admin_t.post_id = {$wpdb->posts}.ID ";
		}

		return $join;
	}

	/**
	 * Add a WHERE clause scoping the query to the selected language.
	 *
	 * Default language uses a LEFT JOIN result, so posts with no translation
	 * record (NULL) are included alongside explicitly-linked default posts.
	 */
	public function postsWhereForFilter(string $where, \WP_Query $query): string
	{
		remove_filter('posts_where', [$this, 'postsWhereForFilter'], 10);

		if ($this->activeFilter === null) {
			return $where;
		}

		global $wpdb;

		if ($this->activeFilter === 'unlinked') {
			$where .= ' AND esml_admin_t.post_id IS NULL ';
		} elseif ($this->isDefaultFilter) {
			// Include posts in the default language OR with no translation record.
			$where .= $wpdb->prepare(
				' AND (esml_admin_t.language_code = %s OR esml_admin_t.language_code IS NULL) ',
				$this->activeFilter,
			);
		} else {
			$where .= $wpdb->prepare(' AND esml_admin_t.language_code = %s ', $this->activeFilter);
		}

		$this->activeFilter    = null;
		$this->isDefaultFilter = false;

		return $where;
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
