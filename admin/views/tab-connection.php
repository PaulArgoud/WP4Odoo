<?php
/**
 * Connection tab template.
 *
 * @var array  $credentials Decrypted Odoo credentials.
 * @var string $token       Webhook token.
 * @var string $webhook_url Webhook endpoint URL.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( empty( $credentials['url'] ) ) : ?>
<div class="wp4odoo-help-section wp4odoo-getting-started">
	<details>
		<summary><?php esc_html_e( 'Getting Started', 'wp4odoo' ); ?></summary>
		<div class="wp4odoo-help-content">
			<h4><?php esc_html_e( 'Prerequisites', 'wp4odoo' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'An Odoo instance (version 14 or later) with API access enabled.', 'wp4odoo' ); ?></li>
				<li><?php esc_html_e( 'An Odoo user with administrator privileges (or at least access to the modules you want to sync).', 'wp4odoo' ); ?></li>
				<li><?php esc_html_e( 'An API key generated from your Odoo user profile (see instructions below).', 'wp4odoo' ); ?></li>
			</ul>
			<h4><?php esc_html_e( 'Required Odoo Modules', 'wp4odoo' ); ?></h4>
			<ul>
				<li><strong><?php esc_html_e( 'CRM:', 'wp4odoo' ); ?></strong> <?php esc_html_e( 'Contacts (base) + CRM (crm) for leads', 'wp4odoo' ); ?></li>
				<li><strong><?php esc_html_e( 'Sales:', 'wp4odoo' ); ?></strong> <?php esc_html_e( 'Sales (sale_management) + Invoicing (account)', 'wp4odoo' ); ?></li>
				<li><strong><?php esc_html_e( 'WooCommerce:', 'wp4odoo' ); ?></strong> <?php esc_html_e( 'Inventory (stock) + Sales (sale_management) + Invoicing (account)', 'wp4odoo' ); ?></li>
			</ul>
		</div>
	</details>
</div>
<?php endif; ?>

<form method="post" action="options.php">
	<?php settings_fields( 'wp4odoo_connection_group' ); ?>

	<h2><?php esc_html_e( 'Odoo Connection', 'wp4odoo' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="wp4odoo_url"><?php esc_html_e( 'Odoo URL', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="url" id="wp4odoo_url" name="wp4odoo_connection[url]"
					value="<?php echo esc_attr( $credentials['url'] ); ?>"
					class="regular-text" placeholder="https://mycompany.odoo.com" required />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_database"><?php esc_html_e( 'Database', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="text" id="wp4odoo_database" name="wp4odoo_connection[database]"
					value="<?php echo esc_attr( $credentials['database'] ); ?>"
					class="regular-text" required />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_username"><?php esc_html_e( 'Username', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="text" id="wp4odoo_username" name="wp4odoo_connection[username]"
					value="<?php echo esc_attr( $credentials['username'] ); ?>"
					class="regular-text" required />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_api_key"><?php esc_html_e( 'API Key', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="password" id="wp4odoo_api_key" name="wp4odoo_connection[api_key]"
					value="" class="regular-text"
					placeholder="<?php esc_attr_e( 'Leave empty to keep the current key', 'wp4odoo' ); ?>"
					autocomplete="new-password" />
				<p class="description">
					<?php
					if ( ! empty( $credentials['api_key'] ) ) {
						esc_html_e( 'An API key is already configured. Leave empty to keep it.', 'wp4odoo' );
					} else {
						esc_html_e( 'Enter your Odoo API key.', 'wp4odoo' );
					}
					?>
				</p>
				<div class="wp4odoo-help-section">
					<details>
						<summary><?php esc_html_e( 'How to generate an API key in Odoo', 'wp4odoo' ); ?></summary>
						<div class="wp4odoo-help-content">
							<h4><?php esc_html_e( 'Odoo 17+ (recommended)', 'wp4odoo' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'Go to Settings > Users & Companies > Users.', 'wp4odoo' ); ?></li>
								<li><?php esc_html_e( 'Select your user, then click the "Preferences" tab.', 'wp4odoo' ); ?></li>
								<li><?php esc_html_e( 'Scroll to "Account Security" and click "New API Key".', 'wp4odoo' ); ?></li>
								<li><?php esc_html_e( 'Enter a description (e.g. "WordPress For Odoo") and copy the generated key.', 'wp4odoo' ); ?></li>
							</ol>
							<h4><?php esc_html_e( 'Odoo 14-16', 'wp4odoo' ); ?></h4>
							<ol>
								<li><?php esc_html_e( 'Go to Settings > Activate the developer mode (from the General settings page).', 'wp4odoo' ); ?></li>
								<li><?php esc_html_e( 'Click your user avatar (top right) > My Profile > "Preferences" tab.', 'wp4odoo' ); ?></li>
								<li><?php esc_html_e( 'Under "Account Security", click "New API Key".', 'wp4odoo' ); ?></li>
								<li><?php esc_html_e( 'Copy the key immediately — it will not be shown again.', 'wp4odoo' ); ?></li>
							</ol>
						</div>
					</details>
				</div>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_protocol"><?php esc_html_e( 'Protocol', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<select id="wp4odoo_protocol" name="wp4odoo_connection[protocol]">
					<option value="jsonrpc" <?php selected( $credentials['protocol'], 'jsonrpc' ); ?>>
						JSON-RPC (Odoo 17+)
					</option>
					<option value="xmlrpc" <?php selected( $credentials['protocol'], 'xmlrpc' ); ?>>
						XML-RPC (Odoo 14+)
					</option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wp4odoo_timeout"><?php esc_html_e( 'Timeout (seconds)', 'wp4odoo' ); ?></label>
			</th>
			<td>
				<input type="number" id="wp4odoo_timeout" name="wp4odoo_connection[timeout]"
					value="<?php echo esc_attr( (string) $credentials['timeout'] ); ?>"
					min="5" max="120" class="small-text" />
			</td>
		</tr>
	</table>

	<p>
		<button type="button" id="wp4odoo-test-connection" class="button button-secondary">
			<?php esc_html_e( 'Test connection', 'wp4odoo' ); ?>
		</button>
		<span id="wp4odoo-test-result"></span>
	</p>

	<?php submit_button( __( 'Save', 'wp4odoo' ) ); ?>
</form>

<hr />

<h2><?php esc_html_e( 'Webhook', 'wp4odoo' ); ?></h2>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><?php esc_html_e( 'Token', 'wp4odoo' ); ?></th>
		<td>
			<code id="wp4odoo-webhook-token"><?php echo esc_html( $token ); ?></code>
			<button type="button" id="wp4odoo-copy-token" class="button button-small">
				<?php esc_html_e( 'Copy', 'wp4odoo' ); ?>
			</button>
			<p class="description">
				<?php
				printf(
					/* translators: %s: header name */
					esc_html__( 'Configure this token in Odoo as the %s header.', 'wp4odoo' ),
					'<code>X-Odoo-Token</code>'
				);
				?>
			</p>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Webhook URL', 'wp4odoo' ); ?></th>
		<td>
			<code><?php echo esc_url( $webhook_url ); ?></code>
		</td>
	</tr>
</table>

<div class="wp4odoo-help-section">
	<details>
		<summary><?php esc_html_e( 'How to configure webhooks in Odoo', 'wp4odoo' ); ?></summary>
		<div class="wp4odoo-help-content">
			<h4><?php esc_html_e( 'Odoo 17+ (native webhooks)', 'wp4odoo' ); ?></h4>
			<ol>
				<li><?php esc_html_e( 'Go to Settings > Technical > Automation > Webhooks.', 'wp4odoo' ); ?></li>
				<li><?php esc_html_e( 'Create a new webhook for each model you want to sync (e.g. res.partner, sale.order).', 'wp4odoo' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: webhook URL */
						esc_html__( 'Set the URL to: %s', 'wp4odoo' ),
						'<code>' . esc_url( $webhook_url ) . '</code>'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: header name */
						esc_html__( 'Add a custom header %s with the token shown above.', 'wp4odoo' ),
						'<code>X-Odoo-Token</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Select the events: Create, Update, Delete (as needed).', 'wp4odoo' ); ?></li>
			</ol>
			<h4><?php esc_html_e( 'Odoo 14-16 (Automated Actions)', 'wp4odoo' ); ?></h4>
			<ol>
				<li><?php esc_html_e( 'Activate the developer mode, then go to Settings > Technical > Automation > Automated Actions.', 'wp4odoo' ); ?></li>
				<li><?php esc_html_e( 'Create an action for each model (e.g. res.partner).', 'wp4odoo' ); ?></li>
				<li><?php esc_html_e( 'Set trigger to "On Creation & Update" and action type to "Execute Python Code".', 'wp4odoo' ); ?></li>
				<li>
					<?php esc_html_e( 'Use the following Python code template:', 'wp4odoo' ); ?>
					<pre class="wp4odoo-code-block">import requests
url = "<?php echo esc_url( $webhook_url ); ?>"
headers = {"X-Odoo-Token": "<?php echo esc_html( $token ); ?>", "Content-Type": "application/json"}
data = {"model": record._name, "id": record.id, "action": "write"}
requests.post(url, json=data, headers=headers, timeout=10)</pre>
				</li>
			</ol>
		</div>
	</details>
</div>
