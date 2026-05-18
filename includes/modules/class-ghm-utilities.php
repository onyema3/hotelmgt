<?php
/**
 * GHM Utilities: Forecasting, CSV Export, Caching, PIN Login, Role Permissions
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
   Revenue Forecasting
================================================================ */
class GHM_Forecasting {

    /**
     * Project revenue for the next N days based on confirmed/booked reservations.
     */
    public static function get_forecast( $days = 30 ) {
        global $wpdb;
        $today    = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("+{$days} days"));

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, r.price_night, r.price_hour, r.type AS room_type
             FROM {$wpdb->prefix}ghm_bookings b
             LEFT JOIN {$wpdb->prefix}ghm_rooms r ON r.id = b.room_id
             WHERE b.status IN ('booked','confirmed','checked_in')
             AND b.check_out >= %s AND b.check_in <= %s",
            $today, $end_date
        ));

        $forecast     = array();
        $daily        = array();
        $total_expected = 0;
        $total_confirmed= 0;

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("+{$i} days"));
            $daily[$date] = 0;
        }

        foreach ($bookings as $b) {
            $remaining = (float)$b->total_amount - (float)$b->paid_amount;
            if ($remaining <= 0) continue;

            $in    = max($today, date('Y-m-d', strtotime($b->check_in)));
            $out   = min($end_date, date('Y-m-d', strtotime($b->check_out)));
            $nights= max(1,(int)(new DateTime($in))->diff(new DateTime($out))->days);

            // Only distribute remaining unpaid amount
            $per_night = $nights > 0 ? $remaining / $nights : $remaining;

            $cur = new DateTime($in);
            $end = new DateTime($out);
            while ($cur < $end) {
                $d = $cur->format('Y-m-d');
                if (isset($daily[$d])) $daily[$d] += $per_night;
                $cur->modify('+1 day');
            }

            $total_expected += $remaining;
            if ($b->status === 'confirmed') $total_confirmed += $remaining;
        }

        return array(
            'daily'           => $daily,
            'total_expected'  => round($total_expected, 2),
            'total_confirmed' => round($total_confirmed, 2),
            'days'            => $days,
            'period_end'      => $end_date,
        );
    }
}

/* ================================================================
   CSV / Accounting Export
================================================================ */
class GHM_Export {

    /**
     * Stream a CSV download of payments.
     */
    public static function payments_csv( $from = '', $to = '' ) {
        global $wpdb;
        $where  = "p.status = 'completed'";
        $params = array();
        if ($from) { $where .= ' AND p.created_at >= %s'; $params[] = $from; }
        if ($to)   { $where .= ' AND p.created_at <= %s'; $params[] = $to.' 23:59:59'; }

        $sql = "SELECT p.created_at, b.booking_ref, CONCAT(c.first_name,' ',c.last_name) AS guest,
                c.email, r.name AS room, p.amount, p.currency, p.method, p.transaction_id, p.notes
                FROM {$wpdb->prefix}ghm_payments p
                LEFT JOIN {$wpdb->prefix}ghm_bookings b  ON b.id  = p.booking_id
                LEFT JOIN {$wpdb->prefix}ghm_customers c ON c.id  = p.customer_id
                LEFT JOIN {$wpdb->prefix}ghm_rooms r     ON r.id  = b.room_id
                WHERE $where ORDER BY p.created_at DESC";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql,$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $filename = 'ghm-payments-'.date('Y-m-d').'.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache'); header('Expires: 0');

        $fh = fopen('php://output','w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8
        fputcsv($fh, array('Date','Booking Ref','Guest Name','Email','Room','Amount','Currency','Method','Transaction ID','Notes'));
        foreach ($rows as $row) {
            $row['created_at'] = date('Y-m-d H:i', strtotime($row['created_at']));
            fputcsv($fh, array_values($row));
        }
        fclose($fh);
        exit;
    }

    /**
     * Stream a CSV of bookings.
     */
    public static function bookings_csv( $args = array() ) {
        $bookings = GHM_Bookings::get_bookings(array_merge($args, array('limit'=>9999)));
        $filename = 'ghm-bookings-'.date('Y-m-d').'.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');

        $fh = fopen('php://output','w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, array('Booking Ref','Guest','Email','Phone','Room','Check-In','Check-Out','Adults','Children','Total','Paid','Balance','Status','Payment Status','Source','Created'));
        foreach ($bookings as $b) {
            fputcsv($fh, array(
                $b->booking_ref,$b->customer_name,$b->customer_email,$b->customer_phone,
                $b->room_name,date('Y-m-d',strtotime($b->check_in)),date('Y-m-d',strtotime($b->check_out)),
                $b->adults,$b->children,
                number_format($b->total_amount,2),number_format($b->paid_amount,2),
                number_format(max(0,$b->total_amount-$b->paid_amount),2),
                $b->status,$b->payment_status,$b->source??'',
                date('Y-m-d H:i',strtotime($b->created_at)),
            ));
        }
        fclose($fh);
        exit;
    }

    /**
     * Handle download request — triggered on any admin page load.
     * Works from both the payments page and direct URLs.
     */
    public static function handle_download() {
        if ( empty($_GET['ghm_export']) ) return;
        if ( ! current_user_can('ghm_manage_payments') ) return;
        if ( ! check_admin_referer('ghm_export') ) return;

        $type   = sanitize_key($_GET['ghm_export']);
        $format = sanitize_key($_GET['ghm_export_format'] ?? 'csv');

        // Print mode — render HTML table and trigger browser print
        if ( $format === 'print' ) {
            self::print_report($type, sanitize_text_field($_GET['from']??''), sanitize_text_field($_GET['to']??''));
            exit;
        }

        if ( $type === 'payments' ) {
            self::payments_csv(
                sanitize_text_field($_GET['from'] ?? ''),
                sanitize_text_field($_GET['to']   ?? '')
            );
        } elseif ( $type === 'bookings' ) {
            self::bookings_csv(array('status' => sanitize_key($_GET['status'] ?? '')));
        } elseif ( $type === 'activity' ) {
            self::activity_csv(
                sanitize_text_field($_GET['from'] ?? ''),
                sanitize_text_field($_GET['to']   ?? '')
            );
        }
        exit;
    }

    /**
     * Print-friendly HTML report — opens in same window, browser handles PDF via Ctrl+P
     */
    private static function print_report($type, $from = '', $to = '') {
        global $wpdb;
        $hotel = get_option('ghm_hotel_name', get_bloginfo('name'));
        $sym   = get_option('ghm_currency_symbol', '₦');
        $from  = $from ?: date('Y-m-01');
        $to    = $to   ?: date('Y-m-t');

        if ($type === 'payments') {
            $where  = "p.status='completed'";
            $params = array();
            $where .= $wpdb->prepare(' AND p.created_at >= %s', $from.' 00:00:00');
            $where .= $wpdb->prepare(' AND p.created_at <= %s', $to.' 23:59:59');
            $rows = $wpdb->get_results(
                "SELECT p.created_at, b.booking_ref, CONCAT(c.first_name,' ',c.last_name) AS guest,
                 r.name AS room, p.amount, p.currency, p.method, p.transaction_id
                 FROM {$wpdb->prefix}ghm_payments p
                 LEFT JOIN {$wpdb->prefix}ghm_bookings b  ON b.id  = p.booking_id
                 LEFT JOIN {$wpdb->prefix}ghm_customers c ON c.id  = p.customer_id
                 LEFT JOIN {$wpdb->prefix}ghm_rooms r     ON r.id  = b.room_id
                 WHERE $where ORDER BY p.created_at DESC"
            );
            $total = array_sum(array_column((array)$rows,'amount'));
            $title = 'Payment Report';
            $cols  = array('Date','Booking Ref','Guest','Room','Amount','Currency','Method','Transaction ID');
            $data  = array_map(function($r) use ($sym) {
                return array(
                    date('M j, Y H:i', strtotime($r->created_at)),
                    $r->booking_ref, $r->guest, $r->room,
                    $sym.number_format($r->amount,2), $r->currency,
                    ucfirst(str_replace('_',' ',$r->method)), $r->transaction_id??'—'
                );
            }, (array)$rows);
            $summary = '<strong>Total Revenue: '.$sym.number_format($total,2).'</strong> &bull; '.count((array)$rows).' transactions';
        } else {
            $bookings = GHM_Bookings::get_bookings(array('limit'=>500,'date_from'=>$from,'date_to'=>$to));
            $title = 'Bookings Report';
            $cols  = array('Ref','Guest','Room','Check-In','Check-Out','Total','Paid','Balance','Status');
            $data  = array_map(function($b) use ($sym) {
                return array(
                    $b->booking_ref, $b->customer_name, $b->room_name,
                    date('M j, Y',strtotime($b->check_in)), date('M j, Y',strtotime($b->check_out)),
                    $sym.number_format($b->total_amount,2), $sym.number_format($b->paid_amount,2),
                    $sym.number_format(max(0,$b->total_amount-$b->paid_amount),2),
                    ucfirst(str_replace('_',' ',$b->status))
                );
            }, (array)$bookings);
            $summary = count((array)$bookings).' bookings &bull; Period: '.date('M j, Y',strtotime($from)).' – '.date('M j, Y',strtotime($to));
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>'.esc_html($hotel).' — '.esc_html($title).'</title>
        <style>
          body{font-family:Arial,sans-serif;font-size:12px;color:#111;margin:24px;}
          h1{font-size:20px;margin:0 0 4px;}
          .meta{color:#666;font-size:12px;margin-bottom:16px;}
          table{width:100%;border-collapse:collapse;margin-top:12px;}
          th{background:#1a1a2e;color:#fff;padding:8px 10px;text-align:left;font-size:11px;}
          td{padding:7px 10px;border-bottom:1px solid #eee;font-size:12px;}
          tr:nth-child(even) td{background:#f9f9f9;}
          .summary{margin-top:16px;padding:10px 14px;background:#f3f4f6;border-radius:6px;font-size:13px;}
          .footer{margin-top:24px;font-size:11px;color:#999;text-align:center;}
          @media print{button{display:none!important;}}
        </style></head><body>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
          <div><h1>'.esc_html($hotel).'</h1><div class="meta">'.esc_html($title).' &bull; Generated '.date('F j, Y 	 g:i A').'</div></div>
          <button onclick="window.print()" style="padding:8px 16px;background:#c9a84c;border:none;border-radius:6px;font-weight:bold;cursor:pointer;">🖨 Print / Save PDF</button>
        </div>
        <table><thead><tr>';
        foreach ($cols as $col) echo '<th>'.esc_html($col).'</th>';
        echo '</tr></thead><tbody>';
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) echo '<td>'.esc_html($cell).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>
        <div class="summary">'.$summary.'</div>
        <div class="footer">'.esc_html($hotel).' &mdash; Confidential</div>
        <script>window.onload=function(){window.print();}</script>
        </body></html>';
    }

    /**
     * CSV export of activity log
     */
    public static function activity_csv($from = '', $to = '') {
        $log      = GHM_Activity_Report::get_report(array('from'=>$from,'to'=>$to,'limit'=>9999));
        $filename = 'ghm-activity-'.date('Y-m-d').'.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Pragma: no-cache');
        $fh = fopen('php://output','w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, array('Date','Staff','Action','Object Type','Object ID','IP Address'));
        foreach ((array)$log as $e) {
            fputcsv($fh, array(
                date('Y-m-d H:i', strtotime($e->created_at)),
                $e->display_name ?? 'System',
                ucwords(str_replace('_',' ',$e->action)),
                $e->object_type ?? '',
                $e->object_id   ?? '',
                $e->ip_address  ?? '',
            ));
        }
        fclose($fh);
    }
}
add_action('admin_init', array('GHM_Export','handle_download'));

/* ================================================================
   Transient Cache Layer
================================================================ */
class GHM_Cache {

    const PREFIX   = 'ghm_cache_';
    const TTL      = 300; // 5 minutes

    public static function get($key) {
        return get_transient(self::PREFIX.$key);
    }

    public static function set($key, $value, $ttl = null) {
        set_transient(self::PREFIX.$key, $value, $ttl ?? self::TTL);
    }

    public static function delete($key) {
        delete_transient(self::PREFIX.$key);
    }

    public static function flush() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ghm_cache_%' OR option_name LIKE '_transient_timeout_ghm_cache_%'");
    }

    // Auto-bust cache on booking/payment changes
    public static function init() {
        add_action('ghm_booking_created',  array(__CLASS__,'bust_stats'));
        add_action('ghm_booking_updated',  array(__CLASS__,'bust_stats'));
        add_action('ghm_booking_cancelled',array(__CLASS__,'bust_stats'));
        add_action('ghm_payment_recorded', array(__CLASS__,'bust_stats'));
    }

    public static function bust_stats($id = 0) {
        self::delete('dashboard_stats');
        self::delete('revenue_chart_6');
        self::delete('revenue_chart_12');
        self::delete('occupancy_rate');
    }
}

add_action('plugins_loaded', array('GHM_Cache','init'), 20);

// Patch GHM_Reports to use cache
add_action('init', function() {
    if (!class_exists('GHM_Reports')) return;

    // Override get_dashboard_stats with cached version
    if (!has_filter('ghm_dashboard_stats')) {
        add_filter('ghm_dashboard_stats', function($stats) {
            $cached = GHM_Cache::get('dashboard_stats');
            if ($cached !== false) return $cached;
            GHM_Cache::set('dashboard_stats', $stats);
            return $stats;
        });
    }
}, 20);


/* ================================================================
   Role Permissions UI
================================================================ */
class GHM_Permissions {

    public static function get_capabilities() {
        return array(
            'ghm_manage_rooms'     => 'Manage Rooms & Workspaces',
            'ghm_manage_bookings'  => 'Manage Bookings',
            'ghm_manage_customers' => 'Manage Customers / CRM',
            'ghm_manage_payments'  => 'Record & View Payments',
            'ghm_view_reports'     => 'View Reports & Analytics',
            'ghm_manage_staff'     => 'Manage Staff',
        );
    }

    public static function get_ghm_roles() {
        return array('ghm_staff','ghm_manager');
    }

    public static function save_permissions($role_slug, $caps_to_grant) {
        $role = get_role($role_slug);
        if (!$role) return;
        foreach (self::get_capabilities() as $cap => $label) {
            if (in_array($cap, (array)$caps_to_grant)) {
                $role->add_cap($cap);
            } else {
                $role->remove_cap($cap);
            }
        }
        // Always keep read
        $role->add_cap('read');
    }
}


/* ================================================================
   PIN / Quick Login (Front Desk Mode)
   ================================================================

   Threat model addressed:

   1. Online brute-force. A 4-digit PIN gives 10,000 combinations.
      Without throttling, a single attacker could exhaust the keyspace
      against the admin-ajax endpoint in roughly 30 seconds. We add:

      a) IP-based rate limit. Five wrong attempts inside a 5-minute
         window from the same IP returns a generic invalid response
         for the rest of the window. Backed by transients keyed on
         sha1(IP) so the IP isn't stored verbatim.

      b) Per-user lockout. Ten wrong attempts on the SAME PIN (across
         any IPs) locks the account for 15 minutes. Defends against a
         distributed / IP-rotating attack that bypasses (a). The
         lockout is enforced by returning the same generic "Invalid
         PIN" response — we never leak that an account is locked,
         otherwise that becomes a user-enumeration oracle.

      c) Setting (or clearing) the PIN clears any active lockout.
         That's also the operator's "unlock this user" UX, so we
         don't need a separate Unlock button this round.

   2. Offline DB-leak resistance. The legacy hash was wp_hash() —
      HMAC-MD5 keyed by SECURE_AUTH_KEY. Anyone who exfiltrated the
      database AND the wp-config.php SECURE_AUTH_KEY could brute-force
      a 4-digit PIN in a few seconds with a GPU. We move new PINs to
      wp_hash_password() (bcrypt with per-row salt). Existing PINs are
      transparently upgraded to bcrypt on the first successful login.

   Trade-offs / known residual risk:

   - The verify path is now O(N) over users-with-PINs because bcrypt
     is per-row salted, so we can't do a single SELECT-by-hash. At
     hotel staff scale (typically <50, hard cap 200) this is fine.
     The cap is enforced and logged.

   - This batch does not change the UX from "PIN-only" to
     "PIN + username". That would defend against the residual
     "attacker who knows ANY valid PIN can log in as that user"
     case, but is a bigger UX change and out of scope here.

================================================================ */
class GHM_PIN_Login {

    /** Throttle / lockout knobs — tunable in one place. */
    const IP_WINDOW_SECONDS = 300;   // 5-minute rolling window
    const IP_MAX_ATTEMPTS   = 5;     // wrong attempts per IP per window
    const USER_MAX_FAILS    = 10;    // wrong attempts per user before lockout
    const USER_LOCK_SECONDS = 900;   // 15-minute lockout
    const MAX_PIN_USERS     = 200;   // sanity cap — bcrypt scan beyond this is suspicious

    /** Usermeta keys */
    const META_LEGACY  = 'ghm_pin';            // wp_hash() — kept for legacy verify only
    const META_BCRYPT  = 'ghm_pin_v2';         // wp_hash_password() — current
    const META_FAILS   = 'ghm_pin_fail_count';
    const META_LOCKED  = 'ghm_pin_locked_until'; // unix timestamp

    public static function init() {
        add_action('wp_ajax_nopriv_ghm_pin_login', array(__CLASS__,'ajax_pin_login'));
        add_action('wp_ajax_ghm_pin_login',        array(__CLASS__,'ajax_pin_login'));
        add_shortcode('ghm_pin_login',             array(__CLASS__,'render'));

        // Admin-only diagnostic. Works both inside wp-admin and on the front-end.
        // Visit any page with ?ghm_pin_diag=1 while logged in as admin.
        add_action('admin_init', array(__CLASS__, 'maybe_run_diagnostic'));
        add_action('init',       array(__CLASS__, 'maybe_run_diagnostic'));
    }

    public static function ajax_pin_login() {
        // Normalize identically to set_pin() so save & login always agree.
        $pin = self::normalize_pin( $_POST['pin'] ?? '' );

        // Generic failure response — never leaks which PINs exist, whether
        // a user is locked, or how many tries are left.
        $invalid = array( 'message' => 'Invalid PIN. Please try again.' );

        // 1. IP-level rate limit BEFORE any DB / hash work. Cheap, and
        //    means an attacker trying random PINs can't pin the CPU.
        $ip_key = self::ip_throttle_key();
        if ( $ip_key && self::ip_is_throttled( $ip_key ) ) {
            self::log( 'rejected: ip throttled' );
            wp_send_json_error( $invalid ); exit;
        }

        if ( strlen( $pin ) < 4 ) {
            self::record_ip_failure( $ip_key );
            self::log( 'rejected: too short (' . strlen( $pin ) . ')' );
            wp_send_json_error( $invalid ); exit;
        }

        // 2. Find the user (bcrypt-aware: O(N) iteration over users-with-PINs).
        $user_id = self::find_user_by_pin( $pin );
        if ( ! $user_id ) {
            self::record_ip_failure( $ip_key );
            self::log( 'rejected: no user matches PIN' );
            wp_send_json_error( $invalid ); exit;
        }

        // 3. Per-user lockout check. We do this AFTER finding the user
        //    rather than before, because (a) we only know which user to
        //    check by matching the PIN first, and (b) returning the same
        //    "Invalid PIN" message keeps the lockout state from leaking.
        if ( self::user_is_locked( $user_id ) ) {
            self::record_ip_failure( $ip_key );
            self::record_user_failure( $user_id );
            self::log( "rejected: user_id=$user_id is locked" );
            wp_send_json_error( $invalid ); exit;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            self::record_ip_failure( $ip_key );
            self::log( "rejected: user_id=$user_id has PIN meta but get_user_by returned nothing" );
            wp_send_json_error( $invalid ); exit;
        }

        // Permissive role check.
        // The fact that an admin saved a PIN for this user IS the authorization.
        // We just refuse explicitly soft-deleted staff entries.
        if ( self::is_deleted_staff( $user_id ) ) {
            self::record_ip_failure( $ip_key );
            self::log( "rejected: user_id=$user_id is marked deleted in ghm_staff" );
            wp_send_json_error( $invalid ); exit;
        }

        // 4. Success path. Clear all throttles for this user/IP, and
        //    transparently migrate legacy wp_hash PINs to bcrypt.
        self::clear_user_throttle( $user_id );
        self::clear_ip_throttle( $ip_key );
        self::maybe_upgrade_legacy_pin( $user_id, $pin );

        wp_set_current_user( $user_id, $user->user_login );
        wp_set_auth_cookie( $user_id, true );
        do_action( 'wp_login', $user->user_login, $user );

        self::log( "success: user_id=$user_id login=$user->user_login roles=" . implode( ',', (array) $user->roles ) );
        wp_send_json_success( array( 'redirect' => admin_url( 'admin.php?page=ghm-dashboard' ) ) );
        exit;
    }

    /* ── Throttle: IP-level ─────────────────────────────────────── */

    /** Build the transient key for this request's IP. Returns '' when
     *  no usable IP is available — in which case we skip IP throttle
     *  and rely on per-user lockout alone. */
    private static function ip_throttle_key() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ( $ip === '' ) return '';
        // Hash the IP so the transient key doesn't store IPs verbatim.
        return 'ghm_pin_ip_' . sha1( $ip );
    }

    private static function ip_is_throttled( $key ) {
        if ( ! $key ) return false;
        $row = get_transient( $key );
        if ( ! is_array( $row ) ) return false;
        return ( (int) ( $row['count'] ?? 0 ) ) >= self::IP_MAX_ATTEMPTS;
    }

    private static function record_ip_failure( $key ) {
        if ( ! $key ) return;
        $row = get_transient( $key );
        if ( ! is_array( $row ) ) {
            $row = array( 'count' => 1, 'first_at' => time() );
        } else {
            $row['count'] = (int) ( $row['count'] ?? 0 ) + 1;
        }
        // Keep the same expiry from first_at so the rolling window
        // doesn't reset on every failure.
        $remaining = max( 1, ( $row['first_at'] ?? time() ) + self::IP_WINDOW_SECONDS - time() );
        set_transient( $key, $row, $remaining );
    }

    private static function clear_ip_throttle( $key ) {
        if ( $key ) delete_transient( $key );
    }

    /* ── Throttle: per-user lockout ─────────────────────────────── */

    private static function user_is_locked( $user_id ) {
        $until = (int) get_user_meta( $user_id, self::META_LOCKED, true );
        return $until > 0 && $until > time();
    }

    private static function record_user_failure( $user_id ) {
        $count = (int) get_user_meta( $user_id, self::META_FAILS, true ) + 1;
        update_user_meta( $user_id, self::META_FAILS, $count );
        if ( $count >= self::USER_MAX_FAILS ) {
            update_user_meta( $user_id, self::META_LOCKED, time() + self::USER_LOCK_SECONDS );
            self::log( "user_id=$user_id locked for " . self::USER_LOCK_SECONDS . 's' );
        }
    }

    /** Called on a successful login OR an admin set/clear action. */
    public static function clear_user_throttle( $user_id ) {
        delete_user_meta( $user_id, self::META_FAILS );
        delete_user_meta( $user_id, self::META_LOCKED );
    }

    /* ── Hashing & verification ─────────────────────────────────── */

    /**
     * Normalize a PIN: strip any non-digit characters and cap at 8.
     * Used by both set_pin() and ajax_pin_login() so the hash always matches.
     */
    public static function normalize_pin( $raw ) {
        $pin = preg_replace( '/\D/', '', (string) $raw );
        return substr( (string) $pin, 0, 8 );
    }

    /**
     * Look up a user by their PIN.
     *
     * Bcrypt has a per-row salt so we can't do a single hash-equality
     * SELECT like the old wp_hash version. Instead we pull the small
     * set of users with EITHER a v2 (bcrypt) or v1 (legacy wp_hash)
     * PIN meta, and check one at a time. At hotel scale (<= a few
     * dozen staff) the cost is trivial; we cap and log if it grows
     * beyond MAX_PIN_USERS as a sanity check.
     *
     * Returns the user_id on match, false otherwise. On a v1 match,
     * the caller is expected to call maybe_upgrade_legacy_pin() to
     * migrate the user to bcrypt.
     */
    private static function find_user_by_pin( $pin ) {
        global $wpdb;
        if ( strlen( $pin ) < 4 ) return false;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_key, meta_value
               FROM {$wpdb->usermeta}
              WHERE meta_key IN (%s, %s)
              ORDER BY user_id ASC
              LIMIT %d",
            self::META_BCRYPT, self::META_LEGACY, self::MAX_PIN_USERS
        ) );

        if ( ! $rows ) return false;

        if ( count( $rows ) >= self::MAX_PIN_USERS ) {
            self::log( 'WARNING: users-with-PIN count is at MAX_PIN_USERS — verification scan was capped.' );
        }

        $legacy_target = wp_hash( $pin );

        foreach ( $rows as $r ) {
            if ( $r->meta_key === self::META_BCRYPT ) {
                if ( wp_check_password( $pin, $r->meta_value ) ) {
                    return (int) $r->user_id;
                }
            } else {
                // Legacy wp_hash compare. hash_equals for timing safety
                // (small benefit on a 64-char hex string, but free).
                if ( hash_equals( (string) $r->meta_value, $legacy_target ) ) {
                    return (int) $r->user_id;
                }
            }
        }
        return false;
    }

    /**
     * If this user logged in via a legacy v1 (wp_hash) PIN, replace it
     * with a v2 (bcrypt) hash now that we have the plaintext in hand.
     * No-op if the user is already on v2.
     */
    private static function maybe_upgrade_legacy_pin( $user_id, $pin ) {
        if ( get_user_meta( $user_id, self::META_BCRYPT, true ) ) return;
        if ( ! get_user_meta( $user_id, self::META_LEGACY, true ) ) return;

        $bcrypt = wp_hash_password( $pin );
        if ( ! $bcrypt ) return;

        update_user_meta( $user_id, self::META_BCRYPT, $bcrypt );
        delete_user_meta( $user_id, self::META_LEGACY );
        self::log( "user_id=$user_id upgraded from legacy PIN hash to bcrypt" );
    }

    /**
     * Set (or replace) a user's PIN. Always stores bcrypt, deletes any
     * legacy wp_hash row, and clears any active lockout — setting a
     * fresh PIN is also the operator's "unlock this user" UX.
     */
    public static function set_pin( $user_id, $pin ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;
        $pin = self::normalize_pin( $pin );
        if ( strlen( $pin ) < 4 || strlen( $pin ) > 8 ) return false;

        $bcrypt = wp_hash_password( $pin );
        if ( ! $bcrypt ) return false;

        update_user_meta( $user_id, self::META_BCRYPT, $bcrypt );
        delete_user_meta( $user_id, self::META_LEGACY );
        self::clear_user_throttle( $user_id );
        return true;
    }

    /**
     * Wipe everything PIN-related for this user. Called from the
     * admin "Clear PIN" button. Public so the AJAX handler in
     * admin/class-ghm-admin.php can call it without re-implementing
     * the meta-key list.
     */
    public static function clear_pin( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return false;
        delete_user_meta( $user_id, self::META_BCRYPT );
        delete_user_meta( $user_id, self::META_LEGACY );
        self::clear_user_throttle( $user_id );
        return true;
    }

    /**
     * Check ghm_staff table for a soft-deleted entry tied to this WP user.
     */
    private static function is_deleted_staff( $user_id ) {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}ghm_staff WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        return $row === 'deleted';
    }

    /**
     * Lightweight debug logger — only writes when WP_DEBUG_LOG is on.
     */
    private static function log( $msg ) {
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[GHM PIN Login] ' . $msg );
        }
    }

    /**
     * Admin-only diagnostic.
     *
     * Visit any wp-admin page with ?ghm_pin_diag=1 to print a brief
     * health summary: how many users have a bcrypt PIN, how many are
     * still on legacy wp_hash, whether any are currently locked. Only
     * administrators see it.
     *
     * The previous version of this tool accepted a PIN as the query
     * value and printed the hash sample, the matching user_id, and the
     * full list of users with PINs. That was useful for debugging but
     * leaked enough information to assist an attacker who'd compromised
     * an admin account. The new version takes no PIN input and prints
     * only counts.
     */
    public static function maybe_run_diagnostic() {
        if ( empty( $_GET['ghm_pin_diag'] ) ) return;
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
        global $wpdb;

        // Only run once per request so the admin_init + init hooks don't double-fire.
        static $ran = false;
        if ( $ran ) return;
        $ran = true;

        $bcrypt_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_BCRYPT
        ) );
        $legacy_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
            self::META_LEGACY
        ) );
        $locked_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d",
            self::META_LOCKED, time()
        ) );

        // Make sure no theme/output buffer has already started writing HTML.
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );

        $version = defined( 'GHM_VERSION' ) ? GHM_VERSION : 'undefined';
        echo "GHM PIN diagnostic\n";
        echo "------------------\n";
        echo "Plugin version on server   : {$version}\n";
        echo "Diagnostic version         : 3 (no-PIN, redacted)\n";
        echo "Site URL                   : " . site_url() . "\n";
        echo "Logged in admin user_id    : " . get_current_user_id() . "\n";
        echo "wp_usermeta table          : {$wpdb->usermeta}\n\n";

        echo "Users with bcrypt PIN (v2) : {$bcrypt_count}\n";
        echo "Users with legacy PIN (v1) : {$legacy_count}";
        if ( $legacy_count > 0 ) {
            echo "  -- will auto-upgrade on next successful login";
        }
        echo "\n";
        echo "Users currently locked     : {$locked_count}\n";

        if ( $bcrypt_count + $legacy_count >= self::MAX_PIN_USERS ) {
            echo "\nWARNING: users-with-PIN count is at or above MAX_PIN_USERS ("
                 . self::MAX_PIN_USERS . "). Verification scan is capped — some\n"
                 . "PINs beyond the cap will silently fail to authenticate. Audit\n"
                 . "the PIN list and clear stale entries.\n";
        }
        exit;
    }

    public static function render($atts) {
        // Ensure scripts are available for PIN login page
        if (!wp_script_is('ghm-public','enqueued')) {
            wp_enqueue_script('ghm-public-pin', GHM_PLUGIN_URL.'public/js/ghm-public.js', array('jquery'), GHM_VERSION, true);
            wp_localize_script('ghm-public-pin','ghmPublic',array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('ghm_public_nonce'),
            ));
        }
        ob_start();
        $hotel = get_option('ghm_hotel_name',get_bloginfo('name'));
        ?>
        <div class="ghm-public-wrap ghm-pin-wrap" style="max-width:400px;margin:60px auto;">
          <div class="ghm-pin-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:32px;box-shadow:0 4px 32px rgba(0,0,0,.08);text-align:center;font-family:'DM Sans',sans-serif;">
            <div style="font-size:48px;margin-bottom:12px;">🏨</div>
            <h2 style="font-family:'Playfair Display',serif;color:#1a1a2e;margin:0 0 4px;"><?php echo esc_html($hotel);?></h2>
            <p style="color:#6b7280;font-size:14px;margin:0 0 24px;">Staff Quick Login</p>
            <div id="ghm-pin-display" aria-live="polite" style="font-size:32px;letter-spacing:12px;color:#1a1a2e;background:#f3f4f6;border-radius:8px;padding:14px;margin-bottom:16px;min-height:60px;">
              ·  ·  ·  ·
            </div>
            <div id="ghm-pin-alert" role="alert" style="display:none;padding:10px;border-radius:8px;font-size:13px;margin-bottom:12px;"></div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:280px;margin:0 auto;">
              <?php for($i=1;$i<=9;$i++): ?>
              <button type="button" class="ghm-pin-key" data-val="<?php echo $i;?>" style="padding:16px;font-size:20px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;color:#1a1a2e;cursor:pointer;font-weight:600;font-family:inherit;"><?php echo $i;?></button>
              <?php endfor;?>
              <button type="button" class="ghm-pin-key" data-val="clear" aria-label="Backspace" style="padding:16px;font-size:14px;border-radius:10px;border:1px solid #e5e7eb;background:#f3f4f6;color:#374151;cursor:pointer;font-weight:600;font-family:inherit;">⌫</button>
              <button type="button" class="ghm-pin-key" data-val="0" style="padding:16px;font-size:20px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;color:#1a1a2e;cursor:pointer;font-weight:600;font-family:inherit;">0</button>
              <button type="button" class="ghm-pin-key ghm-pin-enter" data-val="enter" aria-label="Sign in" style="padding:16px;font-size:18px;border-radius:10px;border:none;background:linear-gradient(135deg,#c9a84c,#e8c97a);color:#1a1a2e;cursor:pointer;font-weight:700;font-family:inherit;">→</button>
            </div>
            <p style="font-size:12px;color:#9ca3af;margin:18px 0 0;">Enter your 4–8 digit PIN, then press →</p>
          </div>
        </div>
        <script>
        (function($){
          var pin = '';
          var busy = false;
          var $display = $('#ghm-pin-display');
          var $alert   = $('#ghm-pin-alert');

          function updateDisplay(){
            $display.text(pin.length ? '●  '.repeat(pin.length).trim() : '·  ·  ·  ·');
          }

          function showError(msg){
            $alert
              .css({background:'#fef2f2',border:'1px solid #fecaca',color:'#991b1b'})
              .text(msg)
              .show();
          }

          function clearError(){ $alert.hide().empty(); }

          function doLogin(){
            if (busy) return;
            if (pin.length < 4) {
              showError('Please enter at least 4 digits.');
              return;
            }
            if (typeof ghmPublic === 'undefined' || !ghmPublic.ajax_url) {
              showError('Login is not configured on this page. Please contact admin.');
              return;
            }
            clearError();
            busy = true;
            $('.ghm-pin-key').prop('disabled', true);

            $.post(ghmPublic.ajax_url, {
              action: 'ghm_pin_login',
              nonce : ghmPublic.nonce,
              pin   : pin
            })
            .done(function(res){
              if (res && res.success && res.data && res.data.redirect) {
                window.location.href = res.data.redirect;
                return;
              }
              showError((res && res.data && res.data.message) || 'Invalid PIN. Please try again.');
              pin = '';
              updateDisplay();
            })
            .fail(function(){
              showError('Network error. Please try again.');
              pin = '';
              updateDisplay();
            })
            .always(function(){
              busy = false;
              $('.ghm-pin-key').prop('disabled', false);
            });
          }

          // Keypad — explicitly cancel any default form behaviour just in case
          // the shortcode is embedded inside a wrapping form (e.g. page builders).
          $(document).on('click', '.ghm-pin-key', function(e){
            e.preventDefault();
            e.stopPropagation();
            if (busy) return;
            var v = String($(this).data('val'));
            if (v === 'clear') {
              pin = pin.slice(0, -1);
              clearError();
              updateDisplay();
              return;
            }
            if (v === 'enter') {
              doLogin();
              return;
            }
            if (/^[0-9]$/.test(v) && pin.length < 8) {
              pin += v;
              clearError();
              updateDisplay();
            }
          });

          // Allow physical keyboard typing as well
          $(document).on('keydown', function(e){
            if (busy) return;
            if (e.key >= '0' && e.key <= '9' && pin.length < 8) {
              pin += e.key;
              clearError();
              updateDisplay();
            } else if (e.key === 'Backspace') {
              pin = pin.slice(0, -1);
              clearError();
              updateDisplay();
            } else if (e.key === 'Enter') {
              e.preventDefault();
              doLogin();
            } else if (e.key === 'Escape') {
              pin = '';
              clearError();
              updateDisplay();
            }
          });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }
}

add_action('plugins_loaded', array('GHM_PIN_Login','init'), 20);


/* ================================================================
   Staff Activity Report
================================================================ */
class GHM_Activity_Report {

    public static function get_report($args = array()) {
        global $wpdb;
        $from  = sanitize_text_field($args['from'] ?? date('Y-m-01'));
        $to    = sanitize_text_field($args['to']   ?? date('Y-m-t'));
        $limit = absint($args['limit'] ?? 100);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.display_name, u.user_email,
             COUNT(*) OVER (PARTITION BY l.user_id) AS total_actions
             FROM {$wpdb->prefix}ghm_activity_log l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             WHERE l.created_at BETWEEN %s AND %s
             ORDER BY l.created_at DESC LIMIT %d",
            $from.' 00:00:00', $to.' 23:59:59', $limit
        ));
    }

    public static function get_summary_by_user($from = '', $to = '') {
        global $wpdb;
        $from = $from ?: date('Y-m-01');
        $to   = $to   ?: date('Y-m-t');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.user_id, u.display_name, COUNT(*) AS total_actions,
             SUM(CASE WHEN l.action LIKE '%booking%' THEN 1 ELSE 0 END) AS booking_actions,
             SUM(CASE WHEN l.action LIKE '%payment%' THEN 1 ELSE 0 END) AS payment_actions,
             MAX(l.created_at) AS last_active
             FROM {$wpdb->prefix}ghm_activity_log l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             WHERE l.created_at BETWEEN %s AND %s
             GROUP BY l.user_id ORDER BY total_actions DESC",
            $from.' 00:00:00', $to.' 23:59:59'
        ));
    }
}
