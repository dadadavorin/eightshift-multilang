<?php

declare(strict_types=1);

namespace EightshiftMultilang\Rest;

/**
 * Abstract base for all plugin REST controllers.
 *
 * Provides:
 * - Shared REST namespace constant
 * - Permission callbacks (manage_options for admin endpoints, edit_posts for
 *   editor-facing endpoints)
 * - Response factory helpers (success + WP_Error)
 *
 * Subclasses implement register() to call register_rest_route() for their
 * specific endpoints.
 */
abstract class RestController
{
	public const REST_NAMESPACE = 'eightshift-multilang/v1';

	abstract public function register(): void;

	// ---------------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------------

	/**
	 * Require manage_options capability (admin-only endpoints).
	 */
	public function permissionManageOptions(): bool
	{
		return current_user_can('manage_options');
	}

	/**
	 * Require edit_posts capability (editor sidebar endpoints).
	 */
	public function permissionEditPosts(): bool
	{
		return current_user_can('edit_posts');
	}

	// ---------------------------------------------------------------------------
	// Response helpers
	// ---------------------------------------------------------------------------

	/**
	 * Build a 200 REST response with a data envelope.
	 *
	 * @param mixed $data
	 */
	protected function respondOk(mixed $data): \WP_REST_Response
	{
		return new \WP_REST_Response(['success' => true, 'data' => $data], 200);
	}

	/**
	 * Build a WP_Error for REST error responses.
	 *
	 * @param string $code    Machine-readable error code, e.g. 'invalid_language'.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 */
	protected function respondError(string $code, string $message, int $status = 400): \WP_Error
	{
		return new \WP_Error(
			$code,
			$message,
			['status' => $status],
		);
	}
}
