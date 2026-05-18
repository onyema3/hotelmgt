<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div id="ghm-wrap">
  <div class="ghm-page-header">
    <h1><span class="dashicons dashicons-businessperson"></span> Staff Management</h1>
    <?php if ( current_user_can('ghm_manage_staff') ): ?>
    <button class="ghm-btn ghm-btn-primary" id="ghm-btn-add-staff">
      <span class="dashicons dashicons-plus-alt"></span> Add Staff Member
    </button>
    <?php endif; ?>
  </div>

  <?php if ( empty($staff) ): ?>
  <div class="ghm-empty">
    <span class="dashicons dashicons-businessperson"></span>
    <p>No staff members found.</p>
    <button class="ghm-btn ghm-btn-primary" id="ghm-btn-add-staff">Add Staff Member</button>
  </div>
  <?php else: ?>
  <div class="ghm-cards-grid">
    <?php foreach ($staff as $member): ?>
    <div class="ghm-room-card">
      <span class="card-badge ghm-badge <?php echo esc_attr($member->status); ?>"><?php echo ucfirst($member->status); ?></span>
      <div class="ghm-profile-avatar" style="width:48px;height:48px;font-size:18px;margin:0 0 10px;text-align:center;display:flex;align-items:center;justify-content:center;background:var(--ghm-surface2);border:2px solid var(--ghm-gold);border-radius:50%;">
        <?php echo strtoupper(substr($member->display_name,0,2)); ?>
      </div>
      <div class="card-name"><?php echo esc_html($member->display_name); ?></div>
      <div class="card-number"><?php echo esc_html($member->position ?: 'Staff'); ?> · <?php echo esc_html($member->department ?: ''); ?></div>
      <ul class="ghm-info-list" style="margin:10px 0;">
        <li><span class="label">Email</span><span class="value" style="font-size:12px;"><?php echo esc_html($member->user_email); ?></span></li>
        <?php if ($member->phone): ?>
        <li><span class="label">Phone</span><span class="value"><?php echo esc_html($member->phone); ?></span></li>
        <?php endif; ?>
        <?php if ($member->shift): ?>
        <li><span class="label">Shift</span><span class="value"><?php echo ucfirst($member->shift); ?></span></li>
        <?php endif; ?>
        <?php if ($member->hire_date): ?>
        <li><span class="label">Hired</span><span class="value"><?php echo date('M Y',strtotime($member->hire_date)); ?></span></li>
        <?php endif; ?>
      </ul>
      <div class="card-actions">
        <button class="ghm-btn ghm-btn-outline ghm-btn-sm ghm-edit-staff" data-id="<?php echo $member->id; ?>">
          <span class="dashicons dashicons-edit"></span> Edit
        </button>
        <button class="ghm-btn ghm-btn-danger ghm-btn-sm ghm-delete-staff" data-id="<?php echo $member->id; ?>">
          <span class="dashicons dashicons-trash"></span>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
