/**
 * Translation sidebar panel.
 *
 * Displayed inside the Gutenberg PluginSidebar. Fetches the translation group
 * for the current post, shows links to sibling translations with sync status,
 * and provides the "Add Translation" button that opens TranslateModal.
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	PanelBody,
	Button,
	Spinner,
	Notice,
	__experimentalText as Text,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import TranslateModal from './TranslateModal';

const { i18n: I18N } = window.esmEditor ?? {};

export default function TranslationSidebar() {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[],
	);

	const [ group, setGroup ]         = useState( null );   // null = loading
	const [ syncMap, setSyncMap ]     = useState( {} );     // postId → bool (out of sync)
	const [ showModal, setShowModal ] = useState( false );
	const [ error, setError ]         = useState( '' );

	const load = useCallback( () => {
		if ( ! postId ) { return; }
		setGroup( null );

		apiFetch( { path: `/eightshift-multilang/v1/translations/${ postId }` } )
			.then( async ( res ) => {
				setGroup( res.data );

				// Fetch sync status for each non-source translation link.
				const links = res.data.links ?? [];
				const syncs = {};

				await Promise.all(
					links
						.filter( ( l ) => ! l.is_source )
						.map( ( l ) =>
							apiFetch( { path: `/eightshift-multilang/v1/translations/${ l.post_id }/sync-status` } )
								.then( ( sr ) => { syncs[ l.post_id ] = sr.data.out_of_sync; } )
								.catch( () => {} )
						),
				);

				setSyncMap( syncs );
			} )
			.catch( ( err ) => {
				setError( err?.message ?? 'Failed to load translations.' );
				setGroup( { group_id: null, links: [] } );
			} );
	}, [ postId ] );

	useEffect( () => { load(); }, [ load ] );

	if ( ! postId ) {
		return null;
	}

	const existingLanguageCodes = ( group?.links ?? [] ).map( ( l ) => l.language_code );

	return (
		<PanelBody title={ I18N?.sidebarTitle ?? 'Translations' } initialOpen={ true }>
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ group === null && <Spinner /> }

			{ group !== null && group.group_id === null && (
				<Text className="esml-sidebar__empty">
					{ I18N?.noGroup ?? 'This post is not part of a translation group.' }
				</Text>
			) }

			{ group !== null && group.links.length > 0 && (
				<table className="esml-sidebar__links">
					<tbody>
						{ group.links.map( ( link ) => (
							<tr key={ link.post_id }>
								<td>
									<code>{ link.language_code }</code>
									{ link.is_source && (
										<span className="esml-badge esml-badge--source">
											{ I18N?.source ?? 'Source' }
										</span>
									) }
								</td>
								<td>
									{ ! link.is_source && (
										<span
											className={ `esml-sync-dot esml-sync-dot--${ syncMap[ link.post_id ] ? 'stale' : 'ok' }` }
											title={
												syncMap[ link.post_id ]
													? ( I18N?.outOfSync ?? 'Out of sync' )
													: ( I18N?.inSync ?? 'In sync' )
											}
										/>
									) }
								</td>
								<td>
									{ link.post_id !== postId && (
										<Button
											variant="link"
											href={ `${ window.location.origin }/wp-admin/post.php?post=${ link.post_id }&action=edit` }
											target="_blank"
											rel="noreferrer"
										>
											{ I18N?.editPost ?? 'Edit' }
										</Button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<Button
				variant="secondary"
				className="esml-sidebar__add-btn"
				onClick={ () => setShowModal( true ) }
			>
				{ I18N?.addTranslation ?? 'Add Translation' }
			</Button>

			{ showModal && (
				<TranslateModal
					postId={ postId }
					existingLanguageCodes={ existingLanguageCodes }
					onClose={ () => setShowModal( false ) }
					onSuccess={ () => {
						setShowModal( false );
						load();
					} }
				/>
			) }
		</PanelBody>
	);
}
