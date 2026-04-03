/**
 * AI settings tab.
 *
 * Manages: AI provider, API key, custom system prompt, monthly call limit.
 * Includes a "Validate connection" action and live usage display.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	Notice,
	Spinner,
	Card,
	CardBody,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const PROVIDER_OPTIONS = [
	{ label: 'Claude (Anthropic)', value: 'claude' },
];

export default function AITab() {
	const [ settings, setSettings ] = useState( null );
	const [ apiKey, setApiKey ]     = useState( '' ); // plaintext, only sent on change
	const [ usage, setUsage ]       = useState( null );
	const [ saving, setSaving ]     = useState( false );
	const [ validating, setValidating ] = useState( false );
	const [ notice, setNotice ]     = useState( null );

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/eightshift-multilang/v1/settings' } ),
			apiFetch( { path: '/eightshift-multilang/v1/usage' } ),
		] )
			.then( ( [ settingsRes, usageRes ] ) => {
				setSettings( settingsRes.data );
				setUsage( usageRes.data );
			} )
			.catch( () =>
				setNotice( { type: 'error', message: __( 'Failed to load settings.', 'eightshift-multilang' ) } )
			);
	}, [] );

	const save = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const data = {
				ai_provider:      settings.ai_provider,
				ai_custom_prompt: settings.ai_custom_prompt,
				ai_monthly_limit: settings.ai_monthly_limit,
			};
			// Only send the key if the user typed something in the field.
			if ( apiKey !== '' ) {
				data.api_key = apiKey;
			}
			await apiFetch( { path: '/eightshift-multilang/v1/settings', method: 'POST', data } );
			setApiKey( '' ); // Clear field — it's stored encrypted.
			setNotice( { type: 'success', message: __( 'Settings saved.', 'eightshift-multilang' ) } );
			// Refresh api_key_set flag.
			const refreshed = await apiFetch( { path: '/eightshift-multilang/v1/settings' } );
			setSettings( refreshed.data );
		} catch ( err ) {
			setNotice( {
				type:    'error',
				message: err?.message ?? __( 'Failed to save settings.', 'eightshift-multilang' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const validateConnection = async () => {
		setValidating( true );
		setNotice( null );
		try {
			const res = await apiFetch( { path: '/eightshift-multilang/v1/settings/validate-connection', method: 'POST' } );
			setNotice( {
				type:    'success',
				message: __( 'Connection successful. Model: ', 'eightshift-multilang' ) + res.data.model,
			} );
		} catch ( err ) {
			setNotice( {
				type:    'error',
				message: err?.message ?? __( 'Connection failed.', 'eightshift-multilang' ),
			} );
		} finally {
			setValidating( false );
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
				label={ __( 'AI provider', 'eightshift-multilang' ) }
				value={ settings.ai_provider }
				options={ PROVIDER_OPTIONS }
				onChange={ ( val ) => setSettings( { ...settings, ai_provider: val } ) }
			/>

			<TextControl
				label={ __( 'API key', 'eightshift-multilang' ) }
				type="password"
				value={ apiKey }
				onChange={ setApiKey }
				placeholder={
					settings.api_key_set
						? __( '••••••••  (key stored — enter a new key to replace)', 'eightshift-multilang' )
						: __( 'Enter your Anthropic API key', 'eightshift-multilang' )
				}
				help={ settings.api_key_set
					? __( 'An API key is currently stored.', 'eightshift-multilang' )
					: __( 'No API key stored yet.', 'eightshift-multilang' )
				}
			/>

			<Button
				variant="secondary"
				onClick={ validateConnection }
				isBusy={ validating }
				disabled={ validating || ! settings.api_key_set }
			>
				{ __( 'Validate connection', 'eightshift-multilang' ) }
			</Button>

			<TextareaControl
				label={ __( 'Custom system prompt', 'eightshift-multilang' ) }
				value={ settings.ai_custom_prompt ?? '' }
				onChange={ ( val ) => setSettings( { ...settings, ai_custom_prompt: val } ) }
				help={ __(
					'Appended to the default prompt. E.g. "Use formal Sie in German."',
					'eightshift-multilang',
				) }
				rows={ 4 }
			/>

			<TextControl
				label={ __( 'Monthly call limit', 'eightshift-multilang' ) }
				type="number"
				value={ String( settings.ai_monthly_limit ?? '0' ) }
				onChange={ ( val ) => setSettings( { ...settings, ai_monthly_limit: val } ) }
				help={ __( 'Set to 0 for unlimited.', 'eightshift-multilang' ) }
				min="0"
			/>

			{ usage && (
				<Card className="esml-usage-card">
					<CardBody>
						<strong>{ __( 'Usage this month:', 'eightshift-multilang' ) }</strong>{ ' ' }
						{ usage.current }
						{ usage.limit > 0 && ` / ${ usage.limit }` }
					</CardBody>
				</Card>
			) }

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
