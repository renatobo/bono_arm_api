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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe with no core API; the result is cached in a transient for BONO_ARM_API_TABLE_CHECK_TTL below.
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
		$offset = ( $page - 1 ) * $per_page;
		$manual = '%' . $wpdb->esc_like( 'manual_by' ) . '%';

		list( $where, $where_args ) = $this->where_clause( $minimum_invoice_id, $plan_id );

		$args = array_merge(
			array( $manual, $tables['payment_log'], $tables['members'], $tables['payment_log'], $tables['members'] ),
			$where_args,
			$where_args,
			array( $per_page, $offset )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders and its values travel in $args, so the statement is prepared exactly once; table names use %i identifier placeholders; ARMember exposes no core API and these reporting reads are intentionally live.
		$query = $wpdb->prepare(
			"SELECT
				a.arm_user_id AS id,
				a.arm_invoice_id AS arm_log_id,
				b.arm_user_login AS username,
				a.arm_payer_email,
				CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
				a.arm_payment_gateway,
				a.arm_payment_date,
				IF(a.arm_extra_vars LIKE %s,
					SUBSTRING_INDEX(SUBSTRING_INDEX(a.arm_extra_vars, 's:13:\"', -1), '\";}', 1),
					'') AS notes,
				a.arm_transaction_status,
				totals.total_count AS bono_total_count
			FROM %i AS a
			INNER JOIN %i AS b ON a.arm_user_id = b.arm_user_id
			CROSS JOIN (
				SELECT COUNT(*) AS total_count
				FROM %i AS a
				INNER JOIN %i AS b ON a.arm_user_id = b.arm_user_id
				{$where}
			) AS totals
			{$where}
			ORDER BY a.arm_invoice_id DESC
			LIMIT %d OFFSET %d",
			$args
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

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
		$limit  = $per_page + 1;
		$manual = '%' . $wpdb->esc_like( 'manual_by' ) . '%';

		list( $where, $where_args ) = $this->where_clause( $after_invoice_id, $plan_id );

		$args = array_merge(
			array( $manual, $tables['payment_log'], $tables['members'] ),
			$where_args,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders and its values travel in $args, so the statement is prepared exactly once; table names use %i identifier placeholders; ARMember exposes no core API and these reporting reads are intentionally live.
		$query = $wpdb->prepare(
			"SELECT
				a.arm_user_id AS id,
				a.arm_invoice_id AS arm_log_id,
				b.arm_user_login AS username,
				a.arm_payer_email,
				CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
				a.arm_payment_gateway,
				a.arm_payment_date,
				IF(a.arm_extra_vars LIKE %s,
					SUBSTRING_INDEX(SUBSTRING_INDEX(a.arm_extra_vars, 's:13:\"', -1), '\";}', 1),
					'') AS notes,
				a.arm_transaction_status
			FROM %i AS a
			INNER JOIN %i AS b ON a.arm_user_id = b.arm_user_id
			{$where}
			ORDER BY a.arm_invoice_id ASC
			LIMIT %d",
			$args
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

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

		list( $where, $where_args ) = $this->where_clause( $minimum_invoice_id, $plan_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders and its values travel in $args, so the statement is prepared exactly once; table names use %i identifier placeholders; ARMember exposes no core API and these reporting reads are intentionally live.
		$query = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM %i AS a
			INNER JOIN %i AS b ON a.arm_user_id = b.arm_user_id
			{$where}",
			array_merge( array( $tables['payment_log'], $tables['members'] ), $where_args )
		);

		return (int) $wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Returns the shared WHERE clause with unresolved placeholders plus its values.
	 *
	 * The clause is deliberately *not* prepared here: callers merge $args into their own
	 * single prepare() call, so the fragment is never processed twice.
	 *
	 * @return array{0:string,1:array}
	 */
	private function where_clause( $minimum_invoice_id, $plan_id ) {
		$where = "WHERE a.arm_transaction_status = 'success' AND a.arm_invoice_id > %d";
		$args  = array( $minimum_invoice_id );

		if ( $plan_id ) {
			$where .= ' AND a.arm_plan_id = %d';
			$args[] = $plan_id;
		}

		return array( $where, $args );
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
