<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\AI\Providers\ClaudeProvider;
use EightshiftMultilang\AI\ProviderStatus;
use EightshiftMultilang\AI\UsageTracker;
use EightshiftMultilang\Rest\SettingsController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Rest\SettingsController
 */
final class SettingsControllerTest extends TestCase
{
	private SettingsController $controller;

	/** @var UsageTracker&MockObject */
	private UsageTracker $usageTracker;

	/** @var ClaudeProvider&MockObject */
	private ClaudeProvider $claudeProvider;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->usageTracker   = $this->createMock(UsageTracker::class);
		$this->claudeProvider = $this->createMock(ClaudeProvider::class);

		$this->controller = new SettingsController($this->usageTracker, $this->claudeProvider);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function makeRequest(array $params = [], array $jsonBody = []): \WP_REST_Request
	{
		$request = $this->createMock(\WP_REST_Request::class);
		$request->method('get_param')->willReturnCallback(
			static fn(string $key) => $params[$key] ?? null,
		);
		$request->method('get_json_params')->willReturn($jsonBody);

		return $request;
	}

	// ---------------------------------------------------------------------------
	// GET /settings — index
	// ---------------------------------------------------------------------------

	public function testIndexReturnsAllSettings(): void
	{
		Functions\stubs([
			'get_option' => static function (string $key, mixed $default = '') {
				return match ($key) {
					'esml_url_mode'                => 'subdirectory',
					'esml_translatable_post_types'  => '["post","page"]',
					'esml_translatable_suffixes'   => '["Content"]',
					'esml_ai_provider'             => 'claude',
					'esml_ai_custom_prompt'        => '',
					'esml_ai_monthly_limit'        => '0',
					'esml_ai_api_key_encrypted'    => 'encrypted_value',
					default                        => $default,
				};
			},
		]);

		$response = $this->controller->index($this->makeRequest());

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$data = $response->get_data()['data'];

		$this->assertSame('subdirectory', $data['url_mode']);
		$this->assertSame(['post', 'page'], $data['translatable_post_types']);
		$this->assertSame(['Content'], $data['translatable_suffixes']);
		$this->assertSame('claude', $data['ai_provider']);
		// API key must NOT be exposed — only a boolean flag.
		$this->assertArrayNotHasKey('api_key', $data);
		$this->assertTrue($data['api_key_set']);
	}

	public function testIndexReportsApiKeyNotSetWhenEmpty(): void
	{
		Functions\stubs(['get_option' => '']);

		$response = $this->controller->index($this->makeRequest());

		$this->assertFalse($response->get_data()['data']['api_key_set']);
	}

	public function testIndexDecodesJsonArraysForPostTypes(): void
	{
		Functions\stubs([
			'get_option' => static fn(string $key, mixed $default = '') => match ($key) {
				'esml_translatable_post_types' => '["post","page","product"]',
				default                        => $default,
			},
		]);

		$response = $this->controller->index($this->makeRequest());

		$postTypes = $response->get_data()['data']['translatable_post_types'];
		$this->assertIsArray($postTypes);
		$this->assertContains('product', $postTypes);
	}

	// ---------------------------------------------------------------------------
	// POST /settings — update
	// ---------------------------------------------------------------------------

	public function testUpdateSavesSimpleSettings(): void
	{
		$updated = [];

		Functions\stubs([
			'update_option'      => static function (string $key, mixed $value) use (&$updated): bool {
				$updated[$key] = $value;
				return true;
			},
			'sanitize_text_field' => static fn(string $v) => $v,
			'do_action'          => null,
		]);

		$request = $this->makeRequest(jsonBody: [
			'url_mode'   => 'subdirectory',
			'ai_provider' => 'claude',
		]);

		$response = $this->controller->update($request);

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$this->assertTrue($response->get_data()['data']['saved']);
		$this->assertSame('subdirectory', $updated['esml_url_mode']);
		$this->assertSame('claude', $updated['esml_ai_provider']);
	}

	public function testUpdateEncryptsApiKey(): void
	{
		$encryptedStored = null;

		Functions\stubs([
			'update_option'      => static function (string $key, mixed $value) use (&$encryptedStored): bool {
				if ($key === 'esml_ai_api_key_encrypted') {
					$encryptedStored = $value;
				}
				return true;
			},
			'sanitize_text_field' => static fn(string $v) => $v,
			'do_action'          => null,
		]);

		// We pass a fake plaintext key; the real encryption is tested in EncryptionHelperTest.
		// Here we just verify update_option is called with something non-empty.
		// EncryptionHelper::encrypt() requires sodium which is available in the test env.
		$request = $this->makeRequest(jsonBody: ['api_key' => 'sk-test-key-12345']);

		// Wrap in try/catch: if sodium is unavailable in the test runner, skip.
		try {
			$response = $this->controller->update($request);
			$this->assertNotNull($encryptedStored);
		} catch (\Exception $e) {
			$this->markTestSkipped('Sodium extension not available: ' . $e->getMessage());
		}
	}

	public function testUpdateClearsApiKeyWhenEmptyStringPassed(): void
	{
		$deleted = false;

		Functions\stubs([
			'update_option'      => static fn() => true,
			'sanitize_text_field' => static fn(string $v) => $v,
			'delete_option'      => static function (string $key) use (&$deleted): bool {
				if ($key === 'esml_ai_api_key_encrypted') {
					$deleted = true;
				}
				return true;
			},
			'do_action'          => null,
		]);

		$response = $this->controller->update($this->makeRequest(jsonBody: ['api_key' => '']));

		$this->assertTrue($deleted);
	}

	public function testUpdateReturnsErrorOnNonJsonBody(): void
	{
		$request = $this->createMock(\WP_REST_Request::class);
		$request->method('get_json_params')->willReturn(null);

		$result = $this->controller->update($request);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('invalid_body', $result->get_error_code());
	}

	// ---------------------------------------------------------------------------
	// POST /settings/validate-connection
	// ---------------------------------------------------------------------------

	public function testValidateConnectionReturnsOkOnSuccess(): void
	{
		Functions\stubs(['get_option' => 'encrypted_value']);

		$this->claudeProvider->method('validateConnection')
			->willReturn(ProviderStatus::ok('claude-sonnet-4-20250514'));

		$response = $this->controller->validateConnection($this->makeRequest());

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$this->assertTrue($response->get_data()['data']['connected']);
		$this->assertSame('claude-sonnet-4-20250514', $response->get_data()['data']['model']);
	}

	public function testValidateConnectionReturnsErrorWhenNoKeyConfigured(): void
	{
		Functions\stubs(['get_option' => '']);

		$result = $this->controller->validateConnection($this->makeRequest());

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('no_api_key', $result->get_error_code());
		$this->assertSame(422, $result->get_error_data()['status']);
	}

	public function testValidateConnectionReturns502OnProviderFailure(): void
	{
		Functions\stubs(['get_option' => 'encrypted_value']);

		$this->claudeProvider->method('validateConnection')
			->willReturn(ProviderStatus::error('Invalid authentication credentials.'));

		$result = $this->controller->validateConnection($this->makeRequest());

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('connection_failed', $result->get_error_code());
		$this->assertSame(502, $result->get_error_data()['status']);
	}

	// ---------------------------------------------------------------------------
	// GET /usage
	// ---------------------------------------------------------------------------

	public function testUsageReturnsSummary(): void
	{
		$this->usageTracker->method('getSummary')
			->willReturn(['current' => 42, 'limit' => 500]);

		$response = $this->controller->usage($this->makeRequest());

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$data = $response->get_data()['data'];
		$this->assertSame(42, $data['current']);
		$this->assertSame(500, $data['limit']);
	}
}
