<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for LMS modules (LearnDash, LifterLMS).
 *
 * Provides shared enrollment loading logic: decode synthetic ID →
 * load enrollment → resolve partner → resolve course product →
 * format sale order. Each LMS module delegates to this pipeline
 * via handler callables.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
abstract class LMS_Module_Base extends Module_Base {

	/**
	 * Load and resolve an enrollment with Odoo references.
	 *
	 * Decodes the synthetic ID (user_id × 1M + course_id), loads
	 * enrollment data via the handler, resolves user → partner and
	 * course → Odoo product, and formats as a sale order.
	 *
	 * @param int      $synthetic_id Synthetic enrollment ID.
	 * @param callable $load_fn      Handler's load_enrollment(int $user_id, int $course_id): array.
	 * @param callable $format_fn    Handler's format_sale_order(int $product_odoo_id, int $partner_id, string $date, string $name): array.
	 * @return array<string, mixed> Formatted sale order data, or empty on failure.
	 */
	protected function load_enrollment_from_synthetic( int $synthetic_id, callable $load_fn, callable $format_fn ): array {
		[ $user_id, $course_id ] = self::decode_synthetic_id( $synthetic_id );

		$data = $load_fn( $user_id, $course_id );
		if ( empty( $data ) ) {
			return [];
		}

		// Resolve user → partner.
		$partner_id = $this->resolve_partner_from_email( $data['user_email'], $data['user_name'], $user_id );

		if ( ! $partner_id ) {
			$this->logger->warning(
				'Cannot resolve partner for enrollment.',
				[
					'user_id'   => $user_id,
					'course_id' => $course_id,
				]
			);
			return [];
		}

		// Resolve course → Odoo product.
		$product_odoo_id = $this->get_mapping( 'course', $course_id ) ?? 0;
		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for enrollment course.', [ 'course_id' => $course_id ] );
			return [];
		}

		$course_post = get_post( $course_id );
		$course_name = $course_post ? $course_post->post_title : '';

		return $format_fn( $product_odoo_id, $partner_id, $data['date'], $course_name );
	}
}
