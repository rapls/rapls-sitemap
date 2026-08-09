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

		swatch.addEventListener( 'input', function () {
			text.value = swatch.value;
			// Anything watching the field — including the browser's own "unsaved
			// changes" tracking — should see this as a real edit.
			text.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		text.addEventListener( 'input', function () {
			var hex = toHex( text.value );
			if ( hex ) {
				swatch.value = hex;
			}
		} );

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				text.value = '';
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

	function buildPanel( input ) {
		var panel = document.createElement( 'div' );
		panel.className = 'rapls-emoji';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', label( 'pick', 'Pick an emoji' ) );

		Object.keys( config.emoji || {} ).forEach( function ( group ) {
			var heading = document.createElement( 'p' );
			heading.className = 'rapls-emoji__group';
			heading.textContent = group;
			panel.appendChild( heading );

			var grid = document.createElement( 'div' );
			grid.className = 'rapls-emoji__grid';

			config.emoji[ group ].forEach( function ( glyph ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'rapls-emoji__item';
				button.textContent = glyph;
				button.setAttribute( 'aria-label', glyph );

				button.addEventListener( 'click', function () {
					input.value = glyph;
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					closePalette();
					input.focus();
				} );

				grid.appendChild( button );
			} );

			panel.appendChild( grid );
		} );

		var clear = document.createElement( 'button' );
		clear.type = 'button';
		clear.className = 'button-link rapls-emoji__clear';
		clear.textContent = label( 'clear', 'No emoji' );
		clear.addEventListener( 'click', function () {
			input.value = '';
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			closePalette();
			input.focus();
		} );
		panel.appendChild( clear );

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

	/* --- wiring -------------------------------------------------------------- */

	function init() {
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
