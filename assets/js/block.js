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

	// Every list below leads with an empty value meaning "inherit the site
	// setting", so a block dropped on a page changes nothing until it is asked
	// to. These mirror Settings::SOURCES, ::ORDERBY and ::LIST_TYPES.
	var SOURCES = [
		{ value: '', label: __( 'Use the site default', 'rapls-sitemap' ) },
		{ value: 'content', label: __( 'Posts and pages', 'rapls-sitemap' ) },
		{ value: 'authors', label: __( 'Authors', 'rapls-sitemap' ) },
		{ value: 'archives', label: __( 'Monthly archives', 'rapls-sitemap' ) },
	];

	var ORDERBY = [
		{ value: '', label: __( 'Use the site default', 'rapls-sitemap' ) },
		{ value: 'default', label: __( 'Whatever suits each list', 'rapls-sitemap' ) },
		{ value: 'date', label: __( 'Published date', 'rapls-sitemap' ) },
		{ value: 'modified', label: __( 'Last modified', 'rapls-sitemap' ) },
		{ value: 'title', label: __( 'Title', 'rapls-sitemap' ) },
		{ value: 'menu_order', label: __( 'Page order', 'rapls-sitemap' ) },
		{ value: 'ID', label: __( 'ID', 'rapls-sitemap' ) },
		{ value: 'comment_count', label: __( 'Comment count', 'rapls-sitemap' ) },
		{ value: 'rand', label: __( 'Random', 'rapls-sitemap' ) },
	];

	var ORDER = [
		{ value: '', label: __( 'Use the site default', 'rapls-sitemap' ) },
		{ value: 'DESC', label: __( 'Descending (newest, Z to A)', 'rapls-sitemap' ) },
		{ value: 'ASC', label: __( 'Ascending (oldest, A to Z)', 'rapls-sitemap' ) },
	];

	var LIST_TYPES = [
		{ value: '', label: __( 'Use the site default', 'rapls-sitemap' ) },
		{ value: 'ul', label: __( 'Unordered (ul)', 'rapls-sitemap' ) },
		{ value: 'ol', label: __( 'Ordered (ol)', 'rapls-sitemap' ) },
	];

	/*
	 * Only `edit` and `save` are declared here.
	 *
	 * The name, title, description, category, icon, supports and — importantly
	 * — the attributes all come from blocks/sitemap/block.json. WordPress
	 * hands them to the editor itself: register_block_type_from_metadata()
	 * prints the server-side block definition ahead of this script, so the
	 * editor already knows the block's shape by the time this runs.
	 *
	 * Repeating any of it here would create a second copy that wins over the
	 * JSON, which is how an icon changes in block.json and nothing happens.
	 */
	wp.blocks.registerBlockType( 'rapls/sitemap', {
		edit: function ( props ) {
			var atts = props.attributes;
			var set = props.setAttributes;

			/** A select whose empty value means "inherit the site setting". */
			function select( label, key, options ) {
				return el( components.SelectControl, {
					label: label,
					value: atts[ key ],
					options: options,
					onChange: function ( value ) {
						set( pair( key, value ) );
					},
				} );
			}

			function toggle( label, key, help ) {
				return el( components.ToggleControl, {
					label: label,
					help: help,
					checked: atts[ key ],
					onChange: function ( value ) {
						set( pair( key, value ) );
					},
				} );
			}

			/** A range where a negative value means "inherit". */
			function range( label, key, min, max, help ) {
				return el( components.RangeControl, {
					label: label,
					help: help,
					min: min,
					max: max,
					value: atts[ key ],
					onChange: function ( value ) {
						set( pair( key, value ) );
					},
				} );
			}

			// Object literals cannot take a computed key in the ES5 this file
			// is deliberately written in.
			function pair( key, value ) {
				var out = {};
				out[ key ] = value;
				return out;
			}

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Content', 'rapls-sitemap' ) },
						select( __( 'What to list', 'rapls-sitemap' ), 'source', SOURCES ),
						el( components.TextControl, {
							label: __( 'Post types', 'rapls-sitemap' ),
							help: __( 'Comma separated slugs. Empty uses the site default.', 'rapls-sitemap' ),
							value: atts.postTypes,
							onChange: function ( value ) {
								set( { postTypes: value } );
							},
						} ),
						el( components.TextControl, {
							label: __( 'Group by taxonomy', 'rapls-sitemap' ),
							help: __( 'A taxonomy slug such as post_tag. Empty picks one automatically.', 'rapls-sitemap' ),
							value: atts.taxonomy,
							onChange: function ( value ) {
								set( { taxonomy: value } );
							},
						} ),
						select( __( 'Category display', 'rapls-sitemap' ), 'termMode', TERM_MODES ),
						toggle( __( 'Show the home link', 'rapls-sitemap' ), 'showHome' ),
						toggle( __( 'Group posts by category', 'rapls-sitemap' ), 'groupByTerm' ),
						toggle( __( 'Nest child categories', 'rapls-sitemap' ), 'nestTerms' )
					),
					el(
						components.PanelBody,
						{ title: __( 'Order', 'rapls-sitemap' ), initialOpen: false },
						select( __( 'Sort entries by', 'rapls-sitemap' ), 'orderby', ORDERBY ),
						select( __( 'Direction', 'rapls-sitemap' ), 'order', ORDER ),
						range( __( 'Maximum depth', 'rapls-sitemap' ), 'depth', -1, 10, __( '-1 uses the site default, 0 shows every level.', 'rapls-sitemap' ) ),
						// A number field, not a slider: the site default is
						// 2000, and a slider whose top end sits below the value
						// it is overriding cannot express what it replaces.
						el( components.TextControl, {
							label: __( 'Entries per list', 'rapls-sitemap' ),
							help: __( '-1 uses the site default, 0 lifts the cap.', 'rapls-sitemap' ),
							type: 'number',
							min: -1,
							value: atts.number,
							onChange: function ( value ) {
								set( { number: '' === value ? -1 : parseInt( value, 10 ) } );
							},
						} ),
						range( __( 'Entries per category', 'rapls-sitemap' ), 'perCategory', -1, 100, __( '-1 uses the site default, 0 lifts the cap.', 'rapls-sitemap' ) )
					),
					el(
						components.PanelBody,
						{ title: __( 'Appearance', 'rapls-sitemap' ), initialOpen: false },
						select( __( 'Design', 'rapls-sitemap' ), 'design', DESIGNS ),
						select( __( 'List element', 'rapls-sitemap' ), 'listType', LIST_TYPES ),
						toggle( __( 'Put a heading above each post type', 'rapls-sitemap' ), 'sectionHeadings' ),
						toggle( __( 'Link section and category headings to their archives', 'rapls-sitemap' ), 'linkHeadings' ),
						toggle( __( 'Published date', 'rapls-sitemap' ), 'showDate' ),
						toggle( __( 'Excerpt', 'rapls-sitemap' ), 'showExcerpt' ),
						toggle( __( 'Entry count beside each category', 'rapls-sitemap' ), 'showCount' ),
						toggle( __( 'Add rel="nofollow" to every link', 'rapls-sitemap' ), 'nofollow' )
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
