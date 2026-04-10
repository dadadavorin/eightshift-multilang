<?php

declare(strict_types=1);

namespace EightshiftMultilang\Router;

use EightshiftMultilang\Languages\LanguageRepository;

/**
 * Scopes all public WP_Query instances to the current request language.
 *
 * Strategy:
 * - Default language → LEFT JOIN; posts with no translation record are included
 *   (they are implicitly in the default language).
 * - Non-default language → INNER JOIN; only posts explicitly assigned to that
 *   language are returned.
 *
 * Hooks: pre_get_posts (decision point), posts_join, posts_where.
 *
 * The filter is a no-op on:
 * - Admin requests
 * - Feed queries
 * - Singular post / page requests (WordPress resolves these by post ID after
 *   the UrlRouter redirects the rewritten path)
 * - Queries that already have post__in set (manual curation)
 */
final class FrontendQueryFilter
{
	/** Shared flag set by preGetPosts and read by the join/where closures. */
	private bool $shouldFilter = false;

	/** The language code to filter for in the current query. */
	private string $langCode = '';

	/** Whether the current filter targets the default language. */
	private bool $isDefault = false;

	public function __construct(
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		add_action('pre_get_posts', [$this, 'preGetPosts']);
		add_filter('posts_join', [$this, 'postsJoin'], 10, 2);
		add_filter('posts_where', [$this, 'postsWhere'], 10, 2);
	}

	/**
	 * Decide whether this query needs language scoping.
	 * Sets $shouldFilter so postsJoin / postsWhere know what to do.
	 *
	 * IMPORTANT: $shouldFilter is always reset to false at the top so that
	 * postsJoin / postsWhere are guaranteed to be no-ops if this hook returns
	 * early (admin, feed, etc.). This prevents stale state from a previous
	 * pre_get_posts call leaking into a later query's postsJoin / postsWhere.
	 */
	public function preGetPosts(\WP_Query $query): void
	{
		// Always start clean — postsJoin / postsWhere read this flag.
		$this->shouldFilter = false;
		$this->langCode     = '';
		$this->isDefault    = false;

		// Skip admin, feeds, and queries that specify explicit post IDs.
		if (is_admin() || $query->is_feed() || !empty($query->get('post__in'))) {
			return;
		}

		// Only filter main query and secondary queries on the frontend.
		if (!$query->is_main_query() && !$query->get('esml_language_filter')) {
			return;
		}

		// Resolve esml_path → page_id for language-prefixed singular URLs.
		//
		// The UrlRouter rewrite rule maps  /hr/cesto-postavljana-pitanja/  to
		//   index.php?esml_language=hr&esml_path=cesto-postavljana-pitanja
		// WordPress has no built-in handling for esml_path, so without this
		// block the main query would find zero posts and the_content() would
		// never run, leaving the page body empty.
		//
		// We resolve the slug here (before the DB query) and set page_id so
		// WordPress treats the request as a normal singular page load.  No
		// language JOIN/WHERE is needed after that — the post was already
		// identified unambiguously by ID.
		if ($query->is_main_query()) {
			$esmlPath = $query->get('esml_path');
			if ($esmlPath !== '') {
				$post = get_page_by_path((string) $esmlPath, OBJECT, get_post_types(['public' => true]));
				if ($post instanceof \WP_Post) {
					$query->set('page_id', $post->ID);
					$query->set('post_type', $post->post_type);
					$query->set('esml_path', '');
					// No language-scoping JOIN/WHERE needed for a direct ID lookup.
					return;
				}
			}
		}

		$lang = LanguageDetector::getCurrentLanguage();

		if ($lang === null || $lang === '') {
			return;
		}

		$defaultCode = $this->languageRepository->getDefaultCode();

		$this->shouldFilter = true;
		$this->langCode     = $lang;
		$this->isDefault    = ($lang === $defaultCode);
	}

	/**
	 * Add a JOIN to the translations table.
	 *
	 * @param string    $join  Current JOIN clause.
	 * @param \WP_Query $query Current query.
	 */
	public function postsJoin(string $join, \WP_Query $query): string
	{
		if (!$this->shouldFilter) {
			return $join;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'es_multilang_translations';

		if ($this->isDefault) {
			// LEFT JOIN: include posts with no translation record (default language).
			$join .= " LEFT JOIN {$table} esml_t ON esml_t.post_id = {$wpdb->posts}.ID ";
		} else {
			// INNER JOIN: only include posts explicitly linked to this language.
			$join .= " INNER JOIN {$table} esml_t ON esml_t.post_id = {$wpdb->posts}.ID ";
		}

		return $join;
	}

	/**
	 * Add a WHERE clause restricting posts to the current language.
	 *
	 * @param string    $where Current WHERE clause.
	 * @param \WP_Query $query Current query.
	 */
	public function postsWhere(string $where, \WP_Query $query): string
	{
		if (!$this->shouldFilter) {
			return $where;
		}

		global $wpdb;

		if ($this->isDefault) {
			// Either the post is the default language OR it has no translation record.
			$where .= $wpdb->prepare(
				' AND (esml_t.language_code = %s OR esml_t.language_code IS NULL) ',
				$this->langCode,
			);
		} else {
			$where .= $wpdb->prepare(
				' AND esml_t.language_code = %s ',
				$this->langCode,
			);
		}

		return $where;
	}
}
