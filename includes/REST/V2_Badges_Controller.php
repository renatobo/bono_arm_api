<?php
namespace BonoArmApi\REST;

use BonoArmApi\Capabilities;
use BonoArmApi\Infrastructure\Renewal_Repository;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Existing ARMember badge definitions and per-user assignments. */
final class V2_Badges_Controller extends WP_REST_Controller {
	public function __construct() {
		$this->namespace = BONO_ARM_API_V2_NAMESPACE;
	}

	public function register_routes() {
		$id = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		register_rest_route(
			$this->namespace,
			'/badges',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/badges',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array( 'user_id' => $id ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/members/(?P<user_id>[\d]+)/badges/(?P<badge_id>[\d]+)',
			array(
				'methods'             => 'PUT, DELETE',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'user_id'  => $id,
					'badge_id' => $id,
				),
			)
		);
	}

	public function permission() {
		return current_user_can( Capabilities::MANAGE_BADGES ) ? true : new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to perform this action.', 'bono-arm-api' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function response_headers( $response, $server, $request ) {
		if ( preg_match( '#^/' . preg_quote( $this->namespace, '#' ) . '/(?:badges|members/\d+/badges(?:/\d+)?)$#D', $request->get_route() ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	public function handle( WP_REST_Request $request ) {
		global $arm_members_badges;
		if ( ! get_option( BONO_ARM_API_OPTION_ENABLE_BADGES, false ) ) {
			return new WP_Error( 'bono_arm_api_feature_disabled', __( 'Badge endpoints are disabled.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}
		foreach ( array( 'arm_get_all_badges', 'arm_get_single_badge' ) as $method ) {
			if ( ! is_callable( array( $arm_members_badges, $method ) ) ) {
				return $this->unavailable();
			}
		}
		try {
			if ( 'GET' === $request->get_method() ) {
				return $this->execute( $request, $arm_members_badges );
			}
			$repository = new Renewal_Repository();
			$lock       = $repository->lock( 'member:' . (int) $request['user_id'] );
			if ( ! $lock ) {
				return $this->conflict();
			}
			try {
				return $this->execute( $request, $arm_members_badges );
			} finally {
				$repository->unlock( $lock );
			}
		} catch ( \Throwable $exception ) {
			return $this->unavailable();
		}
	}

	private function execute( $request, $native ) {
		$user_id = (int) $request['user_id'];
		if ( $user_id && ( ! get_userdata( $user_id ) || ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) ) ) {
			return new WP_Error( 'bono_arm_api_member_not_found', __( 'Member not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}
		if ( 'GET' === $request->get_method() ) {
			$assignments = $user_id ? $this->assignments( get_user_meta( $user_id, 'arm_achievements', true ) ) : array();
			if ( is_wp_error( $assignments ) ) {
				return $assignments;
			}
			$badges      = array();
			$definitions = $native->arm_get_all_badges();
			foreach ( $definitions ? (array) $definitions : array() as $definition ) {
				$definition = (array) $definition;
				$id         = (int) $definition['arm_badges_id'];
				if ( $user_id && empty( $assignments[ $id ] ) ) {
					continue;
				}
				$badges[] = $this->badge( $definition );
			}
			return new WP_REST_Response( array( 'badges' => $badges ), 200 );
		}
		$badge_id = (int) $request['badge_id'];
		$badge    = $native->arm_get_single_badge( $badge_id );
		if ( ! $badge || 'badge' !== ( $badge['arm_badges_type'] ?? '' ) ) {
			return new WP_Error( 'bono_arm_api_badge_not_found', __( 'Badge not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}
		$present = 'PUT' === $request->get_method();
		// Compare-and-swap protects other awards against concurrent metadata updates.
		// Pro 8.1.1's arm_*_user_meta wrappers use these WordPress metadata functions.
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$exists = metadata_exists( 'user', $user_id, 'arm_achievements' );
			$before = get_user_meta( $user_id, 'arm_achievements', true );
			$after  = $this->assignments( $before );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			$assigned = ! empty( $after[ $badge_id ] );
			$changed  = $assigned !== $present;
			if ( false === $changed ) {
				return new WP_REST_Response(
					array(
						'user_id'  => $user_id,
						'badge_id' => $badge_id,
						'assigned' => $present,
						'changed'  => false,
					),
					200
				);
			}
			if ( $present ) {
				$after[ $badge_id ] = array( array( 'admin' => '1' ) );
			} else {
				unset( $after[ $badge_id ] );
			}
			// Native admin assignment stores a serialized array inside WordPress metadata.
			$value = wp_slash( maybe_serialize( $after ) );
			if ( $exists && ! empty( $before ) ) {
				$saved = update_user_meta( $user_id, 'arm_achievements', $value, $before );
			} elseif ( ! $exists ) {
				$saved = add_user_meta( $user_id, 'arm_achievements', $value, true );
			} else {
				// API writers share the member lock, including for legacy empty values.
				$saved = update_user_meta( $user_id, 'arm_achievements', $value );
			}
			if ( $saved && $after === $this->assignments( get_user_meta( $user_id, 'arm_achievements', true ) ) ) {
				return new WP_REST_Response(
					array(
						'user_id'  => $user_id,
						'badge_id' => $badge_id,
						'assigned' => $present,
						'changed'  => true,
					),
					200
				);
			}
		}
		return $this->conflict();
	}

	private function assignments( $value ) {
		$value = maybe_unserialize( $value );
		if ( '' === $value || false === $value || null === $value ) {
			return array();
		}
		return is_array( $value ) ? $value : new WP_Error( 'bono_arm_api_badge_state_invalid', __( 'Badge assignment data is invalid.', 'bono-arm-api' ), array( 'status' => 409 ) );
	}

	private function badge( array $definition ) {
		return array(
			'badge_id' => (int) $definition['arm_badges_id'],
			'name'     => (string) $definition['arm_badges_name'],
			'icon'     => esc_url_raw( $definition['arm_badges_icon'] ),
		);
	}

	private function conflict() {
		return new WP_Error( 'bono_arm_api_badge_update_conflict', __( 'Badge assignments changed or could not be saved. Read the current state before retrying.', 'bono-arm-api' ), array( 'status' => 409 ) );
	}

	private function unavailable() {
		return new WP_Error( 'bono_arm_api_armember_unavailable', __( 'ARMember badge support is unavailable.', 'bono-arm-api' ), array( 'status' => 503 ) );
	}
}
