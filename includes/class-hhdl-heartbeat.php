<?php
/**
 * Heartbeat Class - Real-time multi-user synchronization
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Heartbeat class for real-time sync
 */
class HHDL_Heartbeat {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_filter('heartbeat_received', array($this, 'heartbeat_received'), 10, 2);
        add_filter('heartbeat_send', array($this, 'heartbeat_send'), 10, 1);
        add_filter('heartbeat_settings', array($this, 'heartbeat_settings'));
    }

    /**
     * Modify heartbeat settings
     */
    public function heartbeat_settings($settings) {
        // Set heartbeat interval to 30 seconds for near real-time updates
        $settings['interval'] = 30;
        return $settings;
    }

    /**
     * Process heartbeat received from frontend
     *
     * @param array $response Response data
     * @param array $data Data from frontend
     * @return array Modified response
     */
    public function heartbeat_received($response, $data) {
        // Check if this is a Daily List heartbeat
        if (!isset($data['hhdl_monitor'])) {
            return $response;
        }

        $monitor_data = $data['hhdl_monitor'];

        // Validate required fields
        if (!isset($monitor_data['location_id']) || !isset($monitor_data['last_check'])) {
            return $response;
        }

        $location_id = intval($monitor_data['location_id']);
        $last_check = sanitize_text_field($monitor_data['last_check']);
        $viewing_date = isset($monitor_data['viewing_date']) ? sanitize_text_field($monitor_data['viewing_date']) : date('Y-m-d');

        // Get recent completions since last check
        $recent_completions = $this->get_recent_completions($location_id, $last_check, $viewing_date);

        // Add to response
        $response['hhdl_updates'] = array(
            'completions' => $recent_completions,
            'timestamp'   => current_time('mysql')
        );

        // Check for activity log monitoring
        if (isset($data['hhdl_activity_monitor'])) {
            $activity_data = $data['hhdl_activity_monitor'];

            if (isset($activity_data['location_id']) && isset($activity_data['service_date']) && isset($activity_data['last_check'])) {
                $activity_location_id = intval($activity_data['location_id']);
                $activity_service_date = sanitize_text_field($activity_data['service_date']);
                $activity_last_check = sanitize_text_field($activity_data['last_check']);

                // Get recent activity events
                $recent_events = $this->get_recent_activity_events($activity_location_id, $activity_service_date, $activity_last_check);

                $response['hhdl_activity_updates'] = array(
                    'events' => $recent_events,
                    'timestamp' => current_time('mysql')
                );
            }
        }

        // Check if linen count module is active and get linen updates
        if (class_exists('HHLC_Heartbeat')) {
            $recent_linen = $this->get_recent_linen_updates($location_id, $last_check, $viewing_date);

            if (!empty($recent_linen)) {
                $response['hhdl_linen_updates'] = array(
                    'updates' => $recent_linen,
                    'timestamp' => current_time('mysql')
                );
            }
        }

        return $response;
    }

    /**
     * Add data to heartbeat send
     *
     * @param array $response Response data
     * @return array Modified response
     */
    public function heartbeat_send($response) {
        // Can be used to push server-side updates without client request
        return $response;
    }

    /**
     * Get recent task completions
     *
     * @param int    $location_id Location ID
     * @param string $last_check Last check timestamp
     * @param string $viewing_date Current viewing date
     * @return array Recent completions
     */
    private function get_recent_completions($location_id, $last_check, $viewing_date) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hhdl_task_completions';

        // Query recent completions for this location and date
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT tc.*, u.display_name as completed_by_name
             FROM {$table_name} tc
             LEFT JOIN {$wpdb->users} u ON tc.completed_by = u.ID
             WHERE tc.location_id = %d
             AND tc.service_date = %s
             AND tc.completed_at > %s
             ORDER BY tc.completed_at DESC",
            $location_id,
            $viewing_date,
            $last_check
        ), ARRAY_A);

        // Format results
        $completions = array();
        foreach ($results as $row) {
            $completions[] = array(
                'id'               => $row['id'],
                'room_id'          => $row['room_id'],
                'task_type'        => $row['task_type'],
                'completed_by'     => $row['completed_by'],
                'completed_by_name' => $row['completed_by_name'],
                'completed_at'     => $row['completed_at'],
                'booking_ref'      => $row['booking_ref']
            );
        }

        return $completions;
    }

    /**
     * Get recent activity log events
     *
     * @param int    $location_id Location ID
     * @param string $service_date Service date
     * @param string $last_check Last check timestamp
     * @return array Recent activity events
     */
    private function get_recent_activity_events($location_id, $service_date, $last_check) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hhdl_activity_log';

        // Query recent activity events
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table_name}
             WHERE location_id = %d
             AND service_date = %s
             AND occurred_at > %s
             ORDER BY occurred_at DESC",
            $location_id,
            $service_date,
            $last_check
        ), ARRAY_A);

        // Check if user can view guest details
        $can_view_guest_details = current_user_can('hhdl_view_guest_details');

        // Get unique room IDs to resolve
        $room_ids = array_unique(array_column($results, 'room_id'));

        // Resolve room IDs to room numbers using HHDL_Ajax helper
        $room_mapping = HHDL_Ajax::resolve_room_numbers($location_id, $room_ids);

        // Format results
        $events = array();
        foreach ($results as $row) {
            $event_data = json_decode($row['event_data'], true);

            // Filter guest details if user lacks permission
            if (!$can_view_guest_details && isset($event_data['guest_name'])) {
                unset($event_data['guest_name']);
            }

            // Resolve room_id to room_number
            $room_id = $row['room_id'];
            $room_number = isset($room_mapping[$room_id]) ? $room_mapping[$room_id] : $room_id;

            $events[] = array(
                'id' => $row['id'],
                'room_id' => $room_number,  // Use resolved room_number instead of site_id
                'event_type' => $row['event_type'],
                'event_data' => $event_data,
                'user_id' => $row['user_id'],
                'user_name' => $row['user_name'],
                'occurred_at' => $row['occurred_at'],
                'booking_ref' => $row['booking_ref']
            );
        }

        return $events;
    }

    /**
     * Get recent linen count updates
     *
     * @param int    $location_id Location ID
     * @param string $last_check Last check timestamp
     * @param string $viewing_date Current viewing date
     * @return array Recent linen count updates grouped by room
     */
    private function get_recent_linen_updates($location_id, $last_check, $viewing_date) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hhlc_linen_counts';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            return array();
        }

        // Query linen counts modified since last check, grouped by room
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT room_id,
                    SUM(count) as total_count,
                    SUM(CASE WHEN is_locked = 0 THEN 1 ELSE 0 END) as unlocked_count,
                    SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked_count,
                    MAX(GREATEST(submitted_at, IFNULL(last_updated_at, '0000-00-00 00:00:00'))) as last_modified
             FROM {$table_name}
             WHERE location_id = %d
             AND service_date = %s
             AND (submitted_at >= %s OR (last_updated_at IS NOT NULL AND last_updated_at >= %s))
             GROUP BY room_id",
            $location_id,
            $viewing_date,
            $last_check,
            $last_check
        ), ARRAY_A);

        // Calculate status for each room
        $updates = array();
        foreach ($results as $row) {
            $total_count = intval($row['total_count']);
            $locked_count = intval($row['locked_count']);
            $unlocked_count = intval($row['unlocked_count']);

            // Determine status based on lock state
            if ($locked_count > 0 && $unlocked_count == 0) {
                $status = 'submitted';
            } elseif ($unlocked_count > 0) {
                $status = 'unsaved';
            } else {
                $status = 'none';
            }

            $updates[] = array(
                'room_id' => $row['room_id'],
                'total_count' => $total_count,
                'status' => $status,
                'locked_count' => $locked_count,
                'unlocked_count' => $unlocked_count,
                'last_modified' => $row['last_modified']
            );
        }

        return $updates;
    }

    /**
     * Get active users monitoring Daily List
     *
     * @param int $location_id Location ID
     * @return array Active users
     */
    private function get_active_users($location_id) {
        // This would track users currently viewing the Daily List
        // Could be implemented with a transient or custom table
        // For now, return empty array
        return array();
    }

    /**
     * Record user activity
     *
     * @param int    $user_id User ID
     * @param int    $location_id Location ID
     * @param string $viewing_date Viewing date
     */
    public static function record_activity($user_id, $location_id, $viewing_date) {
        $key = sprintf('hhdl_active_%d_%s', $location_id, $viewing_date);

        // Get current active users
        $active_users = get_transient($key);
        if (!$active_users) {
            $active_users = array();
        }

        // Add/update this user
        $active_users[$user_id] = array(
            'user_id'     => $user_id,
            'last_active' => time(),
            'display_name' => wp_get_current_user()->display_name
        );

        // Remove stale entries (older than 2 minutes)
        $active_users = array_filter($active_users, function($user) {
            return (time() - $user['last_active']) < 120;
        });

        // Save transient (expires in 5 minutes)
        set_transient($key, $active_users, 300);
    }

    /**
     * Get list of active users for a location/date
     *
     * @param int    $location_id Location ID
     * @param string $viewing_date Viewing date
     * @return array Active users
     */
    public static function get_active_users_list($location_id, $viewing_date) {
        $key = sprintf('hhdl_active_%d_%s', $location_id, $viewing_date);
        $active_users = get_transient($key);

        if (!$active_users) {
            return array();
        }

        // Remove stale entries
        $active_users = array_filter($active_users, function($user) {
            return (time() - $user['last_active']) < 120;
        });

        return array_values($active_users);
    }
}
