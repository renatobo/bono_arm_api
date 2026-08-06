<?php
namespace BonoArmApi\Admin;

use BonoArmApi\Capabilities;
use BonoArmApi\Infrastructure\Payment_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {
	private $repository;

	public function __construct( Payment_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_page() {
		add_options_page(
			__( 'Bono API for ARMember', 'bono-arm-api' ),
			__( 'Bono API', 'bono-arm-api' ),
			'manage_options',
			BONO_ARM_API_SETTINGS_PAGE,
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		$settings = array(
			BONO_ARM_API_OPTION_ENABLE_TRANSACTIONS      => __( 'Payment endpoints', 'bono-arm-api' ),
			BONO_ARM_API_OPTION_ENABLE_MEMBER_ACTIVATION => __( 'Member activation endpoints', 'bono-arm-api' ),
			BONO_ARM_API_OPTION_ENABLE_MEMBER_DELETE     => __( 'Member deletion endpoints', 'bono-arm-api' ),
		);

		add_settings_section(
			'bono_arm_api_features',
			__( 'API features', 'bono-arm-api' ),
			static function () {
				echo '<p>' . esc_html__( 'Enable only the API operations required by your integration.', 'bono-arm-api' ) . '</p>';
			},
			BONO_ARM_API_SETTINGS_PAGE
		);

		foreach ( $settings as $option => $label ) {
			register_setting(
				'bono_arm_api_settings',
				$option,
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => static function ( $value ) {
						return (bool) $value;
					},
				)
			);
			add_settings_field(
				$option,
				$label,
				array( $this, 'render_checkbox' ),
				BONO_ARM_API_SETTINGS_PAGE,
				'bono_arm_api_features',
				array(
					'option'    => $option,
					'label_for' => $option,
				)
			);
		}
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . BONO_ARM_API_SETTINGS_PAGE !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'bono-arm-api-admin', BONO_ARM_API_URL . 'assets/admin.css', array(), BONO_ARM_API_VERSION );
		wp_enqueue_script( 'bono-arm-api-admin', BONO_ARM_API_URL . 'assets/admin.js', array(), BONO_ARM_API_VERSION, true );
		wp_localize_script(
			'bono-arm-api-admin',
			'bonoArmApiAdmin',
			array(
				'copied' => __( 'Copied to clipboard.', 'bono-arm-api' ),
				'failed' => __( 'Copy failed. Select and copy the value manually.', 'bono-arm-api' ),
			)
		);
	}

	public function render_checkbox( $args ) {
		$option = $args['option'];
		printf(
			'<input id="%1$s" name="%1$s" type="checkbox" value="1" %2$s>',
			esc_attr( $option ),
			checked( true, (bool) get_option( $option, false ), false )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$base_url     = rest_url();
		$tables_exist = $this->repository->tables_exist();
		?>
		<div class="wrap bono-arm-api-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="description"><?php esc_html_e( 'Capability-controlled ARMember REST endpoints with stable v1 compatibility and a schema-first v2 API.', 'bono-arm-api' ); ?></p>

			<?php // Settings errors are printed by core's options-head.php for pages under the Settings menu. ?>
			<div class="notice inline <?php echo $tables_exist ? 'notice-success' : 'notice-warning'; ?>">
				<p>
					<?php
					echo $tables_exist
						? esc_html__( 'ARMember payment tables are available.', 'bono-arm-api' )
						: esc_html__( 'ARMember payment tables are not available. Payment and activation requests will return Service Unavailable.', 'bono-arm-api' );
					?>
				</p>
			</div>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'bono_arm_api_settings' );
				do_settings_sections( BONO_ARM_API_SETTINGS_PAGE );
				submit_button();
				?>
			</form>

			<section class="bono-arm-api-card" aria-labelledby="bono-arm-api-endpoints">
				<h2 id="bono-arm-api-endpoints"><?php esc_html_e( 'Endpoints', 'bono-arm-api' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'API', 'bono-arm-api' ); ?></th><th><?php esc_html_e( 'Route', 'bono-arm-api' ); ?></th><th><?php esc_html_e( 'Capability', 'bono-arm-api' ); ?></th></tr></thead>
					<tbody>
						<?php $this->endpoint_row( 'v1', $base_url . BONO_ARM_API_NAMESPACE . '/arm_payments_log', Capabilities::READ_PAYMENTS ); ?>
						<?php $this->endpoint_row( 'v2', $base_url . BONO_ARM_API_V2_NAMESPACE . '/payments', Capabilities::READ_PAYMENTS ); ?>
						<?php $this->endpoint_row( 'v2', $base_url . BONO_ARM_API_V2_NAMESPACE . '/members/{user_id}/activate', Capabilities::ACTIVATE_MEMBERS ); ?>
						<?php $this->endpoint_row( 'v2', $base_url . BONO_ARM_API_V2_NAMESPACE . '/members/{user_id}', Capabilities::DELETE_MEMBERS ); ?>
					</tbody>
				</table>
			</section>
			<p class="description"><?php esc_html_e( 'Administrator roles receive these capabilities on activation. Delegate individual capabilities only to trusted API users. The v2 payment endpoint hides payer email and notes in view context.', 'bono-arm-api' ); ?></p>
			<div id="bono-arm-api-copy-status" class="screen-reader-text" aria-live="polite"></div>
		</div>
		<?php
	}

	private function endpoint_row( $version, $route, $capability ) {
		$id = 'bono-arm-api-route-' . wp_unique_id();
		?>
		<tr>
			<td><?php echo esc_html( $version ); ?></td>
			<td><code id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $route ); ?></code> <button type="button" class="button button-small bono-arm-api-copy" data-copy-target="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'bono-arm-api' ); ?></button></td>
			<td><code><?php echo esc_html( $capability ); ?></code></td>
		</tr>
		<?php
	}
}
