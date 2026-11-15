<?php
namespace BonoArmApi\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable reservations survive timeouts, failed hooks, and new idempotency keys. */
class Renewal_Repository {
	private function table() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->prefix . 'bono_arm_renewals';
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function payment_insert_guard( &$inserts, $maximum ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		return static function ( $query ) use ( &$inserts, $maximum ) {
			global $wpdb;
			if ( preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $wpdb->prefix . 'arm_payment_log', '/' ) . '`?\s*\(/i', $query ) ) {
				++$inserts;
				// Block the vendor primitive’s unescaped retry SQL and extra payment insertions.
				if ( $inserts > $maximum || false === strpos( $query, '`' . $wpdb->prefix . 'arm_payment_log`' ) ) {
					return '';
				}
			}
			return $query;
		};
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function install() {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function lock( $scope ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		// Includes database and site, stays below MySQL's 64-character lock-name limit.
		$name = 'bono:' . substr( hash( 'sha256', DB_NAME . ':' . $wpdb->prefix . ':' . $scope ), 0, 58 );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ? $name : false;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function unlock( $name ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function find_key( $key ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $this->decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE key_hash = %s', $this->table(), hash( 'sha256', $key ) ), ARRAY_A ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function get( $id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $this->decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_id = %s', $this->table(), $id ), ARRAY_A ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function unresolved( $user_id, $plan_id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $this->decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND plan_id = %d AND state IN ('in_progress', 'needs_reconciliation') LIMIT 1", $this->table(), $user_id, $plan_id ), ARRAY_A ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function payment_reserved( $hash ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT operation_id FROM %i WHERE payment_hash = %s', $this->table(), $hash ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function funding_reserved( $id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT operation_id FROM %i WHERE funding_log_id = %d', $this->table(), $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function reservation_conflict( $payment_hash, $cycle_hash, $log_id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT operation_id FROM %i WHERE payment_hash = %s OR cycle_hash = %s OR funding_log_id = %d LIMIT 1', $this->table(), $payment_hash, $cycle_hash, $log_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function reserve( array $row ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		$row['payload']    = wp_json_encode( $row['payload'] );
		$row['created_at'] = gmdate( 'Y-m-d H:i:s' );
		$row['updated_at'] = $row['created_at'];
		return false !== $wpdb->insert( $this->table(), $row );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function save( $id, $state, array $payload ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	private function decode( $row ) {
		if ( $row ) {
			$row['payload'] = json_decode( $row['payload'], true );
		}
		return $row;
	}

	public function payment( $id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE arm_log_id = %d', $wpdb->prefix . 'arm_payment_log', $id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function reference_exists( $reference ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT arm_log_id FROM %i WHERE BINARY arm_transaction_id = %s LIMIT 1', $wpdb->prefix . 'arm_payment_log', $reference ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function armember_available() {
		return ( new Payment_Repository() )->tables_exist();
	}

	public function latest_payment( $user_id, $plan_id, $exclude_id = 0 ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE arm_user_id = %d AND arm_plan_id = %d AND arm_log_id != %d ORDER BY arm_log_id DESC LIMIT 1', $wpdb->prefix . 'arm_payment_log', $user_id, $plan_id, $exclude_id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function link_payment( $id, $log_id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return 1 === $wpdb->update( $this->table(), array( 'funding_log_id' => $log_id ), array( 'operation_id' => $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/** Close an operator-reconciled operation without releasing payment reservations. */
	public function resolve( $row, array $resolution ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		$payload                        = $row['payload'];
		$payload['resolution']          = $resolution;
		$payload['reserved_cycle_hash'] = $row['cycle_hash'];
		return 1 === $wpdb->update(
			$this->table(),
			array(
				'state'      => 'resolved',
				'payload'    => wp_json_encode( $payload ),
				'cycle_hash' => $resolution['cycle_stands'] ? $row['cycle_hash'] : hash( 'sha256', 'resolved:' . $row['operation_id'] ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'operation_id' => $row['operation_id'],
				'state'        => $row['state'],
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function privacy_rows( $user_id, $page = 1 ) {
		global $wpdb;
		if ( '1' !== get_option( 'bono_arm_api_renewals_schema' ) ) {
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Privacy requests require current journal records.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d OR actor_id = %d OR JSON_EXTRACT(payload, '$.resolution.actor_id') = %d ORDER BY operation_id LIMIT 100 OFFSET %d", $this->table(), $user_id, $user_id, $user_id, max( 0, $page - 1 ) * 100 ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return array_map( array( $this, 'decode' ), $rows );
	}

	/** Preserve deduplication keys while removing terminal journal personal details. */
	public function erase_personal_details( $row, $user_id ) {
		global $wpdb;
		if ( ! in_array( $row['state'], array( 'completed', 'resolved' ), true ) ) {
			return false;
		}
		$payload = $row['payload'];
		if ( (int) $row['user_id'] === $user_id ) {
			$payload = array( 'privacy_erased' => true );
		} elseif ( isset( $payload['resolution']['actor_id'] ) && (int) $payload['resolution']['actor_id'] === $user_id ) {
				$payload['resolution']['actor_id'] = 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Persist privacy erasure without releasing financial reservations.
		return 1 === $wpdb->update(
			$this->table(),
			array(
				'user_id'    => (int) $row['user_id'] === $user_id ? 0 : $row['user_id'],
				'actor_id'   => (int) $row['actor_id'] === $user_id ? 0 : $row['actor_id'],
				'payload'    => wp_json_encode( $payload ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'operation_id' => $row['operation_id'],
				'state'        => $row['state'],
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function member_status( $user_id ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fresh settlement reads, writes and connection locks bypass caches.
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT arm_primary_status, arm_secondary_status FROM %i WHERE arm_user_id = %d', $wpdb->prefix . 'arm_members', $user_id ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}
