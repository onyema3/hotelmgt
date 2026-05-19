<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GHM_Payments {

    public static function get_payments( $args = array() ) {
        global $wpdb;
        $defaults = array( 'booking_id' => 0, 'limit' => 20, 'offset' => 0 );
        $args     = wp_parse_args( $args, $defaults );
        $where    = '1=1';
        if ( $args['booking_id'] ) {
            $where .= $wpdb->prepare( ' AND p.booking_id = %d', $args['booking_id'] );
        }
        $limit = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );

        return $wpdb->get_results(
            "SELECT p.*, b.booking_ref,
             CONCAT(c.first_name,' ',c.last_name) AS customer_name
             FROM {$wpdb->prefix}ghm_payments p
             LEFT JOIN {$wpdb->prefix}ghm_bookings b  ON b.id = p.booking_id
             LEFT JOIN {$wpdb->prefix}ghm_customers c ON c.id = p.customer_id
             WHERE $where ORDER BY p.created_at DESC $limit"
        );
    }

    /**
     * Record a payment against a booking.
     *
     * After saving the payment:
     *  - Updates paid_amount and payment_status on the booking.
     *  - Calls GHM_Bookings::maybe_confirm_on_payment() which upgrades
     *    status from 'booked' → 'confirmed' when fully paid.
     */
    public static function record_payment( $data ) {
        global $wpdb;

        $booking = GHM_Bookings::get_booking( absint( $data['booking_id'] ?? 0 ) );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', 'Booking not found.' );
        }

        $amount = (float)( $data['amount'] ?? 0 );
        if ( $amount <= 0 ) {
            return new WP_Error( 'invalid_amount', 'Payment amount must be greater than zero.' );
        }

        // --- 1. Insert payment record ---
        $fields = array(
            'booking_id'      => $booking->id,
            'customer_id'     => $booking->customer_id,
            'amount'          => $amount,
            'currency'        => sanitize_text_field( $data['currency']       ?? get_option( 'ghm_currency', 'NGN' ) ),
            'method'          => sanitize_text_field( $data['method']         ?? 'cash' ),
            'status'          => 'completed',
            'transaction_id'  => sanitize_text_field( $data['transaction_id'] ?? '' ),
            'notes'           => sanitize_textarea_field( $data['notes']      ?? '' ),
            'created_by'      => get_current_user_id(),
        );

        $wpdb->insert( $wpdb->prefix . 'ghm_payments', $fields );
        $payment_id = $wpdb->insert_id;

        // --- 2. Recalculate paid_amount on booking ---
        $new_paid   = round( (float) $booking->paid_amount + $amount, 2 );
        $total      = (float) $booking->total_amount;
        $pay_status = $new_paid >= $total && $total > 0 ? 'paid' : 'partial';

        $wpdb->update(
            $wpdb->prefix . 'ghm_bookings',
            array(
                'paid_amount'    => $new_paid,
                'payment_status' => $pay_status,
            ),
            array( 'id' => $booking->id )
        );

        // --- 3. Auto-confirm booking when fully paid ---
        GHM_Bookings::maybe_confirm_on_payment( $booking->id );

        // --- 4. Update customer lifetime spend ---
        GHM_Customers::update_customer_spent( $booking->customer_id );

        do_action( 'ghm_payment_recorded', $payment_id, $fields );

        return $payment_id;
    }

    /**
     * Refund a payment (fully or partially).
     *
     * Records a new row with status='refunded' and the refund amount,
     * then decrements paid_amount on the booking. Does NOT call any
     * gateway API — this is a local ledger entry only. The operator is
     * responsible for processing the actual refund in the payment
     * gateway's dashboard (Paystack, Flutterwave, bank, etc.).
     *
     * Design decisions:
     *   - Per-payment, not per-booking. Operator picks which payment to refund.
     *   - Partial: refund_amount can be less than the original payment.
     *   - Capped: total refunds against a payment can't exceed original amount.
     *   - Reason required: stored in notes for audit trail.
     *   - Permission: manage_options only (checked in AJAX handler).
     *   - Booking status untouched — operator cancels separately if needed.
     *   - payment_status on booking recalculated to 'refunded' (fully refunded),
     *     'partial' (some money left), or 'paid' (tiny partial refund, still covered).
     *
     * @param array $data {
     *     @type int    $payment_id   ID of the original payment row to refund against.
     *     @type float  $amount       Refund amount (must be > 0 and <= refundable balance).
     *     @type string $reason       Required reason text.
     * }
     * @return int|WP_Error  New refund row ID on success, WP_Error on failure.
     */
    public static function refund_payment( $data ) {
        global $wpdb;

        $payment_id = absint( $data['payment_id'] ?? 0 );
        if ( ! $payment_id ) {
            return new WP_Error( 'missing_payment', 'Payment ID is required.' );
        }

        // Load the original payment
        $original = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ghm_payments WHERE id = %d",
            $payment_id
        ) );
        if ( ! $original ) {
            return new WP_Error( 'not_found', 'Original payment not found.' );
        }
        if ( $original->status !== 'completed' ) {
            return new WP_Error( 'not_refundable', 'Only completed payments can be refunded.' );
        }

        // Calculate how much has already been refunded against this payment
        $already_refunded = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ghm_payments
             WHERE status = 'refunded' AND transaction_id = %s",
            'REFUND-' . $payment_id
        ) );

        $refundable = round( (float) $original->amount - $already_refunded, 2 );
        if ( $refundable <= 0 ) {
            return new WP_Error( 'already_refunded', 'This payment has already been fully refunded.' );
        }

        // Validate refund amount
        $refund_amount = round( (float) ( $data['amount'] ?? 0 ), 2 );
        if ( $refund_amount <= 0 ) {
            return new WP_Error( 'invalid_amount', 'Refund amount must be greater than zero.' );
        }
        if ( $refund_amount > $refundable ) {
            return new WP_Error(
                'exceeds_refundable',
                sprintf( 'Maximum refundable amount is %s.', number_format( $refundable, 2 ) )
            );
        }

        // Validate reason
        $reason = sanitize_textarea_field( $data['reason'] ?? '' );
        if ( trim( $reason ) === '' ) {
            return new WP_Error( 'reason_required', 'A reason is required for all refunds.' );
        }

        // --- 1. Insert refund row ---
        // transaction_id links back to the original payment for audit/cap purposes.
        $fields = array(
            'booking_id'     => $original->booking_id,
            'customer_id'    => $original->customer_id,
            'amount'         => $refund_amount,
            'currency'       => $original->currency,
            'method'         => $original->method,
            'status'         => 'refunded',
            'transaction_id' => 'REFUND-' . $payment_id,
            'notes'          => 'Refund of payment #' . $payment_id . ': ' . $reason,
            'created_by'     => get_current_user_id(),
        );

        $wpdb->insert( $wpdb->prefix . 'ghm_payments', $fields );
        $refund_id = $wpdb->insert_id;

        if ( ! $refund_id ) {
            return new WP_Error( 'db_error', $wpdb->last_error ?: 'Could not record refund.' );
        }

        // --- 2. Recalculate booking paid_amount ---
        $booking = GHM_Bookings::get_booking( $original->booking_id );
        if ( $booking ) {
            $new_paid = max( 0, round( (float) $booking->paid_amount - $refund_amount, 2 ) );
            $total    = (float) $booking->total_amount;

            // Determine new payment_status
            if ( $new_paid <= 0 ) {
                $pay_status = 'refunded';
            } elseif ( $new_paid >= $total && $total > 0 ) {
                $pay_status = 'paid';
            } else {
                $pay_status = 'partial';
            }

            $wpdb->update(
                $wpdb->prefix . 'ghm_bookings',
                array(
                    'paid_amount'    => $new_paid,
                    'payment_status' => $pay_status,
                ),
                array( 'id' => $booking->id )
            );

            // Update customer lifetime spend
            GHM_Customers::update_customer_spent( $booking->customer_id );
        }

        // --- 3. Activity log ---
        $wpdb->insert( $wpdb->prefix . 'ghm_activity_log', array(
            'user_id'     => get_current_user_id(),
            'action'      => 'refunded_payment',
            'object_type' => 'payment',
            'object_id'   => $payment_id,
            'details'     => json_encode( array(
                'refund_id'     => $refund_id,
                'amount'        => $refund_amount,
                'reason'        => $reason,
                'booking_id'    => $original->booking_id,
            ) ),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
        ) );

        do_action( 'ghm_payment_refunded', $refund_id, $payment_id, $refund_amount );

        return $refund_id;
    }

    /**
     * Get the total amount already refunded against a specific payment.
     */
    public static function get_refunded_amount( $payment_id ) {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ghm_payments
             WHERE status = 'refunded' AND transaction_id = %s",
            'REFUND-' . absint( $payment_id )
        ) );
    }

    public static function get_total_revenue( $from = '', $to = '' ) {
        global $wpdb;
        $where = "status = 'completed'";
        if ( $from ) $where .= $wpdb->prepare( ' AND created_at >= %s', $from );
        if ( $to )   $where .= $wpdb->prepare( ' AND created_at <= %s', $to . ' 23:59:59' );
        return (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$wpdb->prefix}ghm_payments WHERE $where" );
    }

    public static function get_payment_methods() {
        return array(
            'cash'          => __( 'Cash',                'guesthouse-manager' ),
            'card'          => __( 'Credit/Debit Card',   'guesthouse-manager' ),
            'bank_transfer' => __( 'Bank Transfer',       'guesthouse-manager' ),
            'mobile_money'  => __( 'Mobile Money',        'guesthouse-manager' ),
            'online'        => __( 'Online (Paystack)',   'guesthouse-manager' ),
            'other'         => __( 'Other',               'guesthouse-manager' ),
        );
    }
}
