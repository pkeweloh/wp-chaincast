/**
 * "Posting key" field enhancement.
 *
 * When a key is already stored (`.cc-key[data-haskey]`), hides the input and
 * shows only the "key saved" badge + a "Replace key" link. Clicking it reveals
 * the input (to type a new one) and a "Cancel" that hides it again. Without JS,
 * the input stays visible and the key can be replaced anyway, so this is just
 * progressive enhancement. All strings are already rendered by the server.
 */
( function () {
	'use strict';

	function enhance( box ) {
		if ( ! box.getAttribute( 'data-haskey' ) || box.getAttribute( 'data-ready' ) ) {
			return;
		}
		box.setAttribute( 'data-ready', '1' );

		var input = box.querySelector( '.cc-key-input' );
		var edit = box.querySelector( '.cc-key-edit' );
		var cancel = box.querySelector( '.cc-key-cancel' );
		var badge = box.querySelector( '.cc-saved' );
		var hint = box.querySelector( '.cc-key-hint' );

		function show( editing ) {
			if ( badge ) {
				badge.style.display = editing ? 'none' : '';
			}
			if ( edit ) {
				edit.style.display = editing ? 'none' : '';
			}
			input.style.display = editing ? '' : 'none';
			if ( cancel ) {
				cancel.style.display = editing ? '' : 'none';
			}
			if ( hint ) {
				hint.style.display = editing ? '' : 'none';
			}
		}

		show( false );

		if ( edit ) {
			edit.addEventListener( 'click', function () {
				show( true );
				input.focus();
			} );
		}
		if ( cancel ) {
			cancel.addEventListener( 'click', function () {
				input.value = '';
				show( false );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.cc-key' ).forEach( enhance );
	} );
} )();
