<?php
/**
 * Main settings page wrapper.
 *
 * @var string $active_tab The currently active tab slug.
 * @var \WP4Odoo\Admin\Settings_Page $this
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'WordPress For Odoo', 'wp4odoo' ); ?></h1>

	<?php $this->render_setup_checklist(); ?>

	<?php $this->render_tabs( $active_tab ); ?>

	<div class="wp4odoo-tab-content" style="margin-top: 20px;">
		<?php
		switch ( $active_tab ) {
			case 'connection':
				$this->render_tab_connection();
				break;
			case 'sync':
				$this->render_tab_sync();
				break;
			case 'modules':
				$this->render_tab_modules();
				break;
			case 'queue':
				$this->render_tab_queue();
				break;
			case 'logs':
				$this->render_tab_logs();
				break;
		}
		?>
	</div>
</div>
