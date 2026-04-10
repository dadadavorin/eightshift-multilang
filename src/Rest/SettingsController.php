<?php

declare(strict_types=1);

namespace EightshiftMultilang\Rest;

use EightshiftMultilang\AI\ProviderRegistry;
use EightshiftMultilang\AI\UsageTracker;
use EightshiftMultilang\Helpers\EncryptionHelper;

/**
 * REST controller for plugin settings, provider metadata, and AI usage.
 *
 * All endpoints require manage_options.
 *
 * Routes (namespace: eightshift-multilang/v1):
 *   GET  /settings                    — read all plugin settings
 *   POST /settings                    — update settings (encrypts per-provider API keys)
 *   GET  /settings/providers          — list registered providers with model options
 *   POST /settings/validate-connection — test the active AI provider
 *   GET  /usage                       — current AI call count & limit
 */
final class SettingsController extends RestController
{
	/**
	 * Settings keys exposed to the REST API.
	 * Maps REST field name → WP option key.
	 *
	 * @var array<string, string>
	 */
	private const SETTINGS_MAP = [
		'url_mode'                => 'esml_url_mode',
		'translatable_post_types' => 'esml_translatable_post_types',
		'translatable_suffixes'   => 'esml_translatable_suffixes',
		'ai_provider'             => 'esml_ai_provider',
		'ai_custom_prompt'        => 'esml_ai_custom_prompt',
		'ai_monthly_limit'        => 'esml_ai_monthly_limit',
		// Phase 2: per-provider model selection.
		'ai_model_claude'         => 'esml_ai_model_claude',
		'ai_model_gemini'         => 'esml_ai_model_gemini',
		'ai_model_openai'         => 'esml_ai_model_openai',
		// Phase 2: custom provider settings.
		'custom_endpoint'         => 'esml_ai_custom_endpoint',
		'custom_model'            => 'esml_ai_custom_model',
		'custom_auth_header_key'  => 'esml_ai_custom_auth_header_key',
	];

	/**
	 * Maps provider identifier → encrypted key option name.
	 * Used for reading key presence and writing new keys.
	 *
	 * @var array<string, string>
	 */
	private const PROVIDER_KEY_OPTIONS = [
		'claude' => 'esml_ai_key_claude_encrypted',
		'gemini' => 'esml_ai_key_gemini_encrypted',
		'openai' => 'esml_ai_key_openai_encrypted',
		'custom' => 'esml_ai_key_custom_encrypted',
	];

	public function __construct(
		private readonly UsageTracker $usageTracker,
		private readonly ProviderRegistry $providerRegistry,
	) {
	}

	public function register(): void
	{
		add_action('rest_api_init', function (): void {
			register_rest_route(self::REST_NAMESPACE, '/settings', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [$this, 'index'],
					'permission_callback' => [$this, 'permissionManageOptions'],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'update'],
					'permission_callback' => [$this, 'permissionManageOptions'],
				],
			]);

			register_rest_route(self::REST_NAMESPACE, '/settings/providers', [
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [$this, 'providers'],
				'permission_callback' => [$this, 'permissionManageOptions'],
			]);

			register_rest_route(self::REST_NAMESPACE, '/settings/validate-connection', [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'validateConnection'],
				'permission_callback' => [$this, 'permissionManageOptions'],
			]);

			register_rest_route(self::REST_NAMESPACE, '/usage', [
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [$this, 'usage'],
				'permission_callback' => [$this, 'permissionManageOptions'],
			]);
		});
	}

	// ---------------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------------

	/**
	 * GET /settings — return all plugin settings.
	 *
	 * API keys are never returned in plaintext. Instead, `provider_keys` is a
	 * map of provider identifier → boolean indicating whether a key is stored.
	 */
	public function index(\WP_REST_Request $request): \WP_REST_Response
	{
		$settings = [];

		foreach (self::SETTINGS_MAP as $field => $optionKey) {
			$raw = get_option($optionKey, '');

			// JSON-encoded arrays are decoded for the response.
			if (in_array($field, ['translatable_post_types', 'translatable_suffixes'], true)) {
				$decoded          = json_decode((string) $raw, true);
				$settings[$field] = is_array($decoded) ? $decoded : [];
			} else {
				$settings[$field] = $raw;
			}
		}

		// Per-provider key presence (boolean, never plaintext).
		$providerKeys = [];

		foreach (self::PROVIDER_KEY_OPTIONS as $providerId => $optionKey) {
			$providerKeys[$providerId] = (get_option($optionKey, '') !== '');
		}

		$settings['provider_keys'] = $providerKeys;

		return $this->respondOk($settings);
	}

	/**
	 * POST /settings — persist settings.
	 *
	 * Accepts any subset of the settings map.
	 *
	 * Per-provider API keys are accepted as:
	 *   provider_api_keys: { claude: "sk-...", gemini: "AIza..." }
	 * Only non-empty values are stored; omitted providers are untouched.
	 *
	 * To clear a stored key, send:
	 *   clear_api_key: "gemini"
	 */
	public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$params = $request->get_json_params();

		if (! is_array($params)) {
			return $this->respondError('invalid_body', 'Request body must be a JSON object.');
		}

		// Save plain settings fields.
		foreach (self::SETTINGS_MAP as $field => $optionKey) {
			if (! array_key_exists($field, $params)) {
				continue;
			}

			$value = $params[$field];

			// JSON-encode array values before storing.
			if (is_array($value)) {
				$value = wp_json_encode($value);
			}

			update_option($optionKey, sanitize_text_field((string) $value));
		}

		// Per-provider API keys: { claude: "plaintext-key", gemini: "..." }
		if (isset($params['provider_api_keys']) && is_array($params['provider_api_keys'])) {
			foreach ($params['provider_api_keys'] as $providerId => $plainKey) {
				$optionKey = self::PROVIDER_KEY_OPTIONS[$providerId] ?? null;

				if ($optionKey === null || ! is_string($plainKey) || $plainKey === '') {
					continue;
				}

				try {
					update_option($optionKey, EncryptionHelper::encrypt($plainKey), false);
				} catch (\Exception $e) {
					return $this->respondError(
						'encryption_failed',
						sprintf('Failed to encrypt API key for provider "%s".', $providerId),
						500
					);
				}
			}
		}

		// Clear a stored key by provider identifier.
		if (isset($params['clear_api_key']) && is_string($params['clear_api_key'])) {
			$optionKey = self::PROVIDER_KEY_OPTIONS[$params['clear_api_key']] ?? null;

			if ($optionKey !== null) {
				delete_option($optionKey);
			}
		}

		do_action('esml_settings_saved');

		return $this->respondOk(['saved' => true]);
	}

	/**
	 * GET /settings/providers — return registered provider metadata.
	 *
	 * Response shape:
	 *   {
	 *     "claude": { "label": "Claude (Anthropic)", "models": [...] },
	 *     "gemini": { "label": "Google Gemini",      "models": [...] },
	 *     ...
	 *   }
	 */
	public function providers(\WP_REST_Request $request): \WP_REST_Response
	{
		return $this->respondOk($this->providerRegistry->getMeta());
	}

	/**
	 * POST /settings/validate-connection — ping the currently-configured AI provider.
	 *
	 * Uses the provider stored in esml_ai_provider at the time of the request,
	 * so calling this after a provider switch reflects the new choice immediately.
	 */
	public function validateConnection(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$activeIdentifier = (string) get_option('esml_ai_provider', 'claude');
		$keyOption        = self::PROVIDER_KEY_OPTIONS[$activeIdentifier] ?? null;

		// For the custom provider, an empty key is acceptable (unauthenticated endpoints).
		// For all others, a key must be present.
		if ($activeIdentifier !== 'custom' && ($keyOption === null || get_option($keyOption, '') === '')) {
			return $this->respondError(
				'no_api_key',
				sprintf(
					'No API key configured for the "%s" provider.',
					$activeIdentifier
				),
				422
			);
		}

		try {
			$provider = $this->providerRegistry->make($activeIdentifier);
			$status   = $provider->validateConnection();
		} catch (\RuntimeException $e) {
			return $this->respondError('connection_error', $e->getMessage(), 500);
		}

		if (! $status->isConnected) {
			return $this->respondError('connection_failed', $status->message, 502);
		}

		return $this->respondOk([
			'connected' => true,
			'provider'  => $activeIdentifier,
			'model'     => $status->model,
		]);
	}

	/**
	 * GET /usage — AI usage summary for the current month.
	 */
	public function usage(\WP_REST_Request $request): \WP_REST_Response
	{
		return $this->respondOk($this->usageTracker->getSummary());
	}
}
