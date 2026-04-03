<?php

declare(strict_types=1);

namespace EightshiftMultilang\Router;

use EightshiftMultilang\Languages\LanguageRepository;

/**
 * Registers WordPress rewrite rules for subdirectory language prefixes.
 *
 * Two rules are added (at the 'top' priority so they run before default rules):
 *   /{lang}/           → index.php?esml_language={lang}
 *   /{lang}/{path}/    → index.php?esml_language={lang}&esml_path={path}
 *
 * Rules are only registered when at least one non-default active language exists.
 * Flush safety is handled via the esml_flush_rewrite_rules option flag, which is
 * written whenever language configuration changes and consumed on the next admin_init.
 */
final class UrlRouter
{
	public function __construct(
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		add_action('init', [$this, 'registerRewriteRules']);
		add_filter('query_vars', [$this, 'registerQueryVars']);

		// Flag a rewrite flush whenever language configuration changes.
		add_action('esml_languages_updated', [$this, 'scheduleRewriteFlush']);
	}

	/**
	 * Register the language-prefix rewrite rules.
	 * Called on the 'init' action.
	 */
	public function registerRewriteRules(): void
	{
		$langPattern = $this->buildLangPattern();

		if ($langPattern === '') {
			return;
		}

		// /{lang}/path/to/page/
		add_rewrite_rule(
			'^(' . $langPattern . ')/(.+?)/?$',
			'index.php?esml_language=$matches[1]&esml_path=$matches[2]',
			'top',
		);

		// /{lang}/ — language home page.
		add_rewrite_rule(
			'^(' . $langPattern . ')/?$',
			'index.php?esml_language=$matches[1]',
			'top',
		);
	}

	/**
	 * Add plugin query vars so WordPress doesn't strip them.
	 *
	 * @param array<string> $vars
	 * @return array<string>
	 */
	public function registerQueryVars(array $vars): array
	{
		$vars[] = 'esml_language';
		$vars[] = 'esml_path';

		return $vars;
	}

	/**
	 * Schedule a rewrite flush by writing an option flag.
	 * The flag is consumed on the next admin_init (see Main::maybeFlushRewriteRules()).
	 */
	public function scheduleRewriteFlush(): void
	{
		update_option('esml_flush_rewrite_rules', '1', false);
	}

	// ---------------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------------

	/**
	 * Build a regex alternation pattern from active non-default language codes.
	 * Returns '' when there are no non-default languages to route.
	 */
	private function buildLangPattern(): string
	{
		$defaultCode = $this->languageRepository->getDefaultCode();
		$activeCodes = $this->languageRepository->getActiveCodes();

		$nonDefault = array_filter(
			$activeCodes,
			static fn(string $code) => $code !== $defaultCode,
		);

		if ($nonDefault === []) {
			return '';
		}

		return implode('|', array_map('preg_quote', $nonDefault, array_fill(0, count($nonDefault), '/')));
	}
}
