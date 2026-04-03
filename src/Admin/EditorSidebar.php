<?php

declare(strict_types=1);

namespace EightshiftMultilang\Admin;

use EightshiftMultilang\Languages\LanguageRepository;

/**
 * Enqueues the Gutenberg editor sidebar script and stylesheet.
 *
 * The script is loaded on every block editor screen for post types that are
 * configured as translatable. The JS component itself reads the post type from
 * the editor store and renders nothing for non-translatable types, so this
 * PHP-side guard is a lightweight optimisation, not a security boundary.
 *
 * The sidebar bundle is built to build/editor/ by webpack.config.js.
 */
final class EditorSidebar
{
	private const ASSET_HANDLE = 'esml-editor';

	public function __construct(
		private readonly LanguageRepository $languageRepository,
	) {
	}

	public function register(): void
	{
		add_action('enqueue_block_editor_assets', [$this, 'enqueueAssets']);
	}

	/**
	 * Enqueue the editor sidebar script on all block editor screens.
	 */
	public function enqueueAssets(): void
	{
		$assetFile = ESML_PLUGIN_DIR . 'build/editor/index.asset.php';

		if (! file_exists($assetFile)) {
			return;
		}

		/** @var array{dependencies: string[], version: string} $asset */
		$asset = require $assetFile;

		wp_enqueue_script(
			self::ASSET_HANDLE,
			ESML_PLUGIN_URL . 'build/editor/index.js',
			$asset['dependencies'],
			$asset['version'],
			true,
		);

		// Register style only if the file was compiled (it may be absent during
		// development when only JS hot-reload is active).
		$styleFile = ESML_PLUGIN_DIR . 'build/editor/index.css';

		if (file_exists($styleFile)) {
			wp_enqueue_style(
				self::ASSET_HANDLE,
				ESML_PLUGIN_URL . 'build/editor/index.css',
				[],
				$asset['version'],
			);
		}

		wp_localize_script(self::ASSET_HANDLE, 'esmEditor', $this->scriptData());
	}

	// ---------------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------------

	/**
	 * Data passed to the JS bundle via window.esmEditor.
	 *
	 * @return array<string, mixed>
	 */
	private function scriptData(): array
	{
		// Pass the list of translatable post types so the sidebar can decide
		// whether to render for the current post.
		$postTypesRaw = get_option('esml_translatable_post_types', '["post","page"]');
		$postTypes    = json_decode((string) $postTypesRaw, true);

		// Pass active language codes + names for the translation modal picker.
		$activeLanguages = array_map(
			static fn($lang) => [
				'code' => $lang->code,
				'name' => $lang->name,
			],
			$this->languageRepository->getActive(),
		);

		return [
			'restUrl'            => esc_url_raw(rest_url('eightshift-multilang/v1')),
			'nonce'              => wp_create_nonce('wp_rest'),
			'pluginUrl'          => ESML_PLUGIN_URL,
			'translatableTypes'  => is_array($postTypes) ? $postTypes : ['post', 'page'],
			'activeLanguages'    => array_values($activeLanguages),
			'defaultLanguage'    => $this->languageRepository->getDefaultCode() ?? 'en',
			'i18n'               => [
				'sidebarTitle'       => __('Translations', 'eightshift-multilang'),
				'noGroup'            => __('This post is not part of a translation group.', 'eightshift-multilang'),
				'addTranslation'     => __('Add Translation', 'eightshift-multilang'),
				'translate'          => __('Translate with AI', 'eightshift-multilang'),
				'translating'        => __('Translating…', 'eightshift-multilang'),
				'translationDone'    => __('Translation created.', 'eightshift-multilang'),
				'translationError'   => __('Translation failed.', 'eightshift-multilang'),
				'editPost'           => __('Edit', 'eightshift-multilang'),
				'outOfSync'          => __('Out of sync — source post was updated.', 'eightshift-multilang'),
				'inSync'             => __('In sync', 'eightshift-multilang'),
				'selectLanguage'     => __('Select target language', 'eightshift-multilang'),
				'cancel'             => __('Cancel', 'eightshift-multilang'),
				'source'             => __('Source', 'eightshift-multilang'),
			],
		];
	}
}
