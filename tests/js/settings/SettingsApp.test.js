/**
 * Tests for the SettingsApp component.
 */
import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch' );

import SettingsApp from '../../../src/scripts/settings/components/SettingsApp';

// ---------------------------------------------------------------------------

describe( 'SettingsApp', () => {
	beforeEach( () => {
		jest.clearAllMocks();

		// Default: every apiFetch call returns empty settings.
		apiFetch.mockResolvedValue( {
			success: true,
			data: {
				url_mode:                'subdirectory',
				translatable_post_types: [ 'post', 'page' ],
				translatable_suffixes:   [ 'Content' ],
				ai_provider:             'claude',
				ai_custom_prompt:        '',
				ai_monthly_limit:        '0',
				api_key_set:             false,
			},
		} );
	} );

	it( 'renders the settings page title', () => {
		render( <SettingsApp /> );

		expect(
			screen.getByText( /eightshift multilang settings/i )
		).toBeInTheDocument();
	} );

	it( 'renders all three tab buttons', () => {
		render( <SettingsApp /> );

		expect( screen.getByText( /^general$/i ) ).toBeInTheDocument();
		expect( screen.getByText( /^ai$/i ) ).toBeInTheDocument();
		expect( screen.getByText( /^languages$/i ) ).toBeInTheDocument();
	} );

	it( 'shows the General tab by default', () => {
		render( <SettingsApp /> );

		// The General tab button should have the active class.
		const generalBtn = screen.getByText( /^general$/i );
		expect( generalBtn.className ).toContain( 'nav-tab-active' );
	} );

	it( 'switches to the AI tab on click', async () => {
		const user = userEvent.setup();
		render( <SettingsApp /> );

		await act( async () => {
			await user.click( screen.getByText( /^ai$/i ) );
		} );

		const aiBtn = screen.getByText( /^ai$/i );
		expect( aiBtn.className ).toContain( 'nav-tab-active' );
	} );

	it( 'switches to the Languages tab on click', async () => {
		// The Languages tab loads the language list — stub a response.
		apiFetch.mockResolvedValue( { success: true, data: [] } );

		const user = userEvent.setup();
		render( <SettingsApp /> );

		await act( async () => {
			await user.click( screen.getByText( /^languages$/i ) );
		} );

		const langsBtn = screen.getByText( /^languages$/i );
		expect( langsBtn.className ).toContain( 'nav-tab-active' );
	} );

	it( 'only shows one tab panel at a time', async () => {
		const user = userEvent.setup();
		render( <SettingsApp /> );

		// Switch to AI tab.
		await act( async () => {
			await user.click( screen.getByText( /^ai$/i ) );
		} );

		// The General-specific "URL mode" label should NOT be visible.
		expect( screen.queryByText( /url mode/i ) ).not.toBeInTheDocument();
	} );
} );
