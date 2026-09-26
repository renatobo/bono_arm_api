<?php
namespace BonoArmApi\Infrastructure;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only access to ARMember's payment and member tables.
 *
 * Caching policy: schema probes are cached in a transient, because they answer the same
 * question on every request and a transient survives without a persistent object cache.
 * Payment reads are deliberately not cached. Each REST request issues its query once and
 * returns, so a request-scoped wp_cache_* entry would never be read back on a default
 * install; where a persistent backend does exist, caching would serve stale rows to the
 * integrations that poll this API by invoice cursor precisely to pick up new records.
 * ARMember writes these tables outside this plugin, so there is no invalidation point.
 *
 * Statement preparation: table names are passed as %i identifier placeholders and
 * where_clause() returns unresolved %d placeholders with their values, so every statement
 * is prepared exactly once. Do not reintroduce a pre-prepared SQL fragment.
 */
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

		list( $where, $where_args ) = $this->where_clause( $minimum_invoice_id, $plan_id );

		$args = array_merge(
			array( $tables['payment_log'], $tables['members'], $tables['payment_log'], $tables['members'] ),
			$where_args,
			$where_args,
			array( $per_page, $offset )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders and its values travel in $args, so the statement is prepared exactly once; table names use %i identifier placeholders; ARMember exposes no core API, and these reads stay uncached on purpose: each REST request runs the query once, so wp_cache_* would never be read back on the sites that lack a persistent object cache, and on the sites that have one it would hand stale rows to cursor-based sync clients. See the class docblock.
		$query = $wpdb->prepare(
			"SELECT
				a.arm_user_id AS id,
				a.arm_invoice_id AS arm_log_id,
				b.arm_user_login AS username,
				a.arm_payer_email,
				CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
				a.arm_payment_gateway,
				a.arm_payment_date,
				a.arm_extra_vars,
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
			return $this->database_error( 'get_page' );
		}

		$total = 0;
		if ( $rows ) {
			$total = (int) $rows[0]['bono_total_count'];
		} elseif ( $page > 1 ) {
			$total = $this->count( $minimum_invoice_id, $plan_id );

			if ( is_wp_error( $total ) ) {
				return $total;
			}
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

		list( $where, $where_args ) = $this->where_clause( $after_invoice_id, $plan_id );

		$args = array_merge(
			array( $tables['payment_log'], $tables['members'] ),
			$where_args,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders and its values travel in $args, so the statement is prepared exactly once; table names use %i identifier placeholders; ARMember exposes no core API, and these reads stay uncached on purpose: each REST request runs the query once, so wp_cache_* would never be read back on the sites that lack a persistent object cache, and on the sites that have one it would hand stale rows to cursor-based sync clients. See the class docblock.
		$query = $wpdb->prepare(
			"SELECT
				a.arm_user_id AS id,
				a.arm_invoice_id AS arm_log_id,
				b.arm_user_login AS username,
				a.arm_payer_email,
				CONCAT(a.arm_currency, ' ', a.arm_amount) AS arm_paid_amount,
				a.arm_payment_gateway,
				a.arm_payment_date,
				a.arm_extra_vars,
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
			return $this->database_error( 'get_cursor_page' );
		}

		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$rows = array_map( array( $this, 'normalize_row' ), $rows );
		$last = $rows ? end( $rows ) : null;

		$total = null;
		if ( $include_totals ) {
			$total = $this->count( $after_invoice_id, $plan_id );

			if ( is_wp_error( $total ) ) {
				return $total;
			}
		}

		return array(
			'payments'    => $rows,
			'has_more'    => $has_more,
			'next_cursor' => $last ? (int) $last['arm_log_id'] : null,
			'total_count' => $total,
		);
	}

	public function count( $minimum_invoice_id, $plan_id ) {
		global $wpdb;

		$tables = $this->tables();

		list( $where, $where_args ) = $this->where_clause( $minimum_invoice_id, $plan_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds only %d placeholders and its values travel in $args, so the statement is prepared exactly once; table names use %i identifier placeholders; ARMember exposes no core API, and these reads stay uncached on purpose: each REST request runs the query once, so wp_cache_* would never be read back on the sites that lack a persistent object cache, and on the sites that have one it would hand stale rows to cursor-based sync clients. See the class docblock.
		$query = $wpdb->prepare(
			"SELECT COUNT(*)
			FROM %i AS a
			INNER JOIN %i AS b ON a.arm_user_id = b.arm_user_id
			{$where}",
			array_merge( array( $tables['payment_log'], $tables['members'] ), $where_args )
		);

		$total = $wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $wpdb->last_error ) {
			return $this->database_error( 'count' );
		}

		return (int) $total;
	}

	/**
	 * Reports whether a WordPress user has a row in ARMember's members table.
	 */
	public function member_exists( $user_id ) {
		global $wpdb;

		$tables = $this->tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- ARMember exposes no lookup API; this runs once per mutating request, right before ARMember writes the same row, so a cached answer could be stale.
		$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE arm_user_id = %d LIMIT 1', $tables['members'], $user_id ) );

		return '1' === (string) $found;
	}

	/**
	 * Reports whether arm_payment_log has an index led by arm_invoice_id.
	 *
	 * ARMember does not create one, so every payments query scans and sorts the whole table.
	 * The answer is cached like tables_exist(), because it only changes when an operator alters
	 * the table.
	 */
	public function invoice_index_exists() {
		global $wpdb;

		$cache_key = 'bono_arm_api_invoice_index_' . get_current_blog_id();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['exists'] ) ) {
			return (bool) $cached['exists'];
		}

		if ( ! $this->tables_exist() ) {
			return false;
		}

		$tables = $this->tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe with no core API; the result is cached in a transient for BONO_ARM_API_TABLE_CHECK_TTL below.
		$index = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Column_name = %s AND Seq_in_index = 1', $tables['payment_log'], 'arm_invoice_id' ) );
		$found = null !== $index && ! $wpdb->last_error;

		set_transient( $cache_key, array( 'exists' => $found ), BONO_ARM_API_TABLE_CHECK_TTL );
		return $found;
	}

	/**
	 * The statement an operator can run to add the index invoice_index_exists() looks for.
	 */
	public function invoice_index_sql() {
		$tables = $this->tables();
		return sprintf( 'ALTER TABLE `%s` ADD INDEX `bono_invoice_id` (`arm_invoice_id`);', $tables['payment_log'] );
	}

	/**
	 * Drops the cached schema probes so the next request checks the database again.
	 */
	public function flush_schema_cache() {
		delete_transient( 'bono_arm_api_tables_' . get_current_blog_id() );
		delete_transient( 'bono_arm_api_invoice_index_' . get_current_blog_id() );
	}

	private function database_error( $operation ) {
		global $wpdb;

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only when WP_DEBUG_LOG is on; the client receives a generic message.
			error_log( sprintf( 'Bono API for ARMember: %s query failed: %s', $operation, $wpdb->last_error ) );
		}

		return new WP_Error( 'bono_arm_api_database_error', __( 'Unable to load ARMember payment records.', 'bono-arm-api' ), array( 'status' => 500 ) );
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
		$raw = $row;

		foreach ( $row as $key => $value ) {
			$row[ $key ] = null === $value ? '' : $value;
		}

		$row['id']               = (int) $row['id'];
		$row['arm_log_id']       = (int) $row['arm_log_id'];
		$row['arm_payment_date'] = $this->to_utc( $row['arm_payment_date'] );
		$row['notes']            = $this->extract_notes( $row['arm_extra_vars'] );
		unset( $row['arm_extra_vars'] );

		/**
		 * Filters one normalized payment row before either API version maps it to a response.
		 *
		 * Keep every field's type unchanged; both OpenAPI specs describe these fields.
		 *
		 * @param array $row Normalized row.
		 * @param array $raw Row as returned by the database.
		 */
		return apply_filters( 'bono_arm_api_payment_row', $row, $raw );
	}

	/**
	 * Converts an ARMember payment date to ISO 8601 UTC.
	 *
	 * ARMember stores arm_payment_date with current_time( 'mysql' ), which is site-local time,
	 * and uses 1970-01-01 00:00:00 as its "no date" column default.
	 */
	private function to_utc( $value ) {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '1970-01-01 00:00:00' ) || 0 === strpos( $value, '0000-00-00' ) ) {
			return '';
		}

		try {
			$local = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}

		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'c' );
	}

	/**
	 * Returns the administrative note from ARMember's serialized arm_extra_vars column.
	 *
	 * Manual payments store the admin's text under `note`; system and admin-assigned plans store
	 * a `manual_by` label, which ARMember translates and HTML-escapes before saving.
	 */
	private function extract_notes( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || ! is_serialized( $raw ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes=false prevents object instantiation; ARMember stores this column serialized, and a corrupt value must not raise a notice into the REST response.
		$vars = @unserialize( $raw, array( 'allowed_classes' => false ) );

		if ( ! is_array( $vars ) ) {
			return '';
		}

		foreach ( array( 'note', 'manual_by' ) as $key ) {
			if ( isset( $vars[ $key ] ) && is_scalar( $vars[ $key ] ) && '' !== (string) $vars[ $key ] ) {
				return html_entity_decode( (string) $vars[ $key ], ENT_QUOTES, 'UTF-8' );
			}
		}

		return '';
	}
}
