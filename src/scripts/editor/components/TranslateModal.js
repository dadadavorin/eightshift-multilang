/**
 * AI Translation Modal.
 *
 * Shown when the user clicks "Add Translation" in the sidebar.
 * Lets them pick a target language and fires the translate REST endpoint.
 * On success, shows a link to edit the new post.
 */
import { useState } from '@wordpress/element';
import { Modal, Button, SelectControl, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const { i18n: I18N, activeLanguages, defaultLanguage } = window.esmEditor ?? {};

export default function TranslateModal( { postId, existingLanguageCodes, onClose, onSuccess } ) {
	// Build options: active languages that don't already have a translation.
	const available = ( activeLanguages ?? [] ).filter(
		( lang ) =>
			lang.code !== defaultLanguage &&
			! existingLanguageCodes.includes( lang.code ),
	);

	const [ targetLanguage, setTargetLanguage ] = useState( available[ 0 ]?.code ?? '' );
	const [ status, setStatus ] = useState( 'idle' ); // idle | translating | success | error
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ editUrl, setEditUrl ] = useState( '' );

	const options = available.map( ( lang ) => ( { label: lang.name, value: lang.code } ) );

	const handleTranslate = async () => {
		setStatus( 'translating' );
		setErrorMessage( '' );
		try {
			const res = await apiFetch( {
				path:   `/translations/${ postId }/translate`,
				method: 'POST',
				data:   { target_language: targetLanguage },
			} );
			setEditUrl( res.data.edit_url ?? '' );
			setStatus( 'success' );
			onSuccess?.();
		} catch ( err ) {
			setErrorMessage( err?.message ?? I18N?.translationError );
			setStatus( 'error' );
		}
	};

	return (
		<Modal
			title={ I18N?.addTranslation ?? 'Add Translation' }
			onRequestClose={ onClose }
			size="small"
		>
			{ status === 'idle' && (
				<>
					{ available.length === 0 ? (
						<p>{ 'All active languages already have translations.' }</p>
					) : (
						<>
							<SelectControl
								label={ I18N?.selectLanguage ?? 'Select target language' }
								value={ targetLanguage }
								options={ options }
								onChange={ setTargetLanguage }
							/>
							<div className="esml-modal__actions">
								<Button
									variant="primary"
									onClick={ handleTranslate }
									disabled={ ! targetLanguage }
								>
									{ I18N?.translate ?? 'Translate with AI' }
								</Button>
								<Button variant="tertiary" onClick={ onClose }>
									{ I18N?.cancel ?? 'Cancel' }
								</Button>
							</div>
						</>
					) }
				</>
			) }

			{ status === 'translating' && (
				<div className="esml-modal__busy">
					<Spinner />
					<p>{ I18N?.translating ?? 'Translating…' }</p>
					<p className="esml-modal__hint">
						{ 'This may take up to 30 seconds depending on content length.' }
					</p>
				</div>
			) }

			{ status === 'success' && (
				<>
					<Notice status="success" isDismissible={ false }>
						{ I18N?.translationDone ?? 'Translation created.' }
					</Notice>
					<div className="esml-modal__actions">
						{ editUrl && (
							<Button variant="primary" href={ editUrl } target="_blank" rel="noreferrer">
								{ I18N?.editPost ?? 'Edit' }
							</Button>
						) }
						<Button variant="tertiary" onClick={ onClose }>
							{ I18N?.cancel ?? 'Close' }
						</Button>
					</div>
				</>
			) }

			{ status === 'error' && (
				<>
					<Notice status="error" isDismissible={ false }>
						{ errorMessage }
					</Notice>
					<div className="esml-modal__actions">
						<Button variant="secondary" onClick={ () => setStatus( 'idle' ) }>
							{ 'Try again' }
						</Button>
						<Button variant="tertiary" onClick={ onClose }>
							{ I18N?.cancel ?? 'Cancel' }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}
