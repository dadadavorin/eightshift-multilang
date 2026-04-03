/**
 * Root settings application component.
 *
 * Renders tab navigation (General / AI / Languages) and delegates
 * to per-tab components. Each tab manages its own REST calls.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import GeneralTab from './GeneralTab';
import AITab from './AITab';
import LanguagesTab from './LanguagesTab';

const TABS = [
	{ id: 'general',   label: __( 'General',   'eightshift-multilang' ) },
	{ id: 'ai',        label: __( 'AI',         'eightshift-multilang' ) },
	{ id: 'languages', label: __( 'Languages',  'eightshift-multilang' ) },
];

export default function SettingsApp() {
	const [ activeTab, setActiveTab ] = useState( 'general' );

	return (
		<div className="esml-settings">
			<h1 className="esml-settings__title">
				{ __( 'Eightshift Multilang Settings', 'eightshift-multilang' ) }
			</h1>

			<nav className="nav-tab-wrapper esml-settings__tabs">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.id }
						className={ `nav-tab${ activeTab === tab.id ? ' nav-tab-active' : '' }` }
						onClick={ () => setActiveTab( tab.id ) }
						type="button"
					>
						{ tab.label }
					</button>
				) ) }
			</nav>

			<div className="esml-settings__panel">
				{ activeTab === 'general'   && <GeneralTab /> }
				{ activeTab === 'ai'        && <AITab /> }
				{ activeTab === 'languages' && <LanguagesTab /> }
			</div>
		</div>
	);
}
