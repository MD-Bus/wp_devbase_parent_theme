jQuery( function( $ ) {
	'use strict';

	function setEditorContent( editorId, content ) {
		if ( window.tinymce && window.tinymce.get( editorId ) ) {
			window.tinymce.get( editorId ).setContent( content || '' );
		}

		$( '#' + editorId ).val( content || '' );
	}

	function closeModal( $overlay ) {
		$overlay.addClass( 'is-hidden' );
	}

	function openModal( $overlay ) {
		$overlay.removeClass( 'is-hidden' );
	}

	function bindRollbackPage() {
		var $rollbackOverlay = $( '#asenha-rollback-modal-overlay' );
		var $logOverlay = $( '#asenha-log-note-modal-overlay' );

		if ( ! $rollbackOverlay.length ) {
			return;
		}

		function updateRollbackSubmitState() {
			var $selectedOption = $( '#asenha-rollback-target-version option:selected' );
			var source = $selectedOption.data( 'source' );

			$( '.asenha-submit-rollback' ).prop( 'disabled', ! source );
		}

		function populateRollbackModal( $row, preferredVersion ) {
			var assetType = $row.data( 'asset-type' );
			var assetKey = $row.data( 'asset-key' );
			var assetName = $row.data( 'asset-name' );
			var currentVersion = $row.data( 'current-version' );
			var $rowSelect = $row.find( '.asenha-rollback-select' );
			var $modalSelect = $( '#asenha-rollback-target-version' );

			$modalSelect.empty();
			$modalSelect.append(
				$( '<option />', {
					value: '',
					text: ( window.asenhaRollback && asenhaRollback.strings && asenhaRollback.strings.selectVersion ) ? asenhaRollback.strings.selectVersion : 'Select version'
				} )
			);

			$rowSelect.find( 'option' ).each( function() {
				var value = $( this ).attr( 'value' );

				if ( ! value ) {
					return;
				}

				$modalSelect.append(
					$( '<option />', {
						value: value,
						text: $( this ).text(),
						'data-source': $( this ).data( 'source' )
					} )
				);
			} );

			if ( preferredVersion && $modalSelect.find( 'option[value="' + preferredVersion + '"]' ).length ) {
				$modalSelect.val( preferredVersion );
			} else if ( $rowSelect.val() ) {
				$modalSelect.val( $rowSelect.val() );
			}

			$( '#asenha-rollback-asset-type' ).val( assetType );
			$( '#asenha-rollback-asset-key' ).val( assetKey );
			$( '.asenha-rollback-asset-name' ).text( assetName );
			$( '.asenha-rollback-current-version-label' ).text( ( asenhaRollback.strings && asenhaRollback.strings.currentVersion ? asenhaRollback.strings.currentVersion : 'Installed version:' ) + ' ' + currentVersion );
			
			setEditorContent( 'asenha_rollback_note', '' );
			updateRollbackSubmitState();
			openModal( $rollbackOverlay );
		}

		$( document ).on( 'click', '.asenha-open-rollback-modal', function() {
			populateRollbackModal( $( this ).closest( '.asenha-rollback-row' ) );
		} );

		$( document ).on( 'change', '#asenha-rollback-target-version', updateRollbackSubmitState );

		

		$( document ).on( 'click', '.asenha-close-modal', function() {
			closeModal( $( this ).closest( '.asenha-modal-overlay' ) );
		} );

		$rollbackOverlay.on( 'click', function( event ) {
			if ( $( event.target ).is( $rollbackOverlay ) ) {
				closeModal( $rollbackOverlay );
			}
		} );

		$logOverlay.on( 'click', function( event ) {
			if ( $( event.target ).is( $logOverlay ) ) {
				closeModal( $logOverlay );
			}
		} );

		$( document ).on( 'click', '.asenha-open-log-note-modal', function() {
			var $button = $( this );
			var noteHtml = $button.closest( 'td' ).find( '.asenha-log-note-value' ).val() || '';

			$( '#asenha-log-note-post-id' ).val( $button.data( 'post-id' ) );
			$( '.asenha-log-note-title' ).text( $button.data( 'title' ) );
			setEditorContent( 'asenha_rollback_log_note', noteHtml );
			openModal( $logOverlay );
		} );

		if ( window.asenhaRollback && asenhaRollback.autoOpen && asenhaRollback.autoOpen.enabled ) {
			$( '.asenha-rollback-row' ).each( function() {
				var $row = $( this );

				if (
					$row.data( 'asset-type' ) === asenhaRollback.autoOpen.assetType &&
					String( $row.data( 'asset-key' ) ) === String( asenhaRollback.autoOpen.assetKey )
				) {
					populateRollbackModal( $row, asenhaRollback.autoOpen.targetVersion || '' );
					return false;
				}
			} );
		}
	}

	function bindThemeBrowserButtons() {
		if ( ! $( 'body' ).hasClass( 'themes-php' ) || ! window.asenhaRollback ) {
			return;
		}

		function buildThemeRollbackUrl( themeSlug ) {
			return asenhaRollback.rollbackPageUrl + '&tab=themes&asset_type=theme&asset_key=' + encodeURIComponent( themeSlug ) + '&open_modal=1';
		}

		function themeRollbackButtonLabel( themeSlug ) {
			var rollbackLabel = ( asenhaRollback.strings && asenhaRollback.strings.rollback ) ? asenhaRollback.strings.rollback : 'Rollback';

			

			return rollbackLabel;
		}

		function applyThemeRollbackButtonLabel( $button, themeSlug ) {
			var label = themeRollbackButtonLabel( themeSlug );

			if ( $button.text() !== label ) {
				$button.text( label );
			}
		}

		function ensureOverlayButton() {
			var params = new URLSearchParams( window.location.search );
			var themeSlug = params.get( 'theme' );
			var $themeActions = $( '.theme-overlay .theme-actions' );

			if ( ! themeSlug || ! $themeActions.length ) {
				return;
			}

			var eligibleThemeSlugs = asenhaRollback.eligibleThemeSlugs || [];

			if ( eligibleThemeSlugs.indexOf( themeSlug ) === -1 ) {
				$themeActions.find( '.asenha-theme-rollback-button' ).remove();
				$( '.theme-overlay .asenha-theme-rollback-latest' ).remove();
				return;
			}

			var expectedHref = buildThemeRollbackUrl( themeSlug );
			var $buttons = $themeActions.find( '.asenha-theme-rollback-button' );

			if ( $buttons.length > 1 ) {
				$buttons.slice( 1 ).remove();
			}

			var $btn = $themeActions.find( '.asenha-theme-rollback-button' ).first();
			var $delete = $themeActions.children( 'a.delete-theme' ).first();
			var $activeTheme = $themeActions.find( '.active-theme' ).first();
			var themeActionsEl = $themeActions.get( 0 );

			function placementIsCorrect( $control ) {
				if ( ! $control.length || ! themeActionsEl ) {
					return false;
				}
				var el = $control.get( 0 );
				var next = el.nextElementSibling;

				if ( next && next.classList && next.classList.contains( 'asenha-theme-rollback-latest' ) ) {
					next = next.nextElementSibling;
				}

				if ( $delete.length ) {
					return next === $delete.get( 0 );
				}
				if ( $activeTheme.length ) {
					return $activeTheme.get( 0 ).nextElementSibling === el;
				}
				return ! next;
			}

			if ( $btn.length && placementIsCorrect( $btn ) ) {
				if ( $btn.attr( 'href' ) !== expectedHref ) {
					$btn.attr( 'href', expectedHref );
				}
				applyThemeRollbackButtonLabel( $btn, themeSlug );
				syncThemeLatestNote( themeSlug );
				return;
			}

			if ( ! $btn.length ) {
				$btn = $( '<a />', {
					'class': 'button asenha-theme-rollback-button',
					href: expectedHref,
					text: themeRollbackButtonLabel( themeSlug )
				} );
			} else {
				$btn.attr( 'href', expectedHref );
				applyThemeRollbackButtonLabel( $btn, themeSlug );
			}

			$btn.detach();

			if ( $delete.length ) {
				$delete.before( $btn );
			} else if ( $activeTheme.length ) {
				$btn.insertAfter( $activeTheme );
			} else {
				$themeActions.append( $btn );
			}

			syncThemeLatestNote( themeSlug );
		}

		function syncThemeLatestNote( themeSlug ) {
			
		}

		var overlayButtonRafId = null;

		function scheduleEnsureOverlayButton() {
			if ( overlayButtonRafId !== null ) {
				return;
			}
			overlayButtonRafId = window.requestAnimationFrame( function() {
				overlayButtonRafId = null;
				ensureOverlayButton();
			} );
		}

		ensureOverlayButton();

		$( document ).on( 'click', '.more-details, .theme-overlay .left, .theme-overlay .right', function() {
			window.setTimeout( ensureOverlayButton, 150 );
		} );

		var observer = new MutationObserver( function() {
			scheduleEnsureOverlayButton();
		} );

		var observeRoot = document.querySelector( '.theme-overlay' ) || document.body;

		observer.observe( observeRoot, {
			childList: true,
			subtree: true
		} );
	}

	bindRollbackPage();
	bindThemeBrowserButtons();
} );
