<?php
/**
 * Sync settings tab template.
 *
 * @var array $sync_settings Current sync settings.
 * @var array $log_settings  Current log settings.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="options.php">
	<?php settings_fields( 'wp4odoo_sync_group' ); ?>

	<h2><?php esc_html_e( 'Synchronization', 'wp4odoo' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="wp4odoo_direction"><?php esc_html_e( 'Direction', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<select id="wp4odoo_direction" name="wp4odoo_sync_settings[direction]">
					<option value="bidirectional" <?php selected( $sync_settings['direction'] ?? '', 'bidirectional' ); ?>>
						<?php esc_html_e( 'Bidirectional', 'wp4odoo' ); ?>
					</option>
					<option value="wp_to_odoo" <?php selected( $sync_settings['direction'] ?? '', 'wp_to_odoo' ); ?>>
						<?php esc_html_e( 'WordPress → Odoo', 'wp4odoo' ); ?>
					</option>
					<option value="odoo_to_wp" <?php selected( $sync_settings['direction'] ?? '', 'odoo_to_wp' ); ?>>
						<?php esc_html_e( 'Odoo → WordPress', 'wp4odoo' ); ?>
					</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_conflict"><?php esc_html_e( 'Conflict rule', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<select id="wp4odoo_conflict" name="wp4odoo_sync_settings[conflict_rule]">
					<option value="newest_wins" <?php selected( $sync_settings['conflict_rule'] ?? '', 'newest_wins' ); ?>>
						<?php esc_html_e( 'Newest wins', 'wp4odoo' ); ?>
					</option>
					<option value="odoo_wins" <?php selected( $sync_settings['conflict_rule'] ?? '', 'odoo_wins' ); ?>>
						<?php esc_html_e( 'Odoo priority', 'wp4odoo' ); ?>
					</option>
					<option value="wp_wins" <?php selected( $sync_settings['conflict_rule'] ?? '', 'wp_wins' ); ?>>
						<?php esc_html_e( 'WordPress priority', 'wp4odoo' ); ?>
					</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_batch"><?php esc_html_e( 'Batch size', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="number" id="wp4odoo_batch" name="wp4odoo_sync_settings[batch_size]"
					value="<?php echo esc_attr( (string) ( $sync_settings['batch_size'] ?? 50 ) ); ?>"
					min="1" max="500" class="small-text" />
				<p class="description">
					<?php esc_html_e( 'Number of jobs processed per cron run.', 'wp4odoo' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_interval"><?php esc_html_e( 'Interval', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<select id="wp4odoo_interval" name="wp4odoo_sync_settings[sync_interval]">
					<option value="wp4odoo_five_minutes" <?php selected( $sync_settings['sync_interval'] ?? '', 'wp4odoo_five_minutes' ); ?>>
						<?php esc_html_e( 'Every 5 minutes', 'wp4odoo' ); ?>
					</option>
					<option value="wp4odoo_fifteen_minutes" <?php selected( $sync_settings['sync_interval'] ?? '', 'wp4odoo_fifteen_minutes' ); ?>>
						<?php esc_html_e( 'Every 15 minutes', 'wp4odoo' ); ?>
					</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Auto sync', 'wp4odoo' ); ?>
			</th>
			<td>
				<label for="wp4odoo_auto_sync">
					<input type="checkbox" id="wp4odoo_auto_sync" name="wp4odoo_sync_settings[auto_sync]"
						value="1" <?php checked( ! empty( $sync_settings['auto_sync'] ) ); ?> />
					<?php esc_html_e( 'Enable automatic sync via cron', 'wp4odoo' ); ?>
				</label>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Logging', 'wp4odoo' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Enable logging', 'wp4odoo' ); ?>
			</th>
			<td>
				<label for="wp4odoo_log_enabled">
					<input type="checkbox" id="wp4odoo_log_enabled" name="wp4odoo_log_settings[enabled]"
						value="1" <?php checked( ! empty( $log_settings['enabled'] ) ); ?> />
					<?php esc_html_e( 'Log events to the database', 'wp4odoo' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Critical messages are always logged.', 'wp4odoo' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_log_level"><?php esc_html_e( 'Minimum level', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<select id="wp4odoo_log_level" name="wp4odoo_log_settings[level]">
					<option value="debug" <?php selected( $log_settings['level'] ?? '', 'debug' ); ?>>Debug</option>
					<option value="info" <?php selected( $log_settings['level'] ?? '', 'info' ); ?>>Info</option>
					<option value="warning" <?php selected( $log_settings['level'] ?? '', 'warning' ); ?>>Warning</option>
					<option value="error" <?php selected( $log_settings['level'] ?? '', 'error' ); ?>>Error</option>
					<option value="critical" <?php selected( $log_settings['level'] ?? '', 'critical' ); ?>>Critical</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_retention"><?php esc_html_e( 'Retention (days)', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="number" id="wp4odoo_retention" name="wp4odoo_log_settings[retention_days]"
					value="<?php echo esc_attr( (string) ( $log_settings['retention_days'] ?? 30 ) ); ?>"
					min="1" max="365" class="small-text" />
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save', 'wp4odoo' ) ); ?>
</form>

<hr />

<h2><?php esc_html_e( 'Bulk Operations', 'wp4odoo' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Import or export all products at once via the queue. Jobs are processed in the background by the sync engine.', 'wp4odoo' ); ?>
</p>
<p>
	<button type="button" id="wp4odoo-bulk-import" class="button">
		<?php esc_html_e( 'Import all products from Odoo', 'wp4odoo' ); ?>
	</button>
	<button type="button" id="wp4odoo-bulk-export" class="button">
		<?php esc_html_e( 'Export all products to Odoo', 'wp4odoo' ); ?>
	</button>
	<span id="wp4odoo-bulk-feedback"></span>
</p>
