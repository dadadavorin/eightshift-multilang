<?php

declare(strict_types=1);

namespace EightshiftMultilang\Admin;

use EightshiftMultilang\Languages\LanguageRepository;

/**
 * Outputs admin notices that guide site administrators through initial setup
 * and warn about configuration gaps that would prevent the plugin from working.
 *
 * Notices shown:
 *
 *  1. No API key configured — shown on the plugin settings page and on the
 *     post-list screens for translatable post types. Disappears automatically
 *     once an API key is stored.
 *
 *  2. No languages configured — shown on the plugin settings page only.
 *     Disappears automatically once at least one language exists.
 *
 *  3. Rewrite rules need flushing — shown on all admin pages when the
 *     esml_flush_rewrite_rules flag is set (e.g. after language add/remove),
 *     urging the admin to visit Settings > Permalinks or providing a one-click
 *     flush link.
 *
 * All notices are standard WordPress "notice" divs with the is-dismissible
 * class (client-side JS dismiss). Server-side persistence of dismissal is
 * intentionally omitted: notices disappear on their own once the underlying
 * condition is resolved, so persistent dismissal state is not needed.
 */
final class AdminNotices
{
	public function __construct(
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		add_action('admin_notices', [$this, 'outputNotices']);
	}

	public function outputNotices(): void
	{
		$screen = get_current_screen();

		if (! $screen) {
			return;
		}

		$this->maybeNoticeNoLanguages($screen);
		$this->maybeNoticeNoApiKey($screen);
		$this->maybeNoticeFlushRequired();
	}

	// ---------------------------------------------------------------------------
	// Individual notices
	// ---------------------------------------------------------------------------

	/**
	 * Warn when no languages are configured yet.
	 * Shown only on the plugin's own settings page.
	 */
	private function maybeNoticeNoLanguages(\WP_Screen $screen): void
	{
		if ($screen->id !== 'settings_page_' . SettingsPage::PAGE_SLUG) {
			return;
		}

		if (! $this->languageRepository->isEmpty()) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					/* translators: %s: link to Languages tab */
					__('Eightshift Multilang: No languages configured yet. Open the <strong>Languages</strong> tab to add your first language.', 'eightshift-multilang'),
				)
			),
		);
	}

	/**
	 * Warn when no AI API key is stored.
	 * Shown on the plugin settings page and on translatable post-list screens.
	 */
	private function maybeNoticeNoApiKey(\WP_Screen $screen): void
	{
		$relevantScreens = $this->relevantScreenIds();

		if (! in_array($screen->id, $relevantScreens, true)) {
			return;
		}

		if (get_option('esml_ai_api_key_encrypted', '') !== '') {
			return;
		}

		$settingsUrl = admin_url('options-general.php?page=' . SettingsPage::PAGE_SLUG . '#ai');

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					/* translators: %1$s: opening link tag, %2$s: closing link tag */
					__('Eightshift Multilang: No AI API key configured — AI translation is disabled. %1$sConfigure it now%2$s.', 'eightshift-multilang'),
					'<a href="' . esc_url($settingsUrl) . '">',
					'</a>',
				)
			),
		);
	}

	/**
	 * Inform the admin when rewrite rules need to be flushed.
	 * This should be rare (language add/remove triggers it) and resolves
	 * automatically on the next admin_init, so this notice is a fallback
	 * for cases where the flush didn't happen (e.g. object-cache issue).
	 */
	private function maybeNoticeFlushRequired(): void
	{
		// The option is deleted once flush_rewrite_rules() has been called
		// (see Main::maybeFlushRewriteRules). If it still exists here, the
		// flush is still pending.
		if (! get_option('esml_flush_rewrite_rules')) {
			return;
		}

		$flushUrl = admin_url('options-permalink.php');

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			wp_kses_post(
				sprintf(
					/* translators: %1$s: opening link tag, %2$s: closing link tag */
					__('Eightshift Multilang: Language configuration changed. Please %1$svisit the Permalinks settings%2$s to flush rewrite rules.', 'eightshift-multilang'),
					'<a href="' . esc_url($flushUrl) . '">',
					'</a>',
				)
			),
		);
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Return screen IDs where the API-key notice is relevant.
	 *
	 * @return string[]
	 */
	private function relevantScreenIds(): array
	{
		$ids = ['settings_page_' . SettingsPage::PAGE_SLUG];

		$rawTypes = get_option('esml_translatable_post_types', '["post","page"]');
		$types    = json_decode((string) $rawTypes, true);

		if (is_array($types)) {
			foreach ($types as $type) {
				$ids[] = 'edit-' . $type;
			}
		}

		return $ids;
	}
}
