<?php

declare(strict_types=1);

namespace EightshiftMultilang\AI;

/**
 * Holds all registered AI provider adapters.
 *
 * Providers are registered at plugin boot with a label, a factory callable,
 * and an ordered list of available models. The registry is exposed to the
 * REST API so the settings UI can populate provider/model dropdowns without
 * hard-coding anything in JavaScript.
 *
 * Third parties can add custom providers via the 'esml_register_ai_provider'
 * action, which receives this registry instance:
 *
 *   add_action('esml_register_ai_provider', function (ProviderRegistry $r): void {
 *       $r->register('my-provider', 'My AI', fn() => new MyProvider(), [
 *           ['id' => 'my-model-v1', 'label' => 'My Model v1'],
 *       ]);
 *   });
 */
final class ProviderRegistry
{
	/**
	 * @var array<string, array{
	 *   label:   string,
	 *   factory: callable(): ProviderInterface,
	 *   models:  list<array{id: string, label: string}>
	 * }>
	 */
	private array $providers = [];

	/**
	 * Register a provider.
	 *
	 * @param string                              $identifier Unique slug (e.g. 'gemini').
	 * @param string                              $label      Human-readable name for the UI.
	 * @param callable(): ProviderInterface       $factory    No-arg callable returning the adapter.
	 * @param list<array{id: string, label: string}> $models  Ordered list of selectable models.
	 */
	public function register(
		string $identifier,
		string $label,
		callable $factory,
		array $models = [],
	): void {
		$this->providers[$identifier] = [
			'label'   => $label,
			'factory' => $factory,
			'models'  => $models,
		];
	}

	/**
	 * Instantiate the provider with the given identifier.
	 * Falls back to the first registered provider when the identifier is unknown.
	 *
	 * @throws \RuntimeException If no providers have been registered.
	 */
	public function make(string $identifier): ProviderInterface
	{
		if (! isset($this->providers[$identifier])) {
			$first = array_key_first($this->providers);

			if ($first === null) {
				throw new \RuntimeException('No AI providers have been registered.');
			}

			$identifier = $first;
		}

		return ($this->providers[$identifier]['factory'])();
	}

	/**
	 * Return whether the identifier maps to a registered provider.
	 */
	public function has(string $identifier): bool
	{
		return isset($this->providers[$identifier]);
	}

	/**
	 * Return metadata for all registered providers, keyed by identifier.
	 * Used by GET /settings/providers to populate the settings UI.
	 *
	 * @return array<string, array{label: string, models: list<array{id: string, label: string}>}>
	 */
	public function getMeta(): array
	{
		$meta = [];

		foreach ($this->providers as $id => $data) {
			$meta[$id] = [
				'label'  => $data['label'],
				'models' => $data['models'],
			];
		}

		return $meta;
	}

	/**
	 * @return list<string>
	 */
	public function getIdentifiers(): array
	{
		return array_keys($this->providers);
	}
}
