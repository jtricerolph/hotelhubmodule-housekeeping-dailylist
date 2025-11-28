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
