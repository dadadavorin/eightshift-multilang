/**
 * General settings tab.
 *
 * Manages: URL mode, translatable post types, translatable attribute suffixes.
 */
import { useState, useEffect } from '@wordpress/element';
import { Button, SelectControl, TextareaControl, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const URL_MODE_OPTIONS = [
	{ label: __( 'Subdirectory (example.com/de/)', 'eightshift-multilang' ), value: 'subdirectory' },
];

// Parse a comma-separated text string into a trimmed, filtered array.
const parseCommaSeparated = ( val ) =>
	val.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );

export default function GeneralTab() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ]     = useState( false );
	const [ notice, setNotice ]     = useState( null ); // { type: 'success'|'error', message }

	// Raw text state for the two comma-separated fields.  Keeping a separate
	// local string prevents the field from eating commas mid-typing (which
	// happened when onChange immediately re-joined the parsed array back into
	// a string, stripping any trailing comma the user just typed).
	const [ postTypesText, setPostTypesText ] = useState( '' );
	const [ suffixesText, setSuffixesText ]   = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/eightshift-multilang/v1/settings' } )
			.then( ( res ) => {
				setSettings( res.data );
				setPostTypesText(
					Array.isArray( res.data.translatable_post_types )
						? res.data.translatable_post_types.join( ', ' )
						: '',
				);
				setSuffixesText(
					Array.isArray( res.data.translatable_suffixes )
						? res.data.translatable_suffixes.join( ', ' )
						: '',
				);
			} )
			.catch( () =>
				setNotice( {
					type:    'error',
					message: __( 'Failed to load settings.', 'eightshift-multilang' ),
				} )
			);
	}, [] );

	const save = async () => {
		// Parse the raw text into arrays just before saving.
		const parsedPostTypes = parseCommaSeparated( postTypesText );
		const parsedSuffixes  = parseCommaSeparated( suffixesText );

		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				path:   '/eightshift-multilang/v1/settings',
				method: 'POST',
				data:   {
					url_mode:                settings.url_mode,
					translatable_post_types: parsedPostTypes,
					translatable_suffixes:   parsedSuffixes,
				},
			} );
			// Sync settings state so the SelectControl stays consistent.
			setSettings( {
				...settings,
				translatable_post_types: parsedPostTypes,
				translatable_suffixes:   parsedSuffixes,
			} );
			setNotice( { type: 'success', message: __( 'Settings saved.', 'eightshift-multilang' ) } );
		} catch ( err ) {
			setNotice( {
				type:    'error',
				message: err?.message ?? __( 'Failed to save settings.', 'eightshift-multilang' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	if ( ! settings ) {
		return <Spinner />;
	}

	return (
		<div className="esml-settings-tab">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<SelectControl
				label={ __( 'URL mode', 'eightshift-multilang' ) }
				value={ settings.url_mode }
				options={ URL_MODE_OPTIONS }
				onChange={ ( val ) => setSettings( { ...settings, url_mode: val } ) }
				help={ __(
					'Currently only subdirectory mode is supported.',
					'eightshift-multilang',
				) }
			/>

			<TextareaControl
				label={ __( 'Translatable post types', 'eightshift-multilang' ) }
				value={ postTypesText }
				onChange={ setPostTypesText }
				help={ __( 'Comma-separated list of post type slugs, e.g. post, page.', 'eightshift-multilang' ) }
				rows={ 2 }
			/>

			<TextareaControl
				label={ __( 'Translatable attribute suffixes', 'eightshift-multilang' ) }
				value={ suffixesText }
				onChange={ setSuffixesText }
				help={ __(
					'Block attribute names ending with these suffixes will be translated, e.g. Content.',
					'eightshift-multilang',
				) }
				rows={ 2 }
			/>

			<Button
				variant="primary"
				onClick={ save }
				isBusy={ saving }
				disabled={ saving }
			>
				{ __( 'Save settings', 'eightshift-multilang' ) }
			</Button>
		</div>
	);
}
