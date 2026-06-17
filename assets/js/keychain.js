/**
 * Modo asistido: publica en Hive firmando con la extensión Hive Keychain.
 * La posting key nunca sale del navegador del usuario.
 */
( function () {
	'use strict';

	function ajax( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', chaincastKeychain.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );

		return fetch( chaincastKeychain.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setStatus( btn, text, color ) {
		var status = btn.parentNode.querySelector( '.cc-keychain-status' );
		if ( status ) {
			status.textContent = text;
			status.style.color = color || '';
		}
	}

	function onClick( e ) {
		e.preventDefault();
		var btn         = e.currentTarget;
		var postId      = btn.getAttribute( 'data-post' );
		var connectorId = btn.getAttribute( 'data-connector' );
		var extName     = btn.getAttribute( 'data-extension' ) || 'hive_keychain';
		var keychain    = window[ extName ];

		if ( ! keychain ) {
			setStatus( btn, chaincastKeychain.i18n.noKeychain, '#b32d2e' );
			return;
		}

		setStatus( btn, chaincastKeychain.i18n.working, '' );

		ajax( chaincastKeychain.actionRequest, { post_id: postId, connector_id: connectorId } )
			.then( function ( res ) {
				if ( ! res.success ) {
					throw new Error( res.data && res.data.message ? res.data.message : 'request failed' );
				}
				var req = res.data;

				keychain.requestBroadcast(
					req.account,
					req.operations,
					'Posting',
					function ( response ) {
						if ( ! response.success ) {
							setStatus( btn, chaincastKeychain.i18n.error + ': ' + ( response.message || response.error ), '#b32d2e' );
							return;
						}
						var txId = response.result && response.result.id ? response.result.id : '';

						ajax( chaincastKeychain.actionConfirm, {
							post_id: postId,
							connector_id: connectorId,
							permlink: req.permlink,
							tx_id: txId,
						} ).then( function ( confirm ) {
							if ( confirm.success ) {
								setStatus( btn, chaincastKeychain.i18n.published, '#008a20' );
							} else {
								setStatus( btn, chaincastKeychain.i18n.error, '#b32d2e' );
							}
						} );
					}
				);
			} )
			.catch( function ( err ) {
				setStatus( btn, chaincastKeychain.i18n.error + ': ' + err.message, '#b32d2e' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var buttons = document.querySelectorAll( '.cc-keychain-btn' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', onClick );
		} );
	} );
} )();
