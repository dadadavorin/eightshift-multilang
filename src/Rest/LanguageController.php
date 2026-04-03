<?php

declare(strict_types=1);

namespace EightshiftMultilang\Rest;

use EightshiftMultilang\Languages\Language;
use EightshiftMultilang\Languages\LanguageManager;
use EightshiftMultilang\Languages\LanguageRepository;

/**
 * REST controller for language management.
 *
 * All endpoints require manage_options.
 *
 * Routes (namespace: eightshift-multilang/v1):
 *   GET    /languages                        — list all languages
 *   POST   /languages                        — add a language
 *   DELETE /languages/(?P<code>[a-z]{2,10}) — remove a language
 *   PUT    /languages/(?P<code>[a-z]{2,10})/default — set as default
 *   PUT    /languages/(?P<code>[a-z]{2,10})/status  — activate / deactivate
 *   POST   /languages/reorder               — reorder (body: order map)
 */
final class LanguageController extends RestController
{
	public function __construct(
		private readonly LanguageRepository $languageRepository,
		private readonly LanguageManager $languageManager,
	) {
	}

	public function register(): void
	{
		add_action('rest_api_init', function (): void {
			register_rest_route(self::REST_NAMESPACE, '/languages', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [$this, 'index'],
					'permission_callback' => [$this, 'permissionManageOptions'],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'store'],
					'permission_callback' => [$this, 'permissionManageOptions'],
					'args'                => $this->storeArgs(),
				],
			]);

			register_rest_route(self::REST_NAMESPACE, '/languages/reorder', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [$this, 'reorder'],
				'permission_callback' => [$this, 'permissionManageOptions'],
				'args'                => [
					'order' => [
						'required'          => true,
						'type'              => 'object',
						'description'       => 'Map of language_code => sort_order (integer).',
						'validate_callback' => [$this, 'validateOrderMap'],
					],
				],
			]);

			register_rest_route(self::REST_NAMESPACE, '/languages/(?P<code>[a-z]{2,10})', [
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [$this, 'destroy'],
				'permission_callback' => [$this, 'permissionManageOptions'],
				'args'                => $this->codeArgs(),
			]);

			register_rest_route(self::REST_NAMESPACE, '/languages/(?P<code>[a-z]{2,10})/default', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [$this, 'setDefault'],
				'permission_callback' => [$this, 'permissionManageOptions'],
				'args'                => $this->codeArgs(),
			]);

			register_rest_route(self::REST_NAMESPACE, '/languages/(?P<code>[a-z]{2,10})/status', [
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [$this, 'updateStatus'],
				'permission_callback' => [$this, 'permissionManageOptions'],
				'args'                => array_merge($this->codeArgs(), [
					'active' => [
						'required' => true,
						'type'     => 'boolean',
					],
				]),
			]);
		});
	}

	// ---------------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------------

	/**
	 * GET /languages — list all languages.
	 */
	public function index(\WP_REST_Request $request): \WP_REST_Response
	{
		$languages = $this->languageRepository->getAll();

		return $this->respondOk(array_map([$this, 'serializeLanguage'], $languages));
	}

	/**
	 * POST /languages — add a language.
	 */
	public function store(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$data = [
			'code'        => $request->get_param('code'),
			'locale'      => $request->get_param('locale'),
			'name'        => $request->get_param('name'),
			'native_name' => $request->get_param('native_name'),
			'flag_code'   => $request->get_param('flag_code') ?? '',
			'date_format' => $request->get_param('date_format'),
		];

		try {
			$id = $this->languageManager->add($data);
		} catch (\InvalidArgumentException $e) {
			return $this->respondError('invalid_language', $e->getMessage());
		} catch (\RuntimeException $e) {
			return $this->respondError('language_error', $e->getMessage(), 500);
		}

		$language = $this->languageRepository->getByCode((string) $data['code']);

		return new \WP_REST_Response(
			['success' => true, 'data' => $this->serializeLanguage($language)],
			201,
		);
	}

	/**
	 * DELETE /languages/{code} — remove a language.
	 */
	public function destroy(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$code = (string) $request->get_param('code');

		try {
			$this->languageManager->remove($code);
		} catch (\InvalidArgumentException $e) {
			return $this->respondError('invalid_language', $e->getMessage(), 404);
		} catch (\RuntimeException $e) {
			return $this->respondError('language_error', $e->getMessage(), 409);
		}

		return $this->respondOk(['code' => $code]);
	}

	/**
	 * PUT /languages/{code}/default — set a language as default.
	 */
	public function setDefault(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$code = (string) $request->get_param('code');

		try {
			$this->languageManager->setDefault($code);
		} catch (\InvalidArgumentException $e) {
			return $this->respondError('invalid_language', $e->getMessage(), 404);
		} catch (\RuntimeException $e) {
			return $this->respondError('language_error', $e->getMessage(), 500);
		}

		return $this->respondOk(['default' => $code]);
	}

	/**
	 * PUT /languages/{code}/status — activate or deactivate.
	 */
	public function updateStatus(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$code   = (string) $request->get_param('code');
		$active = (bool) $request->get_param('active');

		try {
			if ($active) {
				$this->languageManager->activate($code);
			} else {
				$this->languageManager->deactivate($code);
			}
		} catch (\InvalidArgumentException $e) {
			return $this->respondError('invalid_language', $e->getMessage(), 404);
		} catch (\RuntimeException $e) {
			return $this->respondError('language_error', $e->getMessage(), 409);
		}

		return $this->respondOk(['code' => $code, 'active' => $active]);
	}

	/**
	 * POST /languages/reorder — update sort order.
	 */
	public function reorder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		/** @var array<string,int> $order */
		$order = (array) $request->get_param('order');

		try {
			$this->languageManager->reorder($order);
		} catch (\InvalidArgumentException $e) {
			return $this->respondError('invalid_order', $e->getMessage());
		} catch (\RuntimeException $e) {
			return $this->respondError('reorder_error', $e->getMessage(), 500);
		}

		return $this->respondOk(['reordered' => true]);
	}

	// ---------------------------------------------------------------------------
	// Serialization
	// ---------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function serializeLanguage(?Language $language): array
	{
		if ($language === null) {
			return [];
		}

		return [
			'id'          => $language->id,
			'code'        => $language->code,
			'locale'      => $language->locale,
			'name'        => $language->name,
			'native_name' => $language->nativeName,
			'flag_code'   => $language->flagCode,
			'is_default'  => $language->isDefault,
			'is_active'   => $language->isActive,
			'sort_order'  => $language->sortOrder,
			'date_format' => $language->dateFormat,
		];
	}

	// ---------------------------------------------------------------------------
	// Argument schemas
	// ---------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function storeArgs(): array
	{
		return [
			'code' => [
				'required'          => true,
				'type'              => 'string',
				'pattern'           => '^[a-z]{2,10}$',
				'sanitize_callback' => 'sanitize_key',
			],
			'locale' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'name' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'native_name' => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'flag_code' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_format' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function codeArgs(): array
	{
		return [
			'code' => [
				'required'          => true,
				'type'              => 'string',
				'pattern'           => '^[a-z]{2,10}$',
				'sanitize_callback' => 'sanitize_key',
			],
		];
	}

	/**
	 * Validate that the 'order' param is a map of string => int.
	 */
	public function validateOrderMap(mixed $value): bool
	{
		if (! is_array($value)) {
			return false;
		}

		foreach ($value as $code => $order) {
			if (! is_string($code) || ! is_int($order)) {
				return false;
			}
		}

		return true;
	}
}
