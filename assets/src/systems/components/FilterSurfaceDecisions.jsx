import { CheckboxControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { queryFields, selectedFacetIds, selectedFilterIds, toggleRequiredSelection } from '../collection-contracts';

const FACET_TYPES = [ 'boolean', 'single_choice', 'multiple_choice', 'taxonomy', 'relation' ];

export default function FilterSurfaceDecisions( { node, document, update } ) {
	const config = node.config || {};
	const fields = queryFields( document, node, 'filter' );
	const selected = selectedFilterIds( node, fields );
	const facets = selectedFacetIds( node, fields, selected );
	const change = ( decisions ) => update( { ...node, config: { ...config, ...decisions } } );
	if ( ! fields.length ) {
		return <p>{ __( 'Connect this Filter Surface to a Collection whose Entity has filterable Fields.', 'elementor-implementation-toolkit' ) }</p>;
	}
	return (
		<div className="eit-decision-stack">
		<fieldset className="eit-decision-group">
			<legend>{ __( 'Available filters', 'elementor-implementation-toolkit' ) }</legend>
			<p>{ __( 'Control type and operators are derived from each Field. Keep at least one filter.', 'elementor-implementation-toolkit' ) }</p>
			{ fields.map( ( field ) => (
				<CheckboxControl
					key={ field.id }
					label={ field.name }
					checked={ selected.includes( field.id ) }
					onChange={ ( checked ) => {
						const next = toggleRequiredSelection( selected, field.id, checked );
						change( { fields: next, facet_fields: facets.filter( ( id ) => next.includes( id ) ) } );
					} }
				/>
			) ) }
		</fieldset>
		<fieldset className="eit-decision-group">
			<legend>{ __( 'Facet counts', 'elementor-implementation-toolkit' ) }</legend>
			<p>{ __( 'Facet options remain visible with zero counts when another filter makes them unavailable.', 'elementor-implementation-toolkit' ) }</p>
			{ fields.filter( ( field ) => selected.includes( field.id ) && FACET_TYPES.includes( field.type ) ).map( ( field ) => (
				<CheckboxControl
					key={ field.id }
					label={ field.name }
					checked={ facets.includes( field.id ) }
					onChange={ ( checked ) => change( { facet_fields: checked ? [ ...new Set( [ ...facets, field.id ] ) ] : facets.filter( ( id ) => id !== field.id ) } ) }
				/>
			) ) }
		</fieldset>
		<ToggleControl
			label={ __( 'Keep filter state in the URL', 'elementor-implementation-toolkit' ) }
			checked={ false !== config.url_state }
			onChange={ ( urlState ) => change( { url_state: urlState } ) }
		/>
		<ToggleControl
			label={ __( 'Show readable active-filter chips', 'elementor-implementation-toolkit' ) }
			checked={ false !== config.active_chips }
			onChange={ ( activeChips ) => change( { active_chips: activeChips } ) }
		/>
		</div>
	);
}
