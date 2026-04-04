<?php

declare(strict_types=1);

namespace EightshiftMultilang\Languages;

/**
 * Seeds the languages table with a pre-populated list on first activation.
 * The seeder only runs when the table is empty.
 */
final class LanguageSeeder
{
	/**
	 * Pre-populated language list.
	 * Format: [code, locale, name, native_name, flag_code]
	 *
	 * @var list<array{code: string, locale: string, name: string, native_name: string, flag_code: string}>
	 */
	private const LANGUAGES = [
		['code' => 'af', 'locale' => 'af', 'name' => 'Afrikaans', 'native_name' => 'Afrikaans', 'flag_code' => 'za'],
		['code' => 'sq', 'locale' => 'sq', 'name' => 'Albanian', 'native_name' => 'Shqip', 'flag_code' => 'al'],
		['code' => 'ar', 'locale' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'flag_code' => 'sa'],
		['code' => 'hy', 'locale' => 'hy', 'name' => 'Armenian', 'native_name' => 'Հայerern', 'flag_code' => 'am'],
		['code' => 'az', 'locale' => 'az', 'name' => 'Azerbaijani', 'native_name' => 'Azərbaycanca', 'flag_code' => 'az'],
		['code' => 'eu', 'locale' => 'eu', 'name' => 'Basque', 'native_name' => 'Euskara', 'flag_code' => 'es'],
		['code' => 'be', 'locale' => 'be', 'name' => 'Belarusian', 'native_name' => 'Беларуская', 'flag_code' => 'by'],
		['code' => 'bn', 'locale' => 'bn', 'name' => 'Bengali', 'native_name' => 'বাংলা', 'flag_code' => 'bd'],
		['code' => 'bs', 'locale' => 'bs', 'name' => 'Bosnian', 'native_name' => 'Bosanski', 'flag_code' => 'ba'],
		['code' => 'bg', 'locale' => 'bg_BG', 'name' => 'Bulgarian', 'native_name' => 'Български', 'flag_code' => 'bg'],
		['code' => 'ca', 'locale' => 'ca', 'name' => 'Catalan', 'native_name' => 'Català', 'flag_code' => 'es'],
		['code' => 'zh', 'locale' => 'zh_CN', 'name' => 'Chinese (Simplified)', 'native_name' => '中文 (简体)', 'flag_code' => 'cn'],
		['code' => 'zh-tw', 'locale' => 'zh_TW', 'name' => 'Chinese (Traditional)', 'native_name' => '中文 (繁體)', 'flag_code' => 'tw'],
		['code' => 'hr', 'locale' => 'hr', 'name' => 'Croatian', 'native_name' => 'Hrvatski', 'flag_code' => 'hr'],
		['code' => 'cs', 'locale' => 'cs_CZ', 'name' => 'Czech', 'native_name' => 'Čeština', 'flag_code' => 'cz'],
		['code' => 'da', 'locale' => 'da_DK', 'name' => 'Danish', 'native_name' => 'Dansk', 'flag_code' => 'dk'],
		['code' => 'nl', 'locale' => 'nl_NL', 'name' => 'Dutch', 'native_name' => 'Nederlands', 'flag_code' => 'nl'],
		['code' => 'en', 'locale' => 'en_US', 'name' => 'English', 'native_name' => 'English', 'flag_code' => 'us'],
		['code' => 'et', 'locale' => 'et', 'name' => 'Estonian', 'native_name' => 'Eesti', 'flag_code' => 'ee'],
		['code' => 'fi', 'locale' => 'fi', 'name' => 'Finnish', 'native_name' => 'Suomi', 'flag_code' => 'fi'],
		['code' => 'fr', 'locale' => 'fr_FR', 'name' => 'French', 'native_name' => 'Français', 'flag_code' => 'fr'],
		['code' => 'gl', 'locale' => 'gl_ES', 'name' => 'Galician', 'native_name' => 'Galego', 'flag_code' => 'es'],
		['code' => 'ka', 'locale' => 'ka_GE', 'name' => 'Georgian', 'native_name' => 'ქართული', 'flag_code' => 'ge'],
		['code' => 'de', 'locale' => 'de_DE', 'name' => 'German', 'native_name' => 'Deutsch', 'flag_code' => 'de'],
		['code' => 'el', 'locale' => 'el', 'name' => 'Greek', 'native_name' => 'Ελληνικά', 'flag_code' => 'gr'],
		['code' => 'gu', 'locale' => 'gu', 'name' => 'Gujarati', 'native_name' => 'ગુજરાતી', 'flag_code' => 'in'],
		['code' => 'he', 'locale' => 'he_IL', 'name' => 'Hebrew', 'native_name' => 'עברית', 'flag_code' => 'il'],
		['code' => 'hi', 'locale' => 'hi_IN', 'name' => 'Hindi', 'native_name' => 'हिन्दी', 'flag_code' => 'in'],
		['code' => 'hu', 'locale' => 'hu_HU', 'name' => 'Hungarian', 'native_name' => 'Magyar', 'flag_code' => 'hu'],
		['code' => 'is', 'locale' => 'is_IS', 'name' => 'Icelandic', 'native_name' => 'Íslenska', 'flag_code' => 'is'],
		['code' => 'id', 'locale' => 'id_ID', 'name' => 'Indonesian', 'native_name' => 'Bahasa Indonesia', 'flag_code' => 'id'],
		['code' => 'ga', 'locale' => 'ga', 'name' => 'Irish', 'native_name' => 'Gaeilge', 'flag_code' => 'ie'],
		['code' => 'it', 'locale' => 'it_IT', 'name' => 'Italian', 'native_name' => 'Italiano', 'flag_code' => 'it'],
		['code' => 'ja', 'locale' => 'ja', 'name' => 'Japanese', 'native_name' => '日本語', 'flag_code' => 'jp'],
		['code' => 'kn', 'locale' => 'kn', 'name' => 'Kannada', 'native_name' => 'ಕನ್ನಡ', 'flag_code' => 'in'],
		['code' => 'kk', 'locale' => 'kk', 'name' => 'Kazakh', 'native_name' => 'Қазақша', 'flag_code' => 'kz'],
		['code' => 'ko', 'locale' => 'ko_KR', 'name' => 'Korean', 'native_name' => '한국어', 'flag_code' => 'kr'],
		['code' => 'ky', 'locale' => 'ky_KY', 'name' => 'Kyrgyz', 'native_name' => 'Кыргызча', 'flag_code' => 'kg'],
		['code' => 'lv', 'locale' => 'lv', 'name' => 'Latvian', 'native_name' => 'Latviešu', 'flag_code' => 'lv'],
		['code' => 'lt', 'locale' => 'lt_LT', 'name' => 'Lithuanian', 'native_name' => 'Lietuvių', 'flag_code' => 'lt'],
		['code' => 'lb', 'locale' => 'lb_LU', 'name' => 'Luxembourgish', 'native_name' => 'Lëtzebuergesch', 'flag_code' => 'lu'],
		['code' => 'mk', 'locale' => 'mk_MK', 'name' => 'Macedonian', 'native_name' => 'Македонски', 'flag_code' => 'mk'],
		['code' => 'ms', 'locale' => 'ms_MY', 'name' => 'Malay', 'native_name' => 'Bahasa Melayu', 'flag_code' => 'my'],
		['code' => 'ml', 'locale' => 'ml_IN', 'name' => 'Malayalam', 'native_name' => 'മലയാളം', 'flag_code' => 'in'],
		['code' => 'mt', 'locale' => 'mt_MT', 'name' => 'Maltese', 'native_name' => 'Malti', 'flag_code' => 'mt'],
		['code' => 'mr', 'locale' => 'mr', 'name' => 'Marathi', 'native_name' => 'मराठी', 'flag_code' => 'in'],
		['code' => 'mn', 'locale' => 'mn', 'name' => 'Mongolian', 'native_name' => 'Монгол', 'flag_code' => 'mn'],
		['code' => 'ne', 'locale' => 'ne_NP', 'name' => 'Nepali', 'native_name' => 'नेपाली', 'flag_code' => 'np'],
		['code' => 'no', 'locale' => 'nb_NO', 'name' => 'Norwegian', 'native_name' => 'Norsk', 'flag_code' => 'no'],
		['code' => 'ps', 'locale' => 'ps', 'name' => 'Pashto', 'native_name' => 'پښتو', 'flag_code' => 'af'],
		['code' => 'fa', 'locale' => 'fa_IR', 'name' => 'Persian', 'native_name' => 'فارسی', 'flag_code' => 'ir'],
		['code' => 'pl', 'locale' => 'pl_PL', 'name' => 'Polish', 'native_name' => 'Polski', 'flag_code' => 'pl'],
		['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Portuguese', 'native_name' => 'Português', 'flag_code' => 'pt'],
		['code' => 'pt-br', 'locale' => 'pt_BR', 'name' => 'Portuguese (Brazil)', 'native_name' => 'Português (Brasil)', 'flag_code' => 'br'],
		['code' => 'pa', 'locale' => 'pa_IN', 'name' => 'Punjabi', 'native_name' => 'ਪੰਜਾਬੀ', 'flag_code' => 'in'],
		['code' => 'ro', 'locale' => 'ro_RO', 'name' => 'Romanian', 'native_name' => 'Română', 'flag_code' => 'ro'],
		['code' => 'ru', 'locale' => 'ru_RU', 'name' => 'Russian', 'native_name' => 'Русский', 'flag_code' => 'ru'],
		['code' => 'sr', 'locale' => 'sr_RS', 'name' => 'Serbian', 'native_name' => 'Српски', 'flag_code' => 'rs'],
		['code' => 'si', 'locale' => 'si_LK', 'name' => 'Sinhala', 'native_name' => 'සිංහල', 'flag_code' => 'lk'],
		['code' => 'sk', 'locale' => 'sk_SK', 'name' => 'Slovak', 'native_name' => 'Slovenčina', 'flag_code' => 'sk'],
		['code' => 'sl', 'locale' => 'sl_SI', 'name' => 'Slovenian', 'native_name' => 'Slovenščina', 'flag_code' => 'si'],
		['code' => 'es', 'locale' => 'es_ES', 'name' => 'Spanish', 'native_name' => 'Español', 'flag_code' => 'es'],
		['code' => 'sw', 'locale' => 'sw', 'name' => 'Swahili', 'native_name' => 'Kiswahili', 'flag_code' => 'ke'],
		['code' => 'sv', 'locale' => 'sv_SE', 'name' => 'Swedish', 'native_name' => 'Svenska', 'flag_code' => 'se'],
		['code' => 'tl', 'locale' => 'tl', 'name' => 'Tagalog', 'native_name' => 'Tagalog', 'flag_code' => 'ph'],
		['code' => 'ta', 'locale' => 'ta_IN', 'name' => 'Tamil', 'native_name' => 'தமிழ்', 'flag_code' => 'in'],
		['code' => 'te', 'locale' => 'te', 'name' => 'Telugu', 'native_name' => 'తెలుగు', 'flag_code' => 'in'],
		['code' => 'th', 'locale' => 'th', 'name' => 'Thai', 'native_name' => 'ภาษาไทย', 'flag_code' => 'th'],
		['code' => 'tr', 'locale' => 'tr_TR', 'name' => 'Turkish', 'native_name' => 'Türkçe', 'flag_code' => 'tr'],
		['code' => 'tk', 'locale' => 'tk', 'name' => 'Turkmen', 'native_name' => 'Türkmen', 'flag_code' => 'tm'],
		['code' => 'uk', 'locale' => 'uk', 'name' => 'Ukrainian', 'native_name' => 'Українська', 'flag_code' => 'ua'],
		['code' => 'ur', 'locale' => 'ur', 'name' => 'Urdu', 'native_name' => 'اردو', 'flag_code' => 'pk'],
		['code' => 'uz', 'locale' => 'uz_UZ', 'name' => 'Uzbek', 'native_name' => "O'zbekcha", 'flag_code' => 'uz'],
		['code' => 'vi', 'locale' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'flag_code' => 'vn'],
		['code' => 'cy', 'locale' => 'cy', 'name' => 'Welsh', 'native_name' => 'Cymraeg', 'flag_code' => 'gb'],
		['code' => 'xh', 'locale' => 'xh', 'name' => 'Xhosa', 'native_name' => 'isiXhosa', 'flag_code' => 'za'],
		['code' => 'yi', 'locale' => 'yi', 'name' => 'Yiddish', 'native_name' => 'ייִדיש', 'flag_code' => 'il'],
		['code' => 'yo', 'locale' => 'yo', 'name' => 'Yoruba', 'native_name' => 'Yorùbá', 'flag_code' => 'ng'],
		['code' => 'zu', 'locale' => 'zu', 'name' => 'Zulu', 'native_name' => 'isiZulu', 'flag_code' => 'za'],
	];

	public function __construct(
		private readonly \wpdb $db,
	) {
	}

	/**
	 * Insert the pre-populated language list if the table is empty.
	 * Safe to call on every activation — no-ops when data already exists.
	 */
	public function seed(): void
	{
		$table = $this->db->prefix . 'es_multilang_languages';

		$count = (int) $this->db->get_var("SELECT COUNT(*) FROM {$table}");
		if ($count > 0) {
			return;
		}

		foreach (self::LANGUAGES as $index => $language) {
			$this->db->insert(
				$table,
				[
					'code'        => $language['code'],
					'locale'      => $language['locale'],
					'name'        => $language['name'],
					'native_name' => $language['native_name'],
					'flag_code'   => $language['flag_code'],
					'is_default'  => 0,
					'is_active'   => 0,
					'sort_order'  => $index,
					'date_format' => null,
				],
				['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']
			);
		}
	}
}
