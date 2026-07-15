import './store';
import App from './components/App';

const apiFetch = window.wp.apiFetch;
apiFetch.use( apiFetch.createNonceMiddleware( window.eitSystemsConfig.nonce ) );

const root = document.getElementById( 'eit-systems-app' );
if ( root ) {
	window.wp.element.createRoot( root ).render( <App /> );
}
