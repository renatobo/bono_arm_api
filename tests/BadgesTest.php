<?php
use BonoArmApi\Capabilities;

final class BadgesTest extends WP_UnitTestCase {
	private $member;
	private $actor;
	private $previous_native;

	public function set_up() {
		parent::set_up();
		$this->previous_native = $GLOBALS['arm_members_badges'] ?? null;
		$GLOBALS['arm_members_badges'] = new class {
			public function arm_get_all_badges() {
				return array(
					(object) array( 'arm_badges_id' => 7, 'arm_badges_type' => 'badge', 'arm_badges_name' => 'VIP', 'arm_badges_icon' => 'https://example.org/vip.svg' ),
					(object) array( 'arm_badges_id' => 8, 'arm_badges_type' => 'badge', 'arm_badges_name' => 'Other', 'arm_badges_icon' => 'https://example.org/other.svg' ),
				);
			}
			public function arm_get_single_badge( $id ) {
				foreach ( $this->arm_get_all_badges() as $badge ) {
					if ( $id === $badge->arm_badges_id ) { return (array) $badge; }
				}
				return 9 === $id ? array( 'arm_badges_id' => 9, 'arm_badges_type' => 'achievement' ) : false;
			}
		};
		$this->member = self::factory()->user->create();
		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Capabilities::activate();
		wp_set_current_user( $this->actor );
		update_option( BONO_ARM_API_OPTION_ENABLE_BADGES, true );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		$GLOBALS['arm_members_badges'] = $this->previous_native;
		parent::tear_down();
	}

	private function request( $method = 'GET', $suffix = '', $user = null ) {
		$user = null === $user ? $this->member : $user;
		$route = 'catalog' === $suffix ? '/bono_armember/v2/badges' : '/bono_armember/v2/members/' . $user . '/badges' . $suffix;
		return rest_get_server()->dispatch( new WP_REST_Request( $method, $route ) );
	}

	public function test_catalog_assignment_removal_and_idempotency_preserve_other_awards() {
		$other = array( 8 => array( 22 => array( 'plans' => 12 ) ) );
		update_user_meta( $this->member, 'arm_achievements', maybe_serialize( $other ) );
		$this->assertCount( 2, $this->request( 'GET', 'catalog' )->get_data()['badges'] );
		$this->assertSame( array( 8 ), array_column( $this->request()->get_data()['badges'], 'badge_id' ) );
		$added = $this->request( 'PUT', '/7' );
		$this->assertSame( 200, $added->get_status() );
		$this->assertTrue( $added->get_data()['changed'] );
		$this->assertFalse( $this->request( 'PUT', '/7' )->get_data()['changed'] );
		$this->assertCount( 2, $this->request()->get_data()['badges'] );
		$stored = maybe_unserialize( get_user_meta( $this->member, 'arm_achievements', true ) );
		$this->assertSame( array( array( 'admin' => '1' ) ), $stored[7] );
		$this->assertTrue( $this->request( 'DELETE', '/7' )->get_data()['changed'] );
		$this->assertFalse( $this->request( 'DELETE', '/7' )->get_data()['changed'] );
		$this->assertSame( $other, maybe_unserialize( get_user_meta( $this->member, 'arm_achievements', true ) ) );
	}

	public function test_removal_clears_every_award_of_target_without_deleting_definition() {
		update_user_meta( $this->member, 'arm_achievements', maybe_serialize( array( 7 => array( array( 'admin' => '1' ), 23 => array( 'plans' => 12 ) ) ) ) );
		$this->assertTrue( $this->request( 'DELETE', '/7' )->get_data()['changed'] );
		$this->assertSame( array(), $this->request()->get_data()['badges'] );
		$this->assertCount( 2, $this->request( 'GET', 'catalog' )->get_data()['badges'] );
	}

	public function test_empty_and_missing_metadata_can_be_assigned_and_reassigned() {
		foreach ( array( null, '', array(), maybe_serialize( array() ) ) as $empty ) {
			delete_user_meta( $this->member, 'arm_achievements' );
			if ( null !== $empty ) { update_user_meta( $this->member, 'arm_achievements', $empty ); }
			$this->assertSame( 200, $this->request( 'PUT', '/7' )->get_status() );
			$this->assertSame( 200, $this->request( 'DELETE', '/7' )->get_status() );
			$this->assertTrue( $this->request( 'PUT', '/7' )->get_data()['changed'] );
		}
	}

	public function test_permissions_disabled_dependency_and_invalid_ids() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request()->get_status() );
		wp_set_current_user( $this->member );
		$this->assertSame( 403, $this->request( 'PUT', '/7' )->get_status() );
		wp_set_current_user( $this->actor );
		update_option( BONO_ARM_API_OPTION_ENABLE_BADGES, false );
		$this->assertSame( 503, $this->request()->get_status() );
		update_option( BONO_ARM_API_OPTION_ENABLE_BADGES, true );
		$this->assertSame( 404, $this->request( 'PUT', '/9999' )->get_status() );
		$this->assertSame( 404, $this->request( 'PUT', '/9' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '', 999999 )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/0' )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '', 0 )->get_status() );
		$GLOBALS['arm_members_badges'] = null;
		$this->assertSame( 503, $this->request()->get_status() );
		$this->assertFalse( metadata_exists( 'user', $this->member, 'arm_achievements' ) );
	}

	public function test_malformed_metadata_is_preserved() {
		update_user_meta( $this->member, 'arm_achievements', 'corrupt' );
		$this->assertSame( 409, $this->request()->get_status() );
		$this->assertSame( 409, $this->request( 'PUT', '/7' )->get_status() );
		$this->assertSame( 'corrupt', get_user_meta( $this->member, 'arm_achievements', true ) );
	}

	public function test_concurrent_change_is_preserved_on_retry() {
		update_user_meta( $this->member, 'arm_achievements', maybe_serialize( array( 8 => array( array( 'admin' => '1' ) ) ) ) );
		$once = false;
		$filter = function ( $check, $id, $key ) use ( &$once ) {
			if ( ! $once && $id === $this->member && 'arm_achievements' === $key ) {
				$once = true;
				update_user_meta( $id, $key, maybe_serialize( array( 8 => array( 55 => array( 'plans' => 17 ) ) ) ) );
			}
			return $check;
		};
		add_filter( 'update_user_metadata', $filter, 10, 3 );
		try { $response = $this->request( 'PUT', '/7' ); }
		finally { remove_filter( 'update_user_metadata', $filter, 10 ); }
		$this->assertSame( 200, $response->get_status() );
		$stored = maybe_unserialize( get_user_meta( $this->member, 'arm_achievements', true ) );
		$this->assertSame( array( 55 => array( 'plans' => 17 ) ), $stored[8] );
		$this->assertArrayHasKey( 7, $stored );
	}
	public function test_empty_catalog_and_no_store_headers() {
		$GLOBALS['arm_members_badges'] = new class {
			public function arm_get_all_badges() { return false; }
			public function arm_get_single_badge( $id ) { return false; }
		};
		$response = $this->request( 'GET', 'catalog' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['badges'] );
		$request = new WP_REST_Request( 'GET', '/bono_armember/v2/badges' );
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_lock_contention_and_failed_save_leave_assignments_unchanged() {
		$filter = static function ( $sql ) {
			return false !== strpos( $sql, 'SELECT GET_LOCK(' ) ? 'SELECT 0' : $sql;
		};
		add_filter( 'query', $filter );
		try { $response = $this->request( 'PUT', '/7' ); }
		finally { remove_filter( 'query', $filter ); }
		$this->assertSame( 409, $response->get_status() );
		$this->assertFalse( metadata_exists( 'user', $this->member, 'arm_achievements' ) );
		$before = maybe_serialize( array( 8 => array( array( 'admin' => '1' ) ) ) );
		update_user_meta( $this->member, 'arm_achievements', $before );
		$fail = static function ( $check, $id, $key ) {
			return 'arm_achievements' === $key ? false : $check;
		};
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		try { $response = $this->request( 'PUT', '/7' ); }
		finally { remove_filter( 'update_user_metadata', $fail, 10 ); }
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $before, get_user_meta( $this->member, 'arm_achievements', true ) );
	}

}
