<?php
namespace BonoArmApi;

use BonoArmApi\Admin\Settings_Page;
use BonoArmApi\ARMember\Gateway;
use BonoArmApi\Infrastructure\Payment_Repository;
use BonoArmApi\REST\V1_Controller;
use BonoArmApi\REST\V2_Members_Controller;
use BonoArmApi\REST\V2_Payments_Controller;
use BonoArmApi\REST\V2_Renewals_Controller;
use BonoArmApi\Infrastructure\Renewal_Repository;
use BonoArmApi\ARMember\Renewal_Gateway;
use BonoArmApi\Renewals\Service;

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

		add_filter( 'gu_config_pre_process', array( $this, 'update_branches' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( 'BonoArmApi\\Capabilities', 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_abilities_api_categories_init', array( 'BonoArmApi\\Abilities', 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( 'BonoArmApi\\Privacy', 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( 'BonoArmApi\\Privacy', 'erasers' ) );
		add_action( 'admin_init', array( 'BonoArmApi\\Privacy', 'register_policy_content' ) );
		add_action( 'admin_notices', array( 'BonoArmApi\\Dependency', 'render_admin_notice' ) );
		add_action( 'activated_plugin', array( $this, 'flush_schema_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BONO_ARM_API_FILE ), array( $this, 'action_links' ) );

		if ( is_admin() ) {
			( new Settings_Page( $this->repository ) )->register();
		}
	}

	/**
	 * Migrate resolved Git Updater configurations to verified production releases.
	 * The earlier gu_fix_repo_slug hook lacks branch/owner fields in newer versions.
	 *
	 * @param array $repos Git Updater repository objects, indexed by repository slug.
	 * @return array
	 */
	public function update_branches( $repos ) {
		if ( ! is_array( $repos ) ) {
			return $repos;
		}

		foreach ( $repos as $repo ) {
			if ( ! is_object( $repo )
				|| 'bono_arm_api' !== ( $repo->slug ?? '' )
				|| 'renatobo' !== ( $repo->owner ?? '' )
				|| 'plugin' !== ( $repo->type ?? '' )
				|| 'stable' !== ( $repo->primary_branch ?? '' )
				|| 'main' !== ( $repo->branch ?? '' ) ) {
				continue;
			}

			// Mutate the shared object so the remote fetch and update transient agree.
			$repo->branch = 'stable';
			$options      = get_site_option( 'git_updater', array() );
			if ( is_array( $options ) && 'main' === ( $options['current_branch_bono_arm_api'] ?? '' ) ) {
				$options['current_branch_bono_arm_api'] = 'stable';
				update_site_option( 'git_updater', $options );
			}
		}

		return $repos;
	}

	public function load_textdomain() {
		/*
		 * Plugin Check flags this call as discouraged because WordPress.org installs receive
		 * language packs automatically. GitHub releases installed through Git Updater do not,
		 * and just-in-time loading only scans WP_LANG_DIR, so the bundled languages/ directory
		 * would never be read without registering it here. Language packs still win when a
		 * WordPress.org listing exists.
		 */
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for the GitHub/Git Updater distribution channel; see above.
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
		$badges = new \BonoArmApi\REST\V2_Badges_Controller();
		$badges->register_routes();
		add_filter( 'rest_post_dispatch', array( $badges, 'response_headers' ), 10, 3 );
		$repository = new Renewal_Repository();
		$renewals   = new V2_Renewals_Controller( new Service( $repository, new Renewal_Gateway( $repository ) ) );
		$renewals->register_routes();
		add_filter( 'rest_post_dispatch', array( $renewals, 'response_headers' ), 10, 3 );
	}

	/**
	 * Activating ARMember creates its tables, so a cached "not available" probe would keep
	 * the endpoints returning Service Unavailable until the transient expired.
	 */
	public function flush_schema_cache( $plugin ) {
		$directory = dirname( (string) $plugin );

		if ( in_array( $directory, array( 'armember', 'armember-membership' ), true ) ) {
			$this->repository->flush_schema_cache();
		}
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
