/*
 * Settings screen behaviour: a colour swatch bound to each colour box, and an
 * emoji palette for the bullet fields.
 *
 * Progressive enhancement throughout. Every field this touches is a plain text
 * input that works on its own — with this script absent, or blocked, or broken,
 * the screen still saves exactly what it saved before. Nothing here is the
 * source of truth for a setting; the text inputs are, and they are what post.
 *
 * No framework, no build step: the family ships neither.
 */
( function () {
	'use strict';

	var config = window.raplsSitemapAdmin || { emoji: {}, labels: {} };

	function label( key, fallback ) {
		return config.labels && config.labels[ key ] ? config.labels[ key ] : fallback;
	}

	/* --- colour swatches --------------------------------------------------- */

	var HEX_LONG = /^#[0-9a-f]{6}$/i;
	var HEX_SHORT = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i;

	/**
	 * A six-digit hex the colour input will accept, or null.
	 *
	 * `currentColor`, a colour name, and `var(--x)` are all valid settings that
	 * a colour input cannot represent — those return null and simply leave the
	 * swatch where it was.
	 */
	function toHex( value ) {
		value = ( value || '' ).trim();

		if ( HEX_LONG.test( value ) ) {
			return value;
		}

		var short = value.match( HEX_SHORT );
		if ( short ) {
			return '#' + short[ 1 ] + short[ 1 ] + short[ 2 ] + short[ 2 ] + short[ 3 ] + short[ 3 ];
		}

		return null;
	}

	function bindColour( wrapper ) {
		var text = wrapper.querySelector( '.rapls-field__color' );
		var swatch = wrapper.querySelector( '.rapls-field__swatch' );
		var clear = wrapper.querySelector( '.rapls-field__clear' );

		if ( ! text || ! swatch ) {
			return;
		}

		var fallback = swatch.getAttribute( 'data-default' ) || '#0073aa';

		/**
		 * Keep the swatch showing whatever the text box says.
		 *
		 * A colour input has no empty state, so an unrepresentable value —
		 * blank, `currentColor`, `var(--x)` — puts it back to the neutral
		 * rather than leaving the previous colour on screen. Leaving it was the
		 * reason clearing a field looked like it had done nothing.
		 */
		function syncSwatch() {
			swatch.value = toHex( text.value ) || fallback;
		}

		swatch.addEventListener( 'input', function () {
			text.value = swatch.value;
			// Anything watching the field — including the browser's own "unsaved
			// changes" tracking — should see this as a real edit.
			text.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		text.addEventListener( 'input', syncSwatch );
		text.addEventListener( 'change', syncSwatch );

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				text.value = '';
				syncSwatch();
				text.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				text.focus();
			} );
		}
	}

	/* --- emoji palette ------------------------------------------------------ */

	var openPalette = null;

	function closePalette() {
		if ( openPalette ) {
			openPalette.panel.remove();
			openPalette.button.setAttribute( 'aria-expanded', 'false' );
			openPalette = null;
		}
	}

	/** Remembered across openings, so picking up where you left off is free. */
	var lastGroup = null;

	function choose( input, glyph ) {
		input.value = glyph;
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		closePalette();
		input.focus();
	}

	function buildPanel( input ) {
		var groups = Object.keys( config.emoji || {} );

		var panel = document.createElement( 'div' );
		panel.className = 'rapls-emoji';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', label( 'pick', 'Pick an emoji' ) );

		var tabs = document.createElement( 'div' );
		tabs.className = 'rapls-emoji__tabs';
		tabs.setAttribute( 'role', 'tablist' );

		var body = document.createElement( 'div' );
		body.className = 'rapls-emoji__body';

		var panes = {};
		var buttons = {};

		function show( group ) {
			groups.forEach( function ( other ) {
				var selected = other === group;
				panes[ other ].hidden = ! selected;
				buttons[ other ].setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				buttons[ other ].tabIndex = selected ? 0 : -1;
				buttons[ other ].classList.toggle( 'is-active', selected );
			} );
			lastGroup = group;
		}

		groups.forEach( function ( group ) {
			var tab = document.createElement( 'button' );
			tab.type = 'button';
			tab.className = 'rapls-emoji__tab';
			tab.textContent = group;
			tab.setAttribute( 'role', 'tab' );
			tab.addEventListener( 'click', function () {
				show( group );
			} );

			// Left/right move between tabs, which is what a tablist is expected
			// to do and costs almost nothing to honour.
			tab.addEventListener( 'keydown', function ( event ) {
				var step = 'ArrowRight' === event.key ? 1 : ( 'ArrowLeft' === event.key ? -1 : 0 );
				if ( ! step ) {
					return;
				}
				event.preventDefault();
				var next = groups[ ( groups.indexOf( group ) + step + groups.length ) % groups.length ];
				show( next );
				buttons[ next ].focus();
			} );

			buttons[ group ] = tab;
			tabs.appendChild( tab );

			var grid = document.createElement( 'div' );
			grid.className = 'rapls-emoji__grid';
			grid.setAttribute( 'role', 'tabpanel' );
			grid.setAttribute( 'aria-label', group );

			config.emoji[ group ].forEach( function ( glyph ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'rapls-emoji__item';
				button.textContent = glyph;
				button.setAttribute( 'aria-label', glyph );
				button.addEventListener( 'click', function () {
					choose( input, glyph );
				} );
				grid.appendChild( button );
			} );

			// A tab may carry a caveat — flags do not render on every platform.
			// It belongs next to the choice, not in a readme nobody opens.
			var note = config.notes && config.notes[ group ];
			if ( note ) {
				var pane = document.createElement( 'div' );
				pane.setAttribute( 'role', 'tabpanel' );
				pane.setAttribute( 'aria-label', group );
				grid.removeAttribute( 'role' );
				grid.removeAttribute( 'aria-label' );

				var text = document.createElement( 'p' );
				text.className = 'rapls-emoji__note';
				text.textContent = note;

				pane.appendChild( grid );
				pane.appendChild( text );

				panes[ group ] = pane;
				body.appendChild( pane );
				return;
			}

			panes[ group ] = grid;
			body.appendChild( grid );
		} );

		panel.appendChild( tabs );
		panel.appendChild( body );

		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'button-link rapls-emoji__clear';
		clear.textContent = label( 'clear', 'No emoji' );
		clear.addEventListener( 'click', function () {
			choose( input, '' );
		} );
		panel.appendChild( clear );

		if ( groups.length ) {
			show( groups.indexOf( lastGroup ) >= 0 ? lastGroup : groups[ 0 ] );
		}

		return panel;
	}

	function bindEmoji( wrapper ) {
		var input = wrapper.querySelector( '.rapls-field__emoji' );
		if ( ! input ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button rapls-field__pick';
		button.textContent = '☺';
		button.title = label( 'pick', 'Pick an emoji' );
		button.setAttribute( 'aria-label', label( 'pick', 'Pick an emoji' ) );
		button.setAttribute( 'aria-expanded', 'false' );

		button.addEventListener( 'click', function ( event ) {
			event.stopPropagation();

			if ( openPalette && openPalette.button === button ) {
				closePalette();
				return;
			}

			closePalette();

			var panel = buildPanel( input );
			wrapper.appendChild( panel );
			button.setAttribute( 'aria-expanded', 'true' );
			openPalette = { panel: panel, button: button };
		} );

		wrapper.appendChild( button );
	}

	/* --- remembering the open tab ------------------------------------------- */

	var TAB_KEY = 'raplsSitemapTab';

	/**
	 * Reopen whichever tab was last chosen.
	 *
	 * The tabs themselves are radio buttons and CSS; this only remembers which
	 * one. It matters because saving leaves the screen through options.php and
	 * comes back on the first tab, so somebody who changed something under
	 * Advanced would be shown the tab they were not on and have to go looking
	 * for their own change to confirm it saved.
	 *
	 * Session storage rather than a URL parameter: which tab is open is not
	 * worth putting in a link, and a bookmark that reopens a tab is not the
	 * behaviour anybody asked for. Wrapped because a browser in private mode can
	 * refuse storage outright, and the tabs still work without it.
	 */
	function bindTabs() {
		var tabs = document.querySelectorAll( '.rapls-tab-input' );

		if ( ! tabs.length ) {
			return;
		}

		try {
			var saved = window.sessionStorage.getItem( TAB_KEY );

			if ( saved ) {
				var open = document.getElementById( saved );

				if ( open && open.classList.contains( 'rapls-tab-input' ) ) {
					open.checked = true;
				}
			}
		} catch ( e ) {} // eslint-disable-line no-empty

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'change', function () {
				try {
					window.sessionStorage.setItem( TAB_KEY, tab.id );
				} catch ( e ) {} // eslint-disable-line no-empty
			} );
		} );
	}

	/* --- the text-size slider ------------------------------------------------ */

	/**
	 * Keep the readout beside the slider showing the step it is on.
	 *
	 * The slider posts its own value, so this changes nothing about what is
	 * saved. The labels are read out of the list already on the page rather
	 * than passed in separately, which keeps one set of strings translated
	 * once.
	 */
	function bindScale() {
		var slider = document.getElementById( 'rapls-sitemap-text-scale' );
		var output = document.getElementById( 'rapls-sitemap-text-scale-now' );
		var list = document.getElementById( 'rapls-sitemap-text-scale-labels' );

		if ( ! slider || ! output || ! list ) {
			return;
		}

		var labels = list.querySelectorAll( 'li' );

		slider.addEventListener( 'input', function () {
			var step = labels[ parseInt( slider.value, 10 ) - 1 ];

			if ( ! step ) {
				return;
			}

			output.textContent = step.textContent;

			// A range input is announced as a bare number otherwise, which is
			// the one thing about this control that carries no meaning.
			slider.setAttribute( 'aria-valuetext', step.textContent );
		} );
	}

	/* --- wiring -------------------------------------------------------------- */

	function init() {
		bindTabs();
		bindScale();
		document.querySelectorAll( '.rapls-field--color' ).forEach( bindColour );
		document.querySelectorAll( '.rapls-field--emoji' ).forEach( bindEmoji );

		// A click anywhere else, or Escape, dismisses the palette. Without this
		// it would be a panel you can open and not obviously close.
		document.addEventListener( 'click', function ( event ) {
			if ( openPalette && ! openPalette.panel.contains( event.target ) ) {
				closePalette();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closePalette();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
