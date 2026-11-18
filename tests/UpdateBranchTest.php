<?php

/** Production updates use Git Updater's resolved slug-indexed repository objects. */
class Test_Update_Branch extends WP_UnitTestCase {
	public function test_cached_production_branch_migrates_to_stable() {
		$repo = (object) array(
			'slug'           => 'bono_arm_api',
			'owner'          => 'renatobo',
			'type'           => 'plugin',
			'primary_branch' => 'stable',
			'branch'         => 'main',
		);
		$previous = get_site_option( 'git_updater', false );
		update_site_option( 'git_updater', array( 'current_branch_bono_arm_api' => 'main', 'other_setting' => 'keep' ) );
		$result = apply_filters( 'gu_config_pre_process', array( 'bono_arm_api' => $repo ) );
		$this->assertSame( $repo, $result['bono_arm_api'] );
		$this->assertSame( 'stable', $repo->branch );
		$this->assertSame( array( 'current_branch_bono_arm_api' => 'stable', 'other_setting' => 'keep' ), get_site_option( 'git_updater' ) );
		if ( false === $previous ) {
			delete_site_option( 'git_updater' );
		} else {
			update_site_option( 'git_updater', $previous );
		}

		foreach ( array( 'branch' => 'develop', 'slug' => 'another-plugin', 'owner' => 'another-owner', 'type' => 'theme', 'primary_branch' => 'main' ) as $field => $value ) {
			$other           = clone $repo;
			$other->branch   = 'main';
			$other->$field   = $value;
			$expected       = clone $other;
			$result         = apply_filters( 'gu_config_pre_process', array( 'entry' => $other ) );
			$this->assertEquals( $expected, $result['entry'] );
		}
	}

	public function test_early_slug_hook_is_not_used_for_branch_migration() {
		$early = array( 'git' => 'github', 'slug' => 'bono_arm_api', 'file' => 'bono-arm-api/bono-arm-api.php' );
		$this->assertSame( $early, apply_filters( 'gu_fix_repo_slug', $early ) );
		$this->assertSame( array(), apply_filters( 'gu_config_pre_process', array() ) );
		$this->assertNull( apply_filters( 'gu_config_pre_process', null ) );
	}
}
