<?php

declare(strict_types=1);

namespace EightshiftMultilang\Main;

use EightshiftMultilang\AI\PromptBuilder;
use EightshiftMultilang\AI\ProviderFactory;
use EightshiftMultilang\AI\ProviderRegistry;
use EightshiftMultilang\AI\Providers\ClaudeProvider;
use EightshiftMultilang\AI\Providers\CustomProvider;
use EightshiftMultilang\AI\Providers\GeminiProvider;
use EightshiftMultilang\AI\Providers\OpenAIProvider;
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
use EightshiftMultilang\Admin\AdminLanguageSwitcher;
use EightshiftMultilang\Admin\AdminNotices;
use EightshiftMultilang\Admin\EditorSidebar;
use EightshiftMultilang\Admin\PostListManager;
use EightshiftMultilang\Admin\SettingsPage;
use EightshiftMultilang\Block\LanguageSwitcherBlock;
use EightshiftMultilang\Rest\LanguageController;
use EightshiftMultilang\Seo\CanonicalFilter;
use EightshiftMultilang\Seo\HreflangManager;
use EightshiftMultilang\Rest\SettingsController;
use EightshiftMultilang\Rest\TranslationController;
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
	private ProviderRegistry $providerRegistry;
	private UsageTracker $usageTracker;
	private TranslationEngine $translationEngine;
	private UrlRouter $urlRouter;
	private LanguageDetector $languageDetector;
	private PermalinkFilter $permalinkFilter;
	private FrontendQueryFilter $frontendQueryFilter;
	private SettingsPage $settingsPage;
	private EditorSidebar $editorSidebar;
	private LanguageController $languageController;
	private TranslationController $translationController;
	private SettingsController $settingsController;
	private HreflangManager $hreflangManager;
	private CanonicalFilter $canonicalFilter;
	private LanguageSwitcherBlock $languageSwitcherBlock;
	private PostListManager $postListManager;
	private AdminNotices $adminNotices;
	private AdminLanguageSwitcher $adminLanguageSwitcher;

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

		// Phase 2: Provider registry — all adapters registered here.
		$responseParser = new ResponseParser();

		$this->providerRegistry = new ProviderRegistry();
		$this->providerRegistry->register('claude', 'Claude (Anthropic)', fn() => new ClaudeProvider($responseParser), [
			['id' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4'],
			['id' => 'claude-opus-4-5',          'label' => 'Claude Opus 4'],
			['id' => 'claude-haiku-4-5',          'label' => 'Claude Haiku 4'],
		]);
		$this->providerRegistry->register('gemini', 'Google Gemini', fn() => new GeminiProvider($responseParser), [
			['id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash'],
			['id' => 'gemini-2.5-pro',   'label' => 'Gemini 2.5 Pro'],
		]);
		$this->providerRegistry->register('openai', 'OpenAI', fn() => new OpenAIProvider($responseParser), [
			['id' => 'gpt-4o',       'label' => 'GPT-4o'],
			['id' => 'gpt-4o-mini',  'label' => 'GPT-4o mini'],
			['id' => 'gpt-4-turbo',  'label' => 'GPT-4 Turbo'],
		]);
		$this->providerRegistry->register('custom', 'Custom (OpenAI-compatible)', fn() => new CustomProvider($responseParser), []);

		// Allow third-party plugins to register additional providers.
		do_action('esml_register_ai_provider', $this->providerRegistry);

		// Sprint 2: Parser + AI.
		$this->blockParser     = new BlockParser(new AttributeExtractor());
		$this->markupRebuilder = new MarkupRebuilder();
		$this->usageTracker    = new UsageTracker();
		$this->translationEngine = new TranslationEngine(
			blockParser: $this->blockParser,
			markupRebuilder: $this->markupRebuilder,
			provider: ProviderFactory::make($this->providerRegistry),
			promptBuilder: new PromptBuilder(),
			languageRepository: $this->languageRepository,
			translationRepository: $this->translationRepository,
			translationLinker: $this->translationLinker,
			usageTracker: $this->usageTracker,
		);

		// Sprint 3: URL Routing & Permalink System.
		$this->urlRouter           = new UrlRouter($this->languageRepository);
		$this->languageDetector    = new LanguageDetector($this->languageRepository);
		$this->permalinkFilter     = new PermalinkFilter($this->translationRepository, $this->languageRepository);
		$this->frontendQueryFilter = new FrontendQueryFilter($this->languageRepository);

		// Sprint 4: Admin Settings & REST API.
		$this->settingsPage    = new SettingsPage($this->languageRepository);
		$this->editorSidebar   = new EditorSidebar($this->languageRepository);
		$this->languageController    = new LanguageController($this->languageRepository, $this->languageManager);
		$this->translationController = new TranslationController(
			$this->translationRepository,
			$this->translationManager,
			$this->translationEngine,
			$this->syncDetector,
		);
		$this->settingsController = new SettingsController($this->usageTracker, $this->providerRegistry);

		// Sprint 6: SEO, Language Switcher, Post List, Admin Notices.
		$this->hreflangManager       = new HreflangManager($this->translationRepository, $this->languageRepository, $this->cacheManager);
		$this->canonicalFilter       = new CanonicalFilter($this->translationRepository, $this->languageRepository);
		$this->languageSwitcherBlock = new LanguageSwitcherBlock($this->languageRepository, $this->translationRepository);
		$this->adminLanguageSwitcher = new AdminLanguageSwitcher($this->languageRepository);
		$this->postListManager       = new PostListManager($this->translationRepository, $this->languageRepository, $this->syncDetector, $this->adminLanguageSwitcher);
		$this->adminNotices          = new AdminNotices($this->languageRepository);
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

		// Sprint 4 + 5: admin page, editor sidebar, REST controllers.
		$this->settingsPage->register();
		$this->editorSidebar->register();
		$this->languageController->register();
		$this->translationController->register();
		$this->settingsController->register();

		// Sprint 6: SEO, language switcher block, post-list UI, admin notices, admin bar.
		$this->hreflangManager->register();
		$this->canonicalFilter->register();
		$this->languageSwitcherBlock->register();
		$this->adminLanguageSwitcher->register();
		$this->postListManager->register();
		$this->adminNotices->register();

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

		wp_safe_redirect(admin_url('admin.php?page=eightshift-multilang'));
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

	public function getProviderRegistry(): ProviderRegistry
	{
		return $this->providerRegistry;
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

	public function getSettingsPage(): SettingsPage
	{
		return $this->settingsPage;
	}

	public function getEditorSidebar(): EditorSidebar
	{
		return $this->editorSidebar;
	}

	public function getLanguageController(): LanguageController
	{
		return $this->languageController;
	}

	public function getTranslationController(): TranslationController
	{
		return $this->translationController;
	}

	public function getSettingsController(): SettingsController
	{
		return $this->settingsController;
	}

	public function getHreflangManager(): HreflangManager
	{
		return $this->hreflangManager;
	}

	public function getCanonicalFilter(): CanonicalFilter
	{
		return $this->canonicalFilter;
	}

	public function getLanguageSwitcherBlock(): LanguageSwitcherBlock
	{
		return $this->languageSwitcherBlock;
	}

	public function getPostListManager(): PostListManager
	{
		return $this->postListManager;
	}

	public function getAdminNotices(): AdminNotices
	{
		return $this->adminNotices;
	}

	public function getAdminLanguageSwitcher(): AdminLanguageSwitcher
	{
		return $this->adminLanguageSwitcher;
	}
}
