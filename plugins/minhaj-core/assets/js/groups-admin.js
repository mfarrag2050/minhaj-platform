/*
 * Groups admin — autocomplete + row-actions glue.
 *
 * Depends on jQuery + jquery-ui-autocomplete, both bundled with core
 * WordPress. All server calls hit admin-ajax.php with a dedicated
 * nonce; the endpoints already re-check the capability, so this file
 * makes no policy decisions of its own — it only wires DOM.
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
						// Manual typing without picking a suggestion is not
						// enough — clear the hidden id so submit validation
						// fails cleanly instead of sending 0.
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
				var $input   = $( this );
				var target   = String( $input.data( 'target' ) || '' );
				var exclude  = parseInt( $input.data( 'exclude' ) || 0, 10 ) || 0;
				var $hidden  = target ? $( '#' + target ) : $();

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

		function bindSubmitGuard() {
			$( document ).on( 'submit', 'form.mj-requires-selection', function ( event ) {
				var ok = true;
				$( this ).find( '.mj-user-search, .mj-group-search' ).each( function () {
					var target  = String( $( this ).data( 'target' ) || '' );
					var $hidden = target ? $( '#' + target ) : $();
					var value   = parseInt( $hidden.val() || 0, 10 ) || 0;
					if ( value <= 0 ) {
						ok = false;
						$( this ).trigger( 'focus' );
						return false;
					}
				} );
				if ( ! ok ) {
					event.preventDefault();
					window.alert( cfg.selectionRequired || 'Please select from the suggestions.' );
				}
			} );
		}

		function bindRowRemove() {
			$( document ).on( 'click', '.mj-row-remove', function ( event ) {
				event.preventDefault();
				var formId = String( $( this ).data( 'form' ) || '' );
				if ( ! formId ) { return; }
				var $form = $( '#' + formId );
				if ( ! $form.length ) { return; }

				var reason = window.prompt( cfg.reasonPrompt || 'Reason:' );
				if ( null === reason ) { return; }
				reason = String( reason ).trim();
				if ( '' === reason ) {
					window.alert( cfg.reasonRequired || 'A reason is required.' );
					return;
				}
				$form.find( 'input[name="reason"]' ).val( reason );
				$form.trigger( 'submit' );
			} );
		}

		function bindRowTransfer() {
			$( document ).on( 'click', '.mj-row-transfer', function ( event ) {
				event.preventDefault();
				var panelId = String( $( this ).data( 'panel' ) || '' );
				if ( ! panelId ) { return; }
				var $panel = $( '#' + panelId );
				if ( 'none' === $panel.css( 'display' ) ) {
					$panel.css( 'display', 'block' );
					$panel.find( '.mj-group-search' ).trigger( 'focus' );
				} else {
					$panel.css( 'display', 'none' );
				}
			} );
			$( document ).on( 'click', '.mj-row-transfer-cancel', function ( event ) {
				event.preventDefault();
				$( this ).closest( '.mj-transfer-panel' ).css( 'display', 'none' );
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
		bindSubmitGuard();
		bindRowRemove();
		bindRowTransfer();
		bindAuditDetails();
	} );
} )( jQuery );
