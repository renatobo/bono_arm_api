<?php

/** Production updates must follow published artifacts even with a cached main selection. */
class Test_Update_Branch extends WP_UnitTestCase {
	public function test_cached_production_branch_migrates_to_stable() {
		$repo = array(
			'slug'           => 'bono_arm_api',
			'owner'          => 'renatobo',
			'type'           => 'plugin',
			'primary_branch' => 'stable',
			'branch'         => 'main',
		);
		$previous = get_site_option( 'git_updater', false );
		update_site_option( 'git_updater', array( 'current_branch_bono_arm_api' => 'main', 'other_setting' => 'keep' ) );
		$this->assertSame( 'stable', apply_filters( 'gu_fix_repo_slug', $repo )['branch'] );
		$this->assertSame( array( 'current_branch_bono_arm_api' => 'stable', 'other_setting' => 'keep' ), get_site_option( 'git_updater' ) );
		if ( false === $previous ) {
			delete_site_option( 'git_updater' );
		} else {
			update_site_option( 'git_updater', $previous );
		}

		foreach ( array( 'branch' => 'develop', 'slug' => 'another-plugin', 'owner' => 'another-owner', 'type' => 'theme', 'primary_branch' => 'main' ) as $field => $value ) {
			$other           = $repo;
			$other[ $field ] = $value;
			$this->assertSame( $other, apply_filters( 'gu_fix_repo_slug', $other ) );
		}
	}
}
