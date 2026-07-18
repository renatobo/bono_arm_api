<?php
namespace BonoArmApi\Infrastructure;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Payment_Repository {
	public function tables() {
		global $wpdb;

		return array(
			'payment_log' => $wpdb->prefix . 'arm_payment_log',
			'members'     => $wpdb->prefix . 'arm_members',
		);
	}

	public function tables_exist() {
		global $wpdb;

		$cache_key = 'bono_arm_api_tables_' . get_current_blog_id();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['exists'] ) ) {
			return (bool) $cached['exists'];
		}

		foreach ( $this->tables() as $table_name ) {
			$existing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );

			if ( $existing !== $table_name ) {
				set_transient( $cache_key, array( 'exists' => false ), BONO_ARM_API_TABLE_CHECK_TTL );
				return false;
			}
		}

		set_transient( $cache_key, array( 'exists' => true ), BONO_ARM_API_TABLE_CHECK_TTL );
		return true;
	}

	public function get_page( $minimum_invoice_id, $plan_id, $page, $per_page ) {
		global $wpdb;

		if ( ! $this->tables_exist() ) {
			return new WP_Error( 'bono_arm_api_armember_unavailable', __( 'ARMember payment tables are not available.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		$tables = $this->tables();
		$where  = $this->where_sql( $minimum_invoice_id, $plan_id );
		$offset = ( $page - 1 ) * $per_page;
		$query  = $wpdb->prepare(
			"SELECT
				a.arm_user_id AS id,
				a.arm_invoice_id AS arm_log_id,
				b.arm_user_login AS username,
				a.arm_payer_email,
				CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
				a.arm_payment_gateway,
				a.arm_payment_date,
				IF(a.arm_extra_vars LIKE '%%manual_by%%',
					SUBSTRING_INDEX(SUBSTRING_INDEX(a.arm_extra_vars, 's:13:\"', -1), '\";}', 1),
					'') AS notes,
				a.arm_transaction_status,
				totals.total_count AS bono_total_count
			FROM {$tables['payment_log']} AS a
			INNER JOIN {$tables['members']} AS b ON a.arm_user_id = b.arm_user_id
			CROSS JOIN (
				SELECT COUNT(*) AS total_count
				FROM {$tables['payment_log']} AS a
				INNER JOIN {$tables['members']} AS b ON a.arm_user_id = b.arm_user_id
				{$where}
			) AS totals
			{$where}
			ORDER BY a.arm_invoice_id DESC
			LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$rows   = $wpdb->get_results( $query, ARRAY_A );

		if ( $wpdb->last_error ) {
			return new WP_Error( 'bono_arm_api_database_error', __( 'Unable to load ARMember payment records.', 'bono-arm-api' ), array( 'status' => 500 ) );
		}

		$total = 0;
		if ( $rows ) {
			$total = (int) $rows[0]['bono_total_count'];
		} elseif ( $page > 1 ) {
			$total = $this->count( $minimum_invoice_id, $plan_id );
		}

		foreach ( $rows as &$row ) {
			unset( $row['bono_total_count'] );
			$row = $this->normalize_row( $row );
		}
		unset( $row );

		return array(
			'payments'    => $rows,
			'total_count' => (int) $total,
		);
	}

	public function get_cursor_page( $after_invoice_id, $plan_id, $per_page, $include_totals = false ) {
		global $wpdb;

		if ( ! $this->tables_exist() ) {
			return new WP_Error( 'bono_arm_api_armember_unavailable', __( 'ARMember payment tables are not available.', 'bono-arm-api' ), array( 'status' => 503 ) );
		}

		$tables = $this->tables();
		$where  = $this->where_sql( $after_invoice_id, $plan_id );
		$limit  = $per_page + 1;
		$query  = $wpdb->prepare(
			"SELECT
				a.arm_user_id AS id,
				a.arm_invoice_id AS arm_log_id,
				b.arm_user_login AS username,
				a.arm_payer_email,
				CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
				a.arm_payment_gateway,
				a.arm_payment_date,
				IF(a.arm_extra_vars LIKE '%%manual_by%%',
					SUBSTRING_INDEX(SUBSTRING_INDEX(a.arm_extra_vars, 's:13:\"', -1), '\";}', 1),
					'') AS notes,
				a.arm_transaction_status
			FROM {$tables['payment_log']} AS a
			INNER JOIN {$tables['members']} AS b ON a.arm_user_id = b.arm_user_id
			{$where}
			ORDER BY a.arm_invoice_id ASC
			LIMIT %d",
			$limit
		);
		$rows   = $wpdb->get_results( $query, ARRAY_A );

		if ( $wpdb->last_error ) {
			return new WP_Error( 'bono_arm_api_database_error', __( 'Unable to load ARMember payment records.', 'bono-arm-api' ), array( 'status' => 500 ) );
		}

		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$rows = array_map( array( $this, 'normalize_row' ), $rows );
		$last = $rows ? end( $rows ) : null;

		return array(
			'payments'    => $rows,
			'has_more'    => $has_more,
			'next_cursor' => $last ? (int) $last['arm_log_id'] : null,
			'total_count' => $include_totals ? $this->count( $after_invoice_id, $plan_id ) : null,
		);
	}

	public function count( $minimum_invoice_id, $plan_id ) {
		global $wpdb;

		$tables = $this->tables();
		$where  = $this->where_sql( $minimum_invoice_id, $plan_id );

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$tables['payment_log']} AS a
			INNER JOIN {$tables['members']} AS b ON a.arm_user_id = b.arm_user_id
			{$where}"
		);
	}

	private function where_sql( $minimum_invoice_id, $plan_id ) {
		global $wpdb;

		$where = $wpdb->prepare(
			"WHERE a.arm_transaction_status = 'success' AND a.arm_invoice_id > %d",
			$minimum_invoice_id
		);

		if ( $plan_id ) {
			$where .= $wpdb->prepare( ' AND a.arm_plan_id = %d', $plan_id );
		}

		return $where;
	}

	public function normalize_row( $row ) {
		foreach ( $row as $key => $value ) {
			$row[ $key ] = null === $value ? '' : $value;
		}

		$row['id']         = (int) $row['id'];
		$row['arm_log_id'] = (int) $row['arm_log_id'];

		if ( $row['arm_payment_date'] ) {
			$timestamp               = strtotime( $row['arm_payment_date'] );
			$row['arm_payment_date'] = false !== $timestamp ? gmdate( 'c', $timestamp ) : '';
		}

		return $row;
	}
}
