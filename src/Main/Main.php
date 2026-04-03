<?php

declare(strict_types=1);

namespace EightshiftMultilang\Main;

use EightshiftMultilang\AI\PromptBuilder;
use EightshiftMultilang\AI\Providers\ClaudeProvider;
use EightshiftMultilang\AI\ResponseParser;
use EightshiftMultilang\AI\TranslationEngine;
use EightshiftMultilang\AI\UsageTracker;
use EightshiftMultilang\Cache\CacheInvalidator;
use EightshiftMultilang\Cache\CacheManager;
use EightshiftMultilang\Languages\LanguageManager;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Parser\AttributeExtractor;
use EightshiftMultilang\Parser\BlockParser;
use EightshiftMultilang\Parser\MarkupRebuilder;
use EightshiftMultilang\Router\FrontendQueryFilter;
use EightshiftMultilang\Router\LanguageDetector;
use EightshiftMultilang\Router\PermalinkFilter;
use EightshiftMultilang\Router\UrlRouter;
use EightshiftMultilang\Translations\SyncDetector;
use EightshiftMultilang\Translations\TranslationLinker;
use EightshiftMultilang\Translations\TranslationManager;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * Plugin service container and entry point.
 *
 * Instantiated on the plugins_loaded action. Builds the full dependency
 * graph and exposes services to the REST controllers, admin pages, and other
 * modules registered in later sprints.
 */
final class Main
{
	private CacheManager $cacheManager;
	private LanguageRepository $languageRepository;
	private LanguageManager $languageManager;
	private TranslationRepository $translationRepository;
	private TranslationManager $translationManager;
	private TranslationLinker $translationLinker;
	private SyncDetector $syncDetector;
	private CacheInvalidator $cacheInvalidator;
	private BlockParser $blockParser;
	private MarkupRebuilder $markupRebuilder;
	private TranslationEngine $translationEngine;
	private UrlRouter $urlRouter;
	private LanguageDetector $languageDetector;
	private PermalinkFilter $permalinkFilter;
	private FrontendQueryFilter $frontendQueryFilter;

	public function __construct()
	{
		global $wpdb;

		// Build the dependency graph bottom-up.
		$this->cacheManager = new CacheManager();

		$this->languageRepository = new LanguageRepository($wpdb, $this->cacheManager);
		$this->languageManager = new LanguageManager($wpdb, $this->languageRepository);

		$this->translationRepository = new TranslationRepository($wpdb, $this->cacheManager);
		$this->translationManager = new TranslationManager($wpdb, $this->translationRepository);
		$this->translationLinker = new TranslationLinker($this->translationManager, $this->translationRepository);
		$this->syncDetector = new SyncDetector($this->translationRepository);

		$this->cacheInvalidator = new CacheInvalidator($this->cacheManager, $this->translationRepository);

		// Sprint 2: Parser + AI.
		$this->blockParser      = new BlockParser(new AttributeExtractor());
		$this->markupRebuilder  = new MarkupRebuilder();
		$this->translationEngine = new TranslationEngine(
			blockParser: $this->blockParser,
			markupRebuilder: $this->markupRebuilder,
			provider: new ClaudeProvider(new ResponseParser()),
			promptBuilder: new PromptBuilder(),
			languageRepository: $this->languageRepository,
			translationRepository: $this->translationRepository,
			translationLinker: $this->translationLinker,
			usageTracker: new UsageTracker(),
		);

		// Sprint 3: URL Routing & Permalink System.
		$this->urlRouter           = new UrlRouter($this->languageRepository);
		$this->languageDetector    = new LanguageDetector($this->languageRepository);
		$this->permalinkFilter     = new PermalinkFilter($this->translationRepository, $this->languageRepository);
		$this->frontendQueryFilter = new FrontendQueryFilter($this->languageRepository);
	}

	/**
	 * Register all plugin services with WordPress.
	 * Called once on plugins_loaded.
	 */
	public function register(): void
	{
		// Load plugin textdomain.
		load_plugin_textdomain(
			'eightshift-multilang',
			false,
			dirname(ESML_PLUGIN_BASENAME) . '/languages'
		);

		// Load template-tag helpers.
		require_once ESML_PLUGIN_DIR . 'src/Helpers/LanguageHelper.php';

		// Register cache invalidation hooks.
		$this->cacheInvalidator->register();

		// Sprint 3: routing, detection, permalink filtering, query scoping.
		$this->urlRouter->register();
		$this->languageDetector->register();
		$this->permalinkFilter->register();
		$this->frontendQueryFilter->register();

		// Run pending DB migrations on each load (safe — versioned, idempotent).
		global $wpdb;
		(new SchemaMigrator($wpdb))->run();

		// Handle activation redirect (first-time setup).
		add_action('admin_init', [$this, 'maybeRedirectToSetup']);

		// Flush rewrite rules if flagged (e.g. after language add/remove).
		add_action('admin_init', [$this, 'maybeFlushRewriteRules']);

		// Clean up translation links when a post is permanently deleted.
		add_action('before_delete_post', function (int $postId): void {
			$this->translationManager->cleanupDeletedPost($postId);
		});
	}

	/**
	 * Redirect to the settings page on first activation.
	 * Runs on admin_init; no-ops on subsequent requests.
	 */
	public function maybeRedirectToSetup(): void
	{
		if (! get_option('esml_activation_redirect')) {
			return;
		}

		delete_option('esml_activation_redirect');

		wp_safe_redirect(admin_url('options-general.php?page=eightshift-multilang'));
		exit;
	}

	/**
	 * Flush rewrite rules if a transient flag is set.
	 * The flag is written whenever language configuration changes.
	 */
	public function maybeFlushRewriteRules(): void
	{
		if (! get_option('esml_flush_rewrite_rules')) {
			return;
		}

		delete_option('esml_flush_rewrite_rules');
		flush_rewrite_rules(false);
	}

	// ---------------------------------------------------------------------------
	// Service accessors — used by REST controllers, admin pages, etc.
	// ---------------------------------------------------------------------------

	public function getCacheManager(): CacheManager
	{
		return $this->cacheManager;
	}

	public function getLanguageRepository(): LanguageRepository
	{
		return $this->languageRepository;
	}

	public function getLanguageManager(): LanguageManager
	{
		return $this->languageManager;
	}

	public function getTranslationRepository(): TranslationRepository
	{
		return $this->translationRepository;
	}

	public function getTranslationManager(): TranslationManager
	{
		return $this->translationManager;
	}

	public function getTranslationLinker(): TranslationLinker
	{
		return $this->translationLinker;
	}

	public function getSyncDetector(): SyncDetector
	{
		return $this->syncDetector;
	}

	public function getBlockParser(): BlockParser
	{
		return $this->blockParser;
	}

	public function getMarkupRebuilder(): MarkupRebuilder
	{
		return $this->markupRebuilder;
	}

	public function getTranslationEngine(): TranslationEngine
	{
		return $this->translationEngine;
	}

	public function getUrlRouter(): UrlRouter
	{
		return $this->urlRouter;
	}

	public function getLanguageDetector(): LanguageDetector
	{
		return $this->languageDetector;
	}

	public function getPermalinkFilter(): PermalinkFilter
	{
		return $this->permalinkFilter;
	}

	public function getFrontendQueryFilter(): FrontendQueryFilter
	{
		return $this->frontendQueryFilter;
	}
}
