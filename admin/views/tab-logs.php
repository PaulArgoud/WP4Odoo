<?php
/**
 * Logs tab template.
 *
 * @var int   $page     Current page number.
 * @var int   $per_page Results per page.
 * @var array $log_data {items: array, total: int, pages: int, page: int}.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Logs', 'wp4odoo' ); ?></h2>

<!-- Filter bar -->
<div class="tablenav top wp4odoo-filters">
	<div class="alignleft actions">
		<select id="wp4odoo-log-level">
			<option value=""><?php esc_html_e( 'All levels', 'wp4odoo' ); ?></option>
			<option value="debug">Debug</option>
			<option value="info">Info</option>
			<option value="warning">Warning</option>
			<option value="error">Error</option>
			<option value="critical">Critical</option>
		</select>

		<select id="wp4odoo-log-module">
			<option value=""><?php esc_html_e( 'All modules', 'wp4odoo' ); ?></option>
			<option value="api">API</option>
			<option value="auth">Auth</option>
			<option value="sync">Sync</option>
			<option value="webhook">Webhook</option>
			<option value="jsonrpc">JSON-RPC</option>
			<option value="xmlrpc">XML-RPC</option>
			<option value="system">System</option>
			<option value="crm">CRM</option>
			<option value="sales">Sales</option>
			<option value="woocommerce">WooCommerce</option>
			<option value="edd">EDD</option>
			<option value="memberships">Memberships</option>
			<option value="memberpress">MemberPress</option>
			<option value="givewp">GiveWP</option>
			<option value="charitable">Charitable</option>
			<option value="simplepay">SimplePay</option>
			<option value="wprm">WPRM</option>
			<option value="forms">Forms</option>
			<option value="amelia">Amelia</option>
			<option value="bookly">Bookly</option>
		</select>

		<input type="date" id="wp4odoo-log-date-from" placeholder="<?php esc_attr_e( 'From', 'wp4odoo' ); ?>" />
		<input type="date" id="wp4odoo-log-date-to" placeholder="<?php esc_attr_e( 'To', 'wp4odoo' ); ?>" />

		<button type="button" id="wp4odoo-filter-logs" class="button">
			<?php esc_html_e( 'Filter', 'wp4odoo' ); ?>
		</button>
	</div>
	<div class="alignright">
		<button type="button" id="wp4odoo-purge-logs" class="button button-secondary">
			<?php esc_html_e( 'Purge logs', 'wp4odoo' ); ?>
		</button>
	</div>
	<br class="clear" />
</div>

<!-- Logs table -->
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Level', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Module', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Message', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Context', 'wp4odoo' ); ?></th>
			<th><?php esc_html_e( 'Date', 'wp4odoo' ); ?></th>
		</tr>
	</thead>
	<tbody id="wp4odoo-logs-tbody">
		<?php if ( empty( $log_data['items'] ) ) : ?>
			<tr>
				<td colspan="5"><?php esc_html_e( 'No log entries.', 'wp4odoo' ); ?></td>
			</tr>
		<?php else : ?>
			<?php foreach ( $log_data['items'] as $log ) : ?>
				<?php
				$context       = $log->context ?? '';
				$context_short = ( mb_strlen( $context ) > 60 ) ? mb_substr( $context, 0, 60 ) . '...' : $context;
				?>
				<tr>
					<td>
						<span class="wp4odoo-badge wp4odoo-badge-<?php echo esc_attr( $log->level ); ?>">
							<?php echo esc_html( $log->level ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $log->module ?: '—' ); ?></td>
					<td><?php echo esc_html( $log->message ); ?></td>
					<td>
						<?php if ( ! empty( $context ) ) : ?>
							<span class="wp4odoo-log-context" title="<?php echo esc_attr( $context ); ?>">
								<?php echo esc_html( $context_short ); ?>
							</span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $log->created_at ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>

<!-- Pagination container (AJAX-driven) -->
<div class="tablenav bottom">
	<div class="tablenav-pages" id="wp4odoo-logs-pagination">
		<?php if ( $log_data['pages'] > 1 ) : ?>
			<?php for ( $i = 1; $i <= $log_data['pages']; $i++ ) : ?>
				<?php if ( $i === $log_data['page'] ) : ?>
					<span class="tablenav-pages-navspan button disabled"><?php echo esc_html( (string) $i ); ?></span>
				<?php else : ?>
					<a href="#" class="button wp4odoo-log-page" data-page="<?php echo esc_attr( (string) $i ); ?>">
						<?php echo esc_html( (string) $i ); ?>
					</a>
				<?php endif; ?>
			<?php endfor; ?>
		<?php endif; ?>
	</div>
</div>
