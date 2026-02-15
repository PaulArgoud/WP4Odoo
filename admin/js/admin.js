/**
 * WordPress For Odoo — Admin JavaScript
 *
 * Handles AJAX interactions for all admin tabs.
 */
(function( $ ) {
	'use strict';

	/**
	 * Escape a string for safe HTML insertion.
	 *
	 * @param {string} str Raw string.
	 * @return {string} HTML-escaped string.
	 */
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	/**
	 * Resolve the domain-specific nonce for a given AJAX action.
	 *
	 * Falls back to wp4odooAdmin.nonce (setup domain) for backward
	 * compatibility when wp4odooAdmin.nonces is unavailable.
	 *
	 * @param {string} action Full WP AJAX action name (e.g. 'wp4odoo_test_connection').
	 * @return {string} Nonce token.
	 */
	function wp4odooNonce( action ) {
		var map = {
			wp4odoo_test_connection:      'setup',
			wp4odoo_dismiss_onboarding:   'setup',
			wp4odoo_dismiss_checklist:    'setup',
			wp4odoo_confirm_webhooks:     'setup',
			wp4odoo_detect_languages:     'setup',
			wp4odoo_fetch_odoo_taxes:     'setup',
			wp4odoo_fetch_odoo_carriers:  'setup',
			wp4odoo_retry_failed:         'monitor',
			wp4odoo_cleanup_queue:        'monitor',
			wp4odoo_cancel_job:           'monitor',
			wp4odoo_purge_logs:           'monitor',
			wp4odoo_fetch_logs:           'monitor',
			wp4odoo_fetch_queue:          'monitor',
			wp4odoo_queue_stats:          'monitor',
			wp4odoo_toggle_module:        'module',
			wp4odoo_save_module_settings: 'module',
			wp4odoo_bulk_import_products: 'module',
			wp4odoo_bulk_export_products: 'module'
		};
		var domain = map[ action ] || 'setup';
		return ( wp4odooAdmin.nonces && wp4odooAdmin.nonces[ domain ] ) || wp4odooAdmin.nonce;
	}

	var WP4Odoo = {

		/**
		 * Initialize all event bindings.
		 */
		init: function() {
			this.bindTestConnection();
			this.bindCopyToken();
			this.bindModuleToggles();
			this.bindSaveModuleSettings();
			this.bindRetryFailed();
			this.bindCleanupQueue();
			this.bindRefreshStats();
			this.bindCancelJob();
			this.bindBulkAction( '#wp4odoo-bulk-import', 'wp4odoo_bulk_import_products', 'confirmBulkImport' );
			this.bindBulkAction( '#wp4odoo-bulk-export', 'wp4odoo_bulk_export_products', 'confirmBulkExport' );
			this.bindQueuePagination();
			this.bindFilterLogs();
			this.bindPurgeLogs();
			this.bindLogPagination();
			this.bindLogContextExpand();
			this.bindConnectionValidation();
			this.bindDismissChecklist();
			this.bindConfirmWebhooks();
			this.bindDetectLanguages();
			this.bindKeyValueMapping();
			this.bindMappingsUI();
		},

		/**
		 * Generic AJAX helper.
		 *
		 * @param {string}   action    WP AJAX action name.
		 * @param {object}   data      Additional POST data.
		 * @param {function} onSuccess Success callback.
		 * @param {jQuery}   $button   Button to disable during request.
		 */
		ajax: function( action, data, onSuccess, $button ) {
			if ( $button ) {
				$button.prop( 'disabled', true ).addClass( 'updating-message' );
			}

			$.post( wp4odooAdmin.ajaxurl, $.extend( {
				action: action,
				_ajax_nonce: wp4odooNonce( action )
			}, data ), function( response ) {
				if ( $button ) {
					$button.prop( 'disabled', false ).removeClass( 'updating-message' );
				}
				if ( response.success ) {
					onSuccess( response.data );
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : ( wp4odooAdmin.i18n.unknownError || 'Unknown error.' );
					WP4Odoo.showNotice( 'error', msg );
				}
			} ).fail( function() {
				if ( $button ) {
					$button.prop( 'disabled', false ).removeClass( 'updating-message' );
				}
				WP4Odoo.showNotice( 'error', ( wp4odooAdmin.i18n.serverError || 'Server communication error.' ) );
			} );
		},

		/**
		 * Display a WordPress-style admin notice.
		 *
		 * @param {string} type    'success', 'error', 'warning', 'info'.
		 * @param {string} message The message text.
		 */
		showNotice: function( type, message ) {
			var escaped = $( '<span>' ).text( message ).html();
			var $notice = $( '<div class="notice notice-' + type + ' is-dismissible"><p>' + escaped + '</p></div>' );
			$( '.wrap > h1' ).first().after( $notice );

			// Trigger WP dismiss button initialization.
			$( document ).trigger( 'wp-updates-notice-added' );

			if ( type === 'success' ) {
				setTimeout( function() {
					$notice.fadeOut( 300, function() { $( this ).remove(); } );
				}, 4000 );
			}
		},

		// ─── Connection tab ───────────────────────────────────────

		bindTestConnection: function() {
			$( '#wp4odoo-test-connection' ).on( 'click', function() {
				var $btn    = $( this );
				var $result = $( '#wp4odoo-test-result' );

				$result.removeClass( 'success error' ).text( wp4odooAdmin.i18n.testing );

				WP4Odoo.ajax( 'wp4odoo_test_connection', {
					url:      $( '#wp4odoo_url' ).val(),
					database: $( '#wp4odoo_database' ).val(),
					username: $( '#wp4odoo_username' ).val(),
					api_key:  $( '#wp4odoo_api_key' ).val(),
					protocol: $( '#wp4odoo_protocol' ).val()
				}, function( data ) {
					if ( data.success ) {
						$result.addClass( 'success' ).html(
							'<span class="dashicons dashicons-yes-alt"></span>' +
							wp4odooAdmin.i18n.connectionOk + ' (UID: ' + data.uid + ')'
						);

						// Show missing Odoo modules warning if detected.
						if ( data.model_warning ) {
							WP4Odoo.showNotice( 'warning', data.model_warning );
						}
					} else {
						$result.addClass( 'error' ).html(
							'<span class="dashicons dashicons-dismiss"></span>' +
							$( '<span>' ).text( data.message || wp4odooAdmin.i18n.connectionFailed ).html()
						);
					}
				}, $btn );
			} );
		},

		bindCopyToken: function() {
			$( '#wp4odoo-copy-token' ).on( 'click', function() {
				var token = $( '#wp4odoo-webhook-token' ).text().trim();
				var $btn  = $( this );
				var original = $btn.text();

				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( token ).then( function() {
						$btn.text( wp4odooAdmin.i18n.copied );
						setTimeout( function() { $btn.text( original ); }, 2000 );
					} );
				} else {
					var $temp = $( '<textarea>' ).val( token ).appendTo( 'body' ).select();
					document.execCommand( 'copy' );
					$temp.remove();
					$btn.text( wp4odooAdmin.i18n.copied );
					setTimeout( function() { $btn.text( original ); }, 2000 );
				}
			} );
		},

		// ─── Modules tab ──────────────────────────────────────────

		bindModuleToggles: function() {
			$( '.wp4odoo-module-toggle' ).on( 'change', function() {
				var $toggle  = $( this );
				var enabled  = $toggle.is( ':checked' );
				var moduleId = $toggle.data( 'module' );
				var $card    = $toggle.closest( '.wp4odoo-module-card' );
				var $panel   = $card.find( '.wp4odoo-module-settings' );

				if ( enabled ) {
					$panel.slideDown( 200 );
				} else {
					$panel.slideUp( 200 );
				}

				$toggle.prop( 'disabled', true );

				$.post( wp4odooAdmin.ajaxurl, {
					action: 'wp4odoo_toggle_module',
					_ajax_nonce: wp4odooNonce( 'wp4odoo_toggle_module' ),
					module_id: moduleId,
					enabled: enabled ? 1 : 0
				}, function( response ) {
					$toggle.prop( 'disabled', false );
					if ( response.success ) {
						WP4Odoo.showNotice( 'success', response.data.message );

						// Auto-disable conflicting modules in the UI.
						if ( response.data.auto_disabled ) {
							$.each( response.data.auto_disabled, function( _, conflictId ) {
								var $conflictCard   = $( '.wp4odoo-module-card[data-module="' + conflictId + '"]' );
								var $conflictToggle = $conflictCard.find( '.wp4odoo-module-toggle' );
								$conflictToggle.prop( 'checked', false );
								$conflictCard.find( '.wp4odoo-module-settings' ).slideUp( 200 );
							} );
						}

						// Show warning (conflicts or missing Odoo models).
						if ( response.data.warning ) {
							WP4Odoo.showNotice( 'warning', response.data.warning );
						}
					} else {
						// Revert toggle on failure.
						$toggle.prop( 'checked', ! enabled );
						if ( enabled ) {
							$panel.slideUp( 200 );
						} else {
							$panel.slideDown( 200 );
						}
						var msg = ( response.data && response.data.message ) ? response.data.message : ( wp4odooAdmin.i18n.unknownError || 'Unknown error.' );
						WP4Odoo.showNotice( 'error', msg );
					}
				} ).fail( function() {
					$toggle.prop( 'disabled', false );
					// Revert toggle on network failure.
					$toggle.prop( 'checked', ! enabled );
					if ( enabled ) {
						$panel.slideUp( 200 );
					} else {
						$panel.slideDown( 200 );
					}
					WP4Odoo.showNotice( 'error', ( wp4odooAdmin.i18n.serverError || 'Server communication error.' ) );
				} );
			} );
		},

		bindSaveModuleSettings: function() {
			$( '.wp4odoo-save-module-settings' ).on( 'click', function() {
				var $btn     = $( this );
				var moduleId = $btn.data( 'module' );
				var $card    = $btn.closest( '.wp4odoo-module-card' );
				var $feedback = $card.find( '.wp4odoo-module-save-feedback' );
				var settings = {};

				// Serialize mappings rows into their hidden JSON input before collecting.
				$card.find( '.wp4odoo-mappings-json' ).each( function() {
					var rows = [];
					$( this ).closest( 'td' ).find( '.wp4odoo-mapping-row' ).each( function() {
						var $row = $( this );
						var rule = {
							target_module: $row.find( '.wp4odoo-mapping-module' ).val(),
							entity_type:   $row.find( '.wp4odoo-mapping-entity' ).val(),
							acf_field:     $row.find( '.wp4odoo-mapping-acf' ).val(),
							odoo_field:    $row.find( '.wp4odoo-mapping-odoo' ).val(),
							type:          $row.find( '.wp4odoo-mapping-type' ).val()
						};
						if ( rule.target_module && rule.entity_type && rule.acf_field && rule.odoo_field ) {
							rows.push( rule );
						}
					} );
					$( this ).val( JSON.stringify( rows ) );
				} );

				$card.find( '.wp4odoo-module-setting' ).each( function() {
					var $input = $( this );
					var key    = $input.data( 'key' );

					if ( $input.is( ':checkbox' ) ) {
						settings[ key ] = $input.is( ':checked' ) ? '1' : '';
					} else {
						settings[ key ] = $input.val();
					}
				} );

				$feedback.text( '' ).removeClass( 'success error' );

				WP4Odoo.ajax( 'wp4odoo_save_module_settings', {
					module_id: moduleId,
					settings:  settings
				}, function( data ) {
					$feedback.addClass( 'success' ).text( data.message );
					setTimeout( function() { $feedback.fadeOut( 300, function() {
						$( this ).text( '' ).removeClass( 'success' ).show();
					} ); }, 3000 );
				}, $btn );
			} );
		},

		// ─── Queue tab ────────────────────────────────────────────

		bindRetryFailed: function() {
			$( '#wp4odoo-retry-failed' ).on( 'click', function() {
				if ( ! confirm( wp4odooAdmin.i18n.confirmBackup ) ) {
					return;
				}
				WP4Odoo.ajax( 'wp4odoo_retry_failed', {}, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
					WP4Odoo.refreshStats();
					WP4Odoo.fetchQueue( 1 );
				}, $( this ) );
			} );
		},

		bindCleanupQueue: function() {
			$( '#wp4odoo-cleanup-queue' ).on( 'click', function() {
				if ( ! confirm( wp4odooAdmin.i18n.confirmCleanup ) ) {
					return;
				}
				WP4Odoo.ajax( 'wp4odoo_cleanup_queue', { days: 7 }, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
					WP4Odoo.refreshStats();
					WP4Odoo.fetchQueue( 1 );
				}, $( this ) );
			} );
		},

		bindRefreshStats: function() {
			$( '#wp4odoo-refresh-stats' ).on( 'click', function() {
				WP4Odoo.refreshStats( $( this ) );
			} );
		},

		refreshStats: function( $btn ) {
			WP4Odoo.ajax( 'wp4odoo_queue_stats', {}, function( data ) {
				$( '#stat-pending' ).text( data.pending );
				$( '#stat-processing' ).text( data.processing );
				$( '#stat-completed' ).text( data.completed );
				$( '#stat-failed' ).text( data.failed );

				// Update last sync timestamp.
				var $lastSync = $( '#wp4odoo-last-sync' );
				if ( $lastSync.length ) {
					$lastSync.text( data.last_completed_at
						? ( wp4odooAdmin.i18n.lastSync || 'Last sync: %s' ).replace( '%s', data.last_completed_at )
						: ''
					);
				}
			}, $btn || null );
		},

		bindCancelJob: function() {
			$( '.wp4odoo-queue-table' ).on( 'click', '.wp4odoo-cancel-job', function( e ) {
				e.preventDefault();
				if ( ! confirm( wp4odooAdmin.i18n.confirmCancel ) ) {
					return;
				}
				var $link = $( this );
				var $row  = $link.closest( 'tr' );
				WP4Odoo.ajax( 'wp4odoo_cancel_job', {
					job_id: $link.data( 'id' )
				}, function() {
					$row.fadeOut( 200, function() { $( this ).remove(); } );
					WP4Odoo.refreshStats();
				}, null );
			} );
		},

		// ─── Bulk Operations ──────────────────────────────────────

		/**
		 * Bind a bulk action button with confirm dialog.
		 *
		 * @param {string} selector   jQuery selector for the button.
		 * @param {string} action     WP AJAX action name.
		 * @param {string} confirmKey Key in wp4odooAdmin.i18n for the confirm message.
		 */
		bindBulkAction: function( selector, action, confirmKey ) {
			$( selector ).on( 'click', function() {
				if ( ! confirm( wp4odooAdmin.i18n.confirmBackup ) ) {
					return;
				}
				if ( ! confirm( wp4odooAdmin.i18n[ confirmKey ] ) ) {
					return;
				}
				var $btn      = $( this );
				var $feedback = $( '#wp4odoo-bulk-feedback' );
				$feedback.text( wp4odooAdmin.i18n.loading || 'Processing...' );

				WP4Odoo.ajax( action, {}, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
					$feedback.text( data.message );
				}, $btn );
			} );
		},

		// ─── Logs tab ─────────────────────────────────────────────

		bindFilterLogs: function() {
			$( '#wp4odoo-filter-logs' ).on( 'click', function() {
				WP4Odoo.fetchLogs( 1 );
			} );
		},

		fetchLogs: function( page ) {
			var $btn   = $( '#wp4odoo-filter-logs' );
			var $tbody = $( '#wp4odoo-logs-tbody' );

			// Loading state.
			$tbody.html( '<tr><td colspan="5">' + ( wp4odooAdmin.i18n.loading || 'Loading...' ) + '</td></tr>' );

			var params = {
				level:     $( '#wp4odoo-log-level' ).val(),
				module:    $( '#wp4odoo-log-module' ).val(),
				date_from: $( '#wp4odoo-log-date-from' ).val(),
				date_to:   $( '#wp4odoo-log-date-to' ).val(),
				page:      page || 1,
				per_page:  50
			};

			WP4Odoo.ajax( 'wp4odoo_fetch_logs', params, function( data ) {
				$tbody.empty();

				if ( ! data.items || data.items.length === 0 ) {
					$tbody.append( '<tr><td colspan="5">' + wp4odooAdmin.i18n.noResults + '</td></tr>' );
					$( '#wp4odoo-logs-pagination' ).empty();
					return;
				}

				$.each( data.items, function( _, log ) {
					var ctx      = log.context || '';
					var ctxShort = ctx.length > 60 ? ctx.substring( 0, 60 ) + '...' : ctx;
					var escaped  = $( '<span>' ).text( log.message ).html();
					var ctxEsc   = $( '<span>' ).text( ctx ).html();
					var ctxSEsc  = $( '<span>' ).text( ctxShort ).html();

					$tbody.append(
						'<tr>' +
						'<td><span class="wp4odoo-badge wp4odoo-badge-' + escapeHtml( log.level ) + '">' + escapeHtml( log.level ) + '</span></td>' +
						'<td>' + escapeHtml( log.module || '—' ) + '</td>' +
						'<td>' + escaped + '</td>' +
						'<td><span class="wp4odoo-log-context" title="' + ctxEsc + '">' + ctxSEsc + '</span></td>' +
						'<td>' + log.created_at + '</td>' +
						'</tr>'
					);
				} );

				WP4Odoo.renderPagination( '#wp4odoo-logs-pagination', 'wp4odoo-log-page', data );
			}, $btn );
		},

		bindLogPagination: function() {
			$( document ).on( 'click', '.wp4odoo-log-page', function( e ) {
				e.preventDefault();
				WP4Odoo.fetchLogs( $( this ).data( 'page' ) );
			} );
		},

		bindPurgeLogs: function() {
			$( '#wp4odoo-purge-logs' ).on( 'click', function() {
				if ( ! confirm( wp4odooAdmin.i18n.confirmPurge ) ) {
					return;
				}
				WP4Odoo.ajax( 'wp4odoo_purge_logs', {}, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
					WP4Odoo.fetchLogs( 1 );
				}, $( this ) );
			} );
		},

		bindLogContextExpand: function() {
			$( document ).on( 'click', '.wp4odoo-log-context', function() {
				var $el = $( this );
				if ( $el.hasClass( 'expanded' ) ) {
					var title = $el.attr( 'title' ) || '';
					$el.removeClass( 'expanded' ).text(
						title.length > 60 ? title.substring( 0, 60 ) + '...' : title
					);
				} else {
					$el.addClass( 'expanded' ).text( $el.attr( 'title' ) || '' );
				}
			} );
		},

		// ─── Queue AJAX pagination ───────────────────────────────

		fetchQueue: function( page ) {
			var $tbody = $( '#wp4odoo-queue-tbody' );
			if ( ! $tbody.length ) {
				return;
			}

			// Loading state.
			$tbody.html( '<tr><td colspan="9">' + ( wp4odooAdmin.i18n.loading || 'Loading...' ) + '</td></tr>' );

			WP4Odoo.ajax( 'wp4odoo_fetch_queue', {
				page: page || 1,
				per_page: 30
			}, function( data ) {
				$tbody.empty();

				if ( ! data.items || data.items.length === 0 ) {
					$tbody.append( '<tr><td colspan="9">' + wp4odooAdmin.i18n.noResults + '</td></tr>' );
					$( '#wp4odoo-queue-pagination' ).empty();
					return;
				}

				var dirLabels = { wp_to_odoo: wp4odooAdmin.i18n.dirWpToOdoo || 'WP \u2192 Odoo', odoo_to_wp: wp4odooAdmin.i18n.dirOdooToWp || 'Odoo \u2192 WP' };
				var statusLabels = {
					pending:    wp4odooAdmin.i18n.statusPending    || 'Pending',
					processing: wp4odooAdmin.i18n.statusProcessing || 'Processing',
					completed:  wp4odooAdmin.i18n.statusCompleted  || 'Completed',
					failed:     wp4odooAdmin.i18n.statusFailed     || 'Failed'
				};

				$.each( data.items, function( _, job ) {
					var dirClass = ( job.direction === 'wp_to_odoo' ) ? 'wp4odoo-dir-wp2odoo' : 'wp4odoo-dir-odoo2wp';
					var dirLabel = dirLabels[ job.direction ] || job.direction;
					var statusLabel = statusLabels[ job.status ] || job.status;
					var titleAttr = ( job.status === 'failed' && job.error_message )
						? ' title="' + $( '<span>' ).text( job.error_message ).html() + '"' : '';
					var cancelCell = ( job.status === 'pending' )
						? '<a href="#" class="wp4odoo-cancel-job" data-id="' + job.id + '">' + ( wp4odooAdmin.i18n.cancel || 'Cancel' ) + '</a>'
						: '\u2014';

					$tbody.append(
						'<tr>' +
						'<td>' + job.id + '</td>' +
						'<td>' + $( '<span>' ).text( job.module ).html() + '</td>' +
						'<td>' + $( '<span>' ).text( job.entity_type ).html() + '</td>' +
						'<td><span class="' + dirClass + '">' + dirLabel + '</span></td>' +
						'<td>' + $( '<span>' ).text( job.action ).html() + '</td>' +
						'<td><span class="wp4odoo-badge wp4odoo-badge-' + job.status + '"' + titleAttr + '>' + statusLabel + '</span></td>' +
						'<td>' + job.attempts + '/' + job.max_attempts + '</td>' +
						'<td>' + $( '<span>' ).text( job.created_at ).html() + '</td>' +
						'<td>' + cancelCell + '</td>' +
						'</tr>'
					);
				} );

				WP4Odoo.renderPagination( '#wp4odoo-queue-pagination', 'wp4odoo-queue-page', data );
			}, null );
		},

		bindQueuePagination: function() {
			$( document ).on( 'click', '.wp4odoo-queue-page', function( e ) {
				e.preventDefault();
				WP4Odoo.fetchQueue( $( this ).data( 'page' ) );
			} );
		},

		// ─── Shared helpers ─────────────────────────────────────

		/**
		 * Render pagination buttons into a container.
		 *
		 * @param {string} containerId jQuery selector for the pagination wrapper.
		 * @param {string} pageClass   CSS class added to each page link.
		 * @param {object} data        Response data with `pages` and `page` properties.
		 */
		renderPagination: function( containerId, pageClass, data ) {
			var $pag = $( containerId );
			$pag.empty();
			if ( data.pages > 1 ) {
				for ( var i = 1; i <= data.pages; i++ ) {
					if ( i === data.page ) {
						$pag.append( '<span class="tablenav-pages-navspan button disabled">' + i + '</span> ' );
					} else {
						$pag.append( '<a href="#" class="button ' + pageClass + '" data-page="' + i + '">' + i + '</a> ' );
					}
				}
			}
		},

		// ─── Setup checklist ────────────────────────────────────

		bindDismissChecklist: function() {
			$( document ).on( 'click', '.wp4odoo-checklist-dismiss', function() {
				var $checklist = $( this ).closest( '.wp4odoo-checklist' );
				var nonce = $checklist.data( 'nonce' );

				$checklist.slideUp( 200, function() { $( this ).remove(); } );

				$.post( wp4odooAdmin.ajaxurl, {
					action: 'wp4odoo_dismiss_checklist',
					_ajax_nonce: nonce
				} );
			} );
		},

		bindConfirmWebhooks: function() {
			$( document ).on( 'click', '.wp4odoo-checklist-action[data-action="wp4odoo_confirm_webhooks"]', function( e ) {
				e.preventDefault();
				var $link      = $( this );
				var $checklist = $link.closest( '.wp4odoo-checklist' );
				var nonce      = $checklist.data( 'nonce' );
				var $li        = $link.closest( 'li' );

				$.post( wp4odooAdmin.ajaxurl, {
					action: 'wp4odoo_confirm_webhooks',
					_ajax_nonce: nonce
				}, function( response ) {
					if ( response.success ) {
						$li.addClass( 'done' ).find( '.wp4odoo-checklist-icon' ).html( '&#10003;' );
						$link.replaceWith( $link.text() );

						// Check if all steps are now done.
						var $steps = $checklist.find( '.wp4odoo-checklist-steps li' );
						var doneCount = $steps.filter( '.done' ).length;
						if ( doneCount === $steps.length ) {
							$checklist.slideUp( 200, function() { $( this ).remove(); } );
						} else {
							// Update progress bar and text.
							var pct = Math.round( ( doneCount / $steps.length ) * 100 );
							$checklist.find( '.wp4odoo-checklist-bar-fill' ).css( 'width', pct + '%' );
							$checklist.find( '.wp4odoo-checklist-progress-text' ).text( doneCount + ' / ' + $steps.length + ' ' + ( wp4odooAdmin.i18n.completed || 'completed' ) );
						}
					}
				} );
			} );
		},

		// ─── Connection form validation ──────────────────────────

		bindConnectionValidation: function() {
			var $btn = $( '#wp4odoo-test-connection' );
			var $fields = $( '#wp4odoo_url, #wp4odoo_database, #wp4odoo_username' );

			if ( ! $btn.length ) {
				return;
			}

			function checkFields() {
				var allFilled = true;
				$fields.each( function() {
					if ( ! $( this ).val().trim() ) {
						allFilled = false;
						return false; // break
					}
				} );
				$btn.prop( 'disabled', ! allFilled );
			}

			$fields.on( 'input', checkFields );
			checkFields(); // initial state
		},

		// ─── Language detection ─────────────────────────────────

		bindDetectLanguages: function() {
			$( document ).on( 'click', '.wp4odoo-detect-languages', function() {
				var $btn    = $( this );
				var $panel  = $btn.closest( '.wp4odoo-languages-panel' );
				var $status = $panel.find( '.wp4odoo-languages-status' );
				var $list   = $panel.find( '.wp4odoo-languages-list' );
				var $hidden = $panel.siblings( 'input.wp4odoo-module-setting' );
				var enabled = ( $hidden.val() || '' ).split( ',' ).filter( Boolean );

				$status.text( wp4odooAdmin.i18n.detectingLanguages || 'Detecting languages...' );
				$list.hide().empty();

				WP4Odoo.ajax( 'wp4odoo_detect_languages', {}, function( data ) {
					if ( ! data.available ) {
						$status.text( data.message );
						return;
					}

					$status.html(
						'<strong>' + $( '<span>' ).text( data.plugin ).html() + '</strong> \u2014 ' +
						( wp4odooAdmin.i18n.defaultLang || 'default' ) + ': ' + data.default.toUpperCase()
					);

					$.each( data.languages, function( code, lang ) {
						if ( code === data.default ) {
							return; // Skip default language.
						}
						var checked = enabled.indexOf( code ) !== -1 ? ' checked' : '';
						var icon = lang.odoo_available ? '&#x2705;' : '&#x274C;';
						var cls  = lang.odoo_available ? 'wp4odoo-lang-ok' : 'wp4odoo-lang-missing';

						$list.append(
							'<label class="wp4odoo-lang-toggle">' +
							'<input type="checkbox" class="wp4odoo-lang-checkbox" value="' + code + '"' + checked + ' /> ' +
							'<span class="' + cls + '">' + icon + '</span> ' +
							code.toUpperCase() + ' <small>(' + $( '<span>' ).text( lang.odoo_locale ).html() + ')</small>' +
							'</label>'
						);
					} );

					$list.show();

					// Sync checkboxes to hidden input.
					$list.off( 'change' ).on( 'change', '.wp4odoo-lang-checkbox', function() {
						var selected = [];
						$list.find( '.wp4odoo-lang-checkbox:checked' ).each( function() {
							selected.push( $( this ).val() );
						} );
						$hidden.val( selected.join( ',' ) );
					} );
				}, $btn );
			} );
		},

		// ─── ACF Mappings UI ─────────────────────────────────────

		/**
		 * Key-value mapping panels (tax/shipping mapping).
		 *
		 * Fetches Odoo items via AJAX and renders a two-column table
		 * with WC keys and Odoo dropdowns.
		 */
		bindKeyValueMapping: function() {
			$( document ).on( 'click', '.wp4odoo-load-kv-options', function() {
				var $btn   = $( this );
				var $panel = $btn.closest( '.wp4odoo-kv-mapping-panel' );
				var action = $panel.data( 'ajax' );
				var key    = $panel.data( 'key' );
				var $rows  = $panel.find( '.wp4odoo-kv-rows' );
				var $status = $panel.find( '.wp4odoo-kv-status' );
				var $hidden = $panel.closest( 'td' ).find( '.wp4odoo-kv-json[data-key="' + key + '"]' );
				var existing = {};

				try {
					existing = JSON.parse( $hidden.val() || '{}' );
				} catch ( e ) {
					existing = {};
				}

				$status.text( wp4odooAdmin.i18n.loading || 'Loading…' );

				WP4Odoo.ajax( action, {}, function( data ) {
					var items = data.items || [];
					$status.text( '' );
					$rows.empty();

					if ( ! items.length ) {
						$rows.html( '<em>' + ( wp4odooAdmin.i18n.noResults || 'No items found in Odoo.' ) + '</em>' );
						return;
					}

					// Build Odoo options.
					var odooOpts = '<option value="">' + ( wp4odooAdmin.i18n.selectOdoo || '— None —' ) + '</option>';
					$.each( items, function( i, item ) {
						var label = item.name + ( item.amount !== undefined ? ' (' + item.amount + '%)' : '' );
						odooOpts += '<option value="' + item.id + '">' + label + '</option>';
					} );

					// Detect WC keys based on the field key.
					var wcKeys = [];
					if ( key === 'tax_mapping' ) {
						wcKeys = [ 'standard' ];
						if ( typeof wc_tax_classes !== 'undefined' ) {
							wcKeys = wc_tax_classes;
						}
					} else if ( key === 'shipping_mapping' ) {
						wcKeys = [ 'flat_rate', 'free_shipping', 'local_pickup' ];
						if ( typeof wc_shipping_methods !== 'undefined' ) {
							wcKeys = wc_shipping_methods;
						}
					}

					// Render rows.
					$.each( wcKeys, function( i, wcKey ) {
						var sel = existing[ wcKey ] || '';
						var $row = $(
							'<div class="wp4odoo-kv-row" style="margin-bottom:4px;">' +
							'<code>' + wcKey + '</code> &rarr; ' +
							'<select class="wp4odoo-kv-select" data-wc-key="' + wcKey + '">' +
							odooOpts +
							'</select>' +
							'</div>'
						);
						if ( sel ) {
							$row.find( 'select' ).val( String( sel ) );
						}
						$rows.append( $row );
					} );

					// Sync selects to hidden JSON on change.
					$rows.off( 'change' ).on( 'change', '.wp4odoo-kv-select', function() {
						var mapping = {};
						$rows.find( '.wp4odoo-kv-select' ).each( function() {
							var v = $( this ).val();
							if ( v ) {
								mapping[ $( this ).data( 'wc-key' ) ] = parseInt( v, 10 );
							}
						} );
						$hidden.val( JSON.stringify( mapping ) );
					} );
				}, $btn );
			} );

			// Serialize key_value JSON before save (in case user saved without clicking Load).
			$( document ).on( 'click', '.wp4odoo-save-module-settings', function() {
				var $card = $( this ).closest( '.wp4odoo-module-card' );
				$card.find( '.wp4odoo-kv-mapping-panel' ).each( function() {
					var $panel = $( this );
					var key    = $panel.data( 'key' );
					var $rows  = $panel.find( '.wp4odoo-kv-rows' );
					var $hidden = $panel.closest( 'td' ).find( '.wp4odoo-kv-json[data-key="' + key + '"]' );
					var mapping = {};

					$rows.find( '.wp4odoo-kv-select' ).each( function() {
						var v = $( this ).val();
						if ( v ) {
							mapping[ $( this ).data( 'wc-key' ) ] = parseInt( v, 10 );
						}
					} );

					if ( Object.keys( mapping ).length > 0 ) {
						$hidden.val( JSON.stringify( mapping ) );
					}
				} );
			} );
		},

		bindMappingsUI: function() {
			// Add a new mapping row.
			$( document ).on( 'click', '.wp4odoo-add-mapping-row', function() {
				var $card  = $( this ).closest( '.wp4odoo-module-card' );
				var $tbody = $card.find( '.wp4odoo-mappings-rows' );

				// Clone option list from the first module select (if exists) or build from scratch.
				var $existing = $card.find( '.wp4odoo-mapping-module:first' );
				var moduleOptions = '';
				if ( $existing.length ) {
					moduleOptions = $existing.html();
				}

				// Build type options.
				var types = [ 'text', 'number', 'integer', 'boolean', 'date', 'datetime', 'html', 'select', 'binary' ];
				var typeOptions = '';
				$.each( types, function( i, t ) {
					typeOptions += '<option value="' + t + '">' + t + '</option>';
				} );

				var $row = $(
					'<tr class="wp4odoo-mapping-row">' +
					'<td><select class="wp4odoo-mapping-module">' + moduleOptions + '</select></td>' +
					'<td><select class="wp4odoo-mapping-entity"><option value="">—</option></select></td>' +
					'<td><input type="text" class="wp4odoo-mapping-acf regular-text" placeholder="company_size" /></td>' +
					'<td><input type="text" class="wp4odoo-mapping-odoo regular-text" placeholder="x_company_size" /></td>' +
					'<td><select class="wp4odoo-mapping-type">' + typeOptions + '</select></td>' +
					'<td><button type="button" class="button wp4odoo-remove-mapping-row" title="' + ( wp4odooAdmin.i18n.remove || 'Remove' ) + '">&times;</button></td>' +
					'</tr>'
				);

				// Reset the module select to empty.
				$row.find( '.wp4odoo-mapping-module' ).val( '' );
				$tbody.append( $row );
			} );

			// Remove a mapping row.
			$( document ).on( 'click', '.wp4odoo-remove-mapping-row', function() {
				$( this ).closest( '.wp4odoo-mapping-row' ).remove();
			} );

			// Update entity select when module changes.
			$( document ).on( 'change', '.wp4odoo-mapping-module', function() {
				var $select  = $( this );
				var $entity  = $select.closest( 'tr' ).find( '.wp4odoo-mapping-entity' );
				var entities = $select.find( ':selected' ).data( 'entities' ) || [];

				$entity.empty();
				if ( entities.length ) {
					$.each( entities, function( i, etype ) {
						$entity.append( '<option value="' + etype + '">' + etype + '</option>' );
					} );
				} else {
					$entity.append( '<option value="">—</option>' );
				}
			} );
		}
	};

	$( document ).ready( function() {
		WP4Odoo.init();
	} );

})( jQuery );
