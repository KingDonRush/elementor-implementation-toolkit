const apiFetch = window.wp.apiFetch;
const root = window.eitSystemsConfig.restRoot;

export const api = {
	list: () => apiFetch( { path: `${ root }/blueprints` } ),
	schema: () => apiFetch( { path: `${ root }/blueprint-schema` } ),
	get: ( id ) => apiFetch( { path: `${ root }/blueprints/${ encodeURIComponent( id ) }` } ),
	save: ( document ) => apiFetch( { path: `${ root }/blueprints/${ encodeURIComponent( document.id ) }`, method: 'PUT', data: { document } } ),
	create: ( document ) => apiFetch( { path: `${ root }/blueprints`, method: 'POST', data: { document } } ),
	remove: ( id ) => apiFetch( { path: `${ root }/blueprints/${ encodeURIComponent( id ) }`, method: 'DELETE' } ),
	validate: ( id ) => apiFetch( { path: `${ root }/blueprints/${ encodeURIComponent( id ) }/validate`, method: 'POST' } ),
	impact: ( id ) => apiFetch( { path: `${ root }/blueprints/${ encodeURIComponent( id ) }/impact`, method: 'POST' } ),
	apply: ( changeSet ) => apiFetch( { path: `${ root }/change-sets/${ encodeURIComponent( changeSet.id ) }/apply`, method: 'POST', data: { confirmation_token: changeSet.confirmation_token } } ),
	reconcile: ( changeSetId ) => apiFetch( { path: `${ root }/change-sets/${ encodeURIComponent( changeSetId ) }/reconcile`, method: 'POST' } ),
	rollback: ( blueprintId, versionId, reason ) => apiFetch( { path: `${ root }/blueprints/${ encodeURIComponent( blueprintId ) }/rollback`, method: 'POST', data: { version_id: versionId, reason } } ),
};

export function errorMessage( error ) {
	return error?.message || 'The Toolkit could not complete this request.';
}
