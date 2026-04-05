<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI\Providers;

use EightshiftMultilang\AI\ProviderInterface;
use EightshiftMultilang\AI\ProviderStatus;
use EightshiftMultilang\AI\ResponseParser;
use EightshiftMultilang\Exceptions\TranslationException;
use EightshiftMultilang\Helpers\EncryptionHelper;

/**
 * OpenAI Chat Completions API adapter.
 *
 * Endpoint:  https://api.openai.com/v1/chat/completions
 * Auth:      Authorization: Bearer {key}
 *
 * The PromptBuilder system prompt maps to the `system` role message, which
 * instructs the model on how to translate. The serialised JSON string map goes
 * to the `user` role message.
 *
 * Response path: choices[0].message.content
 * The content is passed through the shared ResponseParser for JSON extraction.
 *
 * CustomProvider extends this adapter for any OpenAI-compatible endpoint.
 */
class OpenAIProvider implements ProviderInterface
{
	protected const API_URL       = 'https://api.openai.com/v1/chat/completions';
	protected const DEFAULT_MODEL = 'gpt-4o';
	protected const MAX_TOKENS    = 8192;
	protected const TIMEOUT       = 60;

	public function __construct(
		protected readonly ResponseParser $responseParser,
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
			'max_tokens' => static::MAX_TOKENS,
			'messages'   => [
				[
					'role'    => 'system',
					'content' => $systemPrompt,
				],
				[
					'role'    => 'user',
					'content' => wp_json_encode(
						$strings,
						\JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT
					),
				],
			],
		];

		/** @see ClaudeProvider::translate() for action docs */
		do_action('esml_before_ai_request', $strings, $targetLanguage);

		$startTime = microtime(true);

		$response = wp_remote_post(
			$this->getEndpointUrl(),
			[
				'timeout' => static::TIMEOUT,
				'headers' => $this->buildAuthHeaders($apiKey),
				'body'    => wp_json_encode($body),
			]
		);

		$duration = microtime(true) - $startTime;

		if (is_wp_error($response)) {
			throw new TranslationException(
				'OpenAI API request failed: ' . $response->get_error_message()
			);
		}

		$statusCode   = (int) wp_remote_retrieve_response_code($response);
		$responseBody = json_decode(wp_remote_retrieve_body($response), true);

		if ($statusCode !== 200) {
			$errorMessage = is_array($responseBody)
				? ($responseBody['error']['message'] ?? 'Unknown error')
				: 'Unknown error';

			throw new TranslationException(
				sprintf('OpenAI API returned HTTP %d: %s', $statusCode, $errorMessage)
			);
		}

		// Extract the generated text from the OpenAI response structure.
		$text = '';

		if (is_array($responseBody)) {
			$text = $responseBody['choices'][0]['message']['content'] ?? '';
		}

		if ($text === '') {
			throw new TranslationException('OpenAI API returned an empty response.');
		}

		$translated = $this->responseParser->parse($text, $strings);

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
			return ProviderStatus::error(
				__('API key is not configured or cannot be decrypted.', 'eightshift-multilang')
			);
		}

		$model = $this->getModel();

		$response = wp_remote_post(
			$this->getEndpointUrl(),
			[
				'timeout' => 10,
				'headers' => $this->buildAuthHeaders($apiKey),
				'body'    => wp_json_encode([
					'model'      => $model,
					'max_tokens' => 16,
					'messages'   => [['role' => 'user', 'content' => 'Hi']],
				]),
			]
		);

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
		return 'openai';
	}

	// ---------------------------------------------------------------------------
	// Protected helpers — overridable by CustomProvider
	// ---------------------------------------------------------------------------

	/**
	 * Return the API endpoint URL.
	 * Overridden by CustomProvider to use a configurable endpoint.
	 */
	protected function getEndpointUrl(): string
	{
		return static::API_URL;
	}

	/**
	 * Build auth headers for the request.
	 * Overridden by CustomProvider to support arbitrary header names.
	 *
	 * @return array<string, string>
	 */
	protected function buildAuthHeaders(string $apiKey): array
	{
		return [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $apiKey,
		];
	}

	/**
	 * Retrieve and decrypt the OpenAI API key.
	 *
	 * @throws \RuntimeException If the key is missing or cannot be decrypted.
	 */
	protected function getDecryptedApiKey(): string
	{
		$encrypted = (string) get_option('esml_ai_key_openai_encrypted', '');

		if ($encrypted === '') {
			throw new \RuntimeException('OpenAI API key is not configured.');
		}

		return EncryptionHelper::decrypt($encrypted);
	}

	/**
	 * Return the configured model identifier, falling back to the default.
	 */
	protected function getModel(): string
	{
		$model = (string) get_option('esml_ai_model_openai', static::DEFAULT_MODEL);

		return $model !== '' ? $model : static::DEFAULT_MODEL;
	}
}
