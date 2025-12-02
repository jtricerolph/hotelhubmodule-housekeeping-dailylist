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
        add_action('wp_ajax_hhdl_update_room_status', array($this, 'update_room_status'));
        add_action('wp_ajax_hhdl_save_user_preferences', array($this, 'save_user_preferences'));
        add_action('wp_ajax_hhdl_reset_user_preferences', array($this, 'reset_user_preferences'));
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

        // Render modal header HTML
        ob_start();
        $this->render_room_modal_header($room_details, $booking_data, $date);
        $header_html = ob_get_clean();

        // Render modal body HTML
        ob_start();
        $this->render_room_modal_body($room_details, $booking_data, $tasks, $date, $location_id);
        $body_html = ob_get_clean();

        wp_send_json_success(array(
            'header' => $header_html,
            'body' => $body_html
        ));
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
        $task_type_id = isset($_POST['task_type_id']) ? intval($_POST['task_type_id']) : null;
        $task_description = isset($_POST['task_description']) ? sanitize_text_field($_POST['task_description']) : '';
        $booking_ref = isset($_POST['booking_ref']) ? sanitize_text_field($_POST['booking_ref']) : '';
        $service_date = isset($_POST['service_date']) ? sanitize_text_field($_POST['service_date']) : date('Y-m-d');

        if (!$location_id || !$room_id || !$task_description) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'hhdl')));
        }

        // STEP 1: Update task in NewBook FIRST (if task_id provided)
        $site_status = '';
        if ($task_id > 0) {
            // Get NewBook API client
            $api = $this->get_newbook_api($location_id);
            if (!$api) {
                wp_send_json_error(array('message' => __('NewBook API not configured', 'hhdl')));
            }

            // Call NewBook API using the new update_task method
            $response = $api->update_task($task_id, current_time('mysql'));

            // Check NewBook response
            if (!$response['success'] || $response['message'] !== 'Updated Task') {
                $error_msg = isset($response['message']) ? $response['message'] : __('Unknown error', 'hhdl');
                error_log('HHDL: NewBook task update failed: ' . $error_msg);
                wp_send_json_error(array('message' => sprintf(__('Failed to update NewBook: %s', 'hhdl'), $error_msg)));
            }

            // Extract site_status from response
            if (isset($response['data']['site_status'])) {
                $site_status = $response['data']['site_status'];
            }
        }

        // STEP 2: ONLY NOW save to local database after NewBook confirms success
        $wpdb->query('START TRANSACTION');

        try {
            // Check for existing completion (with row lock)
            // IMPORTANT: Use task_id for duplicate check, not task_type
            // Multiple tasks can have the same description for the same room/date
            $table_name = $wpdb->prefix . 'hhdl_task_completions';
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_name}
                 WHERE task_id = %d AND service_date = %s
                 FOR UPDATE",
                $task_id,
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
                    'location_id'      => $location_id,
                    'room_id'          => $room_id,
                    'task_id'          => $task_id,
                    'task_type_id'     => $task_type_id,
                    'task_description' => $task_description,
                    'completed_by'     => get_current_user_id(),
                    'completed_at'     => current_time('mysql'),
                    'booking_ref'      => $booking_ref,
                    'service_date'     => $service_date
                ),
                array('%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s')
            );

            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                wp_send_json_error(array('message' => __('Failed to save completion', 'hhdl')));
            }

            // Commit transaction
            $wpdb->query('COMMIT');

            // Get user info for response
            $user = wp_get_current_user();

            wp_send_json_success(array(
                'message'       => __('Task completed successfully', 'hhdl'),
                'completed_by'  => $user->display_name,
                'completed_at'  => current_time('mysql'),
                'completion_id' => $wpdb->insert_id,
                'site_status'   => $site_status // Return site_status to frontend
            ));

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Update room status (AJAX handler)
     */
    public function update_room_status() {
        try {
            // Verify nonce
            check_ajax_referer('hhdl_ajax_nonce', 'nonce');

            // Check permissions
            if (!$this->user_can_access()) {
                wp_send_json_error(array('message' => __('Permission denied', 'hhdl')));
            }

            // Get parameters
            $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
            $room_id = isset($_POST['room_id']) ? sanitize_text_field($_POST['room_id']) : '';
            $site_status = isset($_POST['site_status']) ? sanitize_text_field($_POST['site_status']) : '';

            // Validate parameters
            if (!$location_id || !$room_id || !$site_status) {
                wp_send_json_error(array('message' => __('Invalid parameters', 'hhdl')));
            }

            // Validate status value
            $valid_statuses = array('Clean', 'Dirty', 'Inspected');
            if (!in_array($site_status, $valid_statuses)) {
                wp_send_json_error(array('message' => __('Invalid status value', 'hhdl')));
            }

            // Get NewBook API client
            $api = $this->get_newbook_api($location_id);
            if (!$api) {
                wp_send_json_error(array('message' => __('NewBook API not configured', 'hhdl')));
            }

            // Call NewBook API to update site status
            $response = $api->update_site_status($room_id, $site_status);

            if (!$response['success']) {
                $error_msg = isset($response['message']) ? $response['message'] : __('Unknown error', 'hhdl');
                wp_send_json_error(array('message' => sprintf(__('Failed to update NewBook: %s', 'hhdl'), $error_msg)));
            }

            wp_send_json_success(array(
                'message' => sprintf(__('Room status updated to %s', 'hhdl'), $site_status),
                'site_status' => $site_status
            ));
        } catch (Exception $e) {
            error_log('HHDL: Exception in update_room_status: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Server error: ' . $e->getMessage()));
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

        // Get all bookings for yesterday through tomorrow to capture:
        // - Departures today (even if already departed)
        // - Arrivals today
        // - Staying bookings covering today
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));

        // Single API call to get all bookings in range
        // 'staying' returns all active bookings (confirmed, unconfirmed, arrived, departed)
        // and excludes cancelled, no_show, quote, waitlist, owner_occupied
        $bookings_response = $api->get_bookings($yesterday, $tomorrow, 'staying', true);
        $all_bookings = isset($bookings_response['data']) ? $bookings_response['data'] : array();

        $staying_booking = null;
        $departing_booking = null;
        $arriving_booking = null;

        // First pass: identify departing, arriving, and staying bookings for this room
        $has_departure_today = false;
        foreach ($all_bookings as $booking) {
            if (isset($booking['site_id']) && $booking['site_id'] === $room_id) {
                $arrival = date('Y-m-d', strtotime($booking['booking_arrival']));
                $departure = date('Y-m-d', strtotime($booking['booking_departure']));

                // Check for any booking ending today (includes already departed)
                if ($departure === $date) {
                    $has_departure_today = true;
                    if ($arrival < $date) {
                        $departing_booking = $booking;
                    }
                }

                // Check for arriving booking (checkin today)
                if ($arrival === $date) {
                    $arriving_booking = $booking;
                }

                // Check for staying booking (covers this date)
                if ($arrival < $date && $departure > $date) {
                    $staying_booking = $booking;
                }
            }
        }

        // Determine booking flow type
        $booking_flow_type = null;
        $primary_booking = null;

        // If there's an arrival today AND any departure today, it's back-to-back
        // This handles cases where the departing booking has already checked out
        if ($arriving_booking && $has_departure_today) {
            // Back-to-back scenario (even if previous guest already departed)
            $booking_flow_type = 'depart_arrive';
            $primary_booking = $arriving_booking; // Use arriving booking as primary
        } elseif ($arriving_booking) {
            $booking_flow_type = 'arrive';
            $primary_booking = $arriving_booking;
        } elseif ($departing_booking) {
            // DEPART: Guest checking out today, room is vacant tonight
            // Don't set primary_booking so modal shows "No booking" instead of departing guest details
            $booking_flow_type = 'depart';
            $primary_booking = null; // Room is vacant for tonight
        } elseif ($staying_booking) {
            $booking_flow_type = 'stay_over';
            $primary_booking = $staying_booking;
        }

        // Process the primary booking
        $booking_data = null;
        if ($primary_booking) {
            $booking = $primary_booking;
            $arrival = date('Y-m-d', strtotime($booking['booking_arrival']));
            $departure = date('Y-m-d', strtotime($booking['booking_departure']));

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

            // Detect twin/sofabed configuration
            $twin_detection = $this->detect_twin($booking, $location_id);
            $has_twin = ($twin_detection['type'] === 'confirmed' || $twin_detection['type'] === 'potential');

            // Detect extra bed
            $extra_bed_detection = $this->detect_extra_bed($booking, $location_id);

            // Early arrival detection (only for arrivals)
            $is_arriving = ($arrival === $date);
            $checkin_time = '';
            $is_early_arrival = false;

            if ($is_arriving && $hotel) {
                $default_arrival_time = isset($hotel->default_arrival_time) ? $hotel->default_arrival_time : '15:00';

                // Extract time from booking_eta
                $eta_time = null;
                if (isset($booking['booking_eta']) && !empty($booking['booking_eta'])) {
                    $eta_time = date('H:i', strtotime($booking['booking_eta']));
                }

                // Extract time from booking_arrival
                $arrival_time = null;
                if (isset($booking['booking_arrival']) && !empty($booking['booking_arrival'])) {
                    $arrival_time = date('H:i', strtotime($booking['booking_arrival']));
                }

                // Check if either is before default time
                if ($eta_time && $eta_time < $default_arrival_time) {
                    $is_early_arrival = true;
                    $checkin_time = $eta_time;
                } elseif ($arrival_time && $arrival_time < $default_arrival_time) {
                    $is_early_arrival = true;
                    $checkin_time = $arrival_time;
                } elseif ($eta_time) {
                    $checkin_time = $eta_time;
                } elseif ($arrival_time) {
                    $checkin_time = $arrival_time;
                }
            }

            $booking_data = array(
                'reference'         => isset($booking['booking_reference_id']) ? $booking['booking_reference_id'] : '',
                'guest_name'        => $guest_name,
                'email'             => isset($booking['guest_email']) ? $booking['guest_email'] : '',
                'phone'             => isset($booking['guest_phone']) ? $booking['guest_phone'] : '',
                'checkin_date'      => $arrival,
                'checkout_date'     => $departure,
                'checkin_time'      => $checkin_time,
                'is_early_arrival'  => $is_early_arrival,
                'checkout_time'     => '10:00', // Default checkout
                'pax'               => isset($booking['pax']) ? $booking['pax'] : 0,
                'nights'            => $total_nights,
                'current_night'     => $current_night,
                'room_type'         => isset($booking['site_category_name']) ? $booking['site_category_name'] : '',
                'rate_plan'         => isset($booking['rate_plan_name']) ? $booking['rate_plan_name'] : '',
                'rate_amount'       => isset($booking['rate_amount']) ? $booking['rate_amount'] : 0,
                'notes'             => isset($booking['notes']) && is_array($booking['notes']) ? $booking['notes'] : array(),
                'booking_status'    => isset($booking['booking_status']) ? strtolower($booking['booking_status']) : 'unconfirmed',
                'has_twin'          => $has_twin,
                'twin_info'         => $twin_detection,
                'extra_bed_info'    => $extra_bed_detection,
                'booking_flow_type' => $booking_flow_type
            );
        } elseif ($booking_flow_type === 'depart') {
            // For DEPART flow, create minimal booking_data with only flow_type
            // This shows the DEPART label but "No booking" for nights/details
            $booking_data = array(
                'booking_flow_type' => $booking_flow_type
            );
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
                'task_location_occupy' => isset($task['task_location_occupy']) ? $task['task_location_occupy'] : 0,
                'completed'        => isset($task['task_completed_on']) && !empty($task['task_completed_on'])
            );
        }

        return array(
            'room_id'       => $room_id,
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

        // Handle minimal booking_data (DEPART flow with only flow_type)
        if (isset($booking['booking_flow_type']) && count($booking) === 1) {
            return array(
                'booking_flow_type' => $booking['booking_flow_type']
            );
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
        $filtered['is_early_arrival'] = isset($booking['is_early_arrival']) ? $booking['is_early_arrival'] : false;
        $filtered['checkout_time'] = $booking['checkout_time'];
        $filtered['pax'] = $booking['pax'];
        $filtered['nights'] = $booking['nights'];
        $filtered['current_night'] = $booking['current_night'];
        $filtered['room_type'] = $booking['room_type'];
        $filtered['booking_status'] = isset($booking['booking_status']) ? $booking['booking_status'] : 'unconfirmed';
        $filtered['booking_flow_type'] = isset($booking['booking_flow_type']) ? $booking['booking_flow_type'] : null;

        // Bed type detection data (always visible for housekeeping)
        $filtered['has_twin'] = isset($booking['has_twin']) ? $booking['has_twin'] : false;
        $filtered['twin_info'] = isset($booking['twin_info']) ? $booking['twin_info'] : array('type' => 'none', 'matched_term' => '', 'source' => '');
        $filtered['extra_bed_info'] = isset($booking['extra_bed_info']) ? $booking['extra_bed_info'] : array('has_extra_bed' => false, 'matched_term' => '', 'source' => '');

        if ($can_view_rates) {
            $filtered['rate_plan'] = $booking['rate_plan'];
            $filtered['rate_amount'] = $booking['rate_amount'];
        }

        // Always include notes as an array (filter by permission if needed)
        if (isset($booking['notes']) && is_array($booking['notes'])) {
            if ($can_view_all_notes) {
                $filtered['notes'] = $booking['notes'];
            } else {
                // Filter to only show notes the user has permission to view
                $filtered['notes'] = $this->filter_housekeeping_notes($booking['notes']);
            }
        } else {
            $filtered['notes'] = array();
        }

        return $filtered;
    }

    /**
     * Build tasks list from NewBook tasks filtered by task description
     */
    private function build_tasks_list($room_details, $task_description_mappings, $date, $location_id) {
        $tasks = array();

        // Check if viewing a future date
        $today = date('Y-m-d');
        $is_future_date = ($date > $today);

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

                // Check if task is a rollover (task period is before the viewing date)
                $is_rollover = false;
                if (!empty($nb_task['task_period']) && $nb_task['task_period'] < $date) {
                    $is_rollover = true;
                }

                // Skip rollover tasks when viewing future dates
                if ($is_future_date && $is_rollover) {
                    continue;
                }

                $matched_color = '#10b981'; // Default color
                $is_default_task = false;

                // Check if this task description matches any of our filter patterns
                foreach ($task_description_mappings as $filter => $mapping) {
                    if (stripos($task_description, $filter) !== false) {
                        $matched_color = $mapping['color'];
                        $is_default_task = true;
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

                // Check if task occupies the site
                $is_occupy_task = !empty($nb_task['task_location_occupy']) && $nb_task['task_location_occupy'] == 1;

                $tasks[] = array(
                    'id'          => $nb_task['id'],
                    'name'        => $task_description, // Use actual task description from NewBook
                    'task_type_id' => isset($nb_task['task_type_id']) ? $nb_task['task_type_id'] : null,
                    'task_type'   => $task_type_display,
                    'task_period' => isset($nb_task['task_period']) ? $nb_task['task_period'] : '',
                    'color'       => $matched_color,
                    'completed'   => $nb_task['completed'] || $locally_completed, // NewBook or local completion
                    'source'      => 'newbook',
                    'is_rollover' => $is_rollover,
                    'is_default_task' => $is_default_task,
                    'is_occupy_task' => $is_occupy_task
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
    private function is_task_completed($room_id, $task_description, $service_date) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'hhdl_task_completions';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name}
             WHERE room_id = %s AND task_description = %s AND service_date = %s",
            $room_id,
            $task_description,
            $service_date
        ));

        return $exists ? true : false;
    }

    /**
     * Filter notes to show only those the user has permission to view
     */
    private function filter_housekeeping_notes($notes) {
        if (empty($notes) || !is_array($notes)) {
            return array();
        }

        $filtered = array();
        foreach ($notes as $note) {
            if (!isset($note['type_id'])) {
                continue;
            }

            // Check if user has permission to view this note type
            if ($this->user_can_view_note_type($note['type_id'])) {
                $filtered[] = $note;
            }
        }

        return $filtered;
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

                // Normalize to midnight to ensure date-only comparison
                $current = strtotime('midnight', $start);
                $end = strtotime('midnight', $end);

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
     * Render room modal header HTML
     */
    private function render_room_modal_header($room_details, $booking_data, $date) {
        // Calculate booking status
        $is_arriving = false;
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if ($booking_data && isset($booking_data['checkin_date'])) {
            $is_arriving = ($booking_data['checkin_date'] === $date);
        }

        // Format occupancy (for now just show pax, can be enhanced with adults/children later)
        $occupancy_text = '';
        if ($booking_data && isset($booking_data['pax']) && $booking_data['pax'] > 0) {
            $occupancy_text = $booking_data['pax'] . ' pax';
        }

        // Check if viewing a future date
        $today = date('Y-m-d');
        $is_future_date = ($date > $today);
        $is_viewing_today = ($date === $today);

        // Check if booking is arrived (checked in)
        $booking_status = $booking_data && isset($booking_data['booking_status']) ? $booking_data['booking_status'] : '';
        $show_arrived = ($booking_status === 'arrived');

        // Get booking flow type
        $booking_flow_type = $booking_data && isset($booking_data['booking_flow_type']) ? $booking_data['booking_flow_type'] : null;
        $flow_label = '';
        $flow_class = '';

        if ($booking_flow_type) {
            switch ($booking_flow_type) {
                case 'stay_over':
                    $flow_label = __('STAY OVER', 'hhdl');
                    $flow_class = 'stay-over';
                    break;
                case 'depart_arrive':
                    $flow_label = __('DEPART/ARRIVE', 'hhdl');
                    $flow_class = 'depart-arrive';
                    break;
                case 'arrive':
                    $flow_label = __('ARRIVE', 'hhdl');
                    $flow_class = 'arrive';
                    break;
                case 'depart':
                    $flow_label = __('DEPART', 'hhdl');
                    $flow_class = 'depart';
                    break;
            }
        }

        ?>
        <div class="hhdl-modal-header-content">
            <div class="hhdl-modal-room-info">
                <span class="hhdl-modal-room-number"><?php echo esc_html($room_details['room_number']); ?></span>
                <?php if ($flow_label): ?>
                    <span class="hhdl-modal-flow-label <?php echo esc_attr($flow_class); ?>"><?php echo esc_html($flow_label); ?></span>
                <?php endif; ?>
                <?php if (!$booking_data || !isset($booking_data['nights'])): ?>
                    <span class="hhdl-modal-vacant-label"><?php _e('No booking', 'hhdl'); ?></span>
                <?php endif; ?>
                <div class="hhdl-modal-right-group">
                    <?php if ($booking_data && isset($booking_data['nights'])): ?>
                        <span class="hhdl-modal-nights">
                            <span class="material-symbols-outlined">bedtime</span>
                            <?php echo esc_html($booking_data['current_night']) . '/' . esc_html($booking_data['nights']); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($is_future_date): ?>
                        <!-- Empty spacer for future dates (like main list) -->
                        <span class="hhdl-modal-status-spacer"></span>
                    <?php elseif ($show_arrived): ?>
                        <!-- Show ARRIVED for checked-in bookings -->
                        <span class="hhdl-modal-site-status arrived">ARRIVED</span>
                    <?php elseif ($is_viewing_today && $is_arriving): ?>
                        <!-- Show site status for today's arrivals (if not unknown) -->
                        <?php if (strtolower($room_details['site_status']) !== 'unknown'): ?>
                            <span class="hhdl-modal-site-status hhdl-status-toggle-btn <?php echo esc_attr(strtolower($room_details['site_status'])); ?>"
                                  data-room-id="<?php echo esc_attr($room_details['room_id']); ?>"
                                  data-current-status="<?php echo esc_attr($room_details['site_status']); ?>"
                                  data-has-tasks="<?php echo !empty($tasks) ? 'true' : 'false'; ?>">
                                <?php echo esc_html($room_details['site_status']); ?>
                            </span>
                        <?php endif; ?>
                    <?php elseif ($is_viewing_today && (!$booking_data || !isset($booking_data['nights']))): ?>
                        <!-- Show site status for vacant rooms and DEPART flow on today's date (always show, even if unknown) -->
                        <span class="hhdl-modal-site-status hhdl-status-toggle-btn <?php echo esc_attr(strtolower($room_details['site_status'])); ?>"
                              data-room-id="<?php echo esc_attr($room_details['room_id']); ?>"
                              data-current-status="<?php echo esc_attr($room_details['site_status']); ?>"
                              data-has-tasks="<?php echo !empty($tasks) ? 'true' : 'false'; ?>">
                            <?php echo esc_html($room_details['site_status']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($booking_data && isset($booking_data['nights'])): ?>
            <div class="hhdl-modal-room-stats">
                <!-- Arrival Time -->
                <?php if ($is_arriving && !empty($booking_data['checkin_time'])): ?>
                    <?php
                    $early_class = 'hhdl-modal-stat-content hhdl-modal-checkin-time';
                    if (isset($booking_data['is_early_arrival']) && $booking_data['is_early_arrival']) {
                        $early_class .= ' hhdl-modal-early-arrival';
                    }
                    ?>
                    <div class="hhdl-modal-stat-block">
                        <div class="<?php echo esc_attr($early_class); ?>">
                            <span class="material-symbols-outlined">schedule</span>
                            <span class="hhdl-time-text"><?php echo esc_html($booking_data['checkin_time']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Bed Type Indicator (show for all bookings, not just arrivals) -->
                <?php if (true): ?>
                    <?php
                    // Get location settings for bed colors
                    $bed_settings = HHDL_Settings::get_location_settings($location_id);

                    // Get detection info
                    $twin_info = isset($booking_data['twin_info']) ? $booking_data['twin_info'] : array('type' => 'none', 'matched_term' => '', 'source' => '');
                    $extra_bed_info = isset($booking_data['extra_bed_info']) ? $booking_data['extra_bed_info'] : array('has_extra_bed' => false, 'matched_term' => '', 'source' => '');
                    $has_twin = isset($booking_data['has_twin']) ? $booking_data['has_twin'] : false;

                    // Determine bed type and color based on priority
                    $bed_color = $bed_settings['bed_color_default']; // Default
                    $bed_title_parts = array();

                    // Check twin status
                    if ($twin_info['type'] === 'confirmed') {
                        $bed_color = $bed_settings['bed_color_twin_confirmed'];
                        $source_display = ($twin_info['source'] === 'custom_field') ? 'custom field' : 'booking notes';
                        $bed_title_parts[] = sprintf(__('Confirmed Twin - Found "%s" in %s', 'hhdl'), $twin_info['matched_term'], $source_display);
                    } elseif ($extra_bed_info['has_extra_bed']) {
                        $bed_color = $bed_settings['bed_color_extra'];
                        $source_display = ($extra_bed_info['source'] === 'custom_field') ? 'custom field' : 'booking notes';
                        $bed_title_parts[] = sprintf(__('Extra Bed - Found "%s" in %s', 'hhdl'), $extra_bed_info['matched_term'], $source_display);
                    } elseif ($twin_info['type'] === 'potential') {
                        $bed_color = $bed_settings['bed_color_twin_potential'];
                        $source_display = ($twin_info['source'] === 'custom_field') ? 'custom field' : 'booking notes';
                        $bed_title_parts[] = sprintf(__('Potential Twin - Found "%s" in %s (verify bed type)', 'hhdl'), $twin_info['matched_term'], $source_display);
                    } else {
                        $bed_title_parts[] = __('Standard Double Bed', 'hhdl');
                    }

                    // Add extra bed to title if detected (in addition to bed type)
                    if ($extra_bed_info['has_extra_bed'] && $twin_info['type'] !== 'none') {
                        $source_display = ($extra_bed_info['source'] === 'custom_field') ? 'custom field' : 'booking notes';
                        $bed_title_parts[] = sprintf(__('Extra Bed - Found "%s" in %s', 'hhdl'), $extra_bed_info['matched_term'], $source_display);
                    }

                    $bed_title = implode(' | ', $bed_title_parts);

                    // Build inline style for color
                    $bed_inline_style = sprintf('color: %s; border-color: %s;', $bed_color, $bed_color);
                    ?>
                    <div class="hhdl-modal-stat-block">
                        <div class="hhdl-modal-stat-content hhdl-modal-bed-type"
                             title="<?php echo esc_attr($bed_title); ?>"
                             style="<?php echo esc_attr($bed_inline_style); ?>">
                            <?php if ($has_twin): ?>
                                <!-- Twin beds: 2x single_bed symbols -->
                                <span class="material-symbols-outlined">single_bed</span>
                                <span class="material-symbols-outlined">single_bed</span>
                            <?php else: ?>
                                <!-- Default double bed: king_bed symbol -->
                                <span class="material-symbols-outlined">king_bed</span>
                            <?php endif; ?>
                            <?php if ($extra_bed_info['has_extra_bed']): ?>
                                <!-- Extra bed indicator: + chair symbol -->
                                <span class="hhdl-extra-bed-separator">+</span>
                                <span class="material-symbols-outlined">chair</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Occupancy -->
                <?php if (!empty($occupancy_text)): ?>
                    <div class="hhdl-modal-stat-block">
                        <div class="hhdl-modal-stat-content hhdl-modal-occupancy">
                            <span class="material-symbols-outlined">group</span>
                            <?php echo esc_html($occupancy_text); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <button class="hhdl-modal-close" aria-label="<?php esc_attr_e('Close', 'hhdl'); ?>">&times;</button>
        <?php
    }

    /**
     * Render room modal body HTML
     */
    private function render_room_modal_body($room_details, $booking_data, $tasks, $date, $location_id) {
        // Render notes section (if applicable note types exist)
        $this->render_notes_section($booking_data, $location_id);
        ?>
        <!-- Tasks Section -->
        <section class="hhdl-tasks-section">
            <div class="hhdl-section-header">
                <h3>
                    <?php _e('NewBook Tasks', 'hhdl'); ?>
                    <?php
                // Check if viewing a future date
                $today = date('Y-m-d');
                $is_future_date = ($date > $today);

                // Determine task status and count
                $incomplete_count = 0;
                if (!empty($tasks)) {
                    foreach ($tasks as $task) {
                        if (empty($task['completed'])) {
                            $incomplete_count++;
                        }
                    }
                }

                // Show icon based on date and incomplete task count
                if ($is_future_date) {
                    // Future dates: grey styling (tasks not final yet)
                    if ($incomplete_count > 0) {
                        $badge_class = 'hhdl-task-late'; // For badge background color
                        $icon_name = 'assignment';
                        $icon_color = '#9ca3af'; // Grey
                        ?>
                        <span class="<?php echo esc_attr($badge_class); ?>" style="position: relative; display: inline-flex;">
                            <span class="material-symbols-outlined" style="color: <?php echo esc_attr($icon_color); ?>; font-size: 16px;"><?php echo esc_html($icon_name); ?></span>
                            <span class="hhdl-task-count-badge" style="background: <?php echo esc_attr($icon_color); ?>;"><?php echo esc_html($incomplete_count); ?></span>
                        </span>
                    <?php } else { ?>
                        <!-- No tasks scheduled - grey -->
                        <span class="material-symbols-outlined" style="color: #9ca3af; font-size: 16px;">assignment_turned_in</span>
                    <?php } ?>
                <?php } elseif ($incomplete_count > 0) { ?>
                    <!-- Outstanding tasks - red assignment_late icon with badge -->
                    <span class="hhdl-task-late" style="position: relative; display: inline-flex;">
                        <span class="material-symbols-outlined" style="color: #dc2626; font-size: 16px;">assignment_late</span>
                        <span class="hhdl-task-count-badge"><?php echo esc_html($incomplete_count); ?></span>
                    </span>
                <?php } else { ?>
                    <!-- All complete or no tasks - green assignment_turned_in icon -->
                    <span class="material-symbols-outlined" style="color: #10b981; font-size: 16px;">assignment_turned_in</span>
                <?php } ?>
                </h3>
            </div>
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
                           data-task-type-id="<?php echo isset($task['task_type_id']) ? esc_attr($task['task_type_id']) : ''; ?>"
                           data-task-description="<?php echo esc_attr($task['name']); ?>"
                           data-booking-ref="<?php echo isset($booking_data['reference']) ? esc_attr($booking_data['reference']) : ''; ?>"
                           data-is-default="<?php echo $task['is_default_task'] ? '1' : '0'; ?>"
                           data-is-occupy="<?php echo $task['is_occupy_task'] ? '1' : '0'; ?>">
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
                <?php if ($room_details['site_status'] === 'Dirty'): ?>
                    <!-- Show clickable card to mark room as clean when no tasks and room is dirty -->
                    <div class="hhdl-task-item hhdl-status-change-card"
                         data-room-id="<?php echo esc_attr($room_details['room_id']); ?>"
                         data-current-status="Dirty"
                         data-new-status="Clean"
                         style="border-left-color: #10b981; cursor: pointer;">
                        <div class="hhdl-task-content">
                            <span class="hhdl-task-name" style="font-weight: 600;">
                                <?php _e('No tasks but still marked Dirty', 'hhdl'); ?>
                            </span>
                            <span class="hhdl-task-meta" style="color: #10b981;">
                                <?php _e('Update NewBook to mark room clean', 'hhdl'); ?>
                            </span>
                        </div>
                        <span class="material-symbols-outlined" style="color: #10b981;">arrow_forward</span>
                    </div>
                <?php else: ?>
                    <p><?php _e('No outstanding housekeeping tasks', 'hhdl'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- Placeholder Sections -->
        <section class="hhdl-placeholder">
            <div class="hhdl-section-header">
                <h3><?php _e('Recurring Tasks', 'hhdl'); ?></h3>
            </div>
            <p class="hhdl-placeholder-text"><?php _e('Future module integration', 'hhdl'); ?></p>
        </section>

        <section class="hhdl-placeholder">
            <div class="hhdl-section-header">
                <h3><?php _e('Spoilt Linen Tracking', 'hhdl'); ?></h3>
            </div>
            <p class="hhdl-placeholder-text"><?php _e('Future module integration', 'hhdl'); ?></p>
        </section>
        <?php
    }

    /**
     * Detect twin/sofabed from booking using Daily List module settings
     *
     * @param array $booking Booking data from NewBook API
     * @param int $hotel_id Hotel Hub hotel ID
     * @return array Detection result with 'type' and 'matched_term' keys
     */
    private function detect_twin($booking, $hotel_id) {
        // Get Daily List settings for this location
        $settings = HHDL_Settings::get_location_settings($hotel_id);

        // Parse settings into arrays
        $custom_field_names = !empty($settings['twin_custom_field_names']) ?
            array_map('trim', explode(',', $settings['twin_custom_field_names'])) : array();
        $custom_field_values = !empty($settings['twin_custom_field_values']) ?
            array_map('trim', explode(',', $settings['twin_custom_field_values'])) : array();
        $notes_search_terms = !empty($settings['twin_notes_search_terms']) ?
            array_map('trim', explode(',', $settings['twin_notes_search_terms'])) : array();
        $excluded_terms = !empty($settings['twin_excluded_terms']) ?
            array_map('trim', explode(',', $settings['twin_excluded_terms'])) : array();

        // PRIMARY DETECTION: Check booking custom fields with configured field names and values
        if (!empty($booking['custom_fields']) && !empty($custom_field_names) && !empty($custom_field_values)) {
            foreach ($booking['custom_fields'] as $custom_field) {
                // Get field label (NewBook uses 'label' for field name)
                $field_label = isset($custom_field['label']) ? $custom_field['label'] : '';

                // Check if this field label matches any configured names
                foreach ($custom_field_names as $field_name) {
                    if ($field_label === $field_name) {
                        // Check if field value contains any configured search values
                        $field_value = isset($custom_field['value']) ? strtolower($custom_field['value']) : '';
                        foreach ($custom_field_values as $search_value) {
                            $search_value_lower = strtolower($search_value);
                            if (strpos($field_value, $search_value_lower) !== false) {
                                return array(
                                    'type' => 'confirmed',
                                    'matched_term' => $search_value,
                                    'source' => 'custom_field'
                                );
                            }
                        }
                    }
                }
            }
        }

        // POTENTIAL DETECTION: Check booking notes for configured search terms
        // First apply excluded terms logic, then search for match terms
        if (!empty($booking['notes']) && !empty($notes_search_terms)) {
            foreach ($booking['notes'] as $note) {
                $note_content = isset($note['content']) ? $note['content'] : '';
                if (empty($note_content)) {
                    continue;
                }

                // Apply excluded terms logic (case-sensitive removal)
                $cleaned_note = $note_content;
                if (!empty($excluded_terms)) {
                    foreach ($excluded_terms as $excluded_term) {
                        // Remove excluded term (case-sensitive)
                        $cleaned_note = str_replace($excluded_term, '', $cleaned_note);
                    }
                }

                // Now search for match terms in the cleaned note (case-insensitive)
                $cleaned_note_lower = strtolower($cleaned_note);
                foreach ($notes_search_terms as $search_term) {
                    $search_term_lower = strtolower($search_term);
                    if (strpos($cleaned_note_lower, $search_term_lower) !== false) {
                        return array(
                            'type' => 'potential',
                            'matched_term' => $search_term,
                            'source' => 'booking_notes'
                        );
                    }
                }
            }
        }

        // No twin detected
        return array(
            'type' => 'none',
            'matched_term' => '',
            'source' => ''
        );
    }

    /**
     * Detect extra bed/sofabed from booking using Daily List module settings
     *
     * @param array $booking Booking data from NewBook API
     * @param int $hotel_id Hotel Hub hotel ID
     * @return array Detection result with 'has_extra_bed' and 'matched_term' keys
     */
    private function detect_extra_bed($booking, $hotel_id) {
        // Get Daily List settings for this location
        $settings = HHDL_Settings::get_location_settings($hotel_id);

        // Parse settings into arrays
        $custom_field_names = !empty($settings['extra_bed_custom_field_names']) ?
            array_map('trim', explode(',', $settings['extra_bed_custom_field_names'])) : array();
        $custom_field_values = !empty($settings['extra_bed_custom_field_values']) ?
            array_map('trim', explode(',', $settings['extra_bed_custom_field_values'])) : array();
        $notes_search_terms = !empty($settings['extra_bed_notes_search_terms']) ?
            array_map('trim', explode(',', $settings['extra_bed_notes_search_terms'])) : array();

        // Check booking custom fields with configured field names and values
        if (!empty($booking['custom_fields']) && !empty($custom_field_names) && !empty($custom_field_values)) {
            foreach ($booking['custom_fields'] as $custom_field) {
                // Get field label (NewBook uses 'label' for field name)
                $field_label = isset($custom_field['label']) ? $custom_field['label'] : '';

                // Check if this field label matches any configured names
                foreach ($custom_field_names as $field_name) {
                    if ($field_label === $field_name) {
                        // Check if field value contains any configured search values
                        $field_value = isset($custom_field['value']) ? strtolower($custom_field['value']) : '';
                        foreach ($custom_field_values as $search_value) {
                            $search_value_lower = strtolower($search_value);
                            if (strpos($field_value, $search_value_lower) !== false) {
                                return array(
                                    'has_extra_bed' => true,
                                    'matched_term' => $search_value,
                                    'source' => 'custom_field'
                                );
                            }
                        }
                    }
                }
            }
        }

        // Check booking notes for configured search terms
        if (!empty($booking['notes']) && !empty($notes_search_terms)) {
            foreach ($booking['notes'] as $note) {
                $note_content = isset($note['content']) ? $note['content'] : '';
                if (empty($note_content)) {
                    continue;
                }

                // Search for match terms in notes (case-insensitive)
                $note_content_lower = strtolower($note_content);
                foreach ($notes_search_terms as $search_term) {
                    $search_term_lower = strtolower($search_term);
                    if (strpos($note_content_lower, $search_term_lower) !== false) {
                        return array(
                            'has_extra_bed' => true,
                            'matched_term' => $search_term,
                            'source' => 'booking_notes'
                        );
                    }
                }
            }
        }

        // No extra bed detected
        return array(
            'has_extra_bed' => false,
            'matched_term' => '',
            'source' => ''
        );
    }

    /**
     * Check if user can view a specific note type
     *
     * @param int $type_id Note type ID
     * @return bool
     */
    private function user_can_view_note_type($type_id) {
        // Check if specific note type permission exists
        if (function_exists('wfa_user_can')) {
            if (wfa_user_can('hhdl_view_note_type_' . $type_id)) {
                return true;
            }
        }

        // Fallback to view all notes permission
        return $this->user_can_view_all_notes();
    }

    /**
     * Render notes section for modal
     *
     * @param array $booking_data Booking data with notes
     * @param int $location_id Location ID
     */
    private function render_notes_section($booking_data, $location_id) {
        // Get Daily List settings for applicable note types
        $dl_settings = HHDL_Settings::get_location_settings($location_id);
        $visible_note_type_ids = isset($dl_settings['visible_note_types']) ? $dl_settings['visible_note_types'] : array();

        // Get note type configurations from Hotel Hub
        $note_types_config = HHDL_Settings::get_note_types($location_id);

        // If no note types configured or none selected, don't show section
        if (empty($note_types_config) || empty($visible_note_type_ids)) {
            return;
        }

        // Create a map of note type ID => config for easy lookup
        $note_types_map = array();
        foreach ($note_types_config as $note_type) {
            if (isset($note_type['id'])) {
                $note_types_map[$note_type['id']] = $note_type;
            }
        }

        // Filter note types to only show applicable ones
        $applicable_note_types = array();
        foreach ($visible_note_type_ids as $type_id) {
            if (isset($note_types_map[$type_id])) {
                $applicable_note_types[$type_id] = $note_types_map[$type_id];
            }
        }

        // If no applicable note types, don't show section
        if (empty($applicable_note_types)) {
            return;
        }

        // Initialize permissions for ALL applicable note types first
        $notes_by_type = array();
        foreach ($applicable_note_types as $type_id => $note_type) {
            $can_view = $this->user_can_view_note_type($type_id);
            $notes_by_type[$type_id] = array(
                'can_view' => $can_view,
                'notes' => array()
            );
        }

        // Group notes by type_id
        if (isset($booking_data['notes']) && is_array($booking_data['notes'])) {
            foreach ($booking_data['notes'] as $note) {
                $type_id = isset($note['type_id']) ? intval($note['type_id']) : 0;

                // Skip if not in applicable types
                if (!isset($applicable_note_types[$type_id])) {
                    continue;
                }

                // Add note if user has permission
                if ($notes_by_type[$type_id]['can_view']) {
                    $notes_by_type[$type_id]['notes'][] = $note;
                }
            }
        }

        // Render notes section
        ?>
        <section class="hhdl-notes-section">
            <div class="hhdl-notes-tabs">
                <?php foreach ($applicable_note_types as $type_id => $note_type): ?>
                    <?php
                    $has_notes = isset($notes_by_type[$type_id]) && !empty($notes_by_type[$type_id]['notes']);
                    $can_view = isset($notes_by_type[$type_id]) && $notes_by_type[$type_id]['can_view'];
                    $tab_class = 'hhdl-note-tab';
                    $tab_class .= $has_notes ? ' has-notes' : ' no-notes';
                    $tab_color = $has_notes ? $note_type['color'] : '#9ca3af';
                    ?>
                    <div class="<?php echo esc_attr($tab_class); ?>"
                         data-type-id="<?php echo esc_attr($type_id); ?>"
                         style="<?php echo $has_notes ? 'color: ' . esc_attr($tab_color) . ';' : ''; ?>"
                         title="<?php echo esc_attr($note_type['name']); ?>">
                        <span class="material-symbols-outlined"><?php echo esc_html($note_type['icon']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php foreach ($applicable_note_types as $type_id => $note_type): ?>
                <div class="hhdl-notes-content" data-type-id="<?php echo esc_attr($type_id); ?>">
                    <div class="hhdl-notes-content-inner">
                        <?php
                        $can_view = isset($notes_by_type[$type_id]) && $notes_by_type[$type_id]['can_view'];
                        $has_notes = isset($notes_by_type[$type_id]) && !empty($notes_by_type[$type_id]['notes']);

                        if (!$can_view):
                        ?>
                            <div class="hhdl-note-no-permission">
                                <span class="material-symbols-outlined" style="font-size: 48px; color: #d1d5db; margin-bottom: 8px;">lock</span>
                                <p><?php _e('No permissions for this note type', 'hhdl'); ?></p>
                            </div>
                        <?php elseif (!$has_notes): ?>
                            <div class="hhdl-note-no-permission">
                                <span class="material-symbols-outlined" style="font-size: 48px; color: #d1d5db; margin-bottom: 8px;">note</span>
                                <p><?php _e('No notes of this type', 'hhdl'); ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notes_by_type[$type_id]['notes'] as $note): ?>
                                <div class="hhdl-note-card" style="border-left-color: <?php echo esc_attr($note_type['color']); ?>; background: <?php echo esc_attr($note_type['color']); ?>15;">
                                    <div class="hhdl-note-header">
                                        <div class="hhdl-note-type-info">
                                            <span class="material-symbols-outlined" style="color: <?php echo esc_attr($note_type['color']); ?>;"><?php echo esc_html($note_type['icon']); ?></span>
                                            <span class="hhdl-note-type-name"><?php echo esc_html($note_type['name']); ?></span>
                                        </div>
                                        <div class="hhdl-note-updated">
                                            <?php
                                            if (isset($note['updated_when']) && !empty($note['updated_when'])) {
                                                echo esc_html(date('M j, Y g:i A', strtotime($note['updated_when'])));
                                            } elseif (isset($note['created_when']) && !empty($note['created_when'])) {
                                                echo esc_html(date('M j, Y g:i A', strtotime($note['created_when'])));
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="hhdl-note-content"><?php echo esc_html(isset($note['content']) ? trim($note['content']) : ''); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    }

    /**
     * Save user preferences (AJAX handler)
     */
    public function save_user_preferences() {
        // Verify nonce
        check_ajax_referer('hhdl_ajax_nonce', 'nonce');

        // Check permissions
        if (!$this->user_can_access()) {
            wp_send_json_error(array('message' => __('Permission denied', 'hhdl')));
        }

        // Get parameters
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;
        $preferences = isset($_POST['preferences']) ? $_POST['preferences'] : array();

        if (!$location_id) {
            wp_send_json_error(array('message' => __('Invalid location', 'hhdl')));
        }

        // Parse preferences if they came as JSON string
        if (is_string($preferences)) {
            $preferences = json_decode(stripslashes($preferences), true);
        }

        // Save preferences
        $result = HHDL_Display::save_user_preferences(null, $location_id, $preferences);

        if ($result) {
            wp_send_json_success(array('message' => __('Preferences saved', 'hhdl')));
        } else {
            wp_send_json_error(array('message' => __('Failed to save preferences', 'hhdl')));
        }
    }

    /**
     * Reset user preferences (AJAX handler)
     */
    public function reset_user_preferences() {
        // Verify nonce
        check_ajax_referer('hhdl_ajax_nonce', 'nonce');

        // Check permissions
        if (!$this->user_can_access()) {
            wp_send_json_error(array('message' => __('Permission denied', 'hhdl')));
        }

        // Get parameters
        $location_id = isset($_POST['location_id']) ? intval($_POST['location_id']) : 0;

        if (!$location_id) {
            wp_send_json_error(array('message' => __('Invalid location', 'hhdl')));
        }

        // Reset preferences
        $result = HHDL_Display::reset_user_preferences(null, $location_id);

        if ($result) {
            wp_send_json_success(array('message' => __('Preferences reset to defaults', 'hhdl')));
        } else {
            wp_send_json_error(array('message' => __('Failed to reset preferences', 'hhdl')));
        }
    }
}
