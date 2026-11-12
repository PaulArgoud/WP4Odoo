<?php
/**
 * Health tab template.
 *
 * @var array  $queue_stats      Queue statistics from Queue_Manager::get_stats().
 * @var array  $health_metrics   Health metrics from Queue_Manager::get_health_metrics().
 * @var array  $cb_state         Circuit breaker state from wp4odoo_cb_state option.
 * @var int    $booted_count     Number of booted modules.
 * @var array  $version_warnings Version warnings from Module_Registry.
 * @var string $cron_warning     Cron warning message (empty if healthy).
 * @var int    $next_cron        Next scheduled cron run timestamp (0 if unscheduled).
 * @var array  $open_modules     Per-module circuit breaker open states (module_id => {failures, opened_at}).
 * @var array  $missing_tables   Plugin database tables that do not exist (without the WordPress prefix).
 * @var mixed  $db_error         Last table-creation failure (array with `tables` and `error`) or empty.
 * @var array  $schema_drops     Fields dropped from pushes (model.field => {count, modules, last}).
 * @var string $odoo_version     Odoo server version detected by the last connection test.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Determine overall system status.
$is_cb_open    = ! empty( $cb_state['opened_at'] );
$has_module_cb = ! empty( $open_modules );
$has_warnings  = ! empty( $cron_warning ) || ! empty( $version_warnings ) || $is_cb_open || $has_module_cb || ! empty( $missing_tables ) || ! empty( $schema_drops );
$success_rate  = $health_metrics['success_rate'] ?? 100.0;
$is_degraded   = $has_warnings || $success_rate < 90.0;
$status_class  = $is_degraded ? 'wp4odoo-health-degraded' : 'wp4odoo-health-ok';
$status_label  = $is_degraded
	? __( 'Degraded', 'wp4odoo' )
	: __( 'Healthy', 'wp4odoo' );
$warning_count = count( $version_warnings );
?>
<h2><?php esc_html_e( 'Health', 'wp4odoo' ); ?></h2>

<!-- System status banner -->
<div class="wp4odoo-health-banner <?php echo esc_attr( $status_class ); ?>">
	<span class="wp4odoo-health-indicator"></span>
	<strong><?php esc_html_e( 'System Status', 'wp4odoo' ); ?>:</strong>
	<?php echo esc_html( $status_label ); ?>
</div>

<!-- Stats grid -->
<div class="wp4odoo-stats-grid">
	<div class="wp4odoo-stat-card wp4odoo-stat-completed">
		<span class="wp4odoo-stat-number"><?php echo esc_html( (string) $booted_count ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Active modules', 'wp4odoo' ); ?></span>
	</div>
	<div class="wp4odoo-stat-card wp4odoo-stat-pending">
		<span class="wp4odoo-stat-number"><?php echo esc_html( (string) $queue_stats['pending'] ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Pending', 'wp4odoo' ); ?></span>
	</div>
	<div class="wp4odoo-stat-card wp4odoo-stat-processing">
		<span class="wp4odoo-stat-number">
			<?php
			$latency = $health_metrics['avg_latency_seconds'] ?? 0.0;
			echo esc_html( number_format( $latency, 1 ) . 's' );
			?>
		</span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Average latency', 'wp4odoo' ); ?></span>
	</div>
	<div class="wp4odoo-stat-card <?php echo esc_attr( $success_rate < 90.0 ? 'wp4odoo-stat-failed' : 'wp4odoo-stat-completed' ); ?>">
		<span class="wp4odoo-stat-number"><?php echo esc_html( number_format( $success_rate, 1 ) . '%' ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Success rate', 'wp4odoo' ); ?></span>
	</div>
</div>

<!-- Detail rows -->
<table class="widefat striped wp4odoo-health-details">
	<tbody>
		<!-- Circuit Breaker -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Circuit Breaker', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( $is_cb_open ) : ?>
					<span class="wp4odoo-badge wp4odoo-badge-failed">
						<?php esc_html_e( 'Open', 'wp4odoo' ); ?>
					</span>
					<span class="description">
						<?php
						printf(
							/* translators: %1$d: failure count, %2$s: datetime */
							esc_html__( '%1$d failures — opened %2$s', 'wp4odoo' ),
							(int) ( $cb_state['failures'] ?? 0 ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cb_state['opened_at'] ) )
						);
						?>
					</span>
				<?php else : ?>
					<span class="wp4odoo-badge wp4odoo-badge-completed">
						<?php esc_html_e( 'Closed', 'wp4odoo' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>

		<!-- Module Circuit Breakers -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Module breakers', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( $has_module_cb ) : ?>
					<?php foreach ( $open_modules as $mod_id => $mod_state ) : ?>
						<span class="wp4odoo-badge wp4odoo-badge-warning">
							<?php echo esc_html( $mod_id ); ?>
						</span>
						<span class="description">
							<?php
							printf(
								/* translators: 1: failure count, 2: datetime */
								esc_html__( '%1$d failures — opened %2$s', 'wp4odoo' ),
								(int) ( $mod_state['failures'] ?? 0 ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $mod_state['opened_at'] ) )
							);
							?>
						</span><br>
					<?php endforeach; ?>
				<?php else : ?>
					<span class="wp4odoo-badge wp4odoo-badge-completed">
						<?php esc_html_e( 'All closed', 'wp4odoo' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>

		<!-- Next cron run -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Next cron run', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( $next_cron ) : ?>
					<?php
					$diff = $next_cron - time();
					if ( $diff > 0 ) {
						printf(
							/* translators: %s: human time diff */
							esc_html__( 'in %s', 'wp4odoo' ),
							esc_html( human_time_diff( time(), $next_cron ) )
						);
					} else {
						esc_html_e( 'overdue', 'wp4odoo' );
					}
					?>
				<?php else : ?>
					<span class="wp4odoo-badge wp4odoo-badge-warning">
						<?php esc_html_e( 'Not scheduled', 'wp4odoo' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>

		<!-- Cron status -->
		<?php if ( ! empty( $cron_warning ) ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Cron status', 'wp4odoo' ); ?></th>
			<td>
				<span class="wp4odoo-badge wp4odoo-badge-warning">
					<?php echo esc_html( $cron_warning ); ?>
				</span>
			</td>
		</tr>
		<?php endif; ?>

		<!-- Odoo version -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Odoo version', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( '' !== $odoo_version ) : ?>
					<?php echo esc_html( $odoo_version ); ?>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Unknown — run a connection test in the Connection tab.', 'wp4odoo' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>

		<!-- Database tables -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Database tables', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( empty( $missing_tables ) ) : ?>
					<span class="wp4odoo-badge wp4odoo-badge-completed">
						<?php esc_html_e( 'All present', 'wp4odoo' ); ?>
					</span>
				<?php else : ?>
					<span class="wp4odoo-badge wp4odoo-badge-failed">
						<?php esc_html_e( 'Missing', 'wp4odoo' ); ?>
					</span>
					<code><?php echo esc_html( implode( ', ', $missing_tables ) ); ?></code>
					<p class="description">
						<?php esc_html_e( 'Synchronization cannot work without these tables. The plugin retries creating them on every admin page load; if they stay missing, the database user probably lacks the CREATE privilege.', 'wp4odoo' ); ?>
						<?php if ( is_array( $db_error ) && ! empty( $db_error['error'] ) ) : ?>
							<br><?php echo esc_html( sprintf( /* translators: %s: database error message */ __( 'Database error: %s', 'wp4odoo' ), (string) $db_error['error'] ) ); ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>

		<!-- Fields skipped on push -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Fields skipped on push', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( empty( $schema_drops ) ) : ?>
					0
				<?php else : ?>
					<span class="wp4odoo-badge wp4odoo-badge-warning"><?php echo esc_html( (string) count( $schema_drops ) ); ?></span>
					<p class="description">
						<?php esc_html_e( 'These fields do not exist in the Odoo version you are connected to, so they were left out of the data sent to Odoo. The rest of each record is still synchronized.', 'wp4odoo' ); ?>
					</p>
					<ul class="wp4odoo-health-warnings-list">
						<?php foreach ( $schema_drops as $field_key => $drop ) : ?>
							<li>
								<code><?php echo esc_html( (string) $field_key ); ?></code>
								—
								<?php echo esc_html( implode( ', ', (array) ( $drop['modules'] ?? [] ) ) ); ?>
								(<?php echo esc_html( sprintf( /* translators: %d: number of times */ _n( '%d time', '%d times', (int) ( $drop['count'] ?? 0 ), 'wp4odoo' ), (int) ( $drop['count'] ?? 0 ) ) ); ?>,
								<?php echo esc_html( sprintf( /* translators: %s: human time difference */ __( 'last %s ago', 'wp4odoo' ), human_time_diff( (int) ( $drop['last'] ?? time() ), time() ) ) ); ?>)
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</td>
		</tr>

		<!-- Compatibility warnings -->
		<tr>
			<th scope="row"><?php esc_html_e( 'Compatibility warnings', 'wp4odoo' ); ?></th>
			<td>
				<?php if ( $warning_count > 0 ) : ?>
					<span class="wp4odoo-badge wp4odoo-badge-warning">
						<?php echo esc_html( (string) $warning_count ); ?>
					</span>
					<ul class="wp4odoo-health-warnings-list">
						<?php foreach ( $version_warnings as $module_id => $notices ) : ?>
							<?php foreach ( $notices as $notice ) : ?>
								<li>
									<strong><?php echo esc_html( $module_id ); ?>:</strong>
									<?php echo esc_html( $notice['message'] ); ?>
								</li>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					0
				<?php endif; ?>
			</td>
		</tr>
	</tbody>
</table>

<!-- Queue Depth by Module -->
<?php
$depth_by_module = $health_metrics['depth_by_module'] ?? [];
if ( ! empty( $depth_by_module ) ) :
	?>
<h3><?php esc_html_e( 'Queue Depth by Module', 'wp4odoo' ); ?></h3>
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Module', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Pending', 'wp4odoo' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $depth_by_module as $module_name => $depth ) : ?>
			<tr>
				<td><?php echo esc_html( $module_name ); ?></td>
				<td><?php echo esc_html( (string) $depth ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
