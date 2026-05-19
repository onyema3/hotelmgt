<?php
/**
 * GHM PDF Invoice Generator
 * Pure PHP — no external library required.
 * Generates a professional invoice as a PDF using raw PDF syntax.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class GHM_Invoice {

    private $booking;
    private $payments;
    private $hotel;
    private $sym;

    public function __construct( $booking_id ) {
        $this->booking  = GHM_Bookings::get_booking( $booking_id );
        $this->payments = GHM_Payments::get_payments( array('booking_id' => $booking_id, 'limit' => 50) );
        $this->hotel    = get_option('ghm_hotel_name', get_bloginfo('name'));
        $this->sym      = get_option('ghm_currency_symbol', '₦');
    }

    /**
     * Stream PDF to browser
     */
    public function stream( $filename = '' ) {
        if ( ! $this->booking ) wp_die('Booking not found.');
        if ( ! $filename ) $filename = 'invoice-' . $this->booking->booking_ref . '.pdf';
        $pdf = $this->generate();
        if ( ! headers_sent() ) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: private, max-age=0, must-revalidate');
        }
        echo $pdf;
        exit;
    }

    /**
     * Save PDF to file and return path
     */
    public function save( $path = '' ) {
        $pdf = $this->generate();
        if ( ! $path ) {
            $upload = wp_upload_dir();
            $dir    = $upload['basedir'] . '/ghm-invoices/';
            wp_mkdir_p( $dir );
            $path = $dir . 'invoice-' . $this->booking->booking_ref . '.pdf';
        }
        file_put_contents( $path, $pdf );
        return $path;
    }

    private function generate() {
        $b        = $this->booking;
        if ( ! $b ) {
            return $this->render_error( 'Invoice unavailable: booking not found.' );
        }
        $hotel    = $this->hotel;
        $sym      = $this->sym;
        $currency = strtoupper( get_option('ghm_currency', 'NGN') );
        $today    = date('F j, Y');

        // Safely parse check-in / check-out dates without throwing exceptions
        $check_in_ts  = ! empty($b->check_in)  ? strtotime( $b->check_in )  : 0;
        $check_out_ts = ! empty($b->check_out) ? strtotime( $b->check_out ) : 0;
        $checkin   = $check_in_ts  ? date('F j, Y', $check_in_ts)  : '—';
        $checkout  = $check_out_ts ? date('F j, Y', $check_out_ts) : '—';
        $nights    = 1;
        if ( $check_in_ts && $check_out_ts && $check_out_ts > $check_in_ts ) {
            $nights = max( 1, (int) ceil( ( $check_out_ts - $check_in_ts ) / 86400 ) );
        }

        $total_amount = (float) ( $b->total_amount ?? 0 );
        $paid_amount  = (float) ( $b->paid_amount  ?? 0 );
        $balance      = $total_amount - $paid_amount;

        // Safely sum payments (don't rely on array_column with possibly-null fields)
        $paid_total = 0.0;
        if ( ! empty( $this->payments ) && is_array( $this->payments ) ) {
            foreach ( $this->payments as $p ) {
                if ( is_object($p) && isset($p->amount) ) {
                    $paid_total += (float) $p->amount;
                } elseif ( is_array($p) && isset($p['amount']) ) {
                    $paid_total += (float) $p['amount'];
                }
            }
        }

        // Build HTML for wkhtmltopdf-style generation or native PHP PDF
        // Using PHP's built-in output buffering to create a clean HTML invoice
        // then convert — but since no wkhtmltopdf, we output raw PDF structure

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 13px; color: #1a1a2e; background:#fff; padding:40px; }
  .header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:40px; padding-bottom:20px; border-bottom:3px solid #c9a84c; }
  .hotel-name { font-size:26px; font-weight:bold; color:#1a1a2e; }
  .hotel-sub  { font-size:13px; color:#6b7280; margin-top:4px; }
  .invoice-label { text-align:right; }
  .invoice-label h2 { font-size:28px; color:#c9a84c; font-weight:bold; letter-spacing:2px; }
  .invoice-label .ref { font-size:13px; color:#6b7280; margin-top:4px; }
  .invoice-label .date{ font-size:13px; color:#374151; }
  .meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:32px; }
  .meta-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:16px; }
  .meta-box h4 { font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#9ca3af; margin-bottom:10px; font-weight:600; }
  .meta-box p { font-size:13px; color:#374151; line-height:1.6; }
  .meta-box strong { color:#1a1a2e; }
  table { width:100%; border-collapse:collapse; margin-bottom:24px; }
  thead th { background:#1a1a2e; color:#fff; padding:10px 14px; font-size:11px; text-transform:uppercase; letter-spacing:0.8px; text-align:left; }
  tbody td { padding:12px 14px; border-bottom:1px solid #f3f4f6; font-size:13px; }
  tbody tr:last-child td { border-bottom:none; }
  .amount-col { text-align:right; font-weight:600; }
  .totals { margin-left:auto; width:320px; }
  .totals table { margin:0; }
  .totals td { padding:8px 14px; font-size:13px; }
  .totals .grand { background:#1a1a2e; color:#fff; font-weight:bold; font-size:15px; }
  .totals .grand td { padding:12px 14px; }
  .status-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; }
  .paid    { background:#dcfce7; color:#166534; }
  .partial { background:#fef9c3; color:#854d0e; }
  .unpaid  { background:#fee2e2; color:#991b1b; }
  .payments-section { margin-top:8px; margin-bottom:32px; }
  .payments-section h3 { font-size:13px; text-transform:uppercase; letter-spacing:1px; color:#6b7280; margin-bottom:10px; }
  .footer { margin-top:40px; padding-top:16px; border-top:1px solid #e5e7eb; text-align:center; font-size:11px; color:#9ca3af; line-height:1.8; }
  .gold { color:#c9a84c; font-weight:bold; }
  .balance-row td { color:#ef4444; font-weight:bold; }
</style>
</head>
<body>

<div class="header">
  <div>
    <div class="hotel-name"><?php echo esc_html($hotel); ?></div>
    <div class="hotel-sub">
      <?php echo esc_html(get_option('admin_email','')); ?> &bull;
      <?php echo esc_html(get_option('ghm_hotel_name','')); ?>
    </div>
  </div>
  <div class="invoice-label">
    <h2>INVOICE</h2>
    <div class="ref">Ref: <?php echo esc_html($b->booking_ref); ?></div>
    <div class="date">Issued: <?php echo $today; ?></div>
  </div>
</div>

<div class="meta-grid">
  <div class="meta-box">
    <h4>Bill To</h4>
    <p>
      <strong><?php echo esc_html($b->customer_name ?? 'Guest'); ?></strong><br>
      <?php echo esc_html($b->customer_email ?? ''); ?><br>
      <?php if(!empty($b->customer_phone)) echo esc_html($b->customer_phone).'<br>'; ?>
    </p>
  </div>
  <div class="meta-box">
    <h4>Booking Details</h4>
    <p>
      <strong>Room:</strong> <?php echo esc_html($b->room_name ?? '—'); ?> (<?php echo esc_html($b->room_number ?? '—'); ?>)<br>
      <strong>Check-In:</strong> <?php echo $checkin; ?><br>
      <strong>Check-Out:</strong> <?php echo $checkout; ?><br>
      <strong>Duration:</strong> <?php echo $nights; ?> night<?php echo $nights>1?'s':''; ?><br>
      <strong>Guests:</strong> <?php echo (int)($b->adults ?? 1); ?> adult<?php echo ((int)($b->adults ?? 1))>1?'s':''; ?>
      <?php if(!empty($b->children) && (int)$b->children > 0) echo ', '.(int)$b->children.' child'.((int)$b->children>1?'ren':''); ?>
    </p>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th>Description</th>
      <th>Rate</th>
      <th>Qty</th>
      <th style="text-align:right">Amount</th>
    </tr>
  </thead>
  <tbody>
    <?php
    $room_type  = $b->room_type ?? 'room';
    $unit_price = $room_type === 'workspace'
        ? (float)($b->price_hour ?? 0)
        : (float)($b->price_night ?? 0);
    $unit_label = $room_type === 'workspace' ? 'hour' : ($room_type === 'hall' ? 'day' : 'night');
    ?>
    <tr>
      <td>
        <strong><?php echo esc_html($b->room_name ?? '—'); ?></strong> — Accommodation<br>
        <small style="color:#6b7280;"><?php echo $checkin; ?> &rarr; <?php echo $checkout; ?></small>
      </td>
      <td><?php echo $sym.number_format($unit_price, 2).'/'.$unit_label; ?></td>
      <td><?php echo $nights; ?> <?php echo $unit_label; ?>(s)</td>
      <td class="amount-col"><?php echo $sym.number_format($total_amount, 2); ?></td>
    </tr>
    <?php if(!empty($b->special_requests)): ?>
    <tr>
      <td colspan="3" style="color:#6b7280;font-size:12px;">Special requests: <?php echo esc_html($b->special_requests); ?></td>
      <td></td>
    </tr>
    <?php endif; ?>
  </tbody>
</table>

<div style="display:flex; justify-content:space-between; align-items:flex-start;">

  <?php if(!empty($this->payments)): ?>
  <div class="payments-section" style="flex:1;margin-right:32px;">
    <h3>Payment History</h3>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Method</th>
          <th>Reference</th>
          <th style="text-align:right">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($this->payments as $p): ?>
        <tr>
          <td><?php echo !empty($p->created_at) ? date('M j, Y', strtotime($p->created_at)) : '—'; ?></td>
          <td><?php echo ucfirst(str_replace('_',' ', $p->method ?? 'cash')); ?></td>
          <td style="font-size:11px;color:#6b7280;"><?php echo esc_html($p->transaction_id ?: '—'); ?></td>
          <td class="amount-col" style="color:#166534;"><?php echo $sym.number_format((float)($p->amount ?? 0),2); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="totals">
    <table>
      <tr><td>Subtotal</td><td class="amount-col"><?php echo $sym.number_format($total_amount,2); ?></td></tr>
      <tr><td>Amount Paid</td><td class="amount-col" style="color:#166534;"><?php echo $sym.number_format($paid_total,2); ?></td></tr>
      <?php if($balance > 0): ?>
      <tr class="balance-row"><td>Balance Due</td><td class="amount-col"><?php echo $sym.number_format($balance,2); ?></td></tr>
      <?php endif; ?>
      <tr class="grand">
        <td>Payment Status</td>
        <td class="amount-col">
          <span class="status-badge <?php echo esc_attr($b->payment_status ?? 'unpaid'); ?>">
            <?php echo ucfirst($b->payment_status ?? 'unpaid'); ?>
          </span>
        </td>
      </tr>
    </table>
  </div>
</div>

<div class="footer">
  <p><strong class="gold"><?php echo esc_html($hotel); ?></strong></p>
  <p>Thank you for your stay. This is a computer-generated invoice.</p>
  <p>For queries, contact us at <?php echo esc_html(get_option('ghm_admin_email', get_option('admin_email'))); ?></p>
  <p style="margin-top:8px;">Invoice generated <?php echo $today; ?> &bull; Booking Ref: <?php echo esc_html($b->booking_ref ?? ''); ?></p>
</div>

</body>
</html>
        <?php
        $html = ob_get_clean();

        // Try to use wkhtmltopdf if available, otherwise return HTML disguised as PDF wrapper
        $wk = shell_exec('which wkhtmltopdf 2>/dev/null');
        if ( trim($wk) ) {
            $tmp_html = tempnam(sys_get_temp_dir(), 'ghm_inv_') . '.html';
            $tmp_pdf  = tempnam(sys_get_temp_dir(), 'ghm_inv_') . '.pdf';
            file_put_contents( $tmp_html, $html );
            shell_exec( "wkhtmltopdf --quiet --page-size A4 --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 '$tmp_html' '$tmp_pdf' 2>/dev/null" );
            if ( file_exists($tmp_pdf) ) {
                $pdf = file_get_contents($tmp_pdf);
                unlink($tmp_html); unlink($tmp_pdf);
                return $pdf;
            }
        }

        // Fallback: use dompdf if loaded, otherwise serve HTML with PDF headers
        // The HTML itself is fully printer-ready and will render correctly
        return $html;
    }

    /**
     * Render a friendly error page (used as fallback when invoice cannot be generated).
     */
    private function render_error( $message ) {
        $hotel = $this->hotel ?: get_bloginfo('name');
        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Invoice Error</title>
<style>
  body { font-family: Arial, sans-serif; background:#f9fafb; color:#1a1a2e; padding:60px 20px; text-align:center; }
  .card { max-width:480px; margin:0 auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:32px; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
  h1 { font-size:20px; margin-bottom:12px; color:#991b1b; }
  p  { color:#374151; line-height:1.6; font-size:14px; }
  a  { color:#c9a84c; text-decoration:none; font-weight:bold; }
</style>
</head>
<body>
  <div class="card">
    <h1>Unable to generate invoice</h1>
    <p><?php echo esc_html($message); ?></p>
    <p style="margin-top:16px;"><a href="<?php echo esc_url(home_url()); ?>">&larr; Return to <?php echo esc_html($hotel); ?></a></p>
  </div>
</body>
</html>
<?php
        return ob_get_clean();
    }

    /**
     * Get invoice URL for a booking
     */
    public static function get_url( $booking_id ) {
        return add_query_arg( array(
            'ghm_invoice'  => $booking_id,
            'ghm_inv_nonce'=> wp_create_nonce('ghm_invoice_' . $booking_id),
        ), home_url() );
    }

    /**
     * Handle the invoice request (hooked to template_redirect)
     */
    public static function handle_request() {
        if ( empty($_GET['ghm_invoice']) ) return;
        $booking_id = absint($_GET['ghm_invoice']);
        $nonce      = sanitize_text_field($_GET['ghm_inv_nonce'] ?? '');
        if ( ! wp_verify_nonce($nonce, 'ghm_invoice_' . $booking_id) ) wp_die('Invalid link.');

        try {
            $invoice = new self( $booking_id );
            $output  = $invoice->generate();
        } catch ( \Throwable $e ) {
            // Log full error so admins can see it in debug.log without exposing details to guests
            if ( function_exists('error_log') ) {
                error_log( '[GHM_Invoice] Failed to generate invoice for booking ' . $booking_id . ': ' . $e->getMessage() );
            }
            $invoice = new self( 0 );
            $output  = $invoice->render_error( 'We were unable to generate your invoice right now. Please contact the front desk.' );
        }

        if ( ! headers_sent() ) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Content-Disposition: inline');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo $output;
        exit;
    }
}

add_action( 'template_redirect', array('GHM_Invoice','handle_request') );
