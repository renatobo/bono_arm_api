<?php
/** Test doubles; optionally exercise licensed native helpers without redistributing them. */
function arm_get_user_meta( $id, $key = '', $single = true ) { return get_user_meta( $id, $key, $single ); }
function arm_update_user_meta( $id, $key, $value ) { return update_user_meta( $id, $key, $value ); }
function arm_maybe_unserialize( $value ) {
	return is_serialized( $value ) ? unserialize( $value, array( 'allowed_classes' => false ) ) : $value;
}

class Bono_Renew_Test_Plan {
	public $ID;
	public $options;
	public $type;
	public $status;
	public $is_delete;
	public $payment_type;
	public $amount;
	public function __construct( $id = 0 ) {}
	public function init( $data ) {
		$this->ID = $data->arm_subscription_plan_id;
		$this->type = $data->arm_subscription_plan_type;
		$this->status = $data->arm_subscription_plan_status;
		$this->is_delete = $data->arm_subscription_plan_is_delete ?? 0;
		$this->options = arm_maybe_unserialize( $data->arm_subscription_plan_options );
		$this->payment_type = $this->options['payment_type'];
		$this->amount = $data->arm_subscription_plan_amount;
	}
	public function exists() { return ! empty( $this->ID ); }
	public function is_active() { return 1 === (int) $this->status && ! $this->is_delete; }
	public function is_free() { return 'free' === $this->type; }
	public function is_lifetime() { return 'lifetime' === ( $this->options['access_type'] ?? '' ); }
	public function is_recurring() { return ! $this->is_lifetime() && 'subscription' === $this->payment_type; }
	public function has_trial_period() { return false; }
	public function prepare_recurring_data( $selected = 0 ) {
		$r = $this->options['recurring'];
		return array( 'amount' => $this->amount, 'period' => $r['type'], 'interval' => $r['years'] ?? $r['months'] ?? $r['days'], 'rec_time' => $r['time'] );
	}
}
class Bono_Renew_Test_Members {
	public function arm_get_next_due_date( $id, $plan, $trial = false, $cycle = 0 ) {
		$data = arm_get_user_meta( $id, 'arm_user_plan_' . $plan, true );
		return (string) strtotime( '+' . $data['arm_completed_recurring'] . ' years', $data['arm_start_plan'] );
	}
}
class Bono_Renew_Test_Transaction {
	public function arm_add_transaction( $data ) {
		global $wpdb, $ARMember;
		$data['arm_invoice_id'] = 1 + (int) get_option( 'arm_last_invoice_id', 0 );
		do_action( 'arm_before_add_transaction', $data );
		if ( ! $wpdb->insert( $ARMember->tbl_arm_payment_log, $data ) ) { return false; }
		$id = $wpdb->insert_id;
		update_option( 'arm_last_invoice_id', $data['arm_invoice_id'] );
		do_action( 'arm_after_add_transaction', $data + array( 'arm_log_id' => $id ) );
		return $id;
	}
}

/** Extract one trusted local method, keeping strings/comments intact. Never used in production. */
function bono_renew_native_method( $file, $name ) {
	$source = file_get_contents( $file );
	$offset = strpos( $source, 'function ' . $name . '(' );
	if ( false === $offset ) { throw new RuntimeException( 'Missing native helper: ' . $name ); }
	$tokens = token_get_all( '<?php ' . substr( $source, $offset ) );
	$body = ''; $depth = 0; $started = false;
	foreach ( array_slice( $tokens, 1 ) as $token ) {
		$body .= is_array( $token ) ? $token[1] : $token;
		if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) { ++$depth; $started = true; }
		if ( '}' === $token && 0 === --$depth && $started ) { return $body; }
	}
	throw new RuntimeException( 'Unclosed native helper: ' . $name );
}
$bono_native_source = getenv( 'BONO_ARMEMBER_SOURCE' );
if ( $bono_native_source ) {
	eval( str_replace( 'function arm_set_member_status(', 'function bono_renew_native_activation(', bono_renew_native_method( $bono_native_source . '/core/classes/class.arm_members.php', 'arm_set_member_status' ) ) );
	// The only eval input is source explicitly supplied by the local test operator.
	eval( 'class ARM_Plan extends Bono_Renew_Test_Plan {' . bono_renew_native_method( $bono_native_source . '/core/classes/class.arm_subscription_plans.php', 'prepare_recurring_data' ) . '}' );
	eval( 'class Bono_Renew_Members extends Bono_Renew_Test_Members {' . bono_renew_native_method( $bono_native_source . '/core/classes/class.arm_members.php', 'arm_get_next_due_date' ) . '}' );
	eval( 'class Bono_Renew_Transaction extends Bono_Renew_Test_Transaction {' . bono_renew_native_method( $bono_native_source . '/core/classes/class.arm_transaction.php', 'arm_add_transaction' ) . '}' );
} else {
	class ARM_Plan extends Bono_Renew_Test_Plan {}
	class Bono_Renew_Members extends Bono_Renew_Test_Members {}
	class Bono_Renew_Transaction extends Bono_Renew_Test_Transaction {}
}

class ARM_manage_communication {
	public function test_delivery_filter( $value ) { return apply_filters( 'pre_wp_mail', $value, array() ); }
}
