/**
 * Realce del campo "Posting key".
 *
 * Cuando ya hay una clave guardada (`.cc-key[data-haskey]`), oculta el input y
 * muestra solo el badge "clave guardada" + un enlace "Replace key". Al pulsarlo
 * aparece el input (para escribir una nueva) y un "Cancel" que lo vuelve a ocultar.
 * Sin JS, el input queda visible y se puede reemplazar la clave igualmente, así que
 * esto es solo una mejora progresiva. Todos los textos vienen ya pintados del server.
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
