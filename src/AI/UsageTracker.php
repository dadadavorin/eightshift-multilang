<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

use EightshiftMultilang\Exceptions\RateLimitException;

/**
 * Tracks monthly AI API call usage and enforces the optional monthly limit.
 *
 * Usage data is stored in the 'esml_ai_monthly_calls' wp_option as JSON:
 * {"month":"2026-04","count":142}
 *
 * The counter resets automatically at the start of each calendar month.
 */
final class UsageTracker
{
	private const OPTION_KEY = 'esml_ai_monthly_calls';
	private const LIMIT_KEY  = 'esml_ai_monthly_limit';

	/**
	 * Increment the monthly usage counter by 1.
	 * Resets the counter if the stored month differs from the current month.
	 *
	 * @throws RateLimitException If the monthly limit has already been reached.
	 */
	public function increment(): void
	{
		// Check limit before incrementing.
		if ($this->isLimitReached()) {
			throw new RateLimitException(
				sprintf(
					'Monthly AI translation limit of %d has been reached. Usage will reset next month.',
					$this->getLimit()
				)
			);
		}

		$data = $this->getCurrentData();
		$data['count']++;

		update_option(self::OPTION_KEY, wp_json_encode($data), false);
	}

	/**
	 * Get the current month's call count.
	 */
	public function getCount(): int
	{
		return $this->getCurrentData()['count'];
	}

	/**
	 * Get the configured monthly limit. 0 means unlimited.
	 */
	public function getLimit(): int
	{
		return (int) get_option(self::LIMIT_KEY, 0);
	}

	/**
	 * Check whether the monthly limit has been reached.
	 * Always returns false when the limit is 0 (unlimited).
	 */
	public function isLimitReached(): bool
	{
		$limit = $this->getLimit();

		if ($limit === 0) {
			return false;
		}

		return $this->getCount() >= $limit;
	}

	/**
	 * Get usage as a display-friendly array: ['current' => int, 'limit' => int].
	 *
	 * @return array{current: int, limit: int}
	 */
	public function getSummary(): array
	{
		return [
			'current' => $this->getCount(),
			'limit'   => $this->getLimit(),
		];
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Load and return the current usage data, resetting the counter if the
	 * stored month is not the current calendar month.
	 *
	 * @return array{month: string, count: int}
	 */
	private function getCurrentData(): array
	{
		$currentMonth = gmdate('Y-m');
		$raw = get_option(self::OPTION_KEY, '');
		$data = is_string($raw) ? json_decode($raw, true) : null;

		if (
			! is_array($data)
			|| ! isset($data['month'], $data['count'])
			|| $data['month'] !== $currentMonth
		) {
			// First run or new month — reset.
			$data = ['month' => $currentMonth, 'count' => 0];
			update_option(self::OPTION_KEY, wp_json_encode($data), false);
		}

		return $data;
	}
}
