import { $, i18n } from './runtime.js';
import { cssEscape } from './utils.js';

export function installFacets( Controller ) {
	Object.assign( Controller.prototype, {
		renderFacets( facets = [] ) {
			facets.forEach( ( facet ) => {
				const fieldId = String( facet.field_id || '' );
				const filter = this.filters.find( ( candidate ) => candidate.key === fieldId );
				const $group = this.$root.find( `[data-eit-filter-group="${ cssEscape( filter?.id || '' ) }"]` );
				if ( ! filter || ! $group.length ) return;
				if ( 'select' === filter.type ) this.renderSelectFacet( $group, facet.values || [] );
				else this.renderChoiceFacet( $group, filter, facet.values || [] );
			} );
			this.updateOptionStates();
		},

		renderSelectFacet( $group, values ) {
			const $select = $group.find( 'select[data-eit-control]' );
			values.forEach( ( item ) => {
				let $option = $select.find( `option[value="${ cssEscape( String( item.value ) ) }"]` );
				if ( ! $option.length ) {
					$option = $( '<option/>' ).val( item.value ).appendTo( $select );
				}
				const selected = $option.prop( 'selected' );
				$option
					.text( `${ item.label } (${ Number( item.count ) || 0 })` )
					.prop( 'disabled', ! item.available && ! selected );
			} );
			$select.closest( '[data-eit-select-field]' ).removeClass( 'eit-select-field--empty-options' );
		},

		renderChoiceFacet( $group, filter, values ) {
			const $options = $group.find( '[data-eit-options]' );
			const $scope = $options.length ? $options : $group;
			values.forEach( ( item ) => {
				let $input = $scope.find( `[data-eit-control][value="${ cssEscape( String( item.value ) ) }"]` );
				if ( ! $input.length && 'toggle' !== filter.type ) {
					$input = this.appendFacetChoice( $options, filter, item );
				}
				if ( ! $input.length ) return;
				const selected = $input.prop( 'checked' );
				$input.prop( 'disabled', ! item.available && ! selected );
				const $option = $input.closest( '.eit-option' );
				let $count = $option.find( '.eit-option-count' );
				if ( ! $count.length ) $count = $( '<span class="eit-option-count"/>' ).appendTo( $option );
				$count
					.text( Number( item.count ) || 0 )
					.attr( 'aria-label', `${ Number( item.count ) || 0 } ${ i18n.items || 'items' }` );
			} );
			$scope.find( '[data-eit-options-empty]' ).remove();
		},

		appendFacetChoice( $options, filter, item ) {
			const $option = $( '<label/>', { class: `eit-option eit-option--${ filter.type } eit-option--has-count` } );
			const $input = $( '<input/>', {
				type: 'checkbox',
				name: `eit-${ this.instance }-${ filter.id }[]`,
				value: item.value,
				'data-eit-control': '',
				'data-eit-type': filter.type,
				'data-eit-key': filter.key,
			} ).appendTo( $option );
			if ( 'checkbox' === filter.type ) $( '<span class="eit-checkbox-indicator" aria-hidden="true"/>' ).appendTo( $option );
			$( '<span class="eit-option__label"/>' ).text( item.label ).appendTo( $option );
			$option.appendTo( $options );
			return $input;
		},
	} );
}
