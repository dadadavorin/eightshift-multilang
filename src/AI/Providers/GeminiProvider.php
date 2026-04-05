<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI\Providers;

use EightshiftMultilang\AI\ProviderInterface;
use EightshiftMultilang\AI\ProviderStatus;
use EightshiftMultilang\AI\ResponseParser;
use EightshiftMultilang\Exceptions\TranslationException;
use EightshiftMultilang\Helpers\EncryptionHelper;

/**
 * Google Gemini API adapter.
 *
 * Endpoint:  https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Auth:      x-goog-api-key header
 *
 * Key structural difference from Claude/OpenAI: Gemini separates the system
 * instruction from the user turn — the system prompt goes into the top-level
 * `systemInstruction` field, not into the `contents` array. The PromptBuilder
 * system prompt is mapped there directly.
 *
 * Response path: candidates[0].content.parts[0].text
 * The text is passed through the shared ResponseParser for JSON extraction.
 */
final class GeminiProvider implements ProviderInterface
{
	private const API_BASE      = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const DEFAULT_MODEL = 'gemini-2.5-flash';
	private const MAX_TOKENS    = 8192;
	private const TIMEOUT       = 60;

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
		$model  = $this->getModel();

		$body = [
			// System prompt goes into systemInstruction, not contents.
			'systemInstruction' => [
				'parts' => [['text' => $systemPrompt]],
			],
			'contents' => [
				[
					'role'  => 'user',
					'parts' => [
						[
							'text' => wp_json_encode(
								$strings,
								\JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT
							),
						],
					],
				],
			],
			'generationConfig' => [
				'maxOutputTokens' => self::MAX_TOKENS,
				'temperature'     => 0.1, // Low temperature for deterministic translation.
			],
		];

		/** @see ClaudeProvider::translate() for action docs */
		do_action('esml_before_ai_request', $strings, $targetLanguage);

		$startTime = microtime(true);

		$response = wp_remote_post(
			self::API_BASE . $model . ':generateContent',
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $apiKey,
				],
				'body' => wp_json_encode($body),
			]
		);

		$duration = microtime(true) - $startTime;

		if (is_wp_error($response)) {
			throw new TranslationException(
				'Gemini API request failed: ' . $response->get_error_message()
			);
		}

		$statusCode   = (int) wp_remote_retrieve_response_code($response);
		$responseBody = json_decode(wp_remote_retrieve_body($response), true);

		if ($statusCode !== 200) {
			$errorMessage = is_array($responseBody)
				? ($responseBody['error']['message'] ?? 'Unknown error')
				: 'Unknown error';

			throw new TranslationException(
				sprintf('Gemini API returned HTTP %d: %s', $statusCode, $errorMessage)
			);
		}

		// Extract the generated text from the Gemini response structure.
		$text = '';

		if (is_array($responseBody)) {
			$text = $responseBody['candidates'][0]['content']['parts'][0]['text'] ?? '';
		}

		if ($text === '') {
			throw new TranslationException('Gemini API returned an empty response.');
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
			self::API_BASE . $model . ':generateContent',
			[
				'timeout' => 10,
				'headers' => [
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $apiKey,
				],
				'body' => wp_json_encode([
					'contents'         => [
						['role' => 'user', 'parts' => [['text' => 'Hi']]],
					],
					'generationConfig' => ['maxOutputTokens' => 16],
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
		return 'gemini';
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Retrieve and decrypt the Gemini API key.
	 *
	 * @throws \RuntimeException If the key is missing or cannot be decrypted.
	 */
	private function getDecryptedApiKey(): string
	{
		$encrypted = (string) get_option('esml_ai_key_gemini_encrypted', '');

		if ($encrypted === '') {
			throw new \RuntimeException('Gemini API key is not configured.');
		}

		return EncryptionHelper::decrypt($encrypted);
	}

	/**
	 * Return the configured Gemini model identifier, falling back to the default.
	 */
	private function getModel(): string
	{
		$model = (string) get_option('esml_ai_model_gemini', self::DEFAULT_MODEL);

		return $model !== '' ? $model : self::DEFAULT_MODEL;
	}
}
