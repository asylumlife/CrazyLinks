/* CrazyLinks admin: Analytics range picker. Plain JS, no dependencies. */
( function () {
	'use strict';
	var form = document.querySelector( '.cl-analytics__filters' );
	if ( ! form ) {
		return;
	}
	var range = form.querySelector( '[name="range"]' );
	var custom = form.querySelectorAll( '[data-cl-custom]' );
	var page = form.querySelector( '[name="page_id"]' );

	function sync() {
		var on = range && 'custom' === range.value;
		custom.forEach( function ( el ) {
			el.hidden = ! on;
		} );
	}
	sync();
	if ( range ) {
		range.addEventListener( 'change', function () {
			sync();
			if ( 'custom' !== range.value ) {
				form.submit();
			}
		} );
	}
	if ( page ) {
		page.addEventListener( 'change', function () {
			form.submit();
		} );
	}
} )();
