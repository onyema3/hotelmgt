<?php
/**
 * Uninstall handler for GuestHouse Manager.
 *
 * WordPress invokes this file when the user clicks Delete on the
 * Plugins page. Until this file existed, deletion left behind every
 * table the plugin had ever created, every wp_options row, and every
 * custom capability — so reinstalling on top of an old uninstall
 * left the database in an inconsistent state and the role table
 * polluted with caps the new install might no longer use.
 *
 * Behavior is opt-in. The destructive path runs ONLY if the operator
 * explicitly enabled the "Delete plugin data on uninstall" setting
 * (option ghm_delete_data_on_uninstall = 1) BEFORE clicking Delete.
 * The default is to leave data in place — losing booking history
 * and payment records to a misclick is not recoverable.
 */

// WP defines this constant when calling uninstall.php. Refusing here
// prevents the file from being executed by anything else (e.g. a
// direct HTTP request to wp-content/plugins/.../uninstall.php).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Opt-in gate. If the setting is absent or 0, do nothing — the
// operator deactivated and deleted, but wants to keep the data.
if ( (int) get_option( 'ghm_delete_data_on_uninstall', 0 ) !== 1 ) {
    return;
}

global $wpdb;

/* ── Drop all GHM tables ─────────────────────────────────────────
 *
 * Listed explicitly rather than discovered via SHOW TABLES LIKE
 * '{$prefix}ghm_%' so a future table named in a way that doesn't
 * match the prefix pattern (or a third-party plugin that happens to
 * use a `${prefix}ghm_` name) doesn't get caught. Update this list
 * whenever a new table is added in includes/class-ghm-install.php
 * or one of the module files.
 */
$tables = array(
    // Core tables (class-ghm-install.php)
    'ghm_rooms',
    'ghm_bookings',
    'ghm_customers',
    'ghm_payments',
    'ghm_staff',
    'ghm_activity_log',

    // Module tables (each module's create_table() in includes/modules/)
    'ghm_dynamic_pricing',
    'ghm_deposits',
    'ghm_housekeeping',
    'ghm_maintenance',
    'ghm_discounts',
    'ghm_waitlist',
    'ghm_reviews',
    'ghm_service_requests',
    'ghm_guest_portal_sessions',
);

foreach ( $tables as $t ) {
    $name = $wpdb->prefix . $t;
    // Identifiers can't be parameterised; the name is built from a
    // hardcoded constant and the WP-controlled prefix, so it's safe.
    $wpdb->query( "DROP TABLE IF EXISTS `$name`" );
}

/* ── Delete all GHM options ──────────────────────────────────────
 *
 * Pattern-match by prefix because individual modules add their own
 * options ad hoc (Paystack keys, Flutterwave keys, WhatsApp config,
 * etc) and listing every one is fragile.
 */
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ghm\\_%' ESCAPE '\\\\'" );

// Plus any transients we might have left behind (rate limits, caches).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ghm\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ghm\\_%'" );

/* ── Delete all GHM usermeta ─────────────────────────────────────
 *
 * Removes PIN hashes (v1 + v2), lockout state, and any per-user
 * preferences. The wp_user rows themselves are left alone — staff
 * may still need their WordPress accounts after the plugin is gone.
 */
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ghm\\_%' ESCAPE '\\\\'" );

/* ── Remove custom roles and capabilities ────────────────────────
 *
 * Roles ghm_manager and ghm_staff were created on activation. The
 * caps were also added to the administrator role; we strip them
 * from administrator and remove the custom roles outright.
 */
remove_role( 'ghm_manager' );
remove_role( 'ghm_staff' );

$admin = get_role( 'administrator' );
if ( $admin ) {
    $caps = array(
        'ghm_manage_rooms',
        'ghm_manage_bookings',
        'ghm_manage_customers',
        'ghm_manage_payments',
        'ghm_view_reports',
        'ghm_manage_staff',
    );
    foreach ( $caps as $cap ) {
        $admin->remove_cap( $cap );
    }
}

/* ── Clear scheduled crons ───────────────────────────────────────
 *
 * Already cleared on deactivation, but the user could have
 * reactivated and never deactivated again before deleting. Clearing
 * here is idempotent and cheap.
 */
$crons = array( 'ghm_hourly_cron', 'ghm_daily_cron', 'ghm_whatsapp_reminders' );
foreach ( $crons as $hook ) {
    wp_clear_scheduled_hook( $hook );
}
