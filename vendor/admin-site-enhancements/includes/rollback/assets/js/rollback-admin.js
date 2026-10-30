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

		var modalRequestId = 0;

		function versionStrings() {
			return ( window.asenhaRollback && asenhaRollback.strings ) ? asenhaRollback.strings : {};
		}

		function selectVersionLabel() {
			return versionStrings().selectVersion || 'Select version';
		}

		function updateRollbackSubmitState() {
			var $selectedOption = $( '#asenha-rollback-target-version option:selected' );
			var source = $selectedOption.attr( 'data-source' );

			$( '.asenha-submit-rollback' ).prop( 'disabled', ! source );
		}

		function setModalLoading( isLoading, message ) {
			var $loading = $( '.asenha-rollback-versions-loading' );
			var $select = $( '#asenha-rollback-target-version' );
			var settings = window.asenhaRollback && asenhaRollback.loadVersions ? asenhaRollback.loadVersions : {};

			if ( isLoading ) {
				$loading.text( message || settings.loading || 'Loading versions…' );
				$loading.removeAttr( 'hidden' );
				$select.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
				$( '.asenha-submit-rollback' ).prop( 'disabled', true );
				return;
			}

			$loading.attr( 'hidden', 'hidden' );
			$select.prop( 'disabled', false ).removeAttr( 'aria-busy' );
		}

		function showModalLoadError() {
			var settings = window.asenhaRollback && asenhaRollback.loadVersions ? asenhaRollback.loadVersions : {};
			var $loading = $( '.asenha-rollback-versions-loading' );

			$loading.text( settings.error || 'Versions could not be loaded. Please try again.' );
			$loading.removeAttr( 'hidden' );
			$( '#asenha-rollback-target-version' ).prop( 'disabled', false ).removeAttr( 'aria-busy' );
			$( '.asenha-submit-rollback' ).prop( 'disabled', true );
		}

		function applyLatestVersion( $row, latestVersion, latestIsNewer ) {
			var $cell = $row.find( '.asenha-rollback-latest-version' );
			var unavailableLabel = 'Not available';

			$cell.removeClass( 'asenha-rollback-version-newer' );
			$cell.empty();

			if ( ! latestVersion ) {
				$cell.append(
					$( '<span />', {
						'class': 'asenha-rollback-latest-unavailable',
						'aria-label': unavailableLabel,
						text: '—'
					} )
				);
				return;
			}

			$cell.text( latestVersion );

			if ( latestIsNewer ) {
				$cell.addClass( 'asenha-rollback-version-newer' );
			}
		}

		function ensureRowVersionSelect( $row ) {
			var $holder = $row.find( '.asenha-rollback-versions-data' );

			if ( ! $holder.length ) {
				$holder = $( '<span />', {
					'class': 'asenha-rollback-versions-data',
					'aria-hidden': 'true'
				} );
				$row.find( '.asenha-rollback-inline-actions' ).prepend( $holder );
			}

			var $select = $holder.find( '.asenha-rollback-select' );

			if ( ! $select.length ) {
				$select = $( '<select />', {
					'class': 'asenha-rollback-select',
					tabindex: '-1'
				} );
				$holder.append( $select );
			}

			return $select;
		}

		function storeVersionsOnRow( $row, versions ) {
			var $select = ensureRowVersionSelect( $row );
			var notice = versionStrings().noVersions || 'No rollback versions are currently available for this item.';
			var $button = $row.find( '.asenha-open-rollback-modal' );

			$select.empty();
			$select.append(
				$( '<option />', {
					value: '',
					text: selectVersionLabel()
				} )
			);

			$.each( versions || [], function( _, option ) {
				if ( ! option || ! option.version ) {
					return;
				}

				$select.append(
					$( '<option />', {
						value: option.version,
						text: option.label || option.version
					} ).attr( 'data-source', option.source || '' )
				);
			} );

			$row.attr( 'data-versions-deferred', '0' );

			if ( ! versions || ! versions.length ) {
				$button.prop( 'disabled', true ).attr( {
					'aria-label': notice,
					title: notice
				} );
			}
		}

		function requestVersions( assetType, assetKeys, includeVersions ) {
			var settings = window.asenhaRollback && asenhaRollback.loadVersions ? asenhaRollback.loadVersions : null;

			if ( ! settings || ! settings.ajaxUrl ) {
				return $.Deferred().reject().promise();
			}

			return $.post( settings.ajaxUrl, {
				action: 'asenha_rollback_load_versions',
				nonce: settings.nonce,
				asset_type: assetType,
				asset_keys: assetKeys,
				include_versions: includeVersions ? '1' : '0'
			} );
		}

		function findLoadedAsset( assets, assetKey ) {
			var match = null;

			$.each( assets || [], function( _, asset ) {
				if ( asset && String( asset.asset_key ) === String( assetKey ) ) {
					match = asset;
					return false;
				}
			} );

			return match;
		}

		function fillModalFromRow( $row, preferredVersion ) {
			var assetType = $row.attr( 'data-asset-type' );
			var assetKey = $row.attr( 'data-asset-key' );
			var assetName = $row.attr( 'data-asset-name' );
			var currentVersion = $row.attr( 'data-current-version' );
			var $rowSelect = $row.find( '.asenha-rollback-select' );
			var $modalSelect = $( '#asenha-rollback-target-version' );

			$modalSelect.empty();
			$modalSelect.append(
				$( '<option />', {
					value: '',
					text: selectVersionLabel()
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
						text: $( this ).text()
					} ).attr( 'data-source', $( this ).attr( 'data-source' ) || '' )
				);
			} );

			if ( preferredVersion && $modalSelect.find( 'option' ).filter( function() {
				return $( this ).attr( 'value' ) === String( preferredVersion );
			} ).length ) {
				$modalSelect.val( preferredVersion );
			} else if ( $rowSelect.val() ) {
				$modalSelect.val( $rowSelect.val() );
			}

			$( '#asenha-rollback-asset-type' ).val( assetType );
			$( '#asenha-rollback-asset-key' ).val( assetKey );
			$( '.asenha-rollback-asset-name' ).text( assetName );
			$( '.asenha-rollback-current-version-label' ).text( ( versionStrings().currentVersion || 'Installed version:' ) + ' ' + currentVersion );
			
			updateRollbackSubmitState();
		}

		function prepareModalIdentity( $row ) {
			$( '#asenha-rollback-asset-type' ).val( $row.attr( 'data-asset-type' ) );
			$( '#asenha-rollback-asset-key' ).val( $row.attr( 'data-asset-key' ) );
			$( '.asenha-rollback-asset-name' ).text( $row.attr( 'data-asset-name' ) );
			$( '.asenha-rollback-current-version-label' ).text( ( versionStrings().currentVersion || 'Installed version:' ) + ' ' + ( $row.attr( 'data-current-version' ) || '' ) );
			
			setEditorContent( 'asenha_rollback_note', '' );
		}

		function rowHasVersionOptions( $row ) {
			return $row.find( '.asenha-rollback-select option' ).filter( function() {
				return !! $( this ).attr( 'value' );
			} ).length > 0;
		}

		function openRollbackForRow( $row, preferredVersion ) {
			var requestId = ++modalRequestId;
			var assetType = $row.attr( 'data-asset-type' );
			var assetKey = $row.attr( 'data-asset-key' );
			var deferred = String( $row.attr( 'data-versions-deferred' ) ) === '1' && ! rowHasVersionOptions( $row );

			prepareModalIdentity( $row );
			openModal( $rollbackOverlay );

			if ( ! deferred ) {
				setModalLoading( false );
				fillModalFromRow( $row, preferredVersion );
				return;
			}

			$( '#asenha-rollback-target-version' ).empty().append(
				$( '<option />', {
					value: '',
					text: selectVersionLabel()
				} )
			);
			setModalLoading( true );

			requestVersions( assetType, [ assetKey ], true ).done( function( response ) {
				if ( requestId !== modalRequestId ) {
					return;
				}

				var assets = response && response.success && response.data ? response.data.assets : [];
				var asset = findLoadedAsset( assets, assetKey );

				if ( ! asset ) {
					showModalLoadError();
					return;
				}

				storeVersionsOnRow( $row, asset.versions || [] );
				applyLatestVersion( $row, asset.latest_version || '', !! asset.latest_is_newer );
				$row.attr( 'data-latest-pending', '0' );
				setModalLoading( false );
				fillModalFromRow( $row, preferredVersion );
			} ).fail( function() {
				if ( requestId !== modalRequestId ) {
					return;
				}

				showModalLoadError();
			} );
		}

		function prefetchLatestVersions() {
			var queue = [];
			var attempts = {};

			$( '.asenha-rollback-row[data-latest-pending="1"]' ).each( function() {
				queue.push( $( this ) );
			} );

			function runBatch() {
				if ( ! queue.length ) {
					return;
				}

				var batch = queue.splice( 0, 5 );
				var assetType = batch[ 0 ].attr( 'data-asset-type' );
				var keys = [];

				$.each( batch, function( _, $row ) {
					keys.push( $row.attr( 'data-asset-key' ) );
				} );

				requestVersions( assetType, keys, false ).done( function( response ) {
					var assets = response && response.success && response.data ? response.data.assets : [];
					var returnedAny = false;
					var pendingRows = [];

					$.each( batch, function( _, $row ) {
						var key = $row.attr( 'data-asset-key' );
						var asset = findLoadedAsset( assets, key );

						if ( asset ) {
							returnedAny = true;
							applyLatestVersion( $row, asset.latest_version || '', !! asset.latest_is_newer );
							$row.attr( 'data-latest-pending', '0' );
							return;
						}

						pendingRows.push( $row );
					} );

					$.each( pendingRows, function( _, $row ) {
						var key = $row.attr( 'data-asset-key' );

						if ( ! returnedAny ) {
							attempts[ key ] = ( attempts[ key ] || 0 ) + 1;

							if ( attempts[ key ] >= 3 ) {
								return;
							}
						}

						queue.push( $row );
					} );

					runBatch();
				} ).fail( function() {
					$.each( batch, function( _, $row ) {
						var key = $row.attr( 'data-asset-key' );

						attempts[ key ] = ( attempts[ key ] || 0 ) + 1;

						if ( attempts[ key ] < 3 ) {
							queue.push( $row );
						}
					} );

					runBatch();
				} );
			}

			runBatch();
		}

		$( document ).on( 'click', '.asenha-open-rollback-modal', function() {
			openRollbackForRow( $( this ).closest( '.asenha-rollback-row' ), '' );
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
					openRollbackForRow( $row, asenhaRollback.autoOpen.targetVersion || '' );
					return false;
				}
			} );
		}

		prefetchLatestVersions();
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
