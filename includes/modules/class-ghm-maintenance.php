<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class GHM_Maintenance {

    public static function create_table() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ghm_maintenance (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id      BIGINT UNSIGNED NOT NULL,
            title        VARCHAR(200) NOT NULL,
            description  TEXT,
            priority     VARCHAR(20) NOT NULL DEFAULT 'normal',
            status       VARCHAR(50) NOT NULL DEFAULT 'open',
            category     VARCHAR(100),
            assigned_to  BIGINT UNSIGNED DEFAULT NULL,
            reported_by  BIGINT UNSIGNED DEFAULT NULL,
            resolved_at  DATETIME DEFAULT NULL,
            images       LONGTEXT,
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY room_id  (room_id),
            KEY status   (status),
            KEY priority (priority)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function get_requests( $args = array() ) {
        global $wpdb;
        $where  = '1=1';
        $params = array();
        if ( ! empty($args['status']) )  { $where .= ' AND m.status = %s';   $params[] = $args['status']; }
        if ( ! empty($args['room_id']) ) { $where .= ' AND m.room_id = %d';  $params[] = $args['room_id']; }
        if ( ! empty($args['priority'])){ $where .= ' AND m.priority = %s';  $params[] = $args['priority']; }
        $limit = isset($args['limit']) ? 'LIMIT ' . absint($args['limit']) : 'LIMIT 50';
        $sql   = "SELECT m.*, r.name AS room_name, r.room_number,
                  u.display_name AS assigned_name, ru.display_name AS reporter_name
                  FROM {$wpdb->prefix}ghm_maintenance m
                  LEFT JOIN {$wpdb->prefix}ghm_rooms r ON r.id = m.room_id
                  LEFT JOIN {$wpdb->users} u  ON u.ID  = m.assigned_to
                  LEFT JOIN {$wpdb->users} ru ON ru.ID = m.reported_by
                  WHERE $where ORDER BY FIELD(m.priority,'urgent','high','normal','low'), m.created_at DESC $limit";
        return $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
    }

    public static function save( $data, $id = 0 ) {
        global $wpdb;
        $fields = array(
            'room_id'     => absint( $data['room_id'] ),
            'title'       => sanitize_text_field( $data['title'] ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'priority'    => sanitize_text_field( $data['priority'] ?? 'normal' ),
            'status'      => sanitize_text_field( $data['status']   ?? 'open' ),
            'category'    => sanitize_text_field( $data['category'] ?? '' ),
            'assigned_to' => !empty($data['assigned_to']) ? absint($data['assigned_to']) : null,
            'reported_by' => get_current_user_id() ?: null,
        );
        if ( $fields['status'] === 'resolved' ) {
            $fields['resolved_at'] = current_time('mysql');
            // Free the room from maintenance status if no other open requests
            $open = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ghm_maintenance WHERE room_id=%d AND status='open' AND id!=%d",
                $fields['room_id'], $id
            ) );
            if ( ! $open ) {
                $wpdb->update( $wpdb->prefix . 'ghm_rooms', array('status'=>'available'), array('id'=>$fields['room_id']) );
            }
        } elseif ( $fields['status'] === 'open' || $fields['status'] === 'in_progress' ) {
            // Mark room as under maintenance
            $wpdb->update( $wpdb->prefix . 'ghm_rooms', array('status'=>'maintenance'), array('id'=>$fields['room_id']) );
        }
        if ( $id > 0 ) {
            $wpdb->update( $wpdb->prefix . 'ghm_maintenance', $fields, array('id'=>$id) );
            return $id;
        }
        $wpdb->insert( $wpdb->prefix . 'ghm_maintenance', $fields );
        return $wpdb->insert_id;
    }

    public static function count_open() {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ghm_maintenance WHERE status IN ('open','in_progress')");
    }

    public static function get_categories() {
        return array('plumbing','electrical','hvac','furniture','electronics','cleaning','safety','structural','other');
    }
    public static function get_statuses() {
        return array('open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','deferred'=>'Deferred');
    }
    public static function get_priorities() {
        return array('low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent');
    }
}
