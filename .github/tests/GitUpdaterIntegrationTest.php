<?php

/** Verify the production migration through actual upstream Git Updater methods. */
class GitUpdaterIntegrationTest extends WP_UnitTestCase {
	public function test_remote_metadata_and_update_generation_share_the_migrated_branch() {
		$directory = getenv( 'BONO_GIT_UPDATER_DIR' );
		$this->assertNotEmpty( $directory, 'This integration test requires a pinned Git Updater checkout.' );
		// Load only the actual upstream classes exercised here, avoiding its dev autoloader.
		require_once $directory . '/src/Git_Updater/Traits/GU_Trait.php';
		require_once $directory . '/src/Git_Updater/Plugin.php';

		foreach ( array( 'get_remote_plugin_meta', 'update_site_transient' ) as $method ) {
			$repo = (object) array(
				'git'            => 'github',
				'slug'           => 'bono_arm_api',
				'file'           => 'bono-arm-api/bono-arm-api.php',
				'owner'          => 'renatobo',
				'type'           => 'plugin',
				'primary_branch' => 'stable',
				'branch'         => 'main',
			);
			$reflection = new ReflectionClass( 'Fragen\\Git_Updater\\Plugin' );
			$updater    = $reflection->newInstanceWithoutConstructor();
			$config     = $reflection->getProperty( 'config' );
			$config->setValue( $updater, array( 'bono_arm_api' => $repo ) );
			$observed = false;
			$capture  = static function ( $repos ) use ( &$observed ) {
				$observed = $repos;
				// Stop after the real upstream integration point, before network I/O.
				throw new RuntimeException( 'upstream-hook-observed' );
			};
			add_filter( 'gu_config_pre_process', $capture, 11 );
			try {
				if ( 'update_site_transient' === $method ) {
					$updater->update_site_transient( new stdClass() );
				} else {
					$updater->get_remote_plugin_meta();
				}
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'upstream-hook-observed', $exception->getMessage() );
			} finally {
				remove_filter( 'gu_config_pre_process', $capture, 11 );
			}
			$this->assertIsArray( $observed, $method . ' must call the supported configuration hook.' );
			$this->assertSame( 'stable', $observed['bono_arm_api']->branch );
			$this->assertSame( $repo, $updater->get_plugin_configs()['bono_arm_api'] );
			$this->assertSame( 'stable', $repo->branch, 'The shared upstream configuration must also change.' );
		}
	}
}
