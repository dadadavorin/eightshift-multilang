<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI\Providers;

use EightshiftMultilang\AI\ProviderStatus;
use EightshiftMultilang\Helpers\EncryptionHelper;

/**
 * Adapter for any OpenAI-compatible API endpoint.
 *
 * Designed for self-hosted models (Ollama, LM Studio), Azure OpenAI,
 * or any third-party API that speaks the OpenAI chat-completions format.
 *
 * Extends OpenAIProvider and overrides the three points that differ:
 *   - getEndpointUrl()     → reads esml_ai_custom_endpoint
 *   - buildAuthHeaders()   → uses a configurable header name + encrypted value
 *   - getDecryptedApiKey() → reads esml_ai_key_custom_encrypted
 *   - getModel()           → reads esml_ai_custom_model
 *   - getIdentifier()      → returns 'custom'
 *
 * The auth header value stored in esml_ai_key_custom_encrypted is whatever
 * the target API expects (e.g. "Bearer sk-...", a raw key, an empty string
 * for unauthenticated local endpoints, etc.).
 */
final class CustomProvider extends OpenAIProvider
{
	/**
	 * {@inheritdoc}
	 */
	public function getIdentifier(): string
	{
		return 'custom';
	}

	/**
	 * {@inheritdoc}
	 */
	public function validateConnection(): ProviderStatus
	{
		$endpoint = $this->getEndpointUrl();

		if ($endpoint === '') {
			return ProviderStatus::error(
				__('Custom provider endpoint URL is not configured.', 'eightshift-multilang')
			);
		}

		return parent::validateConnection();
	}

	// ---------------------------------------------------------------------------
	// Overrides
	// ---------------------------------------------------------------------------

	/**
	 * Return the configurable endpoint URL.
	 * Defaults to the OpenAI API URL so the field can be left blank for
	 * OpenAI-compatible proxies that share the same domain.
	 */
	protected function getEndpointUrl(): string
	{
		$endpoint = trim((string) get_option('esml_ai_custom_endpoint', ''));

		return $endpoint !== '' ? $endpoint : static::API_URL;
	}

	/**
	 * Build auth headers using the configurable header name.
	 * Supports APIs that expect 'Authorization', 'x-api-key', 'api-key', etc.
	 * If the decrypted value is empty (unauthenticated endpoint), only
	 * Content-Type is sent.
	 *
	 * @return array<string, string>
	 */
	protected function buildAuthHeaders(string $apiKey): array
	{
		$headers = ['Content-Type' => 'application/json'];

		if ($apiKey !== '') {
			$headerKey = trim((string) get_option('esml_ai_custom_auth_header_key', 'Authorization'));
			$headers[$headerKey !== '' ? $headerKey : 'Authorization'] = $apiKey;
		}

		return $headers;
	}

	/**
	 * Retrieve and decrypt the custom provider auth value.
	 * Returns an empty string (rather than throwing) when no value is stored,
	 * allowing unauthenticated local endpoints to work without a key.
	 */
	protected function getDecryptedApiKey(): string
	{
		$encrypted = (string) get_option('esml_ai_key_custom_encrypted', '');

		if ($encrypted === '') {
			return ''; // Unauthenticated endpoint — no key required.
		}

		return EncryptionHelper::decrypt($encrypted);
	}

	/**
	 * Return the configured model name.
	 * No fallback constant — the user must supply a model name for custom endpoints.
	 */
	protected function getModel(): string
	{
		return trim((string) get_option('esml_ai_custom_model', ''));
	}
}
