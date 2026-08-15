<?php
/**
 * harden-site: the tool that closes the loop the scanner opened.
 *
 * `scan-security` has always known what was wrong and said so. This applies it.
 * Dry-run by default like `build-site`, reversible through the change ledger,
 * and honest about the three findings it deliberately will not touch.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers harden-site.
 *
 * @since 1.5.0
 */
class KarMCP_Security_Fix_Abilities {

	/**
	 * @since 1.5.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/harden-site' );
	}

	/**
	 * @since 1.5.0
	 * @return void
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/harden-site',
			array(
				'label'               => __( 'Harden Site', 'karmcp' ),
				'description'         => __( 'Applies the configuration hardening that scan-security reports: disables the dashboard file editor, disables XML-RPC, stops disclosing the WordPress version, and sends the missing security headers. DRY-RUN BY DEFAULT — returns the plan, and only changes anything when called again with apply:true and confirm:true. Every fix is a switch this plugin owns, not an edit to wp-config.php, so each one is reversible with revert:[ids]. Three findings are deliberately never auto-fixed and the plan says which and why.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'apply'   => array(
							'type'        => 'boolean',
							'description' => __( 'Actually apply the fixes. Default false, which returns the plan only.', 'karmcp' ),
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => __( 'Required alongside apply:true. Acknowledges that the listed side effects are acceptable.', 'karmcp' ),
						),
						'fixes'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Only apply these fix ids (disallow_file_edit, disable_xmlrpc, hide_version, security_headers). Omit to apply everything the plan lists as fixable.', 'karmcp' ),
						),
						'revert'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Switch these fix ids back off. Also needs confirm:true.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'applied'         => array( 'type' => 'boolean' ),
						'plan'            => array( 'type' => 'object' ),
						'changed'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'reverted'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'still_manual'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'change_id'       => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Site-wide configuration, so: administrators.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 1.5.0
	 *
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute( $input ) {
		if ( ! class_exists( 'KarMCP_Security_Hardening_Audit' ) ) {
			return new \WP_Error( 'audit_unavailable', __( 'The hardening audit is not available on this install.', 'karmcp' ) );
		}

		$audit    = new KarMCP_Security_Hardening_Audit();
		$result   = $audit->run();
		$findings = is_array( $result['findings'] ?? null ) ? $result['findings'] : array();

		$before = KarMCP_Security_Hardening_Fixer::applied();
		$plan   = KarMCP_Security_Hardening_Fixer::plan( $findings, $before );

		$apply  = ! empty( $input['apply'] );
		$revert = array_values( array_filter( array_map( 'strval', (array) ( $input['revert'] ?? array() ) ) ) );

		if ( ! $apply && ! $revert ) {
			return array(
				'applied'      => false,
				'plan'         => $plan,
				'changed'      => array(),
				'reverted'     => array(),
				'still_manual' => $plan['manual'],
				'change_id'    => '',
			);
		}

		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error(
				'confirmation_required',
				__( 'This changes how the site behaves for every visitor. Review the plan from a dry run, then call again with apply:true and confirm:true.', 'karmcp' )
			);
		}

		$wanted = array_values( array_filter( array_map( 'strval', (array) ( $input['fixes'] ?? array() ) ) ) );
		if ( ! $wanted && $apply ) {
			$wanted = wp_list_pluck( $plan['fixable'], 'id' );
		}

		$changed  = $apply ? KarMCP_Security_Hardening_Fixer::apply( $wanted ) : array();
		$removed  = $revert ? KarMCP_Security_Hardening_Fixer::revert( $revert ) : array();
		$change_id = '';

		if ( ( $changed || $removed ) && class_exists( 'KarMCP_Change_Recorder' ) ) {
			$change_id = KarMCP_Change_Recorder::record_options(
				array( KarMCP_Security_Hardening_Fixer::OPTION_APPLIED => $before ),
				sprintf(
					/* translators: 1: applied ids, 2: reverted ids. */
					__( 'Hardening applied: %1$s; reverted: %2$s', 'karmcp' ),
					$changed ? implode( ', ', $changed ) : '—',
					$removed ? implode( ', ', $removed ) : '—'
				),
				__( 'Site hardening', 'karmcp' ),
				'settings',
				'harden-site'
			);
		}

		return array(
			'applied'      => (bool) $changed,
			'plan'         => KarMCP_Security_Hardening_Fixer::plan( $findings, KarMCP_Security_Hardening_Fixer::applied() ),
			'changed'      => $changed,
			'reverted'     => $removed,
			'still_manual' => $plan['manual'],
			'change_id'    => (string) $change_id,
		);
	}
}
