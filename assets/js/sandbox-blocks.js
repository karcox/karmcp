/**
 * KarMCP Sandbox — editor registration for generated blocks (no build step).
 *
 * Every generated block is server-rendered, so there is nothing to compile per
 * block: this one script registers them all from the descriptors PHP localizes
 * in window.karmcpSandboxBlocks, gives each a ServerSideRender preview of the
 * same PHP the front end runs, and builds its inspector panel from the control
 * list the block's spec declared.
 *
 * save() returns null — the markup lives in the render file, not in post
 * content, so editing a block's spec updates every place it is used.
 */
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.serverSideRender ) {
		return;
	}

	var blocks = wp.blocks;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var be = wp.blockEditor || wp.editor;
	var comp = wp.components;
	var SSR = wp.serverSideRender;
	var data = window.karmcpSandboxBlocks || { blocks: {}, category: 'karmcp-custom' };

	var InspectorControls = be.InspectorControls;
	var MediaUpload = be.MediaUpload;
	var MediaUploadCheck = be.MediaUploadCheck;
	var useBlockProps = be.useBlockProps;

	var PanelBody = comp.PanelBody;
	var TextControl = comp.TextControl;
	var TextareaControl = comp.TextareaControl;
	var SelectControl = comp.SelectControl;
	var ToggleControl = comp.ToggleControl;
	var Button = comp.Button;

	function __( text ) {
		return wp.i18n && wp.i18n.__ ? wp.i18n.__( text, 'karmcp' ) : text;
	}

	/** Builds one inspector control from its descriptor. */
	function renderControl( def, props ) {
		var name = def.name;
		var value = props.attributes[ name ];

		function set( next ) {
			var patch = {};
			patch[ name ] = next;
			props.setAttributes( patch );
		}

		switch ( def.control ) {
			case 'textarea':
				return el( TextareaControl, {
					key: name,
					label: def.label,
					value: value || '',
					onChange: set,
				} );

			case 'number':
				return el( TextControl, {
					key: name,
					type: 'number',
					label: def.label,
					value: typeof value === 'number' ? value : 0,
					onChange: function ( next ) {
						var parsed = parseFloat( next );
						set( isNaN( parsed ) ? 0 : parsed );
					},
				} );

			case 'select':
				return el( SelectControl, {
					key: name,
					label: def.label,
					value: value || '',
					options: ( def.options || [] ).map( function ( option ) {
						return { value: option.value, label: option.label };
					} ),
					onChange: set,
				} );

			case 'toggle':
				return el( ToggleControl, {
					key: name,
					label: def.label,
					checked: !! value,
					onChange: set,
				} );

			case 'color':
				return el( TextControl, {
					key: name,
					label: def.label,
					value: value || '',
					placeholder: '#112233',
					onChange: set,
				} );

			case 'url':
				return el( TextControl, {
					key: name,
					type: 'url',
					label: def.label,
					value: value || '',
					placeholder: 'https://',
					onChange: set,
				} );

			case 'image':
				var image = value && typeof value === 'object' ? value : {};
				return el(
					'div',
					{ key: name, className: 'karmcp-sb-image-control', style: { marginBottom: '16px' } },
					el( 'p', { style: { marginBottom: '4px' } }, el( 'strong', {}, def.label ) ),
					image.url
						? el( 'img', {
							src: image.url,
							alt: '',
							style: { maxWidth: '100%', height: 'auto', display: 'block', marginBottom: '8px' },
						} )
						: null,
					el(
						MediaUploadCheck,
						{},
						el( MediaUpload, {
							allowedTypes: [ 'image' ],
							value: image.id,
							onSelect: function ( media ) {
								set( { id: media.id, url: media.url } );
							},
							render: function ( open ) {
								return el(
									Fragment,
									{},
									el(
										Button,
										{ variant: 'secondary', onClick: open.open },
										image.url ? __( 'Replace image' ) : __( 'Select image' )
									),
									image.url
										? el(
											Button,
											{
												variant: 'tertiary',
												isDestructive: true,
												onClick: function () {
													set( {} );
												},
											},
											__( 'Remove' )
										)
										: null
								);
							},
						} )
					)
				);

			default:
				return el( TextControl, {
					key: name,
					label: def.label,
					value: value || '',
					onChange: set,
				} );
		}
	}

	Object.keys( data.blocks || {} ).forEach( function ( name ) {
		if ( blocks.getBlockType( name ) ) {
			return;
		}

		var def = data.blocks[ name ];

		blocks.registerBlockType( name, {
			apiVersion: 3,
			title: def.title || name,
			icon: def.icon || 'block-default',
			category: def.category || data.category,
			attributes: def.attributes || {},
			supports: { html: false, anchor: true, align: [ 'wide', 'full' ] },

			edit: function ( props ) {
				var blockProps = useBlockProps ? useBlockProps() : {};
				var controls = ( def.controls || [] ).map( function ( control ) {
					return renderControl( control, props );
				} );

				return el(
					Fragment,
					{},
					controls.length
						? el(
							InspectorControls,
							{},
							el( PanelBody, { title: def.title || __( 'Settings' ), initialOpen: true }, controls )
						)
						: null,
					el(
						'div',
						blockProps,
						el( SSR, {
							block: name,
							attributes: props.attributes,
							httpMethod: 'POST',
						} )
					)
				);
			},

			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );
