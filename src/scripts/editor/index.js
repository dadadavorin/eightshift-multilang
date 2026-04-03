/**
 * Editor sidebar entry point.
 *
 * Registers the "Translations" PluginSidebar in the Gutenberg block editor.
 * The sidebar is rendered only when the current post type is in the list of
 * translatable post types passed via window.esmEditor.translatableTypes.
 *
 * window.esmEditor is localised by EditorSidebar::scriptData().
 */
import apiFetch from '@wordpress/api-fetch';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import TranslationSidebar from './components/TranslationSidebar';

const { nonce, restUrl, translatableTypes = [] } = window.esmEditor ?? {};

// Configure apiFetch for this bundle.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ?? '' ) );
apiFetch.use( apiFetch.createRootURLMiddleware( restUrl ?? '' ) );

/**
 * Wrapper that hides the sidebar for non-translatable post types.
 */
function TranslationPlugin() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[],
	);

	if ( ! translatableTypes.includes( postType ) ) {
		return null;
	}

	return (
		<PluginSidebar
			name="esml-translations"
			title={ __( 'Translations', 'eightshift-multilang' ) }
			icon="translation"
		>
			<TranslationSidebar />
		</PluginSidebar>
	);
}

registerPlugin( 'esml-translations', { render: TranslationPlugin } );
