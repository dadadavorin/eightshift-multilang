/**
 * Editor sidebar entry point.
 *
 * Registers the "Translations" PluginSidebar in the Gutenberg block editor.
 * The sidebar is rendered only when the current post type is in the list of
 * translatable post types passed via window.esmEditor.translatableTypes.
 *
 * window.esmEditor is localised by EditorSidebar::scriptData().
 */
import './style.css';
import apiFetch from '@wordpress/api-fetch';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import TranslationSidebar from './components/TranslationSidebar';

const { nonce, restUrl, translatableTypes = [] } = window.esmEditor ?? {};

// Configure apiFetch for this bundle.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ?? '' ) );

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
		layout:          { type: 'string',  default: 'vertical' },
		showNativeNames: { type: 'boolean', default: false },
		showFlags:       { type: 'boolean', default: false },
		showCodes:       { type: 'boolean', default: false },
		hideActive:      { type: 'boolean', default: false },
	},

	edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps( { className: 'esml-switcher-editor-preview' } );

		const layoutLabels = {
			vertical:   __( 'Vertical', 'eightshift-multilang' ),
			horizontal: __( 'Horizontal', 'eightshift-multilang' ),
			dropdown:   __( 'Dropdown', 'eightshift-multilang' ),
		};

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __( 'Display', 'eightshift-multilang' ) }
						initialOpen={ true }
					>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Layout', 'eightshift-multilang' ) }
							value={ attributes.layout }
							options={ [
								{ label: __( 'Vertical list', 'eightshift-multilang' ), value: 'vertical' },
								{ label: __( 'Horizontal list', 'eightshift-multilang' ), value: 'horizontal' },
								{ label: __( 'Dropdown', 'eightshift-multilang' ), value: 'dropdown' },
							] }
							onChange={ ( layout ) => setAttributes( { layout } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Show language codes', 'eightshift-multilang' ) }
							help={ __( 'Show codes like EN, HR, DE instead of full names.', 'eightshift-multilang' ) }
							checked={ attributes.showCodes }
							onChange={ ( showCodes ) => setAttributes( { showCodes } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Show native names', 'eightshift-multilang' ) }
							help={ __( 'Show "Deutsch" instead of "German". Has no effect when codes are shown.', 'eightshift-multilang' ) }
							checked={ attributes.showNativeNames }
							onChange={ ( showNativeNames ) => setAttributes( { showNativeNames } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Show flags', 'eightshift-multilang' ) }
							checked={ attributes.showFlags }
							onChange={ ( showFlags ) => setAttributes( { showFlags } ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Hide active language', 'eightshift-multilang' ) }
							help={ __( 'Remove the current language from the list.', 'eightshift-multilang' ) }
							checked={ attributes.hideActive }
							onChange={ ( hideActive ) => setAttributes( { hideActive } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<div { ...blockProps }>
					<div className="esml-switcher-editor-preview__inner">
						<span className="dashicons dashicons-translation" />
						<p>
							{ __( 'Language Switcher', 'eightshift-multilang' ) }
							<br />
							<small>{ __( 'Rendered on the frontend.', 'eightshift-multilang' ) }</small>
						</p>
					</div>
					<div className="esml-switcher-editor-preview__tags">
						<span className="esml-switcher-preview-tag">
							{ layoutLabels[ attributes.layout ] ?? layoutLabels.vertical }
						</span>
						{ attributes.showCodes && (
							<span className="esml-switcher-preview-tag">
								{ __( 'Codes', 'eightshift-multilang' ) }
							</span>
						) }
						{ attributes.showNativeNames && ! attributes.showCodes && (
							<span className="esml-switcher-preview-tag">
								{ __( 'Native names', 'eightshift-multilang' ) }
							</span>
						) }
						{ attributes.showFlags && (
							<span className="esml-switcher-preview-tag">
								{ __( 'Flags', 'eightshift-multilang' ) }
							</span>
						) }
						{ attributes.hideActive && (
							<span className="esml-switcher-preview-tag">
								{ __( 'Hide active', 'eightshift-multilang' ) }
							</span>
						) }
					</div>
				</div>
			</>
		);
	},

	// Server-side rendered — save returns null so WordPress calls render_callback.
	save: () => null,
} );
