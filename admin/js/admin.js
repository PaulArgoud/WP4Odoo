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
			this.bindBulkImport();
			this.bindBulkExport();
			this.bindFilterLogs();
			this.bindPurgeLogs();
			this.bindLogPagination();
			this.bindLogContextExpand();
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

				WP4Odoo.ajax( 'wp4odoo_toggle_module', {
					module_id: moduleId,
					enabled:   enabled ? 1 : 0
				}, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
				}, null );
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

		bindBulkImport: function() {
			$( '#wp4odoo-bulk-import' ).on( 'click', function() {
				if ( ! confirm( wp4odooAdmin.i18n.confirmBulkImport ) ) {
					return;
				}
				WP4Odoo.ajax( 'wp4odoo_bulk_import_products', {}, function( data ) {
					WP4Odoo.showNotice( 'success', data.message );
				}, $( this ) );
			} );
		},

		bindBulkExport: function() {
			$( '#wp4odoo-bulk-export' ).on( 'click', function() {
				if ( ! confirm( wp4odooAdmin.i18n.confirmBulkExport ) ) {
					return;
				}
				WP4Odoo.ajax( 'wp4odoo_bulk_export_products', {}, function( data ) {
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
			var params = {
				level:     $( '#wp4odoo-log-level' ).val(),
				module:    $( '#wp4odoo-log-module' ).val(),
				date_from: $( '#wp4odoo-log-date-from' ).val(),
				date_to:   $( '#wp4odoo-log-date-to' ).val(),
				page:      page || 1,
				per_page:  50
			};

			WP4Odoo.ajax( 'wp4odoo_fetch_logs', params, function( data ) {
				var $tbody = $( '#wp4odoo-logs-tbody' );
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

				// Pagination.
				var $pag = $( '#wp4odoo-logs-pagination' );
				$pag.empty();
				if ( data.pages > 1 ) {
					for ( var i = 1; i <= data.pages; i++ ) {
						if ( i === data.page ) {
							$pag.append( '<span class="tablenav-pages-navspan button disabled">' + i + '</span> ' );
						} else {
							$pag.append( '<a href="#" class="button wp4odoo-log-page" data-page="' + i + '">' + i + '</a> ' );
						}
					}
				}
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
		}
	};

	$( document ).ready( function() {
		WP4Odoo.init();
	} );

})( jQuery );
