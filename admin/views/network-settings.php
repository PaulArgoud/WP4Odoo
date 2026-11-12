<?php
/**
 * Network admin settings page template.
 *
 * @var array          $connection     Network connection settings.
 * @var array          $site_companies Site → Company ID mapping.
 * @var \WP_Site[]     $sites          All sites in the network.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1><?php esc_html_e( 'Odoo Connector — Network Settings', 'wp4odoo' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Settings saved.', 'wp4odoo' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=wp4odoo_network' ) ); ?>">
		<?php wp_nonce_field( 'wp4odoo_network_settings' ); ?>

		<h2><?php esc_html_e( 'Shared Odoo Connection', 'wp4odoo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'This connection is shared across all sites in the network. Individual sites can override with their own connection in Settings > Odoo Connector.', 'wp4odoo' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wp4odoo_network_url"><?php esc_html_e( 'Odoo URL', 'wp4odoo' ); ?></label>
				</th>
				<td>
					<input type="url" id="wp4odoo_network_url" name="wp4odoo_network_url"
						value="<?php echo esc_attr( $connection['url'] ?? '' ); ?>"
						class="regular-text" placeholder="https://mycompany.odoo.com" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp4odoo_network_database"><?php esc_html_e( 'Database', 'wp4odoo' ); ?></label>
				</th>
				<td>
					<input type="text" id="wp4odoo_network_database" name="wp4odoo_network_database"
						value="<?php echo esc_attr( $connection['database'] ?? '' ); ?>"
						class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp4odoo_network_username"><?php esc_html_e( 'Username', 'wp4odoo' ); ?></label>
				</th>
				<td>
					<input type="text" id="wp4odoo_network_username" name="wp4odoo_network_username"
						value="<?php echo esc_attr( $connection['username'] ?? '' ); ?>"
						class="regular-text" />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp4odoo_network_api_key"><?php esc_html_e( 'API Key', 'wp4odoo' ); ?></label>
				</th>
				<td>
					<input type="password" id="wp4odoo_network_api_key" name="wp4odoo_network_api_key"
						value="" class="regular-text"
						placeholder="<?php esc_attr_e( 'Leave empty to keep the current key', 'wp4odoo' ); ?>"
						autocomplete="new-password" />
					<?php if ( ! empty( $connection['api_key'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'An API key is already configured. Leave empty to keep it.', 'wp4odoo' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp4odoo_network_protocol"><?php esc_html_e( 'Protocol', 'wp4odoo' ); ?></label>
				</th>
				<td>
					<select id="wp4odoo_network_protocol" name="wp4odoo_network_protocol">
						<option value="jsonrpc" <?php selected( $connection['protocol'] ?? 'jsonrpc', 'jsonrpc' ); ?>>
							<?php esc_html_e( 'JSON-RPC (Odoo 17+)', 'wp4odoo' ); ?>
						</option>
						<option value="xmlrpc" <?php selected( $connection['protocol'] ?? 'jsonrpc', 'xmlrpc' ); ?>>
							<?php esc_html_e( 'XML-RPC (Odoo 14+)', 'wp4odoo' ); ?>
						</option>
						<option value="json2" <?php selected( $connection['protocol'] ?? 'jsonrpc', 'json2' ); ?>>
							<?php esc_html_e( 'JSON-2 (Odoo 19+, API key required)', 'wp4odoo' ); ?>
						</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wp4odoo_network_timeout"><?php esc_html_e( 'Timeout (seconds)', 'wp4odoo' ); ?></label>
				</th>
				<td>
					<input type="number" id="wp4odoo_network_timeout" name="wp4odoo_network_timeout"
						value="<?php echo esc_attr( (string) ( $connection['timeout'] ?? 30 ) ); ?>"
						min="5" max="120" class="small-text" />
				</td>
			</tr>
		</table>

		<hr />

		<h2><?php esc_html_e( 'Site → Odoo Company Mapping', 'wp4odoo' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Assign an Odoo Company ID to each site. All API calls from that site will be scoped to the specified res.company. Leave at 0 for no company restriction.', 'wp4odoo' ); ?>
		</p>

		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site ID', 'wp4odoo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Site Name', 'wp4odoo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL', 'wp4odoo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Odoo Company ID', 'wp4odoo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sites as $site ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $site->blog_id ); ?></td>
						<td><?php echo esc_html( get_blog_details( (int) $site->blog_id )->blogname ?? '' ); ?></td>
						<td><?php echo esc_url( $site->siteurl ); ?></td>
						<td>
							<input type="number"
								name="wp4odoo_site_company[<?php echo esc_attr( (string) $site->blog_id ); ?>]"
								value="<?php echo esc_attr( (string) ( $site_companies[ $site->blog_id ] ?? 0 ) ); ?>"
								min="0" class="small-text" />
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button( __( 'Save Network Settings', 'wp4odoo' ) ); ?>
	</form>
</div>
