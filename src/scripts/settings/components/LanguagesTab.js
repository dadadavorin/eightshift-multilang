/**
 * Languages settings tab.
 *
 * Lists all languages with controls to activate/deactivate, set as default,
 * remove, and reorder. Also provides an "Add language" form.
 *
 * Active/inactive toggles are batched: clicking a checkbox only updates local
 * state, and the "Save changes" button appears once there are pending changes.
 * Set Default and Remove take effect immediately (they are explicit, named actions).
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	TextControl,
	Notice,
	Spinner,
	CheckboxControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const EMPTY_FORM = { code: '', locale: '', name: '', native_name: '', flag_code: '' };

export default function LanguagesTab() {
	const [ languages, setLanguages ]           = useState( null );
	const [ pendingChanges, setPendingChanges ] = useState( {} ); // { [code]: boolean }
	const [ form, setForm ]                     = useState( EMPTY_FORM );
	const [ saving, setSaving ]                 = useState( false );
	const [ adding, setAdding ]                 = useState( false );
	const [ notice, setNotice ]                 = useState( null );

	const hasPendingChanges = Object.keys( pendingChanges ).length > 0;

	const load = useCallback( () => {
		apiFetch( { path: '/eightshift-multilang/v1/languages' } )
			.then( ( res ) => {
				setLanguages( res.data );
				// Clear pending changes when a fresh server state is loaded.
				setPendingChanges( {} );
			} )
			.catch( () =>
				setNotice( { type: 'error', message: __( 'Failed to load languages.', 'eightshift-multilang' ) } )
			);
	}, [] );

	useEffect( () => { load(); }, [ load ] );

	// ---------------------------------------------------------------------------
	// Active / inactive — local-only until Save is clicked.
	// ---------------------------------------------------------------------------

	const handleToggleActive = ( code, currentlyActive ) => {
		const newValue = ! currentlyActive;

		// Optimistically update the display so the checkbox reflects the click immediately.
		setLanguages( ( prev ) =>
			prev.map( ( l ) => l.code === code ? { ...l, is_active: newValue } : l )
		);

		// Record the desired value for this language. If the user toggles back
		// and forth we always store the latest intent; saving is idempotent.
		setPendingChanges( ( prev ) => ( { ...prev, [ code ]: newValue } ) );
	};

	const handleSaveChanges = async () => {
		setSaving( true );
		setNotice( null );
		try {
			const entries = Object.entries( pendingChanges );

			for ( const [ code, active ] of entries ) {
				await apiFetch( {
					path:   `/eightshift-multilang/v1/languages/${ code }/status`,
					method: 'PUT',
					data:   { active },
				} );
			}

			setNotice( { type: 'success', message: __( 'Settings saved.', 'eightshift-multilang' ) } );
			load(); // Reload from server; clears pendingChanges.
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
	// Immediate actions (Set Default, Remove, Add).
	// ---------------------------------------------------------------------------

	const handleAdd = async () => {
		setAdding( true );
		setNotice( null );
		try {
			await apiFetch( { path: '/eightshift-multilang/v1/languages', method: 'POST', data: form } );
			setForm( EMPTY_FORM );
			load();
		} catch ( err ) {
			setNotice( {
				type:    'error',
				message: err?.message ?? __( 'Failed to add language.', 'eightshift-multilang' ),
			} );
		} finally {
			setAdding( false );
		}
	};

	const handleSetDefault = async ( code ) => {
		setNotice( null );
		try {
			await apiFetch( { path: `/eightshift-multilang/v1/languages/${ code }/default`, method: 'PUT' } );
			load();
		} catch ( err ) {
			setNotice( { type: 'error', message: err?.message ?? __( 'Failed to set default.', 'eightshift-multilang' ) } );
		}
	};

	const handleRemove = async ( code ) => {
		if ( ! window.confirm( __( 'Remove this language? This cannot be undone.', 'eightshift-multilang' ) ) ) {
			return;
		}
		setNotice( null );
		try {
			await apiFetch( { path: `/eightshift-multilang/v1/languages/${ code }`, method: 'DELETE' } );
			load();
		} catch ( err ) {
			setNotice( { type: 'error', message: err?.message ?? __( 'Failed to remove language.', 'eightshift-multilang' ) } );
		}
	};

	// ---------------------------------------------------------------------------

	if ( ! languages ) {
		return <Spinner />;
	}

	const formValid = form.code && form.locale && form.name && form.native_name;

	return (
		<div className="esml-settings-tab">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			{ /* Language list */ }
			{ languages.length === 0 ? (
				<p>{ __( 'No languages configured yet.', 'eightshift-multilang' ) }</p>
			) : (
				<table className="widefat esml-languages-table">
					<thead>
						<tr>
							<th>{ __( 'Code', 'eightshift-multilang' ) }</th>
							<th>{ __( 'Name', 'eightshift-multilang' ) }</th>
							<th>{ __( 'Native', 'eightshift-multilang' ) }</th>
							<th>{ __( 'Active', 'eightshift-multilang' ) }</th>
							<th>{ __( 'Default', 'eightshift-multilang' ) }</th>
							<th>{ __( 'Actions', 'eightshift-multilang' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ languages.map( ( lang ) => (
							<tr
								key={ lang.code }
								className={
									( lang.is_default ? 'esml-lang--default' : '' ) +
									( lang.code in pendingChanges ? ' esml-lang--pending' : '' )
								}
							>
								<td><code>{ lang.code }</code></td>
								<td>{ lang.name }</td>
								<td>{ lang.native_name }</td>
								<td>
									<CheckboxControl
										checked={ lang.is_active }
										onChange={ () => handleToggleActive( lang.code, lang.is_active ) }
										disabled={ lang.is_default }
										label=""
									/>
								</td>
								<td>
									{ lang.is_default
										? <span className="esml-badge esml-badge--default">{ __( 'Default', 'eightshift-multilang' ) }</span>
										: (
											<Button
												variant="link"
												onClick={ () => handleSetDefault( lang.code ) }
											>
												{ __( 'Set default', 'eightshift-multilang' ) }
											</Button>
										)
									}
								</td>
								<td>
									{ ! lang.is_default && (
										<Button
											variant="link"
											isDestructive
											onClick={ () => handleRemove( lang.code ) }
										>
											{ __( 'Remove', 'eightshift-multilang' ) }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ /* Save button — shown whenever there are unsaved active/inactive changes. */ }
			<div className="esml-settings-tab__footer">
				<Button
					variant="primary"
					onClick={ handleSaveChanges }
					isBusy={ saving }
					disabled={ saving || ! hasPendingChanges }
				>
					{ __( 'Save settings', 'eightshift-multilang' ) }
				</Button>
				{ hasPendingChanges && (
					<span className="esml-settings-tab__unsaved-hint">
						{ __( 'You have unsaved changes.', 'eightshift-multilang' ) }
					</span>
				) }
			</div>

			{ /* Add language form */ }
			<details className="esml-add-language">
				<summary>{ __( 'Add language', 'eightshift-multilang' ) }</summary>
				<div className="esml-add-language__form">
					<TextControl
						label={ __( 'Code (e.g. de)', 'eightshift-multilang' ) }
						value={ form.code }
						onChange={ ( v ) => setForm( { ...form, code: v.toLowerCase() } ) }
					/>
					<TextControl
						label={ __( 'Locale (e.g. de_DE)', 'eightshift-multilang' ) }
						value={ form.locale }
						onChange={ ( v ) => setForm( { ...form, locale: v } ) }
					/>
					<TextControl
						label={ __( 'English name (e.g. German)', 'eightshift-multilang' ) }
						value={ form.name }
						onChange={ ( v ) => setForm( { ...form, name: v } ) }
					/>
					<TextControl
						label={ __( 'Native name (e.g. Deutsch)', 'eightshift-multilang' ) }
						value={ form.native_name }
						onChange={ ( v ) => setForm( { ...form, native_name: v } ) }
					/>
					<TextControl
						label={ __( 'Flag code (optional, e.g. de)', 'eightshift-multilang' ) }
						value={ form.flag_code }
						onChange={ ( v ) => setForm( { ...form, flag_code: v.toLowerCase() } ) }
					/>
					<Button
						variant="primary"
						onClick={ handleAdd }
						isBusy={ adding }
						disabled={ adding || ! formValid }
					>
						{ __( 'Add language', 'eightshift-multilang' ) }
					</Button>
				</div>
			</details>
		</div>
	);
}
