<?php
namespace BonoArmApi\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable reservations survive timeouts, failed hooks, and new idempotency keys. */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh reads, durable writes and connection locks are required for settlement correctness.
class Renewal_Repository {
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'bono_arm_renewals';
	}

	public function install() {
		global $wpdb;
		if ( '1' === get_option( 'bono_arm_api_renewals_schema' ) ) {
			return true;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $this->table();
		// Hashes use fixed ASCII hex; no collation-dependent reference comparisons.
		dbDelta(
			"CREATE TABLE $table (
			operation_id varchar(36) NOT NULL,
			key_hash varchar(64) NOT NULL,
			command_hash varchar(64) NOT NULL,
			payment_hash varchar(64) NOT NULL,
			funding_log_id bigint(20) unsigned DEFAULT NULL,
			cycle_hash varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			plan_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL,
			state varchar(32) NOT NULL,
			payload longtext NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (operation_id),
			UNIQUE KEY idempotency (key_hash),
			UNIQUE KEY payment (payment_hash),
			UNIQUE KEY funding (funding_log_id),
			UNIQUE KEY cycle (cycle_hash),
			KEY member_plan (user_id,plan_id)
		) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			update_option( 'bono_arm_api_renewals_schema', '1', false );
			return true;
		}
		return false;
	}

	public function lock( $scope ) {
		global $wpdb;
		// Includes database and site, stays below MySQL's 64-character lock-name limit.
		$name = 'bono:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $scope ), 0, 58 );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ? $name : false;
	}

	public function unlock( $name ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	public function find_key( $key ) {
		global $wpdb;
		return $this->decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE key_hash = %s', $this->table(), hash( 'sha256', $key ) ), ARRAY_A ) );
	}

	public function get( $id ) {
		global $wpdb;
		return $this->decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_id = %s', $this->table(), $id ), ARRAY_A ) );
	}

	public function unresolved( $user_id, $plan_id ) {
		global $wpdb;
		return $this->decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND plan_id = %d AND state IN ('in_progress', 'needs_reconciliation') LIMIT 1", $this->table(), $user_id, $plan_id ), ARRAY_A ) );
	}

	public function payment_reserved( $hash ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT operation_id FROM %i WHERE payment_hash = %s', $this->table(), $hash ) );
	}

	public function funding_reserved( $id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT operation_id FROM %i WHERE funding_log_id = %d', $this->table(), $id ) );
	}

	public function reservation_conflict( $payment_hash, $cycle_hash, $log_id ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT operation_id FROM %i WHERE payment_hash = %s OR cycle_hash = %s OR funding_log_id = %d LIMIT 1', $this->table(), $payment_hash, $cycle_hash, $log_id ) );
	}

	public function reserve( array $row ) {
		global $wpdb;
		$row['payload']    = wp_json_encode( $row['payload'] );
		$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$row['updated_at'] = $row['created_at'];
		return false !== $wpdb->insert( $this->table(), $row );
	}

	public function save( $id, $state, array $payload ) {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table(),
			array(
				'state'      => $state,
				'payload'    => wp_json_encode( $payload ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'operation_id' => $id )
		);
	}

	private function decode( $row ) {
		if ( $row ) {
			$row['payload'] = json_decode( $row['payload'], true );
		}
		return $row;
	}

	public function payment( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE arm_log_id = %d', $wpdb->prefix . 'arm_payment_log', $id ), ARRAY_A );
	}

	public function reference_exists( $reference ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT arm_log_id FROM %i WHERE BINARY arm_transaction_id = %s LIMIT 1', $wpdb->prefix . 'arm_payment_log', $reference ) );
	}

	public function armember_available() {
		return ( new Payment_Repository() )->tables_exist();
	}

	public function latest_payment( $user_id, $plan_id, $exclude_id = 0 ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE arm_user_id = %d AND arm_plan_id = %d AND arm_log_id != %d ORDER BY arm_log_id DESC LIMIT 1', $wpdb->prefix . 'arm_payment_log', $user_id, $plan_id, $exclude_id ), ARRAY_A );
	}

	public function link_payment( $id, $log_id ) {
		global $wpdb;
		return 1 === $wpdb->update( $this->table(), array( 'funding_log_id' => $log_id ), array( 'operation_id' => $id ) );
	}

	public function member_status( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT arm_primary_status, arm_secondary_status FROM %i WHERE arm_user_id = %d', $wpdb->prefix . 'arm_members', $user_id ), ARRAY_A );
	}
}
