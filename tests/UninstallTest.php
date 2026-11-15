<?php
/** Run uninstall in isolated processes: the suite itself loads the plugin constant. */
final class UninstallTest extends PHPUnit\Framework\TestCase {
	/** @dataProvider scenarios */
	public function test_uninstall_preserves_journal_and_active_siblings( $scenario ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/uninstall-runner.php' ) . ' ' . escapeshellarg( $scenario );
		exec( $command, $output, $code );
		$this->assertSame( 0, $code );
		$data = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $data );
		if ( in_array( $scenario, array( 'sibling', 'constant', 'network_sibling' ), true ) ) {
			$this->assertSame( array(), $data['deleted'] );
			$this->assertSame( array(), $data['removed'] );
			return;
		}
		$this->assertContains( 'bono_arm_api_enable_member_renewals', $data['deleted'] );
		$this->assertContains( 'bono_arm_api_enable_badges', $data['deleted'] );
		$this->assertNotContains( 'bono_arm_api_renewals_schema', $data['deleted'] );
		$this->assertContains( 'bono_arm_api_renew_members', $data['removed'] );
		$this->assertContains( 'bono_arm_api_manage_badges', $data['removed'] );
		$this->assertSame( 'multisite' === $scenario ? array( 1, 2 ) : array(), $data['sites'] );
	}
	public function scenarios() { return array_map( static function ( $s ) { return array( $s ); }, array( 'single', 'sibling', 'constant', 'network_sibling', 'multisite' ) ); }
}
