<?php
/**
 * Setup checklist partial.
 *
 * Displayed between the page title and the tab bar until all steps
 * are completed or the user dismisses it.
 *
 * @var array $steps     Checklist steps: [ ['done' => bool, 'label' => string, 'tab' => string], ... ]
 * @var int   $done      Number of completed steps.
 * @var int   $total     Total number of steps.
 * @var int   $percent   Completion percentage (0-100).
 *
 * @package WP4Odoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wp4odoo-checklist" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp4odoo_admin' ) ); ?>">
	<div class="wp4odoo-checklist-header">
		<strong><?php esc_html_e( 'Setup Checklist', 'wp4odoo' ); ?></strong>
		<span class="wp4odoo-checklist-progress-text">
			<?php
			printf(
				/* translators: 1: completed steps, 2: total steps */
				esc_html__( '%1$d / %2$d completed', 'wp4odoo' ),
				absint( $done ),
				absint( $total )
			);
			?>
		</span>
		<button type="button" class="wp4odoo-checklist-dismiss" aria-label="<?php esc_attr_e( 'Dismiss checklist', 'wp4odoo' ); ?>">&times;</button>
	</div>
	<div class="wp4odoo-checklist-bar">
		<div class="wp4odoo-checklist-bar-fill" style="width: <?php echo esc_attr( (string) $percent ); ?>%;"></div>
	</div>
	<ol class="wp4odoo-checklist-steps">
		<?php foreach ( $steps as $step ) : ?>
			<li class="<?php echo $step['done'] ? 'done' : ''; ?>">
				<span class="wp4odoo-checklist-icon"><?php echo $step['done'] ? '&#10003;' : '&#9675;'; ?></span>
				<?php if ( ! empty( $step['tab'] ) && ! $step['done'] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp4odoo&tab=' . $step['tab'] ) ); ?>">
						<?php echo esc_html( $step['label'] ); ?>
					</a>
				<?php elseif ( ! empty( $step['action'] ) && ! $step['done'] ) : ?>
					<a href="#" class="wp4odoo-checklist-action" data-action="<?php echo esc_attr( $step['action'] ); ?>">
						<?php echo esc_html( $step['label'] ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( $step['label'] ); ?>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</div>
