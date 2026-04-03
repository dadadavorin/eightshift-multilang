/**
 * Tests for the TranslationSidebar component.
 */
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

// Mock @wordpress/data so useSelect returns a stable postId without needing
// a real Redux store.
jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( ( fn ) =>
		fn( {
			select: () => ( {
				getCurrentPostId:   () => 42,
				getCurrentPostType: () => 'post',
			} ),
		} )
	),
} ) );

// Mock apiFetch so no real HTTP calls are made.
jest.mock( '@wordpress/api-fetch' );

import TranslationSidebar from '../../../src/scripts/editor/components/TranslationSidebar';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const GROUP_WITH_LINKS = {
	success: true,
	data: {
		post_id:  42,
		group_id: 'uuid-1234',
		links: [
			{ post_id: 42, language_code: 'en', is_source: true },
			{ post_id: 99, language_code: 'de', is_source: false },
		],
	},
};

const GROUP_EMPTY = {
	success: true,
	data: { post_id: 42, group_id: null, links: [] },
};

const SYNC_IN_SYNC    = { success: true, data: { post_id: 99, out_of_sync: false } };
const SYNC_OUT_OF_SYNC = { success: true, data: { post_id: 99, out_of_sync: true } };

// ---------------------------------------------------------------------------

describe( 'TranslationSidebar', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'shows a spinner while loading', () => {
		// apiFetch never resolves — keeps component in loading state.
		apiFetch.mockReturnValue( new Promise( () => {} ) );

		render( <TranslationSidebar /> );

		// @wordpress/components Spinner renders a div with the spinner role.
		expect( document.querySelector( '.components-spinner' ) ).toBeTruthy();
	} );

	it( 'shows "no group" message when post is not linked', async () => {
		apiFetch.mockResolvedValue( GROUP_EMPTY );

		render( <TranslationSidebar /> );

		await waitFor( () =>
			expect(
				screen.getByText( /not part of a translation group/i )
			).toBeInTheDocument()
		);
	} );

	it( 'renders translation links when group exists', async () => {
		apiFetch
			.mockResolvedValueOnce( GROUP_WITH_LINKS ) // translations/{postId}
			.mockResolvedValue( SYNC_IN_SYNC );        // sync-status for each link

		render( <TranslationSidebar /> );

		await waitFor( () => expect( screen.getByText( 'en' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'de' ) ).toBeInTheDocument();
	} );

	it( 'shows Source badge for the source link', async () => {
		apiFetch
			.mockResolvedValueOnce( GROUP_WITH_LINKS )
			.mockResolvedValue( SYNC_IN_SYNC );

		render( <TranslationSidebar /> );

		await waitFor( () =>
			expect( screen.getByText( /source/i ) ).toBeInTheDocument()
		);
	} );

	it( 'renders an Edit link for sibling translations', async () => {
		apiFetch
			.mockResolvedValueOnce( GROUP_WITH_LINKS )
			.mockResolvedValue( SYNC_IN_SYNC );

		render( <TranslationSidebar /> );

		await waitFor( () =>
			expect( screen.getByText( /edit/i ) ).toBeInTheDocument()
		);
	} );

	it( 'opens TranslateModal when Add Translation is clicked', async () => {
		apiFetch
			.mockResolvedValueOnce( GROUP_WITH_LINKS )
			.mockResolvedValue( SYNC_IN_SYNC );

		const user = userEvent.setup();
		render( <TranslationSidebar /> );

		await waitFor( () =>
			expect( screen.getByText( /add translation/i ) ).toBeInTheDocument()
		);

		await act( async () => {
			await user.click( screen.getByText( /add translation/i ) );
		} );

		// Modal title should appear.
		expect( screen.getAllByText( /add translation/i ).length ).toBeGreaterThan( 1 );
	} );

	it( 'shows error notice when REST call fails', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network error' ) );

		render( <TranslationSidebar /> );

		await waitFor( () =>
			expect( screen.getByText( /network error/i ) ).toBeInTheDocument()
		);
	} );
} );
