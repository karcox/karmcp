<?php
/**
 * Security & Malware Scanner MCP ability (read-only).
 *
 * One tool — scan-security — that scans for malware, core-integrity, hardening,
 * and outdated-software issues and returns a scored report. manage_options;
 * enabled by default.
 *
 * @package KarMCP
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class KarMCP_Security_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @var KarMCP_Security_Scanner */
	private $scanner;

	public function __construct( ?KarMCP_Security_Scanner $scanner = null ) {
		$this->scanner = $scanner ?: new KarMCP_Security_Scanner();
	}

	public function get_ability_names(): array {
		return $this->ability_names;
	}

	public function register(): void {
		$this->register_scan_security();
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function register_scan_security(): void {
		$this->ability_names[] = 'karmcp/scan-security';
		karmcp_register_ability(
			'karmcp/scan-security',
			array(
				'label'               => __( 'Scan Security', 'karmcp' ),
				'description'         => __( 'Scans this WordPress site for security and malware problems across four areas: PHP malware heuristics (uploads + active plugins/themes; pass deep=true for the whole tree), WordPress core file integrity (vs official wordpress.org checksums), configuration hardening (file editor, debug output, admin username, XML-RPC, version disclosure, HTTPS, security headers), and outdated/abandoned software. Returns a scored report (0-100 + A-F grade) with severities and ranked, actionable recommendations. Read-only; self-contained; scans this site only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_scan_security' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'checks'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string', 'enum' => array( 'malware', 'integrity', 'hardening', 'software' ) ),
							'description' => __( 'Subset of audits to run. Omit to run all four.', 'karmcp' ),
						),
						'deep'        => array( 'type' => 'boolean', 'description' => __( 'When true, the malware scan covers ALL plugins/themes and the wider tree (slower). Default false: uploads + active plugins/themes only.', 'karmcp' ) ),
						'max_files'   => array( 'type' => 'integer', 'description' => __( 'Override the malware file-count cap (default 2000, ceiling 20000).', 'karmcp' ) ),
						'max_seconds' => array( 'type' => 'integer', 'description' => __( 'Override the malware scan time budget in seconds (default 20, ceiling 120).', 'karmcp' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'summary'             => array( 'type' => 'object' ),
						'sections'            => array( 'type' => 'object' ),
						'scan_meta'           => array( 'type' => 'object' ),
						'top_recommendations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_scan_security( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		return $this->scanner->scan( $input );
	}
}
