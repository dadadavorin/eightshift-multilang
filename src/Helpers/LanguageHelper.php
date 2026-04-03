<?php

declare(strict_types=1);

namespace EightshiftMultilang\Helpers;

use EightshiftMultilang\Router\LanguageDetector;

/**
 * Template-tag helper functions for use in themes and other plugins.
 *
 * Functions are defined in the global namespace so they are callable from
 * any template file without namespace imports.
 */

if (! function_exists('esml_get_current_language')) {
	/**
	 * Return the language code for the current request.
	 *
	 * Returns an empty string before parse_request has fired (e.g. during
	 * plugins_loaded or init when called too early). Themes should call this
	 * from template_redirect or later hooks.
	 *
	 * @return string Language code (e.g. 'de', 'fr') or '' if not yet resolved.
	 */
	function esml_get_current_language(): string
	{
		return LanguageDetector::getCurrentLanguage() ?? '';
	}
}

if (! function_exists('esml_is_language')) {
	/**
	 * Check whether the current request is for a specific language.
	 *
	 * @param string $code Language code to test against.
	 */
	function esml_is_language(string $code): bool
	{
		return esml_get_current_language() === $code;
	}
}
