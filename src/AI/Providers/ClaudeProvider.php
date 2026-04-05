<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI\Providers;

use EightshiftMultilang\AI\ProviderInterface;
use EightshiftMultilang\AI\ProviderStatus;
use EightshiftMultilang\AI\ResponseParser;
use EightshiftMultilang\Exceptions\TranslationException;
use EightshiftMultilang\Helpers\EncryptionHelper;

/**
 * Anthropic Claude API adapter.
 *
 * Uses WordPress's wp_remote_post() to avoid shipping a separate HTTP client.
 * The API key is decrypted on each call — never stored in memory beyond the request.
 */
final class ClaudeProvider implements ProviderInterface
{
	private const API_URL         = 'https://api.anthropic.com/v1/messages';
	private const DEFAULT_MODEL   = 'claude-sonnet-4-20250514';
	private const MAX_TOKENS      = 8192;
	private const TIMEOUT_SECONDS = 60;
	private const API_VERSION     = '2023-06-01';

	public function __construct(
		private readonly ResponseParser $responseParser,
	) {
	}

	/**
	 * {@inheritdoc}
	 */
	public function translate(
		array $strings,
		string $sourceLanguage,
		string $targetLanguage,
		string $systemPrompt,
	): array {
		$apiKey = $this->getDecryptedApiKey();

		$body = [
			'model'      => $this->getModel(),
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $systemPrompt,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => wp_json_encode($strings, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT),
				],
			],
		];

		/**
		 * Action: esml_before_ai_request
		 *
		 * @param array<string,string> $strings     The strings being translated.
		 * @param string               $targetLang  Target language name.
		 */
		do_action('esml_before_ai_request', $strings, $targetLanguage);

		$startTime = microtime(true);

		$response = wp_remote_post(self::API_URL, [
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => [
				'Content-Type'    => 'application/json',
				'x-api-key'       => $apiKey,
				'anthropic-version' => self::API_VERSION,
			],
			'body'    => wp_json_encode($body),
		]);

		$duration = microtime(true) - $startTime;

		if (is_wp_error($response)) {
			throw new TranslationException(
				'Claude API request failed: ' . $response->get_error_message()
			);
		}

		$statusCode   = (int) wp_remote_retrieve_response_code($response);
		$responseBody = json_decode(wp_remote_retrieve_body($response), true);

		if ($statusCode !== 200) {
			$errorMessage = $responseBody['error']['message'] ?? 'Unknown error';
			throw new TranslationException(
				sprintf('Claude API returned HTTP %d: %s', $statusCode, $errorMessage)
			);
		}

		if (! is_array($responseBody) || empty($responseBody['content'])) {
			throw new TranslationException('Claude API returned an unexpected response structure.');
		}

		// Extract all text blocks from the response.
		$text = '';
		foreach ($responseBody['content'] as $block) {
			if (is_array($block) && ($block['type'] ?? '') === 'text') {
				$text .= $block['text'];
			}
		}

		$translated = $this->responseParser->parse($text, $strings);

		/**
		 * Action: esml_after_ai_request
		 *
		 * @param array<string,string> $translated  The translated key→value map.
		 * @param string               $targetLang  Target language name.
		 * @param float                $duration    Wall-clock seconds the API call took.
		 */
		do_action('esml_after_ai_request', $translated, $targetLanguage, $duration);

		return $translated;
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateConnection(): ProviderStatus
	{
		try {
			$apiKey = $this->getDecryptedApiKey();
		} catch (\RuntimeException $e) {
			return ProviderStatus::error(__('API key is not configured or cannot be decrypted.', 'eightshift-multilang'));
		}

		$model = $this->getModel();

		// Send a minimal request to confirm the key is valid.
		$response = wp_remote_post(self::API_URL, [
			'timeout' => 10,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $apiKey,
				'anthropic-version' => self::API_VERSION,
			],
			'body'    => wp_json_encode([
				'model'      => $model,
				'max_tokens' => 16,
				'messages'   => [['role' => 'user', 'content' => 'Hi']],
			]),
		]);

		if (is_wp_error($response)) {
			return ProviderStatus::error($response->get_error_message());
		}

		$statusCode = (int) wp_remote_retrieve_response_code($response);

		if ($statusCode === 200) {
			return ProviderStatus::ok($model);
		}

		$body  = json_decode(wp_remote_retrieve_body($response), true);
		$error = is_array($body) ? ($body['error']['message'] ?? 'Unknown error') : 'Unknown error';

		return ProviderStatus::error(sprintf('HTTP %d: %s', $statusCode, $error));
	}

	/**
	 * {@inheritdoc}
	 */
	public function getIdentifier(): string
	{
		return 'claude';
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Retrieve and decrypt the Claude API key.
	 * Reads from the per-provider option (Phase 2+).
	 *
	 * @throws \RuntimeException If the key is missing or cannot be decrypted.
	 */
	private function getDecryptedApiKey(): string
	{
		$encrypted = (string) get_option('esml_ai_key_claude_encrypted', '');

		if ($encrypted === '') {
			throw new \RuntimeException('Claude API key is not configured.');
		}

		return EncryptionHelper::decrypt($encrypted);
	}

	/**
	 * Return the configured model identifier, falling back to the default.
	 */
	private function getModel(): string
	{
		$model = (string) get_option('esml_ai_model_claude', self::DEFAULT_MODEL);

		return $model !== '' ? $model : self::DEFAULT_MODEL;
	}
}
