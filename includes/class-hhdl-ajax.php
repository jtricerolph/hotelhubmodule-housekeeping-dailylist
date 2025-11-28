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

        // Get task description mappings for this location
        $task_description_mappings = HHDL_Settings::get_task_description_mappings($location_id);

        // Build response with permission-based data
        $response = array(
            'room_number' => $room_details['room_number'],
            'booking'     => $this->filter_booking_data($room_details['booking']),
            'tasks'       => $this->build_tasks_list($room_details, $task_description_mappings, $date),
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
        // Get NewBook API client
        $api = $this->get_newbook_api($location_id);
        if (!$api) {
            return null;
        }

        // Get site details
        $sites_response = $api->get_sites(true);
        $sites = isset($sites_response['data']) ? $sites_response['data'] : array();

        $site_name = $room_id;
        $site_status = 'Unknown';
        foreach ($sites as $site) {
            if ($site['site_id'] === $room_id) {
                $site_name = $site['site_name'];
                $site_status = isset($site['site_status']) ? $site['site_status'] : 'Unknown';
                break;
            }
        }

        // Get booking for this room/date
        $bookings_response = $api->get_bookings($date, date('Y-m-d', strtotime($date . ' +1 day')), 'staying', true);
        $bookings = isset($bookings_response['data']) ? $bookings_response['data'] : array();

        $booking_data = null;
        foreach ($bookings as $booking) {
            if (isset($booking['site_id']) && $booking['site_id'] === $room_id) {
                $arrival = date('Y-m-d', strtotime($booking['booking_arrival']));
                $departure = date('Y-m-d', strtotime($booking['booking_departure']));

                // Check if booking covers this date
                if ($arrival <= $date && $departure > $date) {
                    $total_nights = (strtotime($departure) - strtotime($arrival)) / 86400;
                    $current_night = (strtotime($date) - strtotime($arrival)) / 86400 + 1;

                    $booking_data = array(
                        'reference'     => isset($booking['booking_reference_id']) ? $booking['booking_reference_id'] : '',
                        'guest_name'    => isset($booking['guest_name']) ? $booking['guest_name'] : '',
                        'email'         => isset($booking['guest_email']) ? $booking['guest_email'] : '',
                        'phone'         => isset($booking['guest_phone']) ? $booking['guest_phone'] : '',
                        'checkin_date'  => $arrival,
                        'checkout_date' => $departure,
                        'checkin_time'  => isset($booking['booking_eta']) ? date('H:i', strtotime($booking['booking_eta'])) : '',
                        'checkout_time' => '10:00', // Default checkout
                        'pax'           => isset($booking['pax']) ? $booking['pax'] : 0,
                        'nights'        => $total_nights,
                        'current_night' => $current_night,
                        'room_type'     => isset($booking['site_category_name']) ? $booking['site_category_name'] : '',
                        'rate_plan'     => isset($booking['rate_plan_name']) ? $booking['rate_plan_name'] : '',
                        'rate_amount'   => isset($booking['rate_amount']) ? $booking['rate_amount'] : 0,
                        'notes'         => isset($booking['notes']) ? $this->format_notes($booking['notes']) : ''
                    );
                    break;
                }
            }
        }

        // Get tasks for this room/date
        $tasks_response = $api->get_tasks($date, date('Y-m-d', strtotime($date . ' +1 day')), array(), true, null, true);
        $all_tasks = isset($tasks_response['data']) ? $tasks_response['data'] : array();

        $newbook_tasks = array();
        foreach ($all_tasks as $task) {
            // Get site ID from task
            $task_site_id = !empty($task['task_location_id']) ? $task['task_location_id'] :
                            (!empty($task['booking_site_id']) ? $task['booking_site_id'] : '');

            if ($task_site_id !== $room_id) {
                continue;
            }

            // Check if task is for this date
            $task_dates = $this->get_task_dates($task);
            if (!in_array($date, $task_dates)) {
                continue;
            }

            $newbook_tasks[] = array(
                'id'               => $task['task_id'],
                'task_description' => isset($task['task_description']) ? $task['task_description'] : '',
                'completed'        => isset($task['task_completed_on']) && !empty($task['task_completed_on'])
            );
        }

        return array(
            'room_number'   => $site_name,
            'site_status'   => $site_status,
            'booking'       => $booking_data,
            'newbook_tasks' => $newbook_tasks
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
     * Build tasks list from NewBook tasks filtered by task description mappings
     */
    private function build_tasks_list($room_details, $task_description_mappings, $date) {
        $tasks = array();

        // Only show NewBook tasks that match configured task description filters
        if (!empty($room_details['newbook_tasks'])) {
            foreach ($room_details['newbook_tasks'] as $nb_task) {
                $task_description = $nb_task['task_description'];

                // Only show if this task description matches one of our filters
                if (isset($task_description_mappings[$task_description])) {
                    $mapping = $task_description_mappings[$task_description];

                    // Check if already completed locally (for user attribution)
                    $locally_completed = $this->is_task_completed($room_details['room_number'], $task_description, $date);

                    $tasks[] = array(
                        'id'        => $nb_task['id'],
                        'name'      => $mapping['name'], // Use mapping display name
                        'color'     => $mapping['color'],
                        'completed' => $nb_task['completed'] || $locally_completed, // NewBook or local completion
                        'source'    => 'newbook'
                    );
                }
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
        // Note: For task completion, we're storing in local DB first
        // Then calling NewBook API. If NewBook fails, transaction rolls back

        // Get current location from context
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        if (!$location_id) {
            return false;
        }

        // Get NewBook API client
        $api = $this->get_newbook_api($location_id);
        if (!$api) {
            return false;
        }

        // Call tasks_update endpoint
        $response = $api->call_api('tasks_update', array(
            'task_id' => $task_id,
            'completed_on' => current_time('mysql')
        ));

        return isset($response['success']) && $response['success'];
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

    /**
     * Get NewBook API client for a location
     */
    private function get_newbook_api($location_id) {
        if (!function_exists('hha')) {
            return null;
        }

        $hotel = hha()->hotels->get($location_id);
        if (!$hotel) {
            return null;
        }

        $integration = hha()->integrations->get_settings($hotel->id, 'newbook');
        if (empty($integration)) {
            return null;
        }

        require_once HHA_PLUGIN_DIR . 'includes/class-hha-newbook-api.php';
        return new HHA_NewBook_API($integration);
    }

    /**
     * Get dates covered by a task
     */
    private function get_task_dates($task) {
        $dates = array();

        // Single-day task
        if (!empty($task['task_when_date'])) {
            $dates[] = date('Y-m-d', strtotime($task['task_when_date']));
            return $dates;
        }

        // Multi-day task
        if (!empty($task['task_period_from']) && !empty($task['task_period_to'])) {
            $start = strtotime($task['task_period_from']);
            $end = strtotime($task['task_period_to']);

            // Period_to is exclusive, so subtract one day
            $end = strtotime('-1 day', $end);

            $current = $start;
            while ($current <= $end) {
                $dates[] = date('Y-m-d', $current);
                $current = strtotime('+1 day', $current);
            }
        }

        return $dates;
    }

    /**
     * Format notes array from NewBook into string
     */
    private function format_notes($notes) {
        if (empty($notes) || !is_array($notes)) {
            return '';
        }

        $formatted = array();
        foreach ($notes as $note) {
            if (isset($note['content']) && !empty($note['content'])) {
                $formatted[] = $note['content'];
            }
        }

        return implode("\n", $formatted);
    }
}
