<?php
namespace BonoArmApi;

use BonoArmApi\Admin\Settings_Page;
use BonoArmApi\ARMember\Gateway;
use BonoArmApi\Infrastructure\Payment_Repository;
use BonoArmApi\REST\V1_Controller;
use BonoArmApi\REST\V2_Members_Controller;
use BonoArmApi\REST\V2_Payments_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static $instance;
	private $repository;
	private $gateway;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot() {
		$this->repository = new Payment_Repository();
		$this->gateway    = new Gateway( $this->repository );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'BonoArmApi\\Capabilities', 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( 'BonoArmApi\\Abilities', 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'admin_init', array( 'BonoArmApi\\Privacy', 'register_policy_content' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BONO_ARM_API_FILE ), array( $this, 'action_links' ) );

		if ( is_admin() ) {
			( new Settings_Page( $this->repository ) )->register();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'bono-arm-api',
			false,
			dirname( plugin_basename( BONO_ARM_API_FILE ) ) . '/languages'
		);
	}

	public function register_rest_routes() {
		( new V1_Controller( $this->repository, $this->gateway ) )->register_routes();
		( new V2_Payments_Controller( $this->repository ) )->register_routes();
		( new V2_Members_Controller( $this->gateway ) )->register_routes();
	}

	public function register_abilities() {
		Abilities::register( $this->repository );
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . BONO_ARM_API_SETTINGS_PAGE ) ),
				esc_html__( 'Settings', 'bono-arm-api' )
			)
		);

		return $links;
	}
}
