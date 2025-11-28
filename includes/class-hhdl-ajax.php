<?php
/**
 * AJAX Class - Handles AJAX requests
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handler class for Daily List module
 */
class HHDL_Ajax {

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
        // Register AJAX handlers
        add_action('wp_ajax_hhdl_get_rooms', array($this, 'get_rooms'));
        add_action('wp_ajax_hhdl_get_room_details', array($this, 'get_room_details'));
        add_action('wp_ajax_hhdl_complete_task', array($this, 'complete_task'));
    }

    /**
     * Get rooms list (AJAX handler)
     */
    public function get_rooms() {
        // Verify nonce
        check_ajax_referer('hhdl_ajax_nonce', 'nonce');

        // Check permissions
        if (!$this->user_can_access()) {
            wp_send_json_error(array('message' => __('Permission denied', 'hhdl')));
        }

        // Get parameters
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : date('Y-m-d');

        if (!$location_id) {
            wp_send_json_error(array('message' => __('Invalid location', 'hhdl')));
        }

        // Check if enabled for this location
        if (!HHDL_Settings::is_enabled($location_id)) {
            wp_send_json_error(array('message' => __('Module not enabled for this location', 'hhdl')));
        }

        // Render room cards
        ob_start();
        $display = HHDL_Display::instance();
        $display->render_room_cards($location_id, $date);
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Get room details (AJAX handler)
     */
    public function get_room_details() {
        // Verify nonce
        check_ajax_referer('hhdl_ajax_nonce', 'nonce');

        // Check permissions
        if (!$this->user_can_access()) {
            wp_send_json_error(array('message' => __('Permission denied', 'hhdl')));
        }

        // Get parameters
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $room_id = isset($_POST['room_id']) ? sanitize_text_field($_POST['room_id']) : '';
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : date('Y-m-d');

        if (!$location_id || !$room_id) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'hhdl')));
        }

        // Fetch room details from NewBook
        $room_details = $this->fetch_room_details($location_id, $room_id, $date);

        if (!$room_details) {
            wp_send_json_error(array('message' => __('Room not found', 'hhdl')));
        }

        // Get default tasks for this location
        $default_tasks = HHDL_Settings::get_default_tasks($location_id);

        // Build response with permission-based data
        $response = array(
            'room_number' => $room_details['room_number'],
            'booking'     => $this->filter_booking_data($room_details['booking']),
            'tasks'       => $this->build_tasks_list($room_details, $default_tasks, $date),
            'site_status' => $room_details['site_status']
        );

        wp_send_json_success($response);
    }

    /**
     * Complete task (AJAX handler)
     */
    public function complete_task() {
        global $wpdb;

        // Verify nonce
        check_ajax_referer('hhdl_ajax_nonce', 'nonce');

        // Check permissions
        if (!$this->user_can_access()) {
            wp_send_json_error(array('message' => __('Permission denied', 'hhdl')));
        }

        // Get parameters
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $room_id = isset($_POST['room_id']) ? sanitize_text_field($_POST['room_id']) : '';
        $task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
        $task_type = isset($_POST['task_type']) ? sanitize_text_field($_POST['task_type']) : '';
        $booking_ref = isset($_POST['booking_ref']) ? sanitize_text_field($_POST['booking_ref']) : '';
        $service_date = isset($_POST['service_date']) ? sanitize_text_field($_POST['service_date']) : date('Y-m-d');

        if (!$location_id || !$room_id || !$task_type) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'hhdl')));
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Check for existing completion (with row lock)
            $table_name = $wpdb->prefix . 'hhdl_task_completions';
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_name}
                 WHERE room_id = %s AND task_type = %s AND service_date = %s
                 FOR UPDATE",
                $room_id,
                $task_type,
                $service_date
            ));

            if ($exists) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array('message' => __('Task already completed', 'hhdl')));
            }

            // Insert completion record
            $inserted = $wpdb->insert(
                $table_name,
                array(
                    'location_id'  => $location_id,
                    'room_id'      => $room_id,
                    'task_id'      => $task_id,
                    'task_type'    => $task_type,
                    'completed_by' => get_current_user_id(),
                    'completed_at' => current_time('mysql'),
                    'booking_ref'  => $booking_ref,
                    'service_date' => $service_date
                ),
                array('%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s')
            );

            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array('message' => __('Failed to save completion', 'hhdl')));
            }

            // Update NewBook if task_id is provided
            if ($task_id > 0) {
                $newbook_result = $this->update_newbook_task($task_id);

                if ($newbook_result === false) {
                    $wpdb->query('ROLLBACK');
                    wp_send_json_error(array('message' => __('Failed to update NewBook', 'hhdl')));
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            // Get user info for response
            $user = wp_get_current_user();

            wp_send_json_success(array(
                'message'       => __('Task completed successfully', 'hhdl'),
                'completed_by'  => $user->display_name,
                'completed_at'  => current_time('mysql'),
                'completion_id' => $wpdb->insert_id
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Fetch room details from NewBook
     */
    private function fetch_room_details($location_id, $room_id, $date) {
        // TODO: Implement NewBook API integration
        // For now, return mock data

        return array(
            'room_number' => $room_id,
            'site_status' => 'Clean',
            'booking'     => array(
                'reference'    => 'NB123456',
                'guest_name'   => 'John Smith',
                'email'        => 'john@example.com',
                'phone'        => '555-0123',
                'checkin_date' => $date,
                'checkout_date' => date('Y-m-d', strtotime($date . ' +3 days')),
                'checkin_time' => '14:00',
                'checkout_time' => '10:00',
                'pax'          => 2,
                'nights'       => 3,
                'current_night' => 1,
                'room_type'    => 'Standard Room',
                'rate_plan'    => 'Best Available Rate',
                'rate_amount'  => 150.00,
                'notes'        => 'Guest requested early check-in'
            ),
            'newbook_tasks' => array()
        );
    }

    /**
     * Filter booking data based on user permissions
     */
    private function filter_booking_data($booking) {
        if (!$booking) {
            return null;
        }

        $can_view_guest = $this->user_can_view_guest_details();
        $can_view_rates = $this->user_can_view_rate_details();
        $can_view_all_notes = $this->user_can_view_all_notes();

        $filtered = array(
            'reference' => $booking['reference']
        );

        if ($can_view_guest) {
            $filtered['guest_name'] = $booking['guest_name'];
            $filtered['email'] = $booking['email'];
            $filtered['phone'] = $booking['phone'];
        }

        // Dates and times are always visible
        $filtered['checkin_date'] = $booking['checkin_date'];
        $filtered['checkout_date'] = $booking['checkout_date'];
        $filtered['checkin_time'] = $booking['checkin_time'];
        $filtered['checkout_time'] = $booking['checkout_time'];
        $filtered['pax'] = $booking['pax'];
        $filtered['nights'] = $booking['nights'];
        $filtered['current_night'] = $booking['current_night'];
        $filtered['room_type'] = $booking['room_type'];

        if ($can_view_rates) {
            $filtered['rate_plan'] = $booking['rate_plan'];
            $filtered['rate_amount'] = $booking['rate_amount'];
        }

        if ($can_view_all_notes) {
            $filtered['notes'] = $booking['notes'];
        } else {
            // Show only housekeeping-related notes
            $filtered['notes'] = $this->filter_housekeeping_notes($booking['notes']);
        }

        return $filtered;
    }

    /**
     * Build tasks list combining NewBook tasks and default tasks
     */
    private function build_tasks_list($room_details, $default_tasks, $date) {
        $tasks = array();

        // Add default tasks
        foreach ($default_tasks as $task) {
            $tasks[] = array(
                'id'        => $task['id'],
                'name'      => $task['name'],
                'color'     => $task['color'],
                'completed' => $this->is_task_completed($room_details['room_number'], $task['name'], $date),
                'source'    => 'default'
            );
        }

        // Add NewBook tasks
        if (!empty($room_details['newbook_tasks'])) {
            foreach ($room_details['newbook_tasks'] as $nb_task) {
                $tasks[] = array(
                    'id'        => $nb_task['id'],
                    'name'      => $nb_task['name'],
                    'color'     => '#6b7280', // Gray for NewBook tasks
                    'completed' => $nb_task['completed'],
                    'source'    => 'newbook'
                );
            }
        }

        return $tasks;
    }

    /**
     * Check if task is completed
     */
    private function is_task_completed($room_id, $task_type, $service_date) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hhdl_task_completions';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name}
             WHERE room_id = %s AND task_type = %s AND service_date = %s",
            $room_id,
            $task_type,
            $service_date
        ));

        return $exists ? true : false;
    }

    /**
     * Update NewBook task status
     */
    private function update_newbook_task($task_id) {
        // TODO: Implement NewBook API call to mark task complete
        // For now, return success
        return true;
    }

    /**
     * Filter notes to show only housekeeping-related content
     */
    private function filter_housekeeping_notes($notes) {
        if (empty($notes)) {
            return '';
        }

        // Simple keyword filter
        $keywords = array('housekeeping', 'clean', 'linen', 'towel', 'amenities', 'room');
        $filtered = '';

        $lines = explode("\n", $notes);
        foreach ($lines as $line) {
            foreach ($keywords as $keyword) {
                if (stripos($line, $keyword) !== false) {
                    $filtered .= $line . "\n";
                    break;
                }
            }
        }

        return trim($filtered);
    }

    /**
     * Check if user can access module
     */
    private function user_can_access() {
        if (function_exists('wfa_user_has_permission')) {
            return wfa_user_has_permission('hhdl_access_module');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can view guest details
     */
    private function user_can_view_guest_details() {
        if (function_exists('wfa_user_has_permission')) {
            return wfa_user_has_permission('hhdl_view_guest_details');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can view rate details
     */
    private function user_can_view_rate_details() {
        if (function_exists('wfa_user_has_permission')) {
            return wfa_user_has_permission('hhdl_view_rate_details');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can view all notes
     */
    private function user_can_view_all_notes() {
        if (function_exists('wfa_user_has_permission')) {
            return wfa_user_has_permission('hhdl_view_all_notes');
        }
        return current_user_can('edit_posts');
    }
}
