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
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Determine overall system status.
$is_cb_open    = ! empty( $cb_state['opened_at'] );
$has_warnings  = ! empty( $cron_warning ) || ! empty( $version_warnings ) || $is_cb_open;
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
	<div class="wp4odoo-stat-card <?php echo $success_rate < 90.0 ? 'wp4odoo-stat-failed' : 'wp4odoo-stat-completed'; ?>">
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
