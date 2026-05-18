<?php
/**
 * Plugin Name:  GuestHouse Manager
 * Plugin URI:   https://example.com/guesthouse-manager
 * Description:  Complete guest house PMS — rooms, workspaces, bookings, housekeeping, maintenance, CRM, Paystack, Flutterwave, WhatsApp, REST API, iCal, dynamic pricing, deposits, guest portal, forecasting, discount codes, waiting list, reviews, and more.
 * Version:      3.2.0
 * Author:       GuestHouse Manager
 * License:      GPL-2.0+
 * Text Domain:  guesthouse-manager
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'GHM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GHM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'GHM_PLUGIN_FILE', __FILE__ );

/**
 * Single source of truth for the plugin version: the "Version:" line
 * in this file's header. Previously GHM_VERSION was a separate define
 * that drifted out of sync with the header — the WordPress Plugins
 * screen reads the header, asset cache-busts read the constant. The
 * audit caught these at 3.0.0 vs 3.2.2 in a prior state of main;
 * current state happens to match at 3.2.0, but nothing prevents the
 * next bump from desyncing again.
 *
 * get_file_data() reads only this file's leading comment block, so
 * the cost is one small file-header parse per request — same hit WP
 * itself takes on the Plugins screen.
 */
$ghm_plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' );
define( 'GHM_VERSION', ! empty( $ghm_plugin_data['Version'] ) ? $ghm_plugin_data['Version'] : '0.0.0' );
unset( $ghm_plugin_data );

/* ── Core ────────────────────────────────────────────────────── */
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-install.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-post-types.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-rooms.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-workspaces.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-bookings.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-customers.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-staff.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-payments.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-reports.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-ajax.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-shortcodes.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-emails.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-paystack.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-staff-access.php';
require_once GHM_PLUGIN_DIR . 'includes/class-ghm-login-page.php';

/* ── Feature Modules ─────────────────────────────────────────── */
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-housekeeping.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-maintenance.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-discounts.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-waitlist.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-channels.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-invoice.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-whatsapp.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-rest-api.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-integrations.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-guest-portal.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-dynamic-pricing.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-deposits.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-flutterwave.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-scheduler.php';
require_once GHM_PLUGIN_DIR . 'includes/modules/class-ghm-utilities.php';

/* ── Admin ───────────────────────────────────────────────────── */
require_once GHM_PLUGIN_DIR . 'admin/class-ghm-admin.php';

/* ── Lifecycle hooks ─────────────────────────────────────────── */
register_activation_hook( __FILE__, array( 'GHM_Install', 'activate' ) );

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'ghm_hourly_cron' );
    wp_clear_scheduled_hook( 'ghm_daily_cron' );
    wp_clear_scheduled_hook( 'ghm_whatsapp_reminders' );
    flush_rewrite_rules();
} );

/**
 * One-shot rewrite-rule flush.
 *
 * register_activation_hook fires before REST routes and CPTs are
 * registered, so the flush at activation time can't capture those
 * routes — pretty permalinks for /wp-json/ghm/v1/* and the booking-
 * confirmation rewrite stayed broken until the operator manually
 * visited Settings → Permalinks. We set a transient at activation
 * (and detect version changes in the option), then flush on the
 * first admin load when all routes have already been registered.
 *
 * The transient is one-shot — it deletes itself the moment the
 * flush runs, so the cost is exactly one extra option write per
 * upgrade. Hooked at priority 99 so module init has already run.
 */
register_activation_hook( __FILE__, function() {
    set_transient( 'ghm_flush_rewrite', 1, HOUR_IN_SECONDS );
} );
add_action( 'admin_init', function() {
    // Trigger on activation (transient) AND on version upgrades.
    // Modules added in a release may register new rewrite rules,
    // and operators rarely visit Permalinks after an update.
    $stored_version = get_option( 'ghm_installed_version', '' );
    if ( get_transient( 'ghm_flush_rewrite' ) || $stored_version !== GHM_VERSION ) {
        delete_transient( 'ghm_flush_rewrite' );
        update_option( 'ghm_installed_version', GHM_VERSION );
        flush_rewrite_rules();
    }
}, 99 );

/* ── Bootstrap ───────────────────────────────────────────────── */
function ghm_init() {
    GHM_Post_Types::init();
    GHM_Admin::init();
    GHM_Ajax::init();
    GHM_Shortcodes::init();
}
add_action( 'plugins_loaded', 'ghm_init' );
