<?php
/**
 * Modules tab template.
 *
 * @var array $modules Array of Module_Base instances keyed by module ID.
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Modules', 'wp4odoo' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'Enable or disable synchronization modules.', 'wp4odoo' ); ?>
</p>

<div class="wp4odoo-modules-grid">
	<?php foreach ( $modules as $module_id => $module ) : ?>
		<?php
		$enabled         = get_option( 'wp4odoo_module_' . $module_id . '_enabled', false );
		$odoo_models     = $module->get_odoo_models();
		$settings_fields = $module->get_settings_fields();
		$settings        = $module->get_settings();
		$is_woo          = ( 'woocommerce' === $module_id );
		$woo_active      = class_exists( 'WooCommerce' );
		?>
		<div class="wp4odoo-module-card" data-module="<?php echo esc_attr( $module_id ); ?>">
			<div class="wp4odoo-module-header">
				<h3><?php echo esc_html( $module->get_name() ); ?></h3>
				<label class="wp4odoo-toggle">
					<input type="checkbox"
						class="wp4odoo-module-toggle"
						data-module="<?php echo esc_attr( $module_id ); ?>"
						<?php checked( $enabled ); ?>
						<?php disabled( $is_woo && ! $woo_active ); ?> />
					<span class="wp4odoo-toggle-slider"></span>
				</label>
			</div>

			<?php if ( $is_woo && ! $woo_active ) : ?>
				<p class="notice notice-warning" style="margin: 10px 0 0; padding: 8px 12px;">
					<?php esc_html_e( 'WooCommerce must be installed and activated to use this module.', 'wp4odoo' ); ?>
				</p>
			<?php elseif ( $is_woo && $woo_active ) : ?>
				<p class="notice notice-info" style="margin: 10px 0 0; padding: 8px 12px;">
					<?php esc_html_e( 'Enabling the WooCommerce module replaces the Sales module. Both cannot be active simultaneously.', 'wp4odoo' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $odoo_models ) ) : ?>
				<p class="wp4odoo-module-models">
					<?php
					echo esc_html(
						implode(
							', ',
							array_map(
								function ( string $type, string $model ): string {
									return $type . ' → ' . $model;
								},
								array_keys( $odoo_models ),
								array_values( $odoo_models )
							)
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $settings_fields ) ) : ?>
				<div class="wp4odoo-module-settings" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
					<hr />
					<table class="form-table wp4odoo-module-settings-table">
						<?php foreach ( $settings_fields as $field_key => $field ) : ?>
							<tr>
								<th scope="row">
									<label for="wp4odoo-<?php echo esc_attr( $module_id . '-' . $field_key ); ?>">
										<?php echo esc_html( $field['label'] ); ?>
									</label>
								</th>
								<td>
									<?php
									$value    = $settings[ $field_key ] ?? '';
									$input_id = 'wp4odoo-' . $module_id . '-' . $field_key;

									switch ( $field['type'] ) {
										case 'checkbox':
											?>
											<label>
												<input type="checkbox"
													id="<?php echo esc_attr( $input_id ); ?>"
													class="wp4odoo-module-setting"
													data-module="<?php echo esc_attr( $module_id ); ?>"
													data-key="<?php echo esc_attr( $field_key ); ?>"
													<?php checked( $value ); ?> />
												<?php if ( ! empty( $field['description'] ) ) : ?>
													<?php echo esc_html( $field['description'] ); ?>
												<?php endif; ?>
											</label>
											<?php
											break;

										case 'select':
											?>
											<select
												id="<?php echo esc_attr( $input_id ); ?>"
												class="wp4odoo-module-setting"
												data-module="<?php echo esc_attr( $module_id ); ?>"
												data-key="<?php echo esc_attr( $field_key ); ?>">
												<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
													<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>>
														<?php echo esc_html( $opt_label ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<?php if ( ! empty( $field['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
											<?php endif; ?>
											<?php
											break;

										case 'number':
											?>
											<input type="number"
												id="<?php echo esc_attr( $input_id ); ?>"
												class="wp4odoo-module-setting small-text"
												data-module="<?php echo esc_attr( $module_id ); ?>"
												data-key="<?php echo esc_attr( $field_key ); ?>"
												value="<?php echo esc_attr( $value ); ?>" />
											<?php if ( ! empty( $field['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
											<?php endif; ?>
											<?php
											break;

										default: // text
											?>
											<input type="text"
												id="<?php echo esc_attr( $input_id ); ?>"
												class="wp4odoo-module-setting regular-text"
												data-module="<?php echo esc_attr( $module_id ); ?>"
												data-key="<?php echo esc_attr( $field_key ); ?>"
												value="<?php echo esc_attr( $value ); ?>" />
											<?php if ( ! empty( $field['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
											<?php endif; ?>
											<?php
											break;
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<p>
						<button type="button" class="button button-primary wp4odoo-save-module-settings" data-module="<?php echo esc_attr( $module_id ); ?>">
							<?php esc_html_e( 'Save settings', 'wp4odoo' ); ?>
						</button>
						<span class="wp4odoo-module-save-feedback"></span>
					</p>
				</div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php if ( empty( $modules ) ) : ?>
		<p><?php esc_html_e( 'No modules registered.', 'wp4odoo' ); ?></p>
	<?php endif; ?>
</div>
