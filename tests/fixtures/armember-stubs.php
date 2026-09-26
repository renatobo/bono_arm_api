<?php
/**
 * Minimal stand-ins for the ARMember surface the plugin calls.
 *
 * These are hand-written fakes, not ARMember code. They mirror only the observable behaviour
 * the plugin depends on: arm_set_member_status() updates the member row and always writes the
 * status user meta, and the members manager exposes the pre- and post-delete cleanup methods.
 */

if ( ! function_exists( 'arm_set_member_status' ) ) {
	function arm_set_member_status( $user_id, $primary_status = 1, $secondary_status = 0 ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'arm_members',
			array(
				'arm_primary_status'   => $primary_status,
				'arm_secondary_status' => $secondary_status,
			),
			array( 'arm_user_id' => $user_id )
		);
		update_user_meta( $user_id, 'arm_primary_status', $primary_status );
		update_user_meta( $user_id, 'arm_secondary_status', $secondary_status );
	}
}

final class Bono_Arm_Api_Test_Members_Manager {
	public $before = array();
	public $after  = array();

	public function arm_before_delete_user_action( $id, $reassign = 1 ) {
		$this->before[] = (int) $id;
	}

	public function arm_after_deleted_user_action( $id, $reassign = 1 ) {
		$this->after[] = (int) $id;
	}
}
