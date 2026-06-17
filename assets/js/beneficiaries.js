/**
 * Realce del campo de reparto de recompensas (beneficiaries).
 *
 * Convierte un input de texto "cuenta:porcentaje, cuenta:porcentaje" en una tabla
 * editable con filas, botón de añadir/quitar y un total en vivo. El input de texto
 * original queda oculto y sincronizado (es lo que se guarda y parsea en el server),
 * así que esto es solo una mejora progresiva: sin JS, el campo de texto sigue valiendo.
 */
( function () {
	'use strict';

	var MAX = 8;

	function t( key, fallback ) {
		return ( window.chaincastBenef && window.chaincastBenef[ key ] ) || fallback;
	}

	function build( container ) {
		var raw = container.querySelector( '.cc-benef-raw' );
		if ( ! raw || container.getAttribute( 'data-ready' ) ) {
			return;
		}
		container.setAttribute( 'data-ready', '1' );
		raw.style.display = 'none';

		var table = document.createElement( 'table' );
		table.className = 'cc-benef-table';

		var thead = document.createElement( 'thead' );
		var htr = document.createElement( 'tr' );
		htr.innerHTML =
			'<th>' + t( 'account', 'Account' ) + '</th><th>%</th><th></th>';
		thead.appendChild( htr );
		table.appendChild( thead );

		var tbody = document.createElement( 'tbody' );
		table.appendChild( tbody );

		var addBtn = document.createElement( 'button' );
		addBtn.type = 'button';
		addBtn.className = 'button button-small cc-benef-add';
		addBtn.textContent = t( 'add', 'Add beneficiary' );

		var totalLine = document.createElement( 'p' );
		totalLine.className = 'cc-benef-total';

		function rowsEl() {
			return tbody.querySelectorAll( 'tr' );
		}

		function update() {
			var sum = 0;
			var parts = [];
			rowsEl().forEach( function ( tr ) {
				var acc = tr.querySelector( '.cc-benef-acct' ).value.trim();
				var pctStr = tr.querySelector( '.cc-benef-pct' ).value.trim();
				var pct = parseFloat( pctStr );
				if ( ! isNaN( pct ) ) {
					sum += pct;
				}
				if ( acc && pctStr ) {
					parts.push( acc + ':' + pctStr );
				}
			} );

			raw.value = parts.join( ', ' );

			var hasRows = rowsEl().length > 0;
			// Sin filas: ocultamos la tabla y dejamos solo el botón Add + un mensaje
			// claro (sin el "0%", que no aporta en ese contexto).
			table.style.display = hasRows ? '' : 'none';
			addBtn.disabled = rowsEl().length >= MAX;

			if ( ! hasRows ) {
				totalLine.textContent = t( 'empty', 'No reward sharing — you keep 100%' );
				totalLine.classList.remove( 'over' );
				return;
			}

			var mine = Math.round( ( 100 - sum ) * 100 ) / 100;
			totalLine.textContent =
				t( 'assigned', 'Assigned' ) + ': ' + sum + '% · ' +
				t( 'mine', 'For you' ) + ': ' + mine + '%';
			totalLine.classList.toggle( 'over', sum > 100 );
		}

		function addRow( account, pct ) {
			if ( rowsEl().length >= MAX ) {
				return;
			}
			var tr = document.createElement( 'tr' );

			var tdAcc = document.createElement( 'td' );
			var acc = document.createElement( 'input' );
			acc.type = 'text';
			acc.className = 'cc-benef-acct';
			acc.value = account || '';
			acc.placeholder = t( 'accountPh', 'account' );
			tdAcc.appendChild( acc );

			var tdPct = document.createElement( 'td' );
			var pctInput = document.createElement( 'input' );
			pctInput.type = 'number';
			pctInput.className = 'cc-benef-pct';
			pctInput.min = '0';
			pctInput.max = '100';
			pctInput.step = '0.01';
			pctInput.value = pct || '';
			tdPct.appendChild( pctInput );

			var tdDel = document.createElement( 'td' );
			var del = document.createElement( 'a' );
			del.href = '#';
			del.className = 'cc-benef-remove';
			del.setAttribute( 'aria-label', t( 'remove', 'Remove' ) );
			del.textContent = '×';
			del.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				tr.parentNode.removeChild( tr );
				update();
			} );
			tdDel.appendChild( del );

			tr.appendChild( tdAcc );
			tr.appendChild( tdPct );
			tr.appendChild( tdDel );

			acc.addEventListener( 'input', update );
			pctInput.addEventListener( 'input', update );
			tbody.appendChild( tr );
		}

		raw.value.split( ',' ).forEach( function ( part ) {
			part = part.trim();
			if ( ! part ) {
				return;
			}
			var idx = part.lastIndexOf( ':' );
			addRow(
				idx >= 0 ? part.slice( 0, idx ).trim() : part,
				idx >= 0 ? part.slice( idx + 1 ).trim() : ''
			);
		} );

		addBtn.addEventListener( 'click', function () {
			addRow( '', '' );
			update();
		} );

		container.appendChild( table );
		container.appendChild( addBtn );
		container.appendChild( totalLine );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.cc-benef' ).forEach( build );
	} );
} )();
