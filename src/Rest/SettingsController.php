<?php

declare(strict_types=1);

namespace EightshiftMultilang\Rest;

use EightshiftMultilang\AI\UsageTracker;
use EightshiftMultilang\AI\Providers\ClaudeProvider;
use EightshiftMultilang\Helpers\EncryptionHelper;

/**
 * REST controller for plugin settings and AI usage.
 *
 * All endpoints require manage_options.
 *
 * Routes (namespace: eightshift-multilang/v1):
 *   GET  /settings                    — read all plugin settings
 *   POST /settings                    — update settings (encrypts API key)
 *   POST /settings/validate-connection — test AI provider connectivity
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
		'url_mode'               => 'esml_url_mode',
		'translatable_post_types' => 'esml_translatable_post_types',
		'translatable_suffixes'  => 'esml_translatable_suffixes',
		'ai_provider'            => 'esml_ai_provider',
		'ai_custom_prompt'       => 'esml_ai_custom_prompt',
		'ai_monthly_limit'       => 'esml_ai_monthly_limit',
	];

	public function __construct(
		private readonly UsageTracker $usageTracker,
		private readonly ClaudeProvider $claudeProvider,
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
	 * GET /settings — return all settings.
	 *
	 * The API key is never returned in plaintext. Instead, a boolean
	 * `api_key_set` indicates whether an encrypted key is stored.
	 */
	public function index(\WP_REST_Request $request): \WP_REST_Response
	{
		$settings = [];

		foreach (self::SETTINGS_MAP as $field => $optionKey) {
			$raw = get_option($optionKey, '');

			// JSON-encoded arrays are decoded for the response.
			if (in_array($field, ['translatable_post_types', 'translatable_suffixes'], true)) {
				$decoded = json_decode((string) $raw, true);
				$settings[$field] = is_array($decoded) ? $decoded : [];
			} else {
				$settings[$field] = $raw;
			}
		}

		// Indicate whether an API key is configured without revealing it.
		$settings['api_key_set'] = (get_option('esml_ai_api_key_encrypted', '') !== '');

		return $this->respondOk($settings);
	}

	/**
	 * POST /settings — persist settings.
	 *
	 * Accepts any subset of the settings map. The API key is accepted as
	 * plaintext in the `api_key` field and stored encrypted.
	 */
	public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$params = $request->get_json_params();

		if (! is_array($params)) {
			return $this->respondError('invalid_body', 'Request body must be a JSON object.');
		}

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

		// API key: accept as plaintext, store encrypted.
		if (isset($params['api_key']) && is_string($params['api_key']) && $params['api_key'] !== '') {
			try {
				$encrypted = EncryptionHelper::encrypt($params['api_key']);
				update_option('esml_ai_api_key_encrypted', $encrypted, false);
			} catch (\Exception $e) {
				return $this->respondError('encryption_failed', 'Failed to encrypt the API key.', 500);
			}
		}

		// If api_key is explicitly set to empty string, clear the stored key.
		if (isset($params['api_key']) && $params['api_key'] === '') {
			delete_option('esml_ai_api_key_encrypted');
		}

		// Fire hook so other systems (e.g. CacheInvalidator) can react.
		do_action('esml_settings_saved');

		return $this->respondOk(['saved' => true]);
	}

	/**
	 * POST /settings/validate-connection — ping the AI provider.
	 */
	public function validateConnection(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		if (get_option('esml_ai_api_key_encrypted', '') === '') {
			return $this->respondError('no_api_key', 'No API key configured.', 422);
		}

		try {
			$status = $this->claudeProvider->validateConnection();
		} catch (\RuntimeException $e) {
			return $this->respondError('connection_error', $e->getMessage(), 500);
		}

		if (! $status->ok) {
			return $this->respondError('connection_failed', $status->message ?? 'Connection failed.', 502);
		}

		return $this->respondOk([
			'connected' => true,
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
