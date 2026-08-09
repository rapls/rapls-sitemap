/*
 * Editor script for the `rapls/sitemap` block.
 *
 * Deliberately written against the `wp.*` globals with no JSX and no bundler —
 * the rapls-* family ships no build toolchain, and a server-rendered block only
 * needs a sidebar plus a ServerSideRender preview. Keep it that way: if this
 * file ever needs a compiler, the block is doing too much.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var components = wp.components;

	// Mirrors SettingsPage::designs(). Adding a preset means touching both.
	var DESIGNS = [
		{ value: '', label: __( 'Use the site default', 'rapls-sitemap' ) },
		{ value: 'none', label: __( 'No style', 'rapls-sitemap' ) },
		{ value: 'simple', label: __( 'Simple', 'rapls-sitemap' ) },
		{ value: 'list', label: __( 'Bulleted list', 'rapls-sitemap' ) },
		{ value: 'compact', label: __( 'Compact', 'rapls-sitemap' ) },
		{ value: 'tree', label: __( 'Document tree', 'rapls-sitemap' ) },
		{ value: 'index', label: __( 'Index', 'rapls-sitemap' ) },
		{ value: 'table', label: __( 'Ruled rows', 'rapls-sitemap' ) },
		{ value: 'columns', label: __( 'Newspaper columns', 'rapls-sitemap' ) },
		{ value: 'outline', label: __( 'Outlined boxes', 'rapls-sitemap' ) },
		{ value: 'numbered', label: __( 'Numbered', 'rapls-sitemap' ) },
		{ value: 'card', label: __( 'Cards', 'rapls-sitemap' ) },
		{ value: 'business', label: __( 'Business', 'rapls-sitemap' ) },
		{ value: 'panel', label: __( 'Panels', 'rapls-sitemap' ) },
		{ value: 'timeline', label: __( 'Timeline', 'rapls-sitemap' ) },
		{ value: 'accordion', label: __( 'Accordion', 'rapls-sitemap' ) },
		{ value: 'grid', label: __( 'Grid', 'rapls-sitemap' ) },
		{ value: 'underline', label: __( 'Underlined headings', 'rapls-sitemap' ) },
		{ value: 'marker', label: __( 'Highlighter', 'rapls-sitemap' ) },
		{ value: 'checklist', label: __( 'Checklist', 'rapls-sitemap' ) },
		{ value: 'label', label: __( 'Sticky notes', 'rapls-sitemap' ) },
		{ value: 'arrow', label: __( 'Arrows', 'rapls-sitemap' ) },
		{ value: 'dots', label: __( 'Dots', 'rapls-sitemap' ) },
		{ value: 'pill', label: __( 'Pills', 'rapls-sitemap' ) },
		{ value: 'ribbon', label: __( 'Ribbons', 'rapls-sitemap' ) },
		{ value: 'magazine', label: __( 'Magazine', 'rapls-sitemap' ) },
		{ value: 'book', label: __( 'Book contents', 'rapls-sitemap' ) },
		{ value: 'neon', label: __( 'Neon', 'rapls-sitemap' ) },
		{ value: 'terminal', label: __( 'Terminal', 'rapls-sitemap' ) },
	];

	var TERM_MODES = [
		{ value: '', label: __( 'Use the site default', 'rapls-sitemap' ) },
		{ value: 'posts', label: __( 'Posts under each category', 'rapls-sitemap' ) },
		{ value: 'terms_only', label: __( 'Categories only', 'rapls-sitemap' ) },
	];

	wp.blocks.registerBlockType( 'rapls/sitemap', {
		title: __( 'Sitemap', 'rapls-sitemap' ),
		description: __( 'A table of contents for the whole site.', 'rapls-sitemap' ),
		category: 'widgets',
		icon: 'networking',
		supports: { html: false, align: [ 'wide', 'full' ] },

		edit: function ( props ) {
			var atts = props.attributes;
			var set = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Sitemap', 'rapls-sitemap' ) },
						el( components.TextControl, {
							label: __( 'Post types', 'rapls-sitemap' ),
							help: __( 'Comma separated slugs. Empty uses the site default.', 'rapls-sitemap' ),
							value: atts.postTypes,
							onChange: function ( value ) {
								set( { postTypes: value } );
							},
						} ),
						el( components.RangeControl, {
							label: __( 'Maximum depth', 'rapls-sitemap' ),
							help: __( '-1 uses the site default, 0 shows every level.', 'rapls-sitemap' ),
							min: -1,
							max: 10,
							value: atts.depth,
							onChange: function ( value ) {
								set( { depth: value } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'Design', 'rapls-sitemap' ),
							value: atts.design,
							options: DESIGNS,
							onChange: function ( value ) {
								set( { design: value } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the home link', 'rapls-sitemap' ),
							checked: atts.showHome,
							onChange: function ( value ) {
								set( { showHome: value } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Group posts by category', 'rapls-sitemap' ),
							checked: atts.groupByTerm,
							onChange: function ( value ) {
								set( { groupByTerm: value } );
							},
						} ),
						el( components.ToggleControl, {
							label: __( 'Nest child categories', 'rapls-sitemap' ),
							checked: atts.nestTerms,
							onChange: function ( value ) {
								set( { nestTerms: value } );
							},
						} ),
						el( components.SelectControl, {
							label: __( 'Category display', 'rapls-sitemap' ),
							value: atts.termMode,
							options: TERM_MODES,
							onChange: function ( value ) {
								set( { termMode: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'rapls/sitemap',
					attributes: atts,
				} )
			);
		},

		// Server-rendered: nothing is stored in post content but the comment.
		save: function () {
			return null;
		},
	} );
} )( window.wp );
