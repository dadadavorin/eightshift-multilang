<?php

declare(strict_types=1);

namespace EightshiftMultilang\Admin;

use EightshiftMultilang\Languages\LanguageRepository;

/**
 * Adds a language switcher dropdown to the WordPress admin toolbar.
 *
 * The selected language is persisted per-user in user meta so it survives
 * page navigation within the admin. When no language has been chosen yet,
 * the default site language is used.
 *
 * When the user picks a language, the admin toolbar link includes a nonce
 * and the current page URL as the redirect target, so the switch is seamless.
 *
 * The PostListManager reads getCurrentAdminLanguage() to auto-filter post
 * lists to the active admin language without requiring the user to use the
 * per-list filter dropdown on every page load.
 */
final class AdminLanguageSwitcher
{
	/** User meta key storing the admin language preference. */
	public const USER_META_KEY = 'esml_admin_language';

	/** Nonce action for language switch requests. */
	private const NONCE_ACTION = 'esml_admin_lang_switch';

	/** GET parameter that triggers a language switch. */
	private const SWITCH_PARAM = 'esml_set_admin_lang';

	public function __construct(
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		add_action('admin_bar_menu', [$this, 'addAdminBarMenu'], 999);
		add_action('admin_init', [$this, 'handleLanguageSwitch']);
	}

	/**
	 * Inject the language switcher node into the admin bar.
	 * Only shown in the admin and when at least one active language exists.
	 *
	 * @param \WP_Admin_Bar $adminBar The global admin bar instance.
	 */
	public function addAdminBarMenu(\WP_Admin_Bar $adminBar): void
	{
		if (! is_admin()) {
			return;
		}

		$languages = $this->languageRepository->getActive();

		if (empty($languages)) {
			return;
		}

		$currentCode = $this->getCurrentAdminLanguage();
		$currentLang = $this->languageRepository->getByCode($currentCode);
		$label       = $currentLang ? $currentLang->name : $currentCode;

		// Root node — shows the active language name.
		$adminBar->add_node([
			'id'    => 'esml-language-switcher',
			'title' => '<span class="ab-icon dashicons dashicons-translation" aria-hidden="true"></span>'
				. '<span class="ab-label">' . esc_html($label) . '</span>',
			'href'  => '#',
			'meta'  => ['class' => 'esml-admin-bar-language'],
		]);

		// One sub-item per active language.
		foreach ($languages as $language) {
			$isCurrent = ($language->code === $currentCode);

			// Build switch URL: current page + switch param + nonce.
			// remove_query_arg strips a previous switch param if the user
			// clicked through twice without a page reload.
			$currentUrl = remove_query_arg([self::SWITCH_PARAM, '_wpnonce']);
			$switchUrl  = wp_nonce_url(
				add_query_arg(self::SWITCH_PARAM, $language->code, $currentUrl),
				self::NONCE_ACTION,
			);

			$title = esc_html($language->name);

			if ($language->isDefault) {
				$title .= ' <span class="esml-admin-bar-default">'
					. esc_html__('(default)', 'eightshift-multilang')
					. '</span>';
			}

			if ($isCurrent) {
				$title = '&#10003;&nbsp;' . $title;
			}

			$adminBar->add_node([
				'parent' => 'esml-language-switcher',
				'id'     => 'esml-lang-' . sanitize_key($language->code),
				'title'  => $title,
				'href'   => $isCurrent ? '#' : $switchUrl,
				'meta'   => $isCurrent ? ['class' => 'esml-lang-current'] : [],
			]);
		}
	}

	/**
	 * Process a language switch request.
	 *
	 * Validates the nonce, confirms the requested code is active, updates
	 * user meta, then redirects back to the originating page (with the
	 * switch params stripped from the URL).
	 */
	public function handleLanguageSwitch(): void
	{
		if (! isset($_GET[self::SWITCH_PARAM])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		check_admin_referer(self::NONCE_ACTION);

		$userId = get_current_user_id();

		if ($userId === 0) {
			return;
		}

		$lang        = sanitize_key($_GET[self::SWITCH_PARAM]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$activeCodes = $this->languageRepository->getActiveCodes();

		if (in_array($lang, $activeCodes, true)) {
			update_user_meta($userId, self::USER_META_KEY, $lang);
		}

		$redirect = remove_query_arg([self::SWITCH_PARAM, '_wpnonce']);
		wp_safe_redirect($redirect);
		exit;
	}

	/**
	 * Return the admin language code for the current user.
	 *
	 * Falls back to the site default if the stored preference is missing or
	 * has since been deactivated.
	 */
	public function getCurrentAdminLanguage(): string
	{
		$userId = get_current_user_id();

		if ($userId !== 0) {
			$stored = get_user_meta($userId, self::USER_META_KEY, true);

			if ($stored !== '') {
				$activeCodes = $this->languageRepository->getActiveCodes();

				if (in_array($stored, $activeCodes, true)) {
					return $stored;
				}
			}
		}

		return $this->languageRepository->getDefaultCode() ?? '';
	}
}
