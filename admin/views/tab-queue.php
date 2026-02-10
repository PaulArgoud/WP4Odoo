<?php
/**
 * Queue tab template.
 *
 * @var array $stats    Queue statistics from Sync_Engine::get_stats().
 * @var int   $page     Current page number.
 * @var int   $per_page Results per page.
 * @var array $jobs     {items: array, total: int, pages: int}.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$direction_labels = [
	'wp_to_odoo' => 'WP → Odoo',
	'odoo_to_wp' => 'Odoo → WP',
];

$status_labels = [
	'pending'    => __( 'Pending', 'wp4odoo' ),
	'processing' => __( 'Processing', 'wp4odoo' ),
	'completed'  => __( 'Completed', 'wp4odoo' ),
	'failed'     => __( 'Failed', 'wp4odoo' ),
];
?>
<h2><?php esc_html_e( 'Queue', 'wp4odoo' ); ?></h2>

<!-- Stats cards -->
<div class="wp4odoo-stats-grid">
	<div class="wp4odoo-stat-card wp4odoo-stat-pending">
		<span class="wp4odoo-stat-number" id="stat-pending"><?php echo esc_html( (string) $stats['pending'] ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Pending', 'wp4odoo' ); ?></span>
	</div>
	<div class="wp4odoo-stat-card wp4odoo-stat-processing">
		<span class="wp4odoo-stat-number" id="stat-processing"><?php echo esc_html( (string) $stats['processing'] ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Processing', 'wp4odoo' ); ?></span>
	</div>
	<div class="wp4odoo-stat-card wp4odoo-stat-completed">
		<span class="wp4odoo-stat-number" id="stat-completed"><?php echo esc_html( (string) $stats['completed'] ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Completed', 'wp4odoo' ); ?></span>
	</div>
	<div class="wp4odoo-stat-card wp4odoo-stat-failed">
		<span class="wp4odoo-stat-number" id="stat-failed"><?php echo esc_html( (string) $stats['failed'] ); ?></span>
		<span class="wp4odoo-stat-label"><?php esc_html_e( 'Failed', 'wp4odoo' ); ?></span>
	</div>
</div>

<!-- Action buttons -->
<div class="wp4odoo-actions">
	<button type="button" id="wp4odoo-retry-failed" class="button button-secondary">
		<?php esc_html_e( 'Retry failed', 'wp4odoo' ); ?>
	</button>
	<button type="button" id="wp4odoo-cleanup-queue" class="button button-secondary">
		<?php esc_html_e( 'Clean up (> 7 days)', 'wp4odoo' ); ?>
	</button>
	<button type="button" id="wp4odoo-refresh-stats" class="button button-secondary">
		<?php esc_html_e( 'Refresh', 'wp4odoo' ); ?>
	</button>
	<span class="wp4odoo-last-sync" id="wp4odoo-last-sync">
		<?php
		$last = $stats['last_completed_at'] ?? '';
		if ( $last ) {
			printf(
				/* translators: %s: datetime string */
				esc_html__( 'Last sync: %s', 'wp4odoo' ),
				esc_html( $last )
			);
		}
		?>
	</span>
</div>

<!-- Jobs table -->
<div class="wp4odoo-table-wrap">
<table class="widefat striped wp4odoo-queue-table">
	<thead>
		<tr>
			<th>ID</th>
			<th><?php esc_html_e( 'Module', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Type', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Direction', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Action', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Status', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Attempts', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Created at', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'wp4odoo' ); ?></th>
		</tr>
	</thead>
	<tbody id="wp4odoo-queue-tbody">
		<?php if ( empty( $jobs['items'] ) ) : ?>
			<tr>
				<td colspan="9"><?php esc_html_e( 'No jobs in the queue.', 'wp4odoo' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $jobs['items'] as $job ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $job->id ); ?></td>
					<td><?php echo esc_html( $job->module ); ?></td>
					<td><?php echo esc_html( $job->entity_type ); ?></td>
					<td>
						<?php
						$dir_class = ( 'wp_to_odoo' === $job->direction ) ? 'wp4odoo-dir-wp2odoo' : 'wp4odoo-dir-odoo2wp';
						$dir_label = $direction_labels[ $job->direction ] ?? $job->direction;
						?>
						<span class="<?php echo esc_attr( $dir_class ); ?>">
							<?php echo esc_html( $dir_label ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $job->action ); ?></td>
					<td>
						<span class="wp4odoo-badge wp4odoo-badge-<?php echo esc_attr( $job->status ); ?>"
							<?php if ( 'failed' === $job->status && ! empty( $job->error_message ) ) : ?>
								title="<?php echo esc_attr( $job->error_message ); ?>"
							<?php endif; ?>>
							<?php echo esc_html( $status_labels[ $job->status ] ?? $job->status ); ?>
						</span>
					</td>
					<td>
						<?php echo esc_html( $job->attempts . '/' . $job->max_attempts ); ?>
					</td>
					<td><?php echo esc_html( $job->created_at ); ?></td>
					<td>
						<?php if ( 'pending' === $job->status ) : ?>
							<a href="#" class="wp4odoo-cancel-job" data-id="<?php echo esc_attr( (string) $job->id ); ?>">
								<?php esc_html_e( 'Cancel', 'wp4odoo' ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>
</div>

<!-- Pagination (AJAX-driven) -->
<div class="tablenav bottom">
	<div class="tablenav-pages" id="wp4odoo-queue-pagination">
		<?php if ( $jobs['pages'] > 1 ) : ?>
			<span class="displaying-num">
				<?php
				printf(
					/* translators: %s: number of items */
					esc_html__( '%s item(s)', 'wp4odoo' ),
					esc_html( number_format_i18n( $jobs['total'] ) )
				);
				?>
			</span>
			<span class="pagination-links">
				<?php for ( $i = 1; $i <= $jobs['pages']; $i++ ) : ?>
					<?php if ( $i === $page ) : ?>
						<span class="tablenav-pages-navspan button disabled"><?php echo esc_html( (string) $i ); ?></span>
					<?php else : ?>
						<a href="#" class="button wp4odoo-queue-page" data-page="<?php echo esc_attr( (string) $i ); ?>">
							<?php echo esc_html( (string) $i ); ?>
						</a>
					<?php endif; ?>
				<?php endfor; ?>
			</span>
		<?php endif; ?>
	</div>
</div>
