/*
 * Groups admin — autocomplete + row-action panel toggles.
 *
 * Depends on jQuery + jquery-ui-autocomplete, both bundled with core
 * WordPress. All server calls hit admin-ajax.php with a dedicated
 * nonce; the endpoints already re-check the capability, so this file
 * makes no policy decisions of its own — it only wires DOM.
 *
 * No browser dialogs (prompt/confirm/alert) — see CLAUDE.md.
 * Server-side validation is authoritative; the admin sees rejections
 * as translated WP admin notices after the POST-Redirect-GET.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var cfg = window.MinhajGroupsAdmin || {};

		function bindUserAutocomplete() {
			$( '.mj-user-search' ).each( function () {
				var $input  = $( this );
				var role    = String( $input.data( 'role' ) || '' );
				var target  = String( $input.data( 'target' ) || '' );
				var $hidden = target ? $( '#' + target ) : $();

				$input.autocomplete( {
					source: function ( request, response ) {
						$.getJSON( cfg.ajaxUrl, {
							action:      cfg.actionUsers,
							_ajax_nonce: cfg.searchNonce,
							role:        role,
							q:           request.term
						} )
							.done( function ( data ) {
								response( Array.isArray( data ) ? data : [] );
							} )
							.fail( function () { response( [] ); } );
					},
					minLength: 2,
					select: function ( event, ui ) {
						$hidden.val( ui.item.id );
						$input.val( ui.item.label );
						return false;
					},
					change: function () {
						// Manual typing without picking a suggestion clears
						// the hidden id so the server rejects with a proper
						// admin notice instead of accepting a bogus 0.
						if ( ! $input.data( 'confirmed' ) ) {
							$hidden.val( '' );
						}
						$input.data( 'confirmed', false );
					}
				} ).on( 'autocompleteselect', function () {
					$input.data( 'confirmed', true );
				} );
			} );
		}

		function bindGroupAutocomplete() {
			$( '.mj-group-search' ).each( function () {
				var $input  = $( this );
				var target  = String( $input.data( 'target' ) || '' );
				var exclude = parseInt( $input.data( 'exclude' ) || 0, 10 ) || 0;
				var $hidden = target ? $( '#' + target ) : $();

				$input.autocomplete( {
					source: function ( request, response ) {
						$.getJSON( cfg.ajaxUrl, {
							action:      cfg.actionGroups,
							_ajax_nonce: cfg.searchNonce,
							exclude:     exclude,
							q:           request.term
						} )
							.done( function ( data ) {
								response( Array.isArray( data ) ? data : [] );
							} )
							.fail( function () { response( [] ); } );
					},
					minLength: 1,
					select: function ( event, ui ) {
						$hidden.val( ui.item.id );
						$input.val( ui.item.label );
						return false;
					}
				} );
			} );
		}

		function togglePanel( panelId ) {
			var $panel = $( '#' + panelId );
			if ( ! $panel.length ) { return; }
			if ( 'none' === $panel.css( 'display' ) ) {
				$panel.css( 'display', 'block' );
				$panel.find( 'input[type="text"]' ).first().trigger( 'focus' );
			} else {
				$panel.css( 'display', 'none' );
			}
		}

		function bindRowActions() {
			$( document ).on( 'click', '.mj-row-remove, .mj-row-transfer', function ( event ) {
				event.preventDefault();
				togglePanel( String( $( this ).data( 'panel' ) || '' ) );
			} );

			$( document ).on( 'click', '.mj-row-remove-cancel, .mj-row-transfer-cancel', function ( event ) {
				event.preventDefault();
				$( this ).closest( '.mj-remove-panel, .mj-transfer-panel' ).css( 'display', 'none' );
			} );
		}

		function bindAuditDetails() {
			$( document ).on( 'click', '.mj-audit-details-toggle', function ( event ) {
				event.preventDefault();
				var $pre = $( this ).closest( 'td' ).find( '.mj-audit-payload' );
				if ( 'none' === $pre.css( 'display' ) ) {
					$pre.css( 'display', 'block' );
					$( this ).text( cfg.hideDetails || 'Hide details' );
				} else {
					$pre.css( 'display', 'none' );
					$( this ).text( cfg.showDetails || 'Details' );
				}
			} );
		}

		bindUserAutocomplete();
		bindGroupAutocomplete();
		bindRowActions();
		bindAuditDetails();
	} );
} )( jQuery );
