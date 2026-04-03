/**
 * Jest global setup for Eightshift Multilang JS tests.
 *
 * Runs after the test framework is installed but before each test file.
 * Provides the window globals that the components read at module parse time.
 */

// Provide window.esmEditor so components don't blow up when imported.
global.window = global.window ?? {};

global.window.esmEditor = {
	restUrl:           'https://example.com/wp-json/eightshift-multilang/v1',
	nonce:             'test-nonce',
	pluginUrl:         'https://example.com/wp-content/plugins/eightshift-multilang/',
	translatableTypes: [ 'post', 'page' ],
	defaultLanguage:   'en',
	activeLanguages: [
		{ code: 'en', name: 'English' },
		{ code: 'de', name: 'German' },
		{ code: 'fr', name: 'French' },
	],
	i18n: {
		sidebarTitle:     'Translations',
		noGroup:          'This post is not part of a translation group.',
		addTranslation:   'Add Translation',
		translate:        'Translate with AI',
		translating:      'Translating…',
		translationDone:  'Translation created.',
		translationError: 'Translation failed.',
		editPost:         'Edit',
		outOfSync:        'Out of sync — source post was updated.',
		inSync:           'In sync',
		selectLanguage:   'Select target language',
		cancel:           'Cancel',
		source:           'Source',
	},
};

global.window.esmSettings = {
	restUrl:   'https://example.com/wp-json/eightshift-multilang/v1',
	nonce:     'test-nonce',
	pluginUrl: 'https://example.com/wp-content/plugins/eightshift-multilang/',
	version:   '1.0.0',
	i18n:      {
		pageTitle:   'Eightshift Multilang Settings',
		saveSuccess: 'Settings saved.',
		saveError:   'Failed to save settings.',
	},
};
