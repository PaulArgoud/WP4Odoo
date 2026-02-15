<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Field_Mapper;
use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for helpdesk plugin handlers.
 *
 * Extracts shared priority mapping and Odoo ticket parsing from
 * Awesome_Support_Handler and SupportCandy_Handler.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
abstract class Helpdesk_Handler_Base {

	/**
	 * Priority map: WP priority → Odoo priority string.
	 *
	 * Odoo helpdesk.ticket priority: '0' = low, '1' = medium, '2' = high, '3' = urgent.
	 *
	 * @var array<string, string>
	 */
	protected const PRIORITY_MAP = [
		'low'    => '0',
		'medium' => '1',
		'high'   => '2',
		'urgent' => '3',
	];

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Parse an Odoo ticket record for pull.
	 *
	 * Extracts the stage_id Many2one name for status resolution
	 * by the module base class.
	 *
	 * @param array<string, mixed> $odoo_data   Raw Odoo record.
	 * @param bool                 $is_helpdesk Whether model is helpdesk.ticket.
	 * @return array<string, mixed>
	 */
	public function parse_ticket_from_odoo( array $odoo_data, bool $is_helpdesk ): array {
		$stage_name = Field_Mapper::many2one_to_name( $odoo_data['stage_id'] ?? null ) ?? '';

		return [
			'_stage_name' => $stage_name,
		];
	}

	/**
	 * Map a WP priority string to an Odoo priority value.
	 *
	 * @param string $wp_priority Priority from post meta or ticket table.
	 * @return string Odoo priority ('0'-'3').
	 */
	protected function map_priority( string $wp_priority ): string {
		return static::PRIORITY_MAP[ strtolower( $wp_priority ) ] ?? '0';
	}
}
