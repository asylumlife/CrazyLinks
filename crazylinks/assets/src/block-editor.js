/* Editor UI for the crazylinks/page block. No build step: uses WordPress globals. */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	wp.blocks.registerBlockType( 'crazylinks/page', {
		edit: function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps();
			var state = useState( [] );
			var pages = state[ 0 ];
			var setPages = state[ 1 ];
			var setId = function ( value ) {
				props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
			};

			useEffect( function () {
				wp.apiFetch( { path: '/crazylinks/v1/pages?per_page=100&status=publish' } ).then( function ( res ) {
					setPages( res || [] );
				} ).catch( function () {
					setPages( [] );
				} );
			}, [] );

			var options = [ { value: 0, label: __( 'Select a link page', 'crazylinks' ) } ].concat(
				pages.map( function ( p ) {
					return { value: p.id, label: p.title || p.slug };
				} )
			);

			return el(
				'div',
				blockProps,
				el(
					wp.blockEditor.InspectorControls,
					null,
					el(
						wp.components.PanelBody,
						{ title: __( 'Link page', 'crazylinks' ) },
						el( wp.components.SelectControl, {
							label: __( 'Page', 'crazylinks' ),
							value: props.attributes.id,
							options: options,
							onChange: setId,
						} )
					)
				),
				props.attributes.id
					? el( wp.serverSideRender, { block: 'crazylinks/page', attributes: props.attributes } )
					: el( wp.components.Placeholder, { icon: 'admin-links', label: __( 'CrazyLinks Page', 'crazylinks' ), instructions: __( 'Choose a link page in the block settings.', 'crazylinks' ) },
						el( wp.components.SelectControl, {
							value: 0,
							options: options,
							onChange: setId,
						} )
					)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
