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
        $counts = $display->render_room_cards($location_id, $date);
        $html = ob_get_clean();

        wp_send_json_success(array(
            'html' => $html,
            'counts' => $counts
        ));
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

        // Prepare data
        $booking_data = $this->filter_booking_data($room_details['booking']);
        $tasks = $this->build_tasks_list($room_details, $task_description_mappings, $date, $location_id);

        // Render modal body HTML
        ob_start();
        $this->render_room_modal_body($room_details, $booking_data, $tasks, $date);
        $html = ob_get_clean();

        wp_send_json_success($html);
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

        // Get hotel and integration settings for task types
        $hotel = $this->get_hotel_from_location($location_id);
        $task_type_ids = array(-1); // Default fallback
        if ($hotel) {
            $integration = hha()->integrations->get_settings($hotel->id, 'newbook');
            $task_type_ids = $this->get_all_task_type_ids($integration);
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

                    // Extract guest name from guests array if not already set
                    $guest_name = '';
                    if (isset($booking['guest_name']) && !empty($booking['guest_name'])) {
                        $guest_name = $booking['guest_name'];
                    } elseif (isset($booking['guests']) && is_array($booking['guests']) && !empty($booking['guests'])) {
                        $first_guest = $booking['guests'][0];
                        $firstname = isset($first_guest['firstname']) ? trim($first_guest['firstname']) : '';
                        $lastname = isset($first_guest['lastname']) ? trim($first_guest['lastname']) : '';
                        if ($firstname || $lastname) {
                            $guest_name = trim($firstname . ' ' . $lastname);
                        }
                    }

                    $booking_data = array(
                        'reference'     => isset($booking['booking_reference_id']) ? $booking['booking_reference_id'] : '',
                        'guest_name'    => $guest_name,
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

        // Get tasks for this room/date using all configured task types
        // Use wider date range to capture rollover tasks (yesterday to today)
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $from_datetime = $yesterday . ' 00:00:00';
        $to_datetime = $date . ' 23:59:59';
        // show_uncomplete=true includes outstanding tasks from before today (rollover tasks)
        $tasks_response = $api->get_tasks($from_datetime, $to_datetime, $task_type_ids, true, null, true);
        $all_tasks = isset($tasks_response['data']) ? $tasks_response['data'] : array();

        $newbook_tasks = array();
        foreach ($all_tasks as $task) {
            // Get site ID from task
            // For booking tasks, use booking_site_id; for site tasks, use task_location_id
            $task_location_type = isset($task['task_location_type']) ? $task['task_location_type'] : '';

            if ($task_location_type === 'bookings' && !empty($task['booking_site_id'])) {
                $task_site_id = $task['booking_site_id'];
            } elseif (!empty($task['task_location_id'])) {
                $task_site_id = $task['task_location_id'];
            } else {
                $task_site_id = '';
            }

            if ($task_site_id !== $room_id) {
                continue;
            }

            // Check if task applies to this date
            $task_dates = $this->get_task_dates($task);

            // Include task if:
            // 1. Today's date is in the task dates (current task)
            // 2. OR task's latest date is before today (rollover/outstanding task)
            $include_task = false;
            if (in_array($date, $task_dates)) {
                $include_task = true;
            } elseif (!empty($task_dates)) {
                $latest_task_date = max($task_dates);
                if ($latest_task_date < $date) {
                    $include_task = true; // Rollover task
                }
            }

            if (!$include_task) {
                continue;
            }

            // Get task period for display
            $task_period = '';
            if (!empty($task['task_when_date'])) {
                $task_period = date('Y-m-d', strtotime($task['task_when_date']));
            } elseif (!empty($task['task_period_from'])) {
                $task_period = date('Y-m-d', strtotime($task['task_period_from']));
            }

            $newbook_tasks[] = array(
                'id'               => $task['task_id'],
                'task_description' => isset($task['task_description']) ? $task['task_description'] : '',
                'task_period'      => $task_period,
                'task_type_id'     => isset($task['task_type_id']) ? $task['task_type_id'] : '',
                'task_location_type' => isset($task['task_location_type']) ? $task['task_location_type'] : '',
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
     * Build tasks list from NewBook tasks filtered by task description
     */
    private function build_tasks_list($room_details, $task_description_mappings, $date, $location_id) {
        $tasks = array();

        // Get task types for this location
        $task_types = HHDL_Settings::get_task_types($location_id);
        $task_types_map = array();
        foreach ($task_types as $task_type) {
            if (isset($task_type['id'])) {
                $task_types_map[$task_type['id']] = isset($task_type['name']) ? $task_type['name'] : '';
            }
        }

        // Show NewBook tasks that match configured task description filters
        if (!empty($room_details['newbook_tasks'])) {
            foreach ($room_details['newbook_tasks'] as $nb_task) {
                $task_description = $nb_task['task_description'];
                $matched_color = '#10b981'; // Default color

                // Check if this task description matches any of our filter patterns
                foreach ($task_description_mappings as $filter => $mapping) {
                    if (stripos($task_description, $filter) !== false) {
                        $matched_color = $mapping['color'];
                        break;
                    }
                }

                // Check if already completed locally (for user attribution)
                $locally_completed = $this->is_task_completed($room_details['room_number'], $task_description, $date);

                // Get task type name from task_type_id
                $task_type_display = 'Task';
                if (!empty($nb_task['task_type_id']) && isset($task_types_map[$nb_task['task_type_id']])) {
                    $task_type_display = $task_types_map[$nb_task['task_type_id']];
                }

                // Check if task is a rollover (task period is before the viewing date)
                $is_rollover = false;
                if (!empty($nb_task['task_period']) && $nb_task['task_period'] < $date) {
                    $is_rollover = true;
                }

                $tasks[] = array(
                    'id'          => $nb_task['id'],
                    'name'        => $task_description, // Use actual task description from NewBook
                    'task_type'   => $task_type_display,
                    'task_period' => isset($nb_task['task_period']) ? $nb_task['task_period'] : '',
                    'color'       => $matched_color,
                    'completed'   => $nb_task['completed'] || $locally_completed, // NewBook or local completion
                    'source'      => 'newbook',
                    'is_rollover' => $is_rollover
                );
            }
        }

        return $tasks;
    }

    /**
     * Get task type name by ID
     *
     * @param string $task_type_id Task type ID
     * @return string Task type name or empty string
     */
    private function get_task_type_name($task_type_id) {
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        if (!$location_id) {
            return '';
        }

        $task_types = HHDL_Settings::get_task_types($location_id);
        foreach ($task_types as $task_type) {
            if ($task_type['id'] === $task_type_id) {
                return isset($task_type['name']) ? $task_type['name'] : '';
            }
        }

        return '';
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
        if (function_exists('wfa_user_can')) {
            return wfa_user_can('hhdl_access_module');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can view guest details
     */
    private function user_can_view_guest_details() {
        if (function_exists('wfa_user_can')) {
            return wfa_user_can('hhdl_view_guest_details');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can view rate details
     */
    private function user_can_view_rate_details() {
        if (function_exists('wfa_user_can')) {
            return wfa_user_can('hhdl_view_rate_details');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can view all notes
     */
    private function user_can_view_all_notes() {
        if (function_exists('wfa_user_can')) {
            return wfa_user_can('hhdl_view_all_notes');
        }
        return current_user_can('edit_posts');
    }

    /**
     * Get hotel from location ID
     */
    private function get_hotel_from_location($location_id) {
        if (!function_exists('hha')) {
            return null;
        }
        return hha()->hotels->get($location_id);
    }

    /**
     * Get all task type IDs from NewBook integration settings
     *
     * @param array $integration NewBook integration settings
     * @return array Array of task type IDs
     */
    private function get_all_task_type_ids($integration) {
        $task_type_ids = array();

        // Get task types from integration settings
        if (isset($integration['task_types']) && is_array($integration['task_types'])) {
            foreach ($integration['task_types'] as $task_type) {
                if (isset($task_type['id'])) {
                    $task_type_ids[] = intval($task_type['id']);
                }
            }
        }

        // If no task types configured, fall back to -1 (housekeeping default)
        if (empty($task_type_ids)) {
            $task_type_ids = array(-1);
        }

        return $task_type_ids;
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

        // Multi-day or period task
        if (!empty($task['task_period_from']) && !empty($task['task_period_to'])) {
            $start = strtotime($task['task_period_from']);
            $end = strtotime($task['task_period_to']);

            // Get date-only parts for comparison
            $start_date = date('Y-m-d', $start);
            $end_date = date('Y-m-d', $end);

            // If dates are the same, it's a single-day task
            if ($start_date === $end_date) {
                $dates[] = $start_date;
            } else {
                // Multi-day: period_to is exclusive, so subtract one day
                $end = strtotime('-1 day', $end);

                $current = $start;
                while ($current <= $end) {
                    $dates[] = date('Y-m-d', $current);
                    $current = strtotime('+1 day', $current);
                }
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

    /**
     * Render room modal body HTML
     */
    private function render_room_modal_body($room_details, $booking_data, $tasks, $date) {
        // Calculate booking status
        $is_arriving = false;
        $is_departing = false;
        $is_stopover = false;

        if ($booking_data) {
            $is_arriving = ($booking_data['checkin_date'] === $date);
            $is_departing = ($booking_data['checkout_date'] === $date);
            $is_stopover = !$is_arriving && !$is_departing;
        }

        // Format occupancy (for now just show pax, can be enhanced with adults/children later)
        $occupancy_text = '';
        if ($booking_data && isset($booking_data['pax']) && $booking_data['pax'] > 0) {
            $occupancy_text = $booking_data['pax'] . ' pax';
        }

        ?>
        <!-- Room Card Header -->
        <div class="hhdl-modal-room-header">
            <div class="hhdl-modal-room-info">
                <span class="hhdl-modal-room-number"><?php echo esc_html($room_details['room_number']); ?></span>
                <?php if ($booking_data): ?>
                    <?php if (isset($booking_data['guest_name']) && !empty($booking_data['guest_name'])): ?>
                        <span class="hhdl-modal-guest-name"><?php echo esc_html($booking_data['guest_name']); ?></span>
                    <?php elseif (isset($booking_data['reference'])): ?>
                        <span class="hhdl-modal-ref-number"><?php echo esc_html($booking_data['reference']); ?></span>
                    <?php endif; ?>
                    <span class="hhdl-modal-nights"><?php echo esc_html($booking_data['current_night']) . '/' . esc_html($booking_data['nights']) . ' nights'; ?></span>
                <?php else: ?>
                    <span class="hhdl-modal-vacant-label"><?php _e('No booking', 'hhdl'); ?></span>
                <?php endif; ?>
                <span class="hhdl-modal-site-status <?php echo esc_attr(strtolower($room_details['site_status'])); ?>">
                    <?php echo esc_html($room_details['site_status']); ?>
                </span>
            </div>

            <?php if ($booking_data): ?>
            <div class="hhdl-modal-room-stats">
                <?php if (!empty($booking_data['checkin_time'])): ?>
                    <span class="hhdl-modal-stat hhdl-modal-checkin-time <?php echo $is_arriving ? 'hhdl-modal-is-arriving' : ''; ?>">
                        <span class="material-symbols-outlined">schedule</span>
                        <?php echo esc_html($booking_data['checkin_time']); ?>
                    </span>
                <?php endif; ?>

                <!-- Bedding type placeholder - to be implemented -->
                <span class="hhdl-modal-stat hhdl-modal-bedding">
                    <span class="material-symbols-outlined">bed</span>
                </span>

                <?php if (!empty($occupancy_text)): ?>
                    <span class="hhdl-modal-stat hhdl-modal-occupancy">
                        <span class="material-symbols-outlined">group</span>
                        <?php echo esc_html($occupancy_text); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tasks Section -->
        <section class="hhdl-tasks-section">
            <h3><?php _e('NewBook Tasks', 'hhdl'); ?></h3>
            <?php if (!empty($tasks)): ?>
            <div class="hhdl-task-list">
                <?php foreach ($tasks as $task): ?>
                <div class="hhdl-task-item <?php echo $task['completed'] ? 'completed' : ''; ?>"
                     style="border-left-color: <?php echo esc_attr($task['color']); ?>;">
                    <input type="checkbox"
                           class="hhdl-task-checkbox"
                           <?php checked($task['completed']); ?>
                           <?php disabled($task['completed']); ?>
                           data-room-id="<?php echo esc_attr($room_details['room_id']); ?>"
                           data-task-id="<?php echo esc_attr($task['id']); ?>"
                           data-task-type="<?php echo esc_attr($task['name']); ?>"
                           data-booking-ref="<?php echo isset($booking_data['reference']) ? esc_attr($booking_data['reference']) : ''; ?>">
                    <div class="hhdl-task-content">
                        <span class="hhdl-task-name">
                            <?php echo esc_html($task['name']); ?>
                            <?php if (!empty($task['is_rollover'])): ?>
                                <span class="material-symbols-outlined hhdl-rollover-icon" title="<?php esc_attr_e('Rollover task from previous day', 'hhdl'); ?>">step_over</span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($task['task_type']) || !empty($task['task_period'])): ?>
                        <span class="hhdl-task-meta">
                            <?php
                            $meta_parts = array();
                            if (!empty($task['task_type'])) {
                                $meta_parts[] = esc_html($task['task_type']);
                            }
                            if (!empty($task['task_period'])) {
                                $meta_parts[] = esc_html($task['task_period']);
                            }
                            echo implode(' - ', $meta_parts);
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p><?php _e('No tasks configured for this room.', 'hhdl'); ?></p>
            <?php endif; ?>
        </section>

        <!-- Placeholder Sections -->
        <section class="hhdl-placeholder">
            <h3><?php _e('Recurring Tasks', 'hhdl'); ?></h3>
            <p class="hhdl-placeholder-text"><?php _e('Future module integration', 'hhdl'); ?></p>
        </section>

        <section class="hhdl-placeholder">
            <h3><?php _e('Spoilt Linen Tracking', 'hhdl'); ?></h3>
            <p class="hhdl-placeholder-text"><?php _e('Future module integration', 'hhdl'); ?></p>
        </section>
        <?php
    }
}
