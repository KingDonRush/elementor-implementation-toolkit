export const createPortal = ( children, container ) => ( window.ReactDOM || window.wp.element ).createPortal( children, container );
