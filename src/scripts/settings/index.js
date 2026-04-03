/**
 * Settings page entry point.
 *
 * Mounts the React settings app into #esml-settings-root.
 * window.esmSettings is localised by SettingsPage::scriptData().
 */
import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';
import SettingsApp from './components/SettingsApp';

// Attach the REST nonce so every apiFetch call is authenticated.
apiFetch.use(
	apiFetch.createNonceMiddleware( window.esmSettings?.nonce ?? '' )
);

const root = document.getElementById( 'esml-settings-root' );

if ( root ) {
	createRoot( root ).render( <SettingsApp /> );
}
