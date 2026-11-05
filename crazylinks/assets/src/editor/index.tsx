/**
 * CrazyLinks page editor entry point (mounted by src/Admin/EditorPage.php).
 */
import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { dispatch } from '@wordpress/data';
import App from './components/App';
import { registerStore, store } from './store';
import { boot } from './boot';
import { coerceDoc } from './store/doc';
import { metaFrom } from './lib/save';
import './style.scss';

domReady( () => {
	const root = document.getElementById( 'crazylinks-editor' );
	if ( ! root ) {
		return;
	}
	const data = boot();
	registerStore();
	const d = dispatch( store );
	d.reset( coerceDoc( data.post.page ), metaFrom( data.post ) );
	d.addMedia( Object.values( data.media ) );
	document.body.classList.add( 'crazylinks-editor-active' );
	createRoot( root ).render( <App /> );
} );
