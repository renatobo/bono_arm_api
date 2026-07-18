<?php
namespace BonoArmApi\ARMember;

use BonoArmApi\Infrastructure\Payment_Repository;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gateway {
	private $repository;

	public function __construct( Payment_Repository $repository ) {
		$this->repository = $repository;
	}

	public function activate_member( $user_id, $send_email ) {
		if ( ! $this->repository->tables_exist() || ! function_exists( 'arm_set_member_status' ) ) {
			return new WP_Error( 'bono_arm_api_armember_unavailable', __( 'ARMember member functions are not available.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'bono_arm_api_user_not_found', __( 'User not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}

		arm_set_member_status( $user_id, 1 );
		$email_sent = false;

		if ( $send_email ) {
			global $arm_email_settings, $arm_global_settings;

			if ( isset( $arm_email_settings->templates->on_menual_activation ) && is_object( $arm_global_settings ) && method_exists( $arm_global_settings, 'arm_mailer' ) ) {
				$arm_global_settings->arm_mailer( $arm_email_settings->templates->on_menual_activation, $user_id );
				$email_sent = true;
			}
		}

		return array(
			'user_id'          => (int) $user_id,
			'primary_status'   => (int) get_user_meta( $user_id, 'arm_primary_status', true ),
			'secondary_status' => (int) get_user_meta( $user_id, 'arm_secondary_status', true ),
			'email_sent'       => $email_sent,
		);
	}

	public function delete_member( $user_id, $reassign_user_id ) {
		if ( is_multisite() ) {
			return new WP_Error( 'bono_arm_api_multisite_unsupported', __( 'Member deletion is not supported on multisite installs.', 'bono-arm-api' ), array( 'status' => 501 ) );
		}

		if ( $user_id === $reassign_user_id ) {
			return new WP_Error( 'bono_arm_api_reassign_invalid', __( 'The reassignment target must be different from the member being deleted.', 'bono-arm-api' ), array( 'status' => 400 ) );
		}

		if ( ! get_user_by( 'ID', $reassign_user_id ) ) {
			return new WP_Error( 'bono_arm_api_reassign_invalid', __( 'A valid content reassignment user is required.', 'bono-arm-api' ), array( 'status' => 400 ) );
		}

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'bono_arm_api_user_not_found', __( 'User not found.', 'bono-arm-api' ), array( 'status' => 404 ) );
		}

		$manager      = $this->members_manager();
		$can_cleanup  = $this->manager_can_delete( $manager );
		$hooks_active = $can_cleanup && false !== has_action( 'delete_user', array( $manager, 'arm_before_delete_user_action' ) ) && false !== has_action( 'deleted_user', array( $manager, 'arm_after_deleted_user_action' ) );

		if ( ! $hooks_active && ! $can_cleanup ) {
			return new WP_Error( 'bono_arm_api_delete_unavailable', __( 'ARMember delete lifecycle is not available.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		if ( ! $hooks_active ) {
			$manager->arm_before_delete_user_action( $user_id, $reassign_user_id );
		}

		$deleted = wp_delete_user( $user_id, $reassign_user_id );
		if ( ! $deleted ) {
			return new WP_Error( 'bono_arm_api_delete_failed', __( 'Member deletion failed.', 'bono-arm-api' ), array( 'status' => 500 ) );
		}

		if ( ! $hooks_active ) {
			$manager->arm_after_deleted_user_action( $user_id, $reassign_user_id );
		}

		return array(
			'user_id'               => (int) $user_id,
			'user_login'            => $user->user_login,
			'user_email'            => $user->user_email,
			'reassigned_to_user_id' => (int) $reassign_user_id,
			'cleanup_mode'          => $hooks_active ? 'automatic_hooks' : 'manual_fallback',
		);
	}

	private function members_manager() {
		global $arm_members_class;
		return is_object( $arm_members_class ) ? $arm_members_class : null;
	}

	private function manager_can_delete( $manager ) {
		return is_object( $manager ) && method_exists( $manager, 'arm_before_delete_user_action' ) && method_exists( $manager, 'arm_after_deleted_user_action' );
	}
}
