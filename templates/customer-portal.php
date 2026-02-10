<?php
/**
 * Customer portal template.
 *
 * Variables available from Portal_Manager::render_portal():
 * - $orders   array{items, total, page, pages}
 * - $invoices array{items, total, page, pages}
 *
 * @package WP4Odoo
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wp4odoo-portal">

	<nav class="wp4odoo-portal-tabs">
		<button type="button" class="wp4odoo-tab active" data-tab="orders">
			<?php esc_html_e( 'Orders', 'wp4odoo' ); ?>
			<span class="wp4odoo-tab-count">(<?php echo (int) $orders['total']; ?>)</span>
		</button>
		<button type="button" class="wp4odoo-tab" data-tab="invoices">
			<?php esc_html_e( 'Invoices', 'wp4odoo' ); ?>
			<span class="wp4odoo-tab-count">(<?php echo (int) $invoices['total']; ?>)</span>
		</button>
	</nav>

	<!-- Orders panel -->
	<div class="wp4odoo-panel active" data-panel="orders">
		<?php if ( empty( $orders['items'] ) ) : ?>
			<p class="wp4odoo-empty"><?php esc_html_e( 'No orders found.', 'wp4odoo' ); ?></p>
		<?php else : ?>
			<table class="wp4odoo-portal-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reference', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Date', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Total', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp4odoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders['items'] as $portal_order ) : ?>
						<tr>
							<td><?php echo esc_html( $portal_order['title'] ); ?></td>
							<td><?php echo esc_html( $portal_order['_order_date'] ?? '—' ); ?></td>
							<td>
								<?php echo esc_html( $portal_order['_order_total'] ?? '—' ); ?>
								<?php if ( ! empty( $portal_order['_order_currency'] ) ) : ?>
									<?php echo esc_html( $portal_order['_order_currency'] ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span class="wp4odoo-status wp4odoo-status--<?php echo esc_attr( $portal_order['_order_state'] ?? 'draft' ); ?>">
									<?php echo esc_html( $portal_order['_order_state'] ?? '—' ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $orders['pages'] > 1 ) : ?>
				<nav class="wp4odoo-pagination" data-tab="orders" data-pages="<?php echo (int) $orders['pages']; ?>">
					<?php for ( $i = 1; $i <= $orders['pages']; $i++ ) : ?>
						<button type="button" class="wp4odoo-page<?php echo ( 1 === $i ) ? ' active' : ''; ?>" data-page="<?php echo absint( $i ); ?>"><?php echo absint( $i ); ?></button>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<!-- Invoices panel -->
	<div class="wp4odoo-panel" data-panel="invoices">
		<?php if ( empty( $invoices['items'] ) ) : ?>
			<p class="wp4odoo-empty"><?php esc_html_e( 'No invoices found.', 'wp4odoo' ); ?></p>
		<?php else : ?>
			<table class="wp4odoo-portal-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reference', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Date', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Total', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp4odoo' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'wp4odoo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $invoices['items'] as $invoice ) : ?>
						<tr>
							<td><?php echo esc_html( $invoice['title'] ); ?></td>
							<td><?php echo esc_html( $invoice['_invoice_date'] ?? '—' ); ?></td>
							<td>
								<?php echo esc_html( $invoice['_invoice_total'] ?? '—' ); ?>
								<?php if ( ! empty( $invoice['_invoice_currency'] ) ) : ?>
									<?php echo esc_html( $invoice['_invoice_currency'] ); ?>
								<?php endif; ?>
							</td>
							<td>
								<span class="wp4odoo-status wp4odoo-status--<?php echo esc_attr( $invoice['_invoice_state'] ?? 'draft' ); ?>">
									<?php echo esc_html( $invoice['_invoice_state'] ?? '—' ); ?>
								</span>
							</td>
							<td>
								<span class="wp4odoo-payment wp4odoo-payment--<?php echo esc_attr( $invoice['_payment_state'] ?? 'not_paid' ); ?>">
									<?php echo esc_html( $invoice['_payment_state'] ?? '—' ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $invoices['pages'] > 1 ) : ?>
				<nav class="wp4odoo-pagination" data-tab="invoices" data-pages="<?php echo (int) $invoices['pages']; ?>">
					<?php for ( $i = 1; $i <= $invoices['pages']; $i++ ) : ?>
						<button type="button" class="wp4odoo-page<?php echo ( 1 === $i ) ? ' active' : ''; ?>" data-page="<?php echo absint( $i ); ?>"><?php echo absint( $i ); ?></button>
					<?php endfor; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>

</div>
