/**
 * WordPress For Odoo — Admin JavaScript
 *
 * Handles AJAX interactions for all admin tabs.
 */
(function( $ ) {
	'use strict';

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
				_ajax_nonce: wp4odooAdmin.nonce
			}, data ), function( response ) {
				if ( $button ) {
					$button.prop( 'disabled', false ).removeClass( 'updating-message' );
				}
				if ( response.success ) {
					onSuccess( response.data );
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : 'Unknown error.';
					WP4Odoo.showNotice( 'error', msg );
				}
			} ).fail( function() {
				if ( $button ) {
					$button.prop( 'disabled', false ).removeClass( 'updating-message' );
				}
				WP4Odoo.showNotice( 'error', 'Server communication error.' );
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
					_ajax_nonce: wp4odooAdmin.nonce,
					module_id: moduleId,
					enabled: enabled ? 1 : 0
				}, function( response ) {
					$toggle.prop( 'disabled', false );
					if ( response.success ) {
						WP4Odoo.showNotice( 'success', response.data.message );
					} else {
						// Revert toggle on failure.
						$toggle.prop( 'checked', ! enabled );
						if ( enabled ) {
							$panel.slideUp( 200 );
						} else {
							$panel.slideDown( 200 );
						}
						var msg = ( response.data && response.data.message ) ? response.data.message : 'Unknown error.';
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
					WP4Odoo.showNotice( 'error', 'Server communication error.' );
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
				if ( ! confirm( wp4odooAdmin.i18n[ confirmKey ] ) ) {
					return;
				}
				WP4Odoo.ajax( action, {}, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
				}, $( this ) );
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
						'<td><span class="wp4odoo-badge wp4odoo-badge-' + log.level + '">' + log.level + '</span></td>' +
						'<td>' + ( log.module || '—' ) + '</td>' +
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

				var dirLabels = { wp_to_odoo: 'WP \u2192 Odoo', odoo_to_wp: 'Odoo \u2192 WP' };
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
							$checklist.find( '.wp4odoo-checklist-progress-text' ).text( doneCount + ' / ' + $steps.length + ' completed' );
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
		}
	};

	$( document ).ready( function() {
		WP4Odoo.init();
	} );

})( jQuery );
