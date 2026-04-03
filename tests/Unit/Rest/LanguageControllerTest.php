<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Languages\Language;
use EightshiftMultilang\Languages\LanguageManager;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Rest\LanguageController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Rest\LanguageController
 */
final class LanguageControllerTest extends TestCase
{
	private LanguageController $controller;

	/** @var LanguageRepository&MockObject */
	private LanguageRepository $repo;

	/** @var LanguageManager&MockObject */
	private LanguageManager $manager;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->repo    = $this->createMock(LanguageRepository::class);
		$this->manager = $this->createMock(LanguageManager::class);

		$this->controller = new LanguageController($this->repo, $this->manager);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function makeLanguage(
		int $id,
		string $code,
		string $name,
		bool $isDefault = false,
		bool $isActive = true,
	): Language {
		return new Language(
			id: $id,
			code: $code,
			locale: $code . '_' . strtoupper($code),
			name: $name,
			nativeName: $name,
			flagCode: $code,
			isDefault: $isDefault,
			isActive: $isActive,
			sortOrder: 0,
			dateFormat: null,
		);
	}

	private function makeRequest(array $params = []): \WP_REST_Request
	{
		$request = $this->createMock(\WP_REST_Request::class);
		$request->method('get_param')->willReturnCallback(
			static fn(string $key) => $params[$key] ?? null,
		);
		$request->method('get_json_params')->willReturn($params);

		return $request;
	}

	// ---------------------------------------------------------------------------
	// GET /languages — index
	// ---------------------------------------------------------------------------

	public function testIndexReturnsAllLanguages(): void
	{
		$languages = [
			$this->makeLanguage(1, 'en', 'English', isDefault: true),
			$this->makeLanguage(2, 'de', 'German'),
		];

		$this->repo->method('getAll')->willReturn($languages);

		$response = $this->controller->index($this->makeRequest());

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$data = $response->get_data();
		$this->assertTrue($data['success']);
		$this->assertCount(2, $data['data']);
		$this->assertSame('en', $data['data'][0]['code']);
		$this->assertSame('de', $data['data'][1]['code']);
	}

	public function testIndexReturnsEmptyArrayWhenNoLanguages(): void
	{
		$this->repo->method('getAll')->willReturn([]);

		$response = $this->controller->index($this->makeRequest());

		$this->assertSame([], $response->get_data()['data']);
	}

	// ---------------------------------------------------------------------------
	// POST /languages — store
	// ---------------------------------------------------------------------------

	public function testStoreReturns201OnSuccess(): void
	{
		$this->manager->expects($this->once())
			->method('add')
			->willReturn(3);

		$this->repo->method('getByCode')
			->willReturn($this->makeLanguage(3, 'fr', 'French'));

		$request = $this->makeRequest([
			'code'        => 'fr',
			'locale'      => 'fr_FR',
			'name'        => 'French',
			'native_name' => 'Français',
		]);

		$response = $this->controller->store($request);

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$this->assertSame(201, $response->get_status());
		$this->assertSame('fr', $response->get_data()['data']['code']);
	}

	public function testStoreReturnsErrorOnDuplicateCode(): void
	{
		$this->manager->method('add')
			->willThrowException(new \InvalidArgumentException('Language code already exists.'));

		$request = $this->makeRequest([
			'code'        => 'en',
			'locale'      => 'en_US',
			'name'        => 'English',
			'native_name' => 'English',
		]);

		$result = $this->controller->store($request);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('invalid_language', $result->get_error_code());
	}

	// ---------------------------------------------------------------------------
	// DELETE /languages/{code} — destroy
	// ---------------------------------------------------------------------------

	public function testDestroyCallsRemove(): void
	{
		$this->manager->expects($this->once())->method('remove')->with('de');

		$response = $this->controller->destroy($this->makeRequest(['code' => 'de']));

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$this->assertTrue($response->get_data()['success']);
	}

	public function testDestroyReturns404ForUnknownCode(): void
	{
		$this->manager->method('remove')
			->willThrowException(new \InvalidArgumentException('Language not found.'));

		$result = $this->controller->destroy($this->makeRequest(['code' => 'xx']));

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame(404, $result->get_error_data()['status']);
	}

	public function testDestroyReturns409WhenDefaultLanguage(): void
	{
		$this->manager->method('remove')
			->willThrowException(new \RuntimeException('Cannot remove the default language.'));

		$result = $this->controller->destroy($this->makeRequest(['code' => 'en']));

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame(409, $result->get_error_data()['status']);
	}

	// ---------------------------------------------------------------------------
	// PUT /languages/{code}/default — setDefault
	// ---------------------------------------------------------------------------

	public function testSetDefaultCallsManager(): void
	{
		$this->manager->expects($this->once())->method('setDefault')->with('de');

		$response = $this->controller->setDefault($this->makeRequest(['code' => 'de']));

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$this->assertSame('de', $response->get_data()['data']['default']);
	}

	// ---------------------------------------------------------------------------
	// PUT /languages/{code}/status — updateStatus
	// ---------------------------------------------------------------------------

	public function testActivateCallsManagerActivate(): void
	{
		$this->manager->expects($this->once())->method('activate')->with('fr');
		$this->manager->expects($this->never())->method('deactivate');

		$response = $this->controller->updateStatus($this->makeRequest(['code' => 'fr', 'active' => true]));

		$this->assertTrue($response->get_data()['data']['active']);
	}

	public function testDeactivateCallsManagerDeactivate(): void
	{
		$this->manager->expects($this->once())->method('deactivate')->with('fr');

		$this->controller->updateStatus($this->makeRequest(['code' => 'fr', 'active' => false]));
	}

	// ---------------------------------------------------------------------------
	// POST /languages/reorder
	// ---------------------------------------------------------------------------

	public function testReorderCallsManager(): void
	{
		$order = ['en' => 0, 'de' => 1, 'fr' => 2];
		$this->manager->expects($this->once())->method('reorder')->with($order);

		$response = $this->controller->reorder($this->makeRequest(['order' => $order]));

		$this->assertTrue($response->get_data()['data']['reordered']);
	}

	// ---------------------------------------------------------------------------
	// validateOrderMap
	// ---------------------------------------------------------------------------

	public function testValidateOrderMapAcceptsStringIntMap(): void
	{
		$this->assertTrue($this->controller->validateOrderMap(['en' => 0, 'de' => 1]));
	}

	public function testValidateOrderMapRejectsNonArray(): void
	{
		$this->assertFalse($this->controller->validateOrderMap('not-an-array'));
	}

	public function testValidateOrderMapRejectsNonIntValues(): void
	{
		$this->assertFalse($this->controller->validateOrderMap(['en' => 'first']));
	}

	// ---------------------------------------------------------------------------
	// Permission callbacks (smoke tests)
	// ---------------------------------------------------------------------------

	public function testPermissionManageOptionsReturnsTrueWhenCapable(): void
	{
		Functions\stubs(['current_user_can' => true]);

		$this->assertTrue($this->controller->permissionManageOptions());
	}

	public function testPermissionManageOptionsReturnsFalseWhenNotCapable(): void
	{
		Functions\stubs(['current_user_can' => false]);

		$this->assertFalse($this->controller->permissionManageOptions());
	}
}
