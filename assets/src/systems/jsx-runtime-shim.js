const { createElement, Fragment } = window.wp.element;

function render( type, props, key ) {
	return createElement( type, null == key ? props : { ...props, key } );
}

export { Fragment };
export const jsx = render;
export const jsxs = render;
