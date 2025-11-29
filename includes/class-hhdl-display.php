<?php
/**
 * Display Class - Handles frontend rendering
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display class for Daily List module
 */
class HHDL_Display {

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
        add_action('hha_module_content_daily_list', array($this, 'render_daily_list'));
    }

    /**
     * Render module (entry point from HHA_Modules)
     *
     * @param array $params Optional parameters
     */
    public function render($params = array()) {
        $this->render_daily_list();
    }

    /**
     * Render daily list view
     */
    public function render_daily_list() {
        // Check permissions
        if (!$this->user_can_access()) {
            $this->render_no_access();
            return;
        }

        // Get current location
        $location_id = $this->get_current_location();

        // Check if enabled for this location
        if (!HHDL_Settings::is_enabled($location_id)) {
            $this->render_not_enabled();
            return;
        }

        // Get selected date (default to today)
        $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');

        // Render the view
        $this->render_header($selected_date);
        $this->render_filters();
        $this->render_room_list($location_id, $selected_date);
        $this->render_modal();
    }

    /**
     * Render header with date picker
     */
    private function render_header($selected_date) {
        ?>
        <div class="hhdl-header">
            <div class="hhdl-date-selector">
                <label for="hhdl-date-picker"><?php _e('Select Date:', 'hhdl'); ?></label>
                <input type="date"
                       id="hhdl-date-picker"
                       class="hhdl-date-input"
                       value="<?php echo esc_attr($selected_date); ?>">
            </div>
            <div class="hhdl-header-info">
                <span class="hhdl-viewing-date"><?php echo date('l, F j, Y', strtotime($selected_date)); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render filter buttons
     */
    private function render_filters() {
        ?>
        <div class="hhdl-filters">
            <button class="hhdl-filter-btn active" data-filter="all">
                <?php _e('All Rooms', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="arrivals">
                <?php _e('Arrivals', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="departs">
                <?php _e('Departures', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="stopovers">
                <?php _e('Stopovers', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="twins">
                <?php _e('Twins', 'hhdl'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render room list
     */
    private function render_room_list($location_id, $date) {
        ?>
        <div class="hhdl-room-list" id="hhdl-room-list" data-location="<?php echo esc_attr($location_id); ?>" data-date="<?php echo esc_attr($date); ?>">
            <div class="hhdl-loading">
                <span class="spinner is-active"></span>
                <p><?php _e('Loading rooms...', 'hhdl'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render room cards (called via AJAX)
     */
    public function render_room_cards($location_id, $date) {
        // Fetch 3-day data from NewBook
        $rooms_data = $this->fetch_rooms_data($location_id, $date);

        if (empty($rooms_data)) {
            echo '<div class="hhdl-no-rooms">';
            echo '<p>' . __('No rooms found for this date.', 'hhdl') . '</p>';
            echo '</div>';
            return array(
                'arrivals' => 0,
                'departures' => 0,
                'stopovers' => 0,
                'twins' => 0
            );
        }

        // Calculate filter counts
        $counts = array(
            'arrivals' => 0,
            'departures' => 0,
            'stopovers' => 0,
            'twins' => 0
        );

        foreach ($rooms_data as $room) {
            if ($room['is_arriving']) $counts['arrivals']++;
            if ($room['is_departing']) $counts['departures']++;
            if ($room['is_stopover']) $counts['stopovers']++;
            if ($room['has_twin']) $counts['twins']++;
        }

        // Render each room card
        foreach ($rooms_data as $room) {
            $this->render_room_card($room);
        }

        return $counts;
    }

    /**
     * Render individual room card
     */
    private function render_room_card($room) {
        $is_vacant = empty($room['booking']) && $room['booking_status'] !== 'blocked';
        $is_blocked = $room['booking_status'] === 'blocked';
        $card_class = $is_blocked ? 'hhdl-blocked' : ($is_vacant ? 'hhdl-vacant' : 'hhdl-booked');

        // Data attributes for filtering
        $data_attrs = array(
            'data-room-id'         => $room['room_id'],
            'data-is-arriving'     => $room['is_arriving'] ? 'true' : 'false',
            'data-is-departing'    => $room['is_departing'] ? 'true' : 'false',
            'data-is-stopover'     => $room['is_stopover'] ? 'true' : 'false',
            'data-has-twin'        => $room['has_twin'] ? 'true' : 'false',
            'data-twin-type'       => isset($room['twin_info']['type']) ? $room['twin_info']['type'] : 'none',
            'data-booking-status'  => $room['booking_status'],
            'data-spans-previous'  => $room['spans_previous'] ? 'true' : 'false',
            'data-spans-next'      => $room['spans_next'] ? 'true' : 'false'
        );

        // Build inline styles for status colors
        $inline_style = '';
        if (!$is_vacant || $is_blocked) {
            $status_color = $this->get_status_color($room['booking_status']);
            $inline_style = sprintf('border-left-color: %s;', $status_color);

            if ($room['spans_next']) {
                $next_color = $this->get_status_color($room['next_booking_status']);
                $inline_style .= sprintf(' --next-status-color: %s;', $next_color);
            }

            if ($room['spans_previous']) {
                $prev_color = $this->get_status_color($room['prev_booking_status']);
                $inline_style .= sprintf(' --previous-status-color: %s;', $prev_color);
            }
        }

        ?>
        <div class="hhdl-room-card <?php echo esc_attr($card_class); ?>"
             <?php foreach ($data_attrs as $key => $value) { echo $key . '="' . esc_attr($value) . '" '; } ?>
             style="<?php echo esc_attr($inline_style); ?>">

            <?php if ($is_blocked): ?>
                <?php $this->render_blocked_room($room); ?>
            <?php elseif ($is_vacant): ?>
                <?php $this->render_vacant_room($room); ?>
            <?php else: ?>
                <?php $this->render_booked_room($room); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render vacant room content
     */
    private function render_vacant_room($room) {
        ?>
        <div class="hhdl-room-content">
            <span class="hhdl-room-number"><?php echo esc_html($room['room_number']); ?></span>
            <span class="hhdl-vacant-label"><?php _e('No booking', 'hhdl'); ?></span>
            <span class="hhdl-site-status <?php echo esc_attr(strtolower($room['site_status'])); ?>">
                <?php echo esc_html($room['site_status']); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Render blocked room content
     */
    private function render_blocked_room($room) {
        $task = $room['blocking_task'];
        $task_color = isset($task['color']) ? $task['color'] : '#ef4444';
        $task_description = isset($task['description']) ? $task['description'] : 'Blocked';
        $task_icon = isset($task['icon']) ? $task['icon'] : 'construction';
        ?>
        <div class="hhdl-room-content">
            <span class="hhdl-room-number"><?php echo esc_html($room['room_number']); ?></span>
            <div class="hhdl-task-block" style="background-color: <?php echo esc_attr($task_color); ?>; border-color: <?php echo esc_attr($task_color); ?>;">
                <span class="material-symbols-outlined hhdl-task-icon">
                    <?php echo esc_html($task_icon); ?>
                </span>
                <span class="hhdl-task-description"><?php echo esc_html($task_description); ?></span>
            </div>
            <span class="hhdl-site-status <?php echo esc_attr(strtolower($room['site_status'])); ?>">
                <?php echo esc_html($room['site_status']); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Render booked room content
     */
    private function render_booked_room($room) {
        $booking = $room['booking'];
        $can_view_guest = $this->user_can_view_guest_details();

        ?>
        <div class="hhdl-room-header">
            <span class="hhdl-room-number"><?php echo esc_html($room['room_number']); ?></span>
            <span class="hhdl-site-status <?php echo esc_attr(strtolower($room['site_status'])); ?>">
                <?php echo esc_html($room['site_status']); ?>
            </span>
            <?php if ($room['is_arriving']): ?>
                <span class="hhdl-arrival-icon" title="<?php esc_attr_e('Arrival', 'hhdl'); ?>">→</span>
            <?php endif; ?>
            <?php if ($room['is_departing']): ?>
                <span class="hhdl-departure-icon" title="<?php esc_attr_e('Departure', 'hhdl'); ?>">←</span>
            <?php endif; ?>
        </div>

        <div class="hhdl-booking-info">
            <?php if ($can_view_guest && !empty($booking['guest_name'])): ?>
                <span class="hhdl-guest-name"><?php echo esc_html($booking['guest_name']); ?></span>
            <?php else: ?>
                <span class="hhdl-ref-number"><?php echo esc_html($booking['reference']); ?></span>
            <?php endif; ?>

            <?php if (!empty($booking['checkin_time'])): ?>
                <span class="hhdl-checkin-time"><?php echo esc_html($booking['checkin_time']); ?></span>
            <?php endif; ?>

            <?php if (!empty($booking['pax'])): ?>
                <span class="hhdl-pax-badge"><?php echo esc_html($booking['pax']); ?> pax</span>
            <?php endif; ?>
        </div>

        <div class="hhdl-booking-meta">
            <?php if (!empty($booking['night_info'])): ?>
                <span class="hhdl-nights"><?php echo esc_html($booking['night_info']); ?></span>
            <?php endif; ?>

            <?php if (!empty($booking['occupancy'])): ?>
                <span class="hhdl-occupancy-badge">🛏️ <?php echo esc_html($booking['occupancy']); ?></span>
            <?php endif; ?>

            <?php if ($room['has_twin']): ?>
                <?php
                $twin_info = isset($room['twin_info']) ? $room['twin_info'] : array('type' => 'none', 'matched_term' => '', 'source' => '');
                $twin_type = $twin_info['type'];
                $matched_term = $twin_info['matched_term'];
                $source = $twin_info['source'];

                // Convert source to user-friendly name
                $source_labels = array(
                    'custom_field' => 'custom field',
                    'legacy_field' => 'bed type field',
                    'universal_fallback' => 'custom field',
                    'booking_notes' => 'booking notes',
                    'notes_fallback' => 'booking notes'
                );
                $source_display = isset($source_labels[$source]) ? $source_labels[$source] : $source;

                if ($twin_type === 'confirmed'):
                    $title = sprintf(__('Confirmed Twin - Found "%s" in %s', 'hhdl'), $matched_term, $source_display);
                    $icon = '👥';
                    $class = 'hhdl-twin-icon hhdl-twin-confirmed';
                elseif ($twin_type === 'potential'):
                    $title = sprintf(__('Potential Twin - Found "%s" in %s (verify bed type)', 'hhdl'), $matched_term, $source_display);
                    $icon = '👥';
                    $class = 'hhdl-twin-icon hhdl-twin-potential';
                else:
                    $title = __('Twin/Sofabed', 'hhdl');
                    $icon = '👥';
                    $class = 'hhdl-twin-icon';
                endif;
                ?>
                <span class="<?php echo esc_attr($class); ?>" title="<?php echo esc_attr($title); ?>"><?php echo $icon; ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render modal structure
     */
    private function render_modal() {
        ?>
        <div class="hhdl-modal-overlay" id="hhdl-modal">
            <div class="hhdl-modal">
                <div class="hhdl-modal-header">
                    <h2 id="hhdl-modal-title"><?php _e('Room Details', 'hhdl'); ?></h2>
                    <button class="hhdl-modal-close" aria-label="<?php esc_attr_e('Close', 'hhdl'); ?>">&times;</button>
                </div>
                <div class="hhdl-modal-body" id="hhdl-modal-body">
                    <div class="hhdl-loading">
                        <span class="spinner is-active"></span>
                        <p><?php _e('Loading details...', 'hhdl'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Fetch rooms data from NewBook (3-day period)
     */
    private function fetch_rooms_data($location_id, $date) {
        // Get NewBook API client
        $api = $this->get_newbook_api($location_id);
        if (!$api) {
            return array();
        }

        // Get Hotel Hub settings for this location
        $hotel = $this->get_hotel_from_location($location_id);
        if (!$hotel) {
            return array();
        }

        $integration = hha()->integrations->get_settings($hotel->id, 'newbook');
        $categories_sort = isset($integration['categories_sort']) ? $integration['categories_sort'] : array();

        // Get all configured task type IDs from NewBook integration
        $task_type_ids = $this->get_all_task_type_ids($integration);

        // Calculate 3-day period
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
        $tomorrow_end = date('Y-m-d', strtotime($date . ' +2 days')); // Period is exclusive

        // Fetch data from NewBook
        $sites_response = $api->get_sites(true);
        $bookings_response = $api->get_bookings($yesterday, $tomorrow_end, 'staying', true);
        // Query ALL task types from hotel integration settings (includes housekeeping, occupy site, custom types)
        $tasks_response = $api->get_tasks($yesterday . ' 00:00:00', $tomorrow_end . ' 00:00:00', $task_type_ids, false, null, true);

        // Process responses
        $sites = isset($sites_response['data']) ? $sites_response['data'] : array();
        $bookings = isset($bookings_response['data']) ? $bookings_response['data'] : array();
        $tasks = isset($tasks_response['data']) ? $tasks_response['data'] : array();

        // Build site-to-category map and exclusion lists
        list($site_to_category, $excluded_sites, $site_order_map) = $this->build_category_maps($categories_sort);

        // Build rooms array with all site data
        $rooms_by_id = array();
        foreach ($sites as $site) {
            $site_id = $site['site_id'];

            // Skip excluded sites
            if (in_array($site_id, $excluded_sites)) {
                continue;
            }

            $rooms_by_id[$site_id] = array(
                'room_id'     => $site_id,
                'room_number' => $site['site_name'],
                'site_status' => isset($site['site_status']) ? $site['site_status'] : 'Unknown',
                'category'    => isset($site_to_category[$site_id]) ? $site_to_category[$site_id] : array(),
                'order'       => isset($site_order_map[$site_id]) ? $site_order_map[$site_id] : array('category_order' => 999, 'site_order' => 999),
                'bookings'    => array('yesterday' => null, 'today' => null, 'tomorrow' => null),
                'has_twin'    => false,
                'twin_info'   => array('type' => 'none', 'matched_term' => '', 'source' => '')
            );
        }

        // Process bookings and assign to dates
        foreach ($bookings as $booking) {
            $site_id = isset($booking['site_id']) ? $booking['site_id'] : '';
            if (empty($site_id) || !isset($rooms_by_id[$site_id])) {
                continue;
            }

            $arrival = date('Y-m-d', strtotime($booking['booking_arrival']));
            $departure = date('Y-m-d', strtotime($booking['booking_departure']));

            // Check which dates this booking overlaps
            if ($arrival <= $yesterday && $departure > $yesterday) {
                $rooms_by_id[$site_id]['bookings']['yesterday'] = $booking;
            }
            if ($arrival <= $date && $departure > $date) {
                $rooms_by_id[$site_id]['bookings']['today'] = $booking;
            }
            if ($arrival <= $tomorrow && $departure > $tomorrow) {
                $rooms_by_id[$site_id]['bookings']['tomorrow'] = $booking;
            }

            // Check for twin/sofabed indicators
            if ($rooms_by_id[$site_id]['bookings']['today'] === $booking) {
                $twin_detection = $this->detect_twin($booking, $location_id);
                $rooms_by_id[$site_id]['twin_info'] = $twin_detection;
                // Set has_twin to true for both confirmed and potential twins
                $rooms_by_id[$site_id]['has_twin'] = ($twin_detection['type'] === 'confirmed' || $twin_detection['type'] === 'potential');
            }
        }

        // Get task description mappings for colors
        $task_description_mappings = HHDL_Settings::get_task_description_mappings($location_id);

        // Process occupy tasks (blocked rooms)
        error_log('HHDL Display - Total tasks fetched: ' . count($tasks));
        error_log('HHDL Display - Available rooms in rooms_by_id: ' . json_encode(array_keys($rooms_by_id)));
        error_log('HHDL Display - Processing occupy tasks for date: ' . $date . ' (yesterday=' . $yesterday . ', tomorrow=' . $tomorrow . ')');

        $occupy_task_count = 0;
        foreach ($tasks as $task) {
            $task_id = isset($task['task_id']) ? $task['task_id'] : 'unknown';
            $task_desc = isset($task['task_description']) ? $task['task_description'] : 'no description';
            $task_occupy = isset($task['task_location_occupy']) ? $task['task_location_occupy'] : 'null';

            if (empty($task['task_location_occupy']) || $task['task_location_occupy'] != 1) {
                continue;
            }

            $occupy_task_count++;
            error_log('HHDL Display - Found occupy task #' . $occupy_task_count . ': task_id=' . $task_id . ', desc=' . $task_desc);

            // Get site ID
            $site_id = !empty($task['task_location_id']) ? $task['task_location_id'] :
                       (!empty($task['booking_site_id']) ? $task['booking_site_id'] : '');

            error_log('HHDL Display - Occupy task ' . $task_id . ': extracted site_id=' . $site_id . ' (task_location_id=' . (isset($task['task_location_id']) ? $task['task_location_id'] : 'null') . ', booking_site_id=' . (isset($task['booking_site_id']) ? $task['booking_site_id'] : 'null') . ')');

            if (empty($site_id)) {
                error_log('HHDL Display - Occupy task ' . $task_id . ' SKIPPED: empty site_id');
                continue;
            }

            // If site doesn't exist in rooms, add it
            if (!isset($rooms_by_id[$site_id])) {
                error_log('HHDL Display - Occupy task ' . $task_id . ' SKIPPED: site_id=' . $site_id . ' not found in rooms_by_id array');
                continue;
            }

            // Get color from task description mapping
            $task_color = '#ef4444'; // Default red
            foreach ($task_description_mappings as $filter => $mapping) {
                if (stripos($task_desc, $filter) !== false) {
                    $task_color = $mapping['color'];
                    break;
                }
            }

            // Build task info array
            $task_info = array(
                'description' => $task_desc,
                'color' => $task_color,
                'icon' => 'construction' // Default Material icon
            );

            // Determine task dates
            $task_dates = $this->get_task_dates($task);
            error_log('HHDL Display - Occupy task ' . $task_id . ': task_dates=' . json_encode($task_dates) . ' (period_from=' . (isset($task['task_period_from']) ? $task['task_period_from'] : 'null') . ', period_to=' . (isset($task['task_period_to']) ? $task['task_period_to'] : 'null') . ')');

            // Check if task blocks any of our 3 days
            foreach ($task_dates as $task_date) {
                if ($task_date === $yesterday) {
                    error_log('HHDL Display - Marking room ' . $site_id . ' as BLOCKED on YESTERDAY (' . $yesterday . ') due to task ' . $task_id);
                    $rooms_by_id[$site_id]['bookings']['yesterday'] = $task_info;
                } elseif ($task_date === $date) {
                    error_log('HHDL Display - Marking room ' . $site_id . ' as BLOCKED on TODAY (' . $date . ') due to task ' . $task_id);
                    $rooms_by_id[$site_id]['bookings']['today'] = $task_info;
                } elseif ($task_date === $tomorrow) {
                    error_log('HHDL Display - Marking room ' . $site_id . ' as BLOCKED on TOMORROW (' . $tomorrow . ') due to task ' . $task_id);
                    $rooms_by_id[$site_id]['bookings']['tomorrow'] = $task_info;
                }
            }
        }

        error_log('HHDL Display - Total occupy tasks found: ' . $occupy_task_count);

        // Build final room cards array for the selected date
        $room_cards = array();
        foreach ($rooms_by_id as $room) {
            $today_booking = $room['bookings']['today'];
            $yesterday_booking = $room['bookings']['yesterday'];
            $tomorrow_booking = $room['bookings']['tomorrow'];

            // Determine today's booking status
            $booking_data = null;
            $blocking_task = null;
            $booking_status = '';
            if ($today_booking && is_array($today_booking)) {
                // Check if it's a blocking task (has 'description' key) or a booking
                if (isset($today_booking['description'])) {
                    // It's a blocking task
                    $booking_status = 'blocked';
                    $blocking_task = $today_booking;
                } else {
                    // It's a regular booking
                    $booking_data = $this->format_booking_data($today_booking, $date);
                    $booking_status = $this->get_booking_status($today_booking);
                }
            }

            // Determine arrival/departure/stopover (only for bookings, not blocking tasks)
            // Arrivals: today's booking arriving today
            $is_arriving = $booking_data && isset($today_booking['booking_arrival']) &&
                          date('Y-m-d', strtotime($today_booking['booking_arrival'])) === $date;

            // Departures: yesterday's booking departing today (room needs cleaning after guest left)
            $is_departing = false;
            if ($yesterday_booking && is_array($yesterday_booking) && !isset($yesterday_booking['description'])) {
                // It's a regular booking (not a blocking task)
                if (isset($yesterday_booking['booking_departure']) &&
                    date('Y-m-d', strtotime($yesterday_booking['booking_departure'])) === $date) {
                    $is_departing = true;
                }
            }

            // Stopovers: today's booking that's not arriving (and not departing from yesterday's perspective)
            $is_stopover = $booking_data && !$is_arriving;

            // Determine spanning
            $spans_previous = !empty($yesterday_booking);
            $spans_next = !empty($tomorrow_booking);

            // Get adjacent booking statuses
            $prev_booking_status = '';
            if ($yesterday_booking && is_array($yesterday_booking)) {
                if (isset($yesterday_booking['description'])) {
                    // It's a blocking task
                    $prev_booking_status = 'blocked';
                } else {
                    $prev_booking_status = $this->get_booking_status($yesterday_booking);
                }
            }

            $next_booking_status = '';
            if ($tomorrow_booking && is_array($tomorrow_booking)) {
                if (isset($tomorrow_booking['description'])) {
                    // It's a blocking task
                    $next_booking_status = 'blocked';
                } else {
                    $next_booking_status = $this->get_booking_status($tomorrow_booking);
                }
            }

            $room_cards[] = array(
                'room_id'              => $room['room_id'],
                'room_number'          => $room['room_number'],
                'site_status'          => $room['site_status'],
                'booking_status'       => $booking_status,
                'is_arriving'          => $is_arriving,
                'is_departing'         => $is_departing,
                'is_stopover'          => $is_stopover,
                'has_twin'             => $room['has_twin'],
                'twin_info'            => $room['twin_info'],
                'spans_previous'       => $spans_previous,
                'spans_next'           => $spans_next,
                'prev_booking_status'  => $prev_booking_status,
                'next_booking_status'  => $next_booking_status,
                'booking'              => $booking_data,
                'blocking_task'        => $blocking_task,
                'order'                => $room['order']
            );
        }

        // Sort by category and site order
        usort($room_cards, function($a, $b) {
            if ($a['order']['category_order'] !== $b['order']['category_order']) {
                return $a['order']['category_order'] - $b['order']['category_order'];
            }
            return $a['order']['site_order'] - $b['order']['site_order'];
        });

        return $room_cards;
    }

    /**
     * Get status color
     */
    private function get_status_color($status) {
        $colors = array(
            'departed'    => '#8b5cf6',
            'confirmed'   => '#10b981',
            'unconfirmed' => '#f59e0b',
            'arrived'     => '#fb923c',
            'seated'      => '#dc2626',
            'waitlist'    => '#eab308',
            'cancelled'   => '#94a3b8',
            'blocked'     => '#ef4444'  // Red for blocked/out of service
        );

        return isset($colors[strtolower($status)]) ? $colors[strtolower($status)] : '#d1d5db';
    }

    /**
     * Render no access message
     */
    private function render_no_access() {
        ?>
        <div class="hhdl-notice hhdl-notice-error">
            <p><?php _e('You do not have permission to access this module.', 'hhdl'); ?></p>
        </div>
        <?php
    }

    /**
     * Render not enabled message
     */
    private function render_not_enabled() {
        ?>
        <div class="hhdl-notice hhdl-notice-warning">
            <p><?php _e('Daily List module is not enabled for this location.', 'hhdl'); ?></p>
            <p><?php _e('Please configure the settings to enable this module.', 'hhdl'); ?></p>
        </div>
        <?php
    }

    /**
     * Get current location ID
     */
    private function get_current_location() {
        // Check if Hotel Hub App function exists
        if (function_exists('hha_get_current_location')) {
            return hha_get_current_location();
        }

        // Fallback
        return isset($_GET['location_id']) ? intval($_GET['location_id']) : 1;
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
     * Get NewBook API client for a location
     */
    private function get_newbook_api($location_id) {
        if (!function_exists('hha')) {
            return null;
        }

        $hotel = $this->get_hotel_from_location($location_id);
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
     * Get hotel from location ID
     */
    private function get_hotel_from_location($location_id) {
        if (!function_exists('hha')) {
            return null;
        }

        return hha()->hotels->get($location_id);
    }

    /**
     * Build category maps for sorting and exclusion
     */
    private function build_category_maps($categories_sort) {
        $site_to_category = array();
        $excluded_sites = array();
        $site_order_map = array();

        foreach ($categories_sort as $cat_index => $category) {
            if (!empty($category['excluded'])) {
                continue; // Skip excluded categories
            }

            foreach ($category['sites'] as $site_index => $site) {
                $site_id = $site['site_id'];

                $site_to_category[$site_id] = array(
                    'category_id' => $category['id'],
                    'category_name' => $category['name']
                );

                $site_order_map[$site_id] = array(
                    'category_order' => $cat_index,
                    'site_order' => $site_index
                );

                if (!empty($site['excluded'])) {
                    $excluded_sites[] = $site_id;
                }
            }
        }

        return array($site_to_category, $excluded_sites, $site_order_map);
    }

    /**
     * Detect twin/sofabed from booking using Twin Optimizer settings
     *
     * @param array $booking Booking data from NewBook API
     * @param int $hotel_id Hotel Hub hotel ID
     * @return array Detection result with 'type' and 'matched_term' keys
     */
    private function detect_twin($booking, $hotel_id) {
        // Get hotel object to retrieve workforce location_id
        $hotel = $this->get_hotel_from_location($hotel_id);
        $workforce_location_id = $hotel && isset($hotel->location_id) ? $hotel->location_id : null;

        // Get Twin Optimizer settings if available
        $twin_settings = array(
            'custom_field_names' => '',
            'custom_field_values' => '',
            'notes_search_terms' => '',
            'custom_field' => 'Bed Type'
        );

        error_log('HHDL Twin Detection - Hotel ID: ' . $hotel_id . ', Workforce location_id: ' . ($workforce_location_id ? $workforce_location_id : 'null'));
        error_log('HHDL Twin Detection - HHTM_Settings class exists: ' . (class_exists('HHTM_Settings') ? 'YES' : 'NO'));

        // Try to get Twin Optimizer settings using workforce location_id
        if (class_exists('HHTM_Settings') && $workforce_location_id) {
            $twin_settings = HHTM_Settings::get_location_settings($workforce_location_id);

            // Also check what's actually in the database
            $all_location_settings = get_option('hhtm_location_settings', array());
            error_log('HHDL Twin Detection - All Twin Optimizer location IDs in database: ' . json_encode(array_keys($all_location_settings)));
            error_log('HHDL Twin Detection - Twin Optimizer settings for workforce location_id ' . $workforce_location_id . ': ' . json_encode($twin_settings));
        } else {
            error_log('HHDL Twin Detection - Could not get workforce location_id from hotel, or HHTM_Settings not available');
        }

        // Parse settings into arrays
        $custom_field_names = !empty($twin_settings['custom_field_names']) ?
            array_map('trim', explode(',', $twin_settings['custom_field_names'])) : array();
        $custom_field_values = !empty($twin_settings['custom_field_values']) ?
            array_map('trim', explode(',', $twin_settings['custom_field_values'])) : array();
        $notes_search_terms = !empty($twin_settings['notes_search_terms']) ?
            array_map('trim', explode(',', $twin_settings['notes_search_terms'])) : array();

        // Determine if Twin Optimizer is configured for this location
        $twin_optimizer_configured = !empty($custom_field_names) && !empty($custom_field_values);

        error_log('HHDL Twin Detection - Twin Optimizer configured: ' . ($twin_optimizer_configured ? 'YES' : 'NO'));
        error_log('HHDL Twin Detection - Custom field names to check: ' . json_encode($custom_field_names));
        error_log('HHDL Twin Detection - Custom field values to match: ' . json_encode($custom_field_values));
        error_log('HHDL Twin Detection - Notes search terms: ' . json_encode($notes_search_terms));

        // Debug: Log booking custom fields structure
        if (!empty($booking['booking_custom_fields'])) {
            error_log('HHDL Twin Detection - Booking ref: ' . (isset($booking['reference']) ? $booking['reference'] : 'unknown'));
            foreach ($booking['booking_custom_fields'] as $idx => $field) {
                error_log('HHDL Twin Detection - Custom field #' . $idx . ': name=' . (isset($field['name']) ? $field['name'] : 'null') .
                         ', label=' . (isset($field['label']) ? $field['label'] : 'null') .
                         ', value=' . (isset($field['value']) ? $field['value'] : 'null'));
            }
        }

        // PRIMARY DETECTION: Check booking custom fields with configured field names and values
        if (!empty($booking['booking_custom_fields']) && !empty($custom_field_names) && !empty($custom_field_values)) {
            error_log('HHDL Twin Detection - Running PRIMARY detection...');
            foreach ($booking['booking_custom_fields'] as $custom_field) {
                // Get field name from both 'name' and 'label' keys
                $field_name_actual = isset($custom_field['name']) ? $custom_field['name'] : '';
                $field_label_actual = isset($custom_field['label']) ? $custom_field['label'] : '';

                // Check if this field name/label matches any configured names
                foreach ($custom_field_names as $field_name) {
                    if ($field_name_actual === $field_name || $field_label_actual === $field_name) {
                        // Check if field value contains any configured search values
                        $field_value = isset($custom_field['value']) ? strtolower($custom_field['value']) : '';
                        foreach ($custom_field_values as $search_value) {
                            $search_value_lower = strtolower($search_value);
                            if (strpos($field_value, $search_value_lower) !== false) {
                                error_log('HHDL Twin Detection - PRIMARY MATCH: field=' . $field_name . ', value=' . $field_value . ', matched=' . $search_value);
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
            error_log('HHDL Twin Detection - PRIMARY detection complete, no match found');
        }

        // FALLBACK DETECTION: Check legacy bed type field for "twin" or "2 x single"
        if (!empty($booking['booking_custom_fields'])) {
            $legacy_field_name = !empty($twin_settings['custom_field']) ? $twin_settings['custom_field'] : 'Bed Type';

            foreach ($booking['booking_custom_fields'] as $custom_field) {
                // Check by both 'name' and 'label' for legacy field
                $field_name = isset($custom_field['name']) ? $custom_field['name'] : '';
                $field_label = isset($custom_field['label']) ? $custom_field['label'] : '';

                if ($field_name === $legacy_field_name || $field_label === $legacy_field_name) {
                    $field_value = isset($custom_field['value']) ? strtolower($custom_field['value']) : '';

                    // Check for "twin"
                    if (strpos($field_value, 'twin') !== false) {
                        return array(
                            'type' => 'confirmed',
                            'matched_term' => 'twin',
                            'source' => 'legacy_field'
                        );
                    }

                    // Check for "2 x single" pattern
                    if (preg_match('/2\s*x?\s*single/i', $field_value)) {
                        return array(
                            'type' => 'confirmed',
                            'matched_term' => '2 x single',
                            'source' => 'legacy_field'
                        );
                    }
                }
            }
        }

        // UNIVERSAL FALLBACK: Check ANY custom field for common twin keywords (ONLY if Twin Optimizer NOT configured)
        if (!$twin_optimizer_configured && !empty($booking['booking_custom_fields'])) {
            foreach ($booking['booking_custom_fields'] as $custom_field) {
                $field_value = isset($custom_field['value']) ? strtolower($custom_field['value']) : '';

                if (empty($field_value)) {
                    continue;
                }

                // Check for common twin indicators
                if (strpos($field_value, 'twin') !== false) {
                    return array(
                        'type' => 'confirmed',
                        'matched_term' => 'twin',
                        'source' => 'universal_fallback'
                    );
                }

                if (strpos($field_value, 'sofabed') !== false || strpos($field_value, 'sofa bed') !== false) {
                    return array(
                        'type' => 'confirmed',
                        'matched_term' => 'sofabed',
                        'source' => 'universal_fallback'
                    );
                }

                if (preg_match('/2\s*x?\s*single/i', $field_value)) {
                    return array(
                        'type' => 'confirmed',
                        'matched_term' => '2 x single',
                        'source' => 'universal_fallback'
                    );
                }
            }
        }

        // POTENTIAL DETECTION: Check booking notes for configured search terms
        if (!empty($booking['notes']) && !empty($notes_search_terms)) {
            error_log('HHDL Twin Detection - Running NOTES detection with ' . count($booking['notes']) . ' notes...');
            foreach ($booking['notes'] as $idx => $note) {
                $note_content = isset($note['content']) ? strtolower($note['content']) : '';
                if (empty($note_content)) {
                    continue;
                }

                error_log('HHDL Twin Detection - Checking note #' . $idx . ': ' . substr($note_content, 0, 100));

                // Check against configured search terms
                foreach ($notes_search_terms as $search_term) {
                    $search_term_lower = strtolower($search_term);
                    if (strpos($note_content, $search_term_lower) !== false) {
                        error_log('HHDL Twin Detection - NOTES MATCH: term="' . $search_term . '" found in note="' . substr($note_content, 0, 100) . '..."');
                        return array(
                            'type' => 'potential',
                            'matched_term' => $search_term,
                            'source' => 'booking_notes'
                        );
                    }
                }
            }
            error_log('HHDL Twin Detection - No notes matched configured search terms');
        }

        // FINAL FALLBACK: Check booking notes for common twin keywords (ONLY if Twin Optimizer NOT configured)
        if (!$twin_optimizer_configured && !empty($booking['notes'])) {
            $common_keywords = array('twin', 'sofabed', 'sofa bed', '2 x single', 'two single');

            foreach ($booking['notes'] as $note) {
                $note_content = isset($note['content']) ? strtolower($note['content']) : '';
                if (empty($note_content)) {
                    continue;
                }

                foreach ($common_keywords as $keyword) {
                    if (strpos($note_content, $keyword) !== false) {
                        return array(
                            'type' => 'potential',
                            'matched_term' => $keyword,
                            'source' => 'notes_fallback'
                        );
                    }
                }
            }
        }

        // No twin detected
        error_log('HHDL Twin Detection - FINAL RESULT: No twin detected');
        return array(
            'type' => 'none',
            'matched_term' => '',
            'source' => ''
        );
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
     * Format booking data for display
     */
    private function format_booking_data($booking, $date) {
        $arrival_date = date('Y-m-d', strtotime($booking['booking_arrival']));
        $departure_date = date('Y-m-d', strtotime($booking['booking_departure']));

        // Calculate nights
        $total_nights = (strtotime($departure_date) - strtotime($arrival_date)) / 86400;
        $current_night = (strtotime($date) - strtotime($arrival_date)) / 86400 + 1;

        return array(
            'reference'    => isset($booking['booking_reference_id']) ? $booking['booking_reference_id'] : '',
            'guest_name'   => isset($booking['guest_name']) ? $booking['guest_name'] : '',
            'checkin_time' => isset($booking['booking_eta']) ? date('H:i', strtotime($booking['booking_eta'])) : '',
            'pax'          => isset($booking['pax']) ? $booking['pax'] : 0,
            'night_info'   => $current_night . '/' . $total_nights . ' nights',
            'occupancy'    => isset($booking['occupancy']) ? $booking['occupancy'] : ''
        );
    }

    /**
     * Get booking status from booking data
     */
    private function get_booking_status($booking) {
        if (isset($booking['booking_status'])) {
            return strtolower($booking['booking_status']);
        }

        // Fallback to determining status from other fields
        if (isset($booking['booking_locked']) && $booking['booking_locked'] == '1') {
            return 'confirmed';
        }

        return 'unconfirmed';
    }
}
