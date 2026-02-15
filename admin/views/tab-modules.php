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
		$enabled         = wp4odoo()->settings()->is_module_enabled( $module_id );
		$odoo_models     = $module->get_odoo_models();
		$settings_fields = $module->get_settings_fields();
		$settings        = $module->get_settings();
		$dep_status      = $module->get_dependency_status();
		$dep_available   = $dep_status['available'];
		$dep_notices     = $dep_status['notices'];

		$direction        = $module->get_sync_direction();
		$direction_labels = [
			'bidirectional' => __( 'Bidirectional', 'wp4odoo' ),
			'wp_to_odoo'    => __( 'WP → Odoo', 'wp4odoo' ),
			'odoo_to_wp'    => __( 'Odoo → WP', 'wp4odoo' ),
		];
		$direction_label  = $direction_labels[ $direction ] ?? $direction;

		$excl_group  = $module->get_exclusive_group();
		$active_peer = '';
		if ( '' !== $excl_group ) {
			foreach ( $modules as $peer_id => $peer ) {
				if ( $peer_id !== $module_id
					&& $peer->get_exclusive_group() === $excl_group
					&& wp4odoo()->settings()->is_module_enabled( $peer_id ) ) {
					$active_peer = $peer->get_name();
					break;
				}
			}
		}
		?>
		<div class="wp4odoo-module-card" data-module="<?php echo esc_attr( $module_id ); ?>" data-exclusive-group="<?php echo esc_attr( $excl_group ); ?>">
			<div class="wp4odoo-module-header">
				<h3>
					<?php echo esc_html( $module->get_name() ); ?>
					<span class="wp4odoo-sync-direction wp4odoo-sync-direction--<?php echo esc_attr( $direction ); ?>">
						<?php echo esc_html( $direction_label ); ?>
					</span>
				</h3>
				<label class="wp4odoo-toggle">
					<input type="checkbox"
						class="wp4odoo-module-toggle"
						data-module="<?php echo esc_attr( $module_id ); ?>"
						<?php /* translators: %s: module name */ ?>
						aria-label="<?php echo esc_attr( sprintf( __( 'Enable %s module', 'wp4odoo' ), $module->get_name() ) ); ?>"
						<?php checked( $enabled ); ?>
						<?php disabled( ! $dep_available ); ?> />
					<span class="wp4odoo-toggle-slider"></span>
				</label>
			</div>

			<?php foreach ( $dep_notices as $notice ) : ?>
				<p class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>" style="margin: 10px 0 0; padding: 8px 12px;">
					<?php echo esc_html( $notice['message'] ); ?>
					<?php if ( ! empty( $notice['plugin_name'] ) ) : ?>
						<a href="<?php echo esc_url( \WP4Odoo\Admin\Admin::build_compat_report_url( $module_id, $notice['plugin_name'], $notice['plugin_version'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Report compatibility', 'wp4odoo' ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>

			<?php if ( $active_peer ) : ?>
				<p class="notice notice-warning wp4odoo-exclusive-notice" style="margin: 10px 0 0; padding: 8px 12px;">
					<?php
					printf(
						/* translators: %s: name of the conflicting active module */
						esc_html__( 'Cannot be active simultaneously with "%s".', 'wp4odoo' ),
						esc_html( $active_peer )
					);
					?>
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

										case 'mappings':
											$mappings_data = is_array( $value ) ? $value : [];
											$all_modules   = \WP4Odoo_Plugin::instance()->get_modules();
											$acf_types     = \WP4Odoo\Modules\ACF_Handler::get_valid_types();
											?>
										<div class="wp4odoo-mappings-table-wrap">
											<table class="widefat wp4odoo-mappings-table">
												<thead>
													<tr>
														<th><?php esc_html_e( 'Target module', 'wp4odoo' ); ?></th>
														<th><?php esc_html_e( 'Entity type', 'wp4odoo' ); ?></th>
														<th><?php esc_html_e( 'ACF field', 'wp4odoo' ); ?></th>
														<th><?php esc_html_e( 'Odoo field', 'wp4odoo' ); ?></th>
														<th><?php esc_html_e( 'Type', 'wp4odoo' ); ?></th>
														<th></th>
													</tr>
												</thead>
												<tbody class="wp4odoo-mappings-rows">
													<?php if ( ! empty( $mappings_data ) ) : ?>
														<?php foreach ( $mappings_data as $rule ) : ?>
															<tr class="wp4odoo-mapping-row">
																<td>
																	<select class="wp4odoo-mapping-module">
																		<option value=""><?php esc_html_e( '— Select —', 'wp4odoo' ); ?></option>
																		<?php foreach ( $all_modules as $mid => $mod ) : ?>
																			<?php
																			if ( 'acf' === $mid ) {
																				continue; }
																			?>
																			<option value="<?php echo esc_attr( $mid ); ?>"
																				data-entities="<?php echo esc_attr( wp_json_encode( array_keys( $mod->get_odoo_models() ) ) ); ?>"
																				<?php selected( $rule['target_module'] ?? '', $mid ); ?>>
																				<?php echo esc_html( $mod->get_name() ); ?>
																			</option>
																		<?php endforeach; ?>
																	</select>
																</td>
																<td>
																	<select class="wp4odoo-mapping-entity">
																		<?php
																		$sel_mod = $rule['target_module'] ?? '';
																		if ( '' !== $sel_mod && isset( $all_modules[ $sel_mod ] ) ) :
																			foreach ( array_keys( $all_modules[ $sel_mod ]->get_odoo_models() ) as $etype ) :
																				?>
																				<option value="<?php echo esc_attr( $etype ); ?>" <?php selected( $rule['entity_type'] ?? '', $etype ); ?>>
																					<?php echo esc_html( $etype ); ?>
																				</option>
																				<?php
																			endforeach;
																		endif;
																		?>
																	</select>
																</td>
																<td><input type="text" class="wp4odoo-mapping-acf regular-text" value="<?php echo esc_attr( $rule['acf_field'] ?? '' ); ?>" placeholder="company_size" /></td>
																<td><input type="text" class="wp4odoo-mapping-odoo regular-text" value="<?php echo esc_attr( $rule['odoo_field'] ?? '' ); ?>" placeholder="x_company_size" /></td>
																<td>
																	<select class="wp4odoo-mapping-type">
																		<?php foreach ( $acf_types as $atype ) : ?>
																			<option value="<?php echo esc_attr( $atype ); ?>" <?php selected( $rule['type'] ?? 'text', $atype ); ?>>
																				<?php echo esc_html( $atype ); ?>
																			</option>
																		<?php endforeach; ?>
																	</select>
																</td>
																<td><button type="button" class="button wp4odoo-remove-mapping-row" title="<?php esc_attr_e( 'Remove', 'wp4odoo' ); ?>">&times;</button></td>
															</tr>
														<?php endforeach; ?>
													<?php endif; ?>
												</tbody>
											</table>
											<p>
												<button type="button" class="button wp4odoo-add-mapping-row" data-module="<?php echo esc_attr( $module_id ); ?>">
													+ <?php esc_html_e( 'Add mapping', 'wp4odoo' ); ?>
												</button>
											</p>
										</div>
										<input type="hidden"
											class="wp4odoo-module-setting wp4odoo-mappings-json"
											data-module="<?php echo esc_attr( $module_id ); ?>"
											data-key="<?php echo esc_attr( $field_key ); ?>"
											id="<?php echo esc_attr( $input_id ); ?>"
											value="<?php echo esc_attr( wp_json_encode( $mappings_data ) ); ?>" />
											<?php if ( ! empty( $field['description'] ) ) : ?>
											<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
										<?php endif; ?>
											<?php
											break;

										case 'languages':
											?>
										<div class="wp4odoo-languages-panel"
											data-module="<?php echo esc_attr( $module_id ); ?>"
											data-key="<?php echo esc_attr( $field_key ); ?>">
											<button type="button" class="button wp4odoo-detect-languages"
												data-module="<?php echo esc_attr( $module_id ); ?>">
												<?php esc_html_e( 'Detect languages', 'wp4odoo' ); ?>
											</button>
											<span class="wp4odoo-languages-status"></span>
											<div class="wp4odoo-languages-list" style="display:none;"></div>
										</div>
										<input type="hidden"
											class="wp4odoo-module-setting"
											data-module="<?php echo esc_attr( $module_id ); ?>"
											data-key="<?php echo esc_attr( $field_key ); ?>"
											id="<?php echo esc_attr( $input_id ); ?>"
											value="<?php echo esc_attr( is_array( $value ) ? implode( ',', $value ) : '' ); ?>" />
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
