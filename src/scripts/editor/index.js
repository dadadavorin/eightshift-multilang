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
import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
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

// ---------------------------------------------------------------------------
// Language Switcher block — editor component.
// The PHP render_callback in LanguageSwitcherBlock.php handles the frontend.
// ---------------------------------------------------------------------------

registerBlockType( 'eightshift-multilang/language-switcher', {
	title:    __( 'Language Switcher', 'eightshift-multilang' ),
	category: 'widgets',
	icon:     'translation',
	attributes: {
		showNativeNames: { type: 'boolean', default: false },
		showFlags:       { type: 'boolean', default: false },
	},

	edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps( { className: 'esml-switcher-editor-preview' } );

		return (
			<div { ...blockProps }>
				<div className="esml-switcher-editor-preview__inner">
					<span className="dashicons dashicons-translation" />
					<p>
						{ __( 'Language Switcher', 'eightshift-multilang' ) }
						<br />
						<small>
							{ __( 'Rendered on the frontend.', 'eightshift-multilang' ) }
						</small>
					</p>
				</div>
			</div>
		);
	},

	// Server-side rendered — save returns null so WordPress calls render_callback.
	save: () => null,
} );
