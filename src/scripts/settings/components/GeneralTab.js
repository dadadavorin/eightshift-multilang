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

export default function GeneralTab() {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ]     = useState( false );
	const [ notice, setNotice ]     = useState( null ); // { type: 'success'|'error', message }

	useEffect( () => {
		apiFetch( { path: '/eightshift-multilang/v1/settings' } )
			.then( ( res ) => setSettings( res.data ) )
			.catch( () =>
				setNotice( {
					type:    'error',
					message: __( 'Failed to load settings.', 'eightshift-multilang' ),
				} )
			);
	}, [] );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			await apiFetch( {
				path:   '/eightshift-multilang/v1/settings',
				method: 'POST',
				data:   {
					url_mode:                settings.url_mode,
					translatable_post_types: settings.translatable_post_types,
					translatable_suffixes:   settings.translatable_suffixes,
				},
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

	const postTypesRaw = Array.isArray( settings.translatable_post_types )
		? settings.translatable_post_types.join( ', ' )
		: '';

	const suffixesRaw = Array.isArray( settings.translatable_suffixes )
		? settings.translatable_suffixes.join( ', ' )
		: '';

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
				value={ postTypesRaw }
				onChange={ ( val ) =>
					setSettings( {
						...settings,
						translatable_post_types: val.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
					} )
				}
				help={ __( 'Comma-separated list of post type slugs, e.g. post, page.', 'eightshift-multilang' ) }
				rows={ 2 }
			/>

			<TextareaControl
				label={ __( 'Translatable attribute suffixes', 'eightshift-multilang' ) }
				value={ suffixesRaw }
				onChange={ ( val ) =>
					setSettings( {
						...settings,
						translatable_suffixes: val.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ),
					} )
				}
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
