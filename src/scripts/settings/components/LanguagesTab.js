/**
 * Languages settings tab.
 *
 * Lists all languages with controls to activate/deactivate, set as default,
 * remove, and reorder. Also provides an "Add language" form.
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
	const [ languages, setLanguages ] = useState( null );
	const [ form, setForm ]           = useState( EMPTY_FORM );
	const [ adding, setAdding ]       = useState( false );
	const [ notice, setNotice ]       = useState( null );

	const load = useCallback( () => {
		apiFetch( { path: '/languages' } )
			.then( ( res ) => setLanguages( res.data ) )
			.catch( () =>
				setNotice( { type: 'error', message: __( 'Failed to load languages.', 'eightshift-multilang' ) } )
			);
	}, [] );

	useEffect( () => { load(); }, [ load ] );

	// ---------------------------------------------------------------------------

	const handleAdd = async () => {
		setAdding( true );
		setNotice( null );
		try {
			await apiFetch( { path: '/languages', method: 'POST', data: form } );
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
			await apiFetch( { path: `/languages/${ code }/default`, method: 'PUT' } );
			load();
		} catch ( err ) {
			setNotice( { type: 'error', message: err?.message ?? __( 'Failed to set default.', 'eightshift-multilang' ) } );
		}
	};

	const handleToggleActive = async ( code, currentlyActive ) => {
		setNotice( null );
		try {
			await apiFetch( {
				path:   `/languages/${ code }/status`,
				method: 'PUT',
				data:   { active: ! currentlyActive },
			} );
			load();
		} catch ( err ) {
			setNotice( { type: 'error', message: err?.message ?? __( 'Failed to update status.', 'eightshift-multilang' ) } );
		}
	};

	const handleRemove = async ( code ) => {
		if ( ! window.confirm( __( 'Remove this language? This cannot be undone.', 'eightshift-multilang' ) ) ) {
			return;
		}
		setNotice( null );
		try {
			await apiFetch( { path: `/languages/${ code }`, method: 'DELETE' } );
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
							<tr key={ lang.code } className={ lang.is_default ? 'esml-lang--default' : '' }>
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
