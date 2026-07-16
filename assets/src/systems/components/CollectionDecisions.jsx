import { SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { entityForCollection, queryFields } from '../collection-contracts';

export default function CollectionDecisions( { node, document, schema, update } ) {
	const config = node.config || {};
	const entity = entityForCollection( document, node );
	const sortFields = queryFields( document, node, 'sort', schema );
	const inferredAccess = entity?.config?.public ? 'public' : 'authenticated';
	const sortValue = config.default_sort?.field_id
		? `${ config.default_sort.field_id }:${ config.default_sort.direction || 'asc' }`
		: '';
	const change = ( decisions ) => update( { ...node, config: { ...config, ...decisions } } );
	const sortOptions = [ { label: __( 'Use source order', 'elementor-implementation-toolkit' ), value: '' } ];
	for ( const field of sortFields ) {
		sortOptions.push(
			{ label: sprintf( __( '%s — ascending', 'elementor-implementation-toolkit' ), field.name ), value: `${ field.id }:asc` },
			{ label: sprintf( __( '%s — descending', 'elementor-implementation-toolkit' ), field.name ), value: `${ field.id }:desc` },
		);
	}
	return (
		<div className="eit-decision-stack">
			<SelectControl
				label={ __( 'Audience', 'elementor-implementation-toolkit' ) }
				help={ __( 'Public collections expose only fields explicitly marked public. Signed-in collections also enforce the connected Policy.', 'elementor-implementation-toolkit' ) }
				value={ config.access || inferredAccess }
				options={ [
					{ label: __( 'Public visitors', 'elementor-implementation-toolkit' ), value: 'public' },
					{ label: __( 'Signed-in users', 'elementor-implementation-toolkit' ), value: 'authenticated' },
				] }
				onChange={ ( access ) => change( { access } ) }
			/>
			<TextControl
				type="number"
				min="1"
				max="48"
				label={ __( 'Items per page', 'elementor-implementation-toolkit' ) }
				help={ __( 'The runtime caps every request at 48 items.', 'elementor-implementation-toolkit' ) }
				value={ config.page_size || 24 }
				onChange={ ( value ) => change( { page_size: Math.min( 48, Math.max( 1, Number( value ) || 24 ) ) } ) }
			/>
			<SelectControl
				label={ __( 'Default order', 'elementor-implementation-toolkit' ) }
				help={ sortFields.length ? __( 'Only fields with a published sort capability appear.', 'elementor-implementation-toolkit' ) : __( 'Connect a sortable Field before choosing an order.', 'elementor-implementation-toolkit' ) }
				value={ sortValue }
				options={ sortOptions }
				onChange={ ( value ) => {
					const [ fieldId = '', direction = 'asc' ] = value.split( ':' );
					change( { default_sort: { field_id: fieldId, direction } } );
				} }
			/>
			<ToggleControl
				label={ __( 'Cache repeated queries', 'elementor-implementation-toolkit' ) }
				help={ __( 'Content and Blueprint changes invalidate the cache automatically.', 'elementor-implementation-toolkit' ) }
				checked={ false !== config.cache?.enabled }
				onChange={ ( enabled ) => change( { cache: { ...config.cache, enabled, ttl_seconds: config.cache?.ttl_seconds || 300 } } ) }
			/>
			<ToggleControl
				label={ __( 'Make Explain Why available', 'elementor-implementation-toolkit' ) }
				help={ __( 'Diagnostics can show each applied comparison without exposing query keys.', 'elementor-implementation-toolkit' ) }
				checked={ false !== config.explain }
				onChange={ ( explain ) => change( { explain } ) }
			/>
		</div>
	);
}
