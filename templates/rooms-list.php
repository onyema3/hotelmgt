<?php if ( ! defined( 'ABSPATH' ) ) exit;
$sym      = get_option( 'ghm_currency_symbol', '$' );
$statuses = GHM_Rooms::get_room_statuses();
?>
<div class="ghm-public-wrap">
  <?php if ( empty($rooms) ): ?>
  <div style="text-align:center;padding:40px 20px;color:#9ca3af;">
    <div style="font-size:48px;margin-bottom:12px;">🏨</div>
    <p>No rooms available at the moment. Please check back later.</p>
  </div>
  <?php else: ?>
  <div class="ghm-rooms-grid">
    <?php foreach ( $rooms as $room ):
      $amenities    = json_decode( $room->amenities ?: '[]', true );
      $is_workspace = $room->type === 'workspace';
      $is_hall      = $room->type === 'hall';
      $icon_map = array('workspace'=>'💼','hall'=>'🎪','suite'=>'🌟','apartment'=>'🏠');
      $icon     = isset($icon_map[$room->type]) ? $icon_map[$room->type] : '🛏️';
      $dp = GHM_Rooms::get_display_price($room);
    ?>
    <div class="ghm-room-pub-card">
      <div class="card-img"><?php echo $icon; ?></div>
      <div class="card-body">
        <div class="card-type"><?php echo esc_html( ucfirst($room->type) ); ?> · Room <?php echo esc_html($room->room_number); ?></div>
        <div class="card-name"><?php echo esc_html($room->name); ?></div>
        <div class="card-feats">
          <span>👥 <?php echo $room->capacity; ?> <?php echo $room->capacity > 1 ? 'guests' : 'guest'; ?></span>
          <?php if ($room->floor): ?>
          <span>🏢 Floor <?php echo esc_html($room->floor); ?></span>
          <?php endif; ?>
          <?php if (!empty($amenities)): ?>
          <span>✨ <?php echo count($amenities); ?> amenities</span>
          <?php endif; ?>
          <span><?php echo $is_hall ? '📅 Per day' : ($is_workspace ? '⏱️ Per hour' : '🌙 Per night'); ?></span>
        </div>
        <?php if ( !empty($amenities) ): ?>
        <div class="card-amenities-pub">
          <?php foreach (array_slice($amenities, 0, 4) as $a): ?>
          <span><?php echo esc_html($a); ?></span>
          <?php endforeach;
          if (count($amenities) > 4): ?>
          <span>+<?php echo count($amenities) - 4; ?> more</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="card-footer">
          <div class="card-price">
            <?php echo $sym; ?><?php echo number_format($dp['price'], 2); ?>
            <small><?php echo esc_html($dp['unit']); ?></small>
          </div>
          <?php if ($room->status === 'available'): ?>
          <button class="card-book-btn ghm-book-room-btn" data-room-id="<?php echo $room->id; ?>">
            Book Now
          </button>
          <?php else: ?>
          <span style="font-size:12px;color:#ef4444;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
            <?php echo $statuses[$room->status] ?? ucfirst($room->status); ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
