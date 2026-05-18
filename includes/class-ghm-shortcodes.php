<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GHM_Shortcodes {

    public static function init() {
        add_shortcode( 'ghm_booking_form',  array( __CLASS__, 'booking_form' ) );
        add_shortcode( 'ghm_rooms_list',    array( __CLASS__, 'rooms_list' ) );
        add_shortcode( 'ghm_booking_confirmation', array( __CLASS__, 'booking_confirmation' ) );
        add_shortcode( 'ghm_waitlist_form',         array( __CLASS__, 'waitlist_form' ) );
        // Guest portal is registered by GHM_Guest_Portal::init() via plugins_loaded
        add_action( 'wp_enqueue_scripts',   array( __CLASS__, 'enqueue_scripts' ) );
    }

    public static function enqueue_scripts() {
        wp_enqueue_style( 'ghm-public', GHM_PLUGIN_URL . 'public/css/ghm-public.css', array(), GHM_VERSION );

        // Paystack inline JS must load before our public script
        $deps = array( 'jquery' );
        if ( class_exists( 'GHM_Paystack' ) && GHM_Paystack::is_enabled() ) {
            wp_enqueue_script( 'paystack-inline', 'https://js.paystack.co/v2/inline.js', array(), null, true );
            $deps[] = 'paystack-inline';
        }

        wp_enqueue_script( 'ghm-public', GHM_PLUGIN_URL . 'public/js/ghm-public.js', $deps, GHM_VERSION, true );

        wp_localize_script( 'ghm-public', 'ghmPublic', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ghm_public_nonce' ),
        ) );

        // Paystack config
        if ( class_exists( 'GHM_Paystack' ) && GHM_Paystack::is_enabled() ) {
            wp_localize_script( 'ghm-public', 'ghmPaystack', array(
                'enabled'    => true,
                'public_key' => GHM_Paystack::public_key(),
                'currency'   => strtoupper( get_option( 'ghm_currency', 'NGN' ) ),
            ) );
        }

        // Flutterwave config
        if ( class_exists( 'GHM_Flutterwave' ) && GHM_Flutterwave::is_enabled() ) {
            wp_enqueue_script( 'flutterwave-inline', 'https://checkout.flutterwave.com/v3.js', array(), null, true );
            wp_localize_script( 'ghm-public', 'ghmFlutterwave', array(
                'enabled'    => true,
                'public_key' => GHM_Flutterwave::public_key(),
                'currency'   => strtoupper( get_option( 'ghm_currency', 'NGN' ) ),
            ) );
        }
    }

    public static function booking_form( $atts ) {
        $atts = shortcode_atts( array(
            'type'    => 'room',
            'room_id' => 0,
        ), $atts );

        $rooms = $atts['room_id']
            ? array( GHM_Rooms::get_room( $atts['room_id'] ) )
            : GHM_Rooms::get_rooms( array( 'status' => 'available', 'type' => $atts['type'] !== 'workspace' ? '' : 'workspace' ) );

        ob_start();
        include GHM_PLUGIN_DIR . 'templates/booking-form.php';
        return ob_get_clean();
    }

    public static function rooms_list( $atts ) {
        $atts  = shortcode_atts( array( 'type' => '' ), $atts );
        $rooms = GHM_Rooms::get_rooms( array( 'status' => 'available', 'type' => $atts['type'] ) );
        ob_start();
        include GHM_PLUGIN_DIR . 'templates/rooms-list.php';
        return ob_get_clean();
    }


    public static function waitlist_form( $atts ) {
        $atts    = shortcode_atts( array( 'room_id' => 0 ), $atts );
        $room_id = absint( $atts['room_id'] );
        $room    = $room_id ? GHM_Rooms::get_room( $room_id ) : null;
        $success = false;
        if ( isset($_POST['ghm_waitlist_submit']) && wp_verify_nonce($_POST['ghm_waitlist_nonce'],'ghm_waitlist') ) {
            $rid = !empty($_POST['room_id']) ? absint($_POST['room_id']) : $room_id;
            $id  = GHM_Waitlist::add( array(
                'room_id'    => $rid,
                'first_name' => sanitize_text_field($_POST['first_name']??''),
                'last_name'  => sanitize_text_field($_POST['last_name']??''),
                'email'      => sanitize_email($_POST['email']??''),
                'phone'      => sanitize_text_field($_POST['phone']??''),
                'check_in'   => sanitize_text_field($_POST['check_in']??''),
                'check_out'  => sanitize_text_field($_POST['check_out']??''),
                'adults'     => absint($_POST['adults']??1),
            ) );
            $success = $id > 0;
        }
        ob_start();
        include GHM_PLUGIN_DIR . 'templates/waitlist-form.php';
        return ob_get_clean();
    }

    public static function booking_confirmation( $atts ) {
        $ref     = get_query_var( 'ghm_booking_ref' ) ?: ( $_GET['ref'] ?? '' );
        $booking = $ref ? GHM_Bookings::get_booking_by_ref( sanitize_text_field( $ref ) ) : null;
        ob_start();
        include GHM_PLUGIN_DIR . 'templates/booking-confirmation.php';
        return ob_get_clean();
    }
}
