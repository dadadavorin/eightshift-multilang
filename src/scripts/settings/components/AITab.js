/**
 * AI settings tab — Phase 2 multi-provider edition.
 *
 * Layout:
 *  1. Provider selector dropdown
 *  2. Per-provider configuration panel (API key, model selector)
 *     - Custom provider also shows endpoint URL and auth header settings
 *  3. Custom system prompt (shared across all providers)
 *  4. Monthly call limit
 *  5. Usage card
 *  6. Validate connection + Save buttons
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

export default function AITab() {
	const [ settings,  setSettings  ] = useState( null );
	const [ providers, setProviders ] = useState( null ); // { claude: { label, models }, ... }
	const [ usage,     setUsage     ] = useState( null );

	// Plaintext API key inputs — one per provider. Cleared after save.
	// Only non-empty values are sent to the server.
	const [ apiKeys, setApiKeys ]       = useState( {} );

	const [ saving,    setSaving    ] = useState( false );
	const [ validating, setValidating ] = useState( false );
	const [ notice,    setNotice    ] = useState( null );

	// ---------------------------------------------------------------------------
	// Load
	// ---------------------------------------------------------------------------

	useEffect( () => {
		Promise.all( [
			apiFetch( { path: '/eightshift-multilang/v1/settings' } ),
			apiFetch( { path: '/eightshift-multilang/v1/settings/providers' } ),
			apiFetch( { path: '/eightshift-multilang/v1/usage' } ),
		] )
			.then( ( [ settingsRes, providersRes, usageRes ] ) => {
				setSettings( settingsRes.data );
				setProviders( providersRes.data );
				setUsage( usageRes.data );
			} )
			.catch( () =>
				setNotice( { type: 'error', message: __( 'Failed to load settings.', 'eightshift-multilang' ) } )
			);
	}, [] );

	// ---------------------------------------------------------------------------
	// Save
	// ---------------------------------------------------------------------------

	const save = async () => {
		setSaving( true );
		setNotice( null );

		try {
			const data = {
				ai_provider:             settings.ai_provider,
				ai_custom_prompt:        settings.ai_custom_prompt,
				ai_monthly_limit:        settings.ai_monthly_limit,
				// Per-provider model selections.
				ai_model_claude:         settings.ai_model_claude,
				ai_model_gemini:         settings.ai_model_gemini,
				ai_model_openai:         settings.ai_model_openai,
				// Custom provider fields.
				custom_endpoint:         settings.custom_endpoint,
				custom_model:            settings.custom_model,
				custom_auth_header_key:  settings.custom_auth_header_key,
			};

			// Only include API keys the user actually typed something into.
			const keysToSend = Object.fromEntries(
				Object.entries( apiKeys ).filter( ( [ , v ] ) => v !== '' )
			);

			if ( Object.keys( keysToSend ).length > 0 ) {
				data.provider_api_keys = keysToSend;
			}

			await apiFetch( {
				path:   '/eightshift-multilang/v1/settings',
				method: 'POST',
				data,
			} );

			// Clear key inputs — they are now stored encrypted.
			setApiKeys( {} );

			setNotice( { type: 'success', message: __( 'Settings saved.', 'eightshift-multilang' ) } );

			// Refresh provider_keys booleans so the UI reflects the new state.
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

	// ---------------------------------------------------------------------------
	// Validate connection
	// ---------------------------------------------------------------------------

	const validateConnection = async () => {
		setValidating( true );
		setNotice( null );

		try {
			const res = await apiFetch( {
				path:   '/eightshift-multilang/v1/settings/validate-connection',
				method: 'POST',
			} );

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

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/** True when the active provider has a stored API key (or is 'custom'). */
	const activeProviderHasKey = () => {
		const id = settings?.ai_provider;
		if ( id === 'custom' ) return true; // Custom can run without a key.
		return !! settings?.provider_keys?.[ id ];
	};

	const setApiKey = ( provider, value ) =>
		setApiKeys( ( prev ) => ( { ...prev, [ provider ]: value } ) );

	const set = ( field, value ) =>
		setSettings( ( prev ) => ( { ...prev, [ field ]: value } ) );

	// ---------------------------------------------------------------------------
	// Render helpers
	// ---------------------------------------------------------------------------

	const renderProviderKeyField = ( providerId ) => {
		const keyIsStored = !! settings.provider_keys?.[ providerId ];
		const currentKey  = apiKeys[ providerId ] ?? '';

		return (
			<TextControl
				label={ __( 'API key', 'eightshift-multilang' ) }
				type="password"
				value={ currentKey }
				onChange={ ( v ) => setApiKey( providerId, v ) }
				placeholder={
					keyIsStored
						? __( '••••••••  (key stored — enter a new key to replace)', 'eightshift-multilang' )
						: __( 'Enter your API key', 'eightshift-multilang' )
				}
				help={ keyIsStored
					? __( 'A key is currently stored and active.', 'eightshift-multilang' )
					: __( 'No key stored yet.', 'eightshift-multilang' )
				}
			/>
		);
	};

	const renderModelSelector = ( providerId, settingsField ) => {
		const providerMeta = providers?.[ providerId ];

		if ( ! providerMeta || providerMeta.models.length === 0 ) {
			return null;
		}

		const options = providerMeta.models.map( ( m ) => ( { label: m.label, value: m.id } ) );

		return (
			<SelectControl
				label={ __( 'Model', 'eightshift-multilang' ) }
				value={ settings[ settingsField ] ?? options[ 0 ]?.value ?? '' }
				options={ options }
				onChange={ ( v ) => set( settingsField, v ) }
			/>
		);
	};

	// ---------------------------------------------------------------------------
	// Provider configuration panels
	// ---------------------------------------------------------------------------

	const renderClaudePanel = () => (
		<div className="esml-provider-panel">
			{ renderProviderKeyField( 'claude' ) }
			{ renderModelSelector( 'claude', 'ai_model_claude' ) }
		</div>
	);

	const renderGeminiPanel = () => (
		<div className="esml-provider-panel">
			{ renderProviderKeyField( 'gemini' ) }
			{ renderModelSelector( 'gemini', 'ai_model_gemini' ) }
		</div>
	);

	const renderOpenAIPanel = () => (
		<div className="esml-provider-panel">
			{ renderProviderKeyField( 'openai' ) }
			{ renderModelSelector( 'openai', 'ai_model_openai' ) }
		</div>
	);

	const renderCustomPanel = () => (
		<div className="esml-provider-panel">
			<TextControl
				label={ __( 'Endpoint URL', 'eightshift-multilang' ) }
				value={ settings.custom_endpoint ?? '' }
				onChange={ ( v ) => set( 'custom_endpoint', v ) }
				placeholder="https://api.example.com/v1/chat/completions"
				help={ __( 'Must follow the OpenAI chat-completions request/response format.', 'eightshift-multilang' ) }
			/>
			<TextControl
				label={ __( 'Model name', 'eightshift-multilang' ) }
				value={ settings.custom_model ?? '' }
				onChange={ ( v ) => set( 'custom_model', v ) }
				placeholder="llama3, mistral, gpt-4, …"
			/>
			<TextControl
				label={ __( 'Auth header name', 'eightshift-multilang' ) }
				value={ settings.custom_auth_header_key ?? 'Authorization' }
				onChange={ ( v ) => set( 'custom_auth_header_key', v ) }
				help={ __( 'The HTTP header used for authentication, e.g. Authorization, x-api-key.', 'eightshift-multilang' ) }
			/>
			<TextControl
				label={ __( 'Auth header value', 'eightshift-multilang' ) }
				type="password"
				value={ apiKeys.custom ?? '' }
				onChange={ ( v ) => setApiKey( 'custom', v ) }
				placeholder={
					settings.provider_keys?.custom
						? __( '••••••••  (value stored — enter a new value to replace)', 'eightshift-multilang' )
						: __( 'Bearer sk-...  or leave empty for unauthenticated endpoints', 'eightshift-multilang' )
				}
				help={ settings.provider_keys?.custom
					? __( 'Auth value is currently stored.', 'eightshift-multilang' )
					: __( 'No auth value stored. Leave blank for endpoints that require no authentication.', 'eightshift-multilang' )
				}
			/>
		</div>
	);

	const PANEL_MAP = {
		claude: renderClaudePanel,
		gemini: renderGeminiPanel,
		openai: renderOpenAIPanel,
		custom: renderCustomPanel,
	};

	// ---------------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------------

	if ( ! settings || ! providers ) {
		return <Spinner />;
	}

	const activeProvider     = settings.ai_provider ?? 'claude';
	const providerOptions    = Object.entries( providers ).map( ( [ id, meta ] ) => ( {
		label: meta.label,
		value: id,
	} ) );
	const renderActivePanel  = PANEL_MAP[ activeProvider ] ?? null;

	return (
		<div className="esml-settings-tab">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			{ /* ── Provider selector ─────────────────────────────────────────── */ }
			<SelectControl
				label={ __( 'AI provider', 'eightshift-multilang' ) }
				value={ activeProvider }
				options={ providerOptions }
				onChange={ ( v ) => set( 'ai_provider', v ) }
				help={ __( 'Select the AI service used for translation. Each provider requires its own API key.', 'eightshift-multilang' ) }
			/>

			{ /* ── Per-provider configuration panel ──────────────────────────── */ }
			{ renderActivePanel && (
				<Card className="esml-provider-config-card">
					<CardBody>
						<h3 className="esml-provider-config-card__heading">
							{ providers[ activeProvider ]?.label ?? activeProvider }
						</h3>
						{ renderActivePanel() }
					</CardBody>
				</Card>
			) }

			{ /* ── Validate connection ─────────────────────────────────────────── */ }
			<Button
				variant="secondary"
				onClick={ validateConnection }
				isBusy={ validating }
				disabled={ validating || ! activeProviderHasKey() }
			>
				{ __( 'Validate connection', 'eightshift-multilang' ) }
			</Button>

			{ /* ── Shared prompt & limits ──────────────────────────────────────── */ }
			<TextareaControl
				label={ __( 'Custom system prompt', 'eightshift-multilang' ) }
				value={ settings.ai_custom_prompt ?? '' }
				onChange={ ( v ) => set( 'ai_custom_prompt', v ) }
				help={ __( 'Appended to the default prompt for every provider. E.g. "Use formal Sie in German."', 'eightshift-multilang' ) }
				rows={ 4 }
			/>

			<TextControl
				label={ __( 'Monthly call limit', 'eightshift-multilang' ) }
				type="number"
				value={ String( settings.ai_monthly_limit ?? '0' ) }
				onChange={ ( v ) => set( 'ai_monthly_limit', v ) }
				help={ __( 'Set to 0 for unlimited.', 'eightshift-multilang' ) }
				min="0"
			/>

			{ /* ── Usage card ───────────────────────────────────────────────────── */ }
			{ usage && (
				<Card className="esml-usage-card">
					<CardBody>
						<strong>{ __( 'Usage this month:', 'eightshift-multilang' ) }</strong>{ ' ' }
						{ usage.current }
						{ usage.limit > 0 && ` / ${ usage.limit }` }
					</CardBody>
				</Card>
			) }

			{ /* ── Save ────────────────────────────────────────────────────────── */ }
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
