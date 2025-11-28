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
            return;
        }

        // Render each room card
        foreach ($rooms_data as $room) {
            $this->render_room_card($room);
        }
    }

    /**
     * Render individual room card
     */
    private function render_room_card($room) {
        $is_vacant = empty($room['booking']);
        $card_class = $is_vacant ? 'hhdl-vacant' : 'hhdl-booked';

        // Data attributes for filtering
        $data_attrs = array(
            'data-room-id'         => $room['room_id'],
            'data-is-arriving'     => $room['is_arriving'] ? 'true' : 'false',
            'data-is-departing'    => $room['is_departing'] ? 'true' : 'false',
            'data-is-stopover'     => $room['is_stopover'] ? 'true' : 'false',
            'data-has-twin'        => $room['has_twin'] ? 'true' : 'false',
            'data-booking-status'  => $room['booking_status'],
            'data-spans-previous'  => $room['spans_previous'] ? 'true' : 'false',
            'data-spans-next'      => $room['spans_next'] ? 'true' : 'false'
        );

        // Build inline styles for status colors
        $inline_style = '';
        if (!$is_vacant) {
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

            <?php if ($is_vacant): ?>
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
                <span class="hhdl-twin-icon" title="<?php esc_attr_e('Twin/Sofabed', 'hhdl'); ?>">👥</span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render modal structure
     */
    private function render_modal() {
        ?>
        <div class="hhdl-modal-overlay" id="hhdl-modal" style="display: none;">
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

        // Calculate 3-day period
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
        $tomorrow_end = date('Y-m-d', strtotime($date . ' +2 days')); // Period is exclusive

        // Fetch data from NewBook
        $sites_response = $api->get_sites(true);
        $bookings_response = $api->get_bookings($yesterday, $tomorrow_end, 'staying', true);
        $tasks_response = $api->get_tasks($yesterday, $tomorrow_end, array(), true, null, true);

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
                'has_twin'    => false
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
                $rooms_by_id[$site_id]['has_twin'] = $this->detect_twin($booking);
            }
        }

        // Process occupy tasks (blocked rooms)
        foreach ($tasks as $task) {
            if (empty($task['task_location_occupy']) || $task['task_location_occupy'] != 1) {
                continue;
            }

            // Get site ID
            $site_id = !empty($task['task_location_id']) ? $task['task_location_id'] :
                       (!empty($task['booking_site_id']) ? $task['booking_site_id'] : '');

            if (empty($site_id)) {
                continue;
            }

            // If site doesn't exist in rooms, add it
            if (!isset($rooms_by_id[$site_id])) {
                // This might be a blocked site not in our visible list
                continue;
            }

            // Determine task dates
            $task_dates = $this->get_task_dates($task);

            // Check if task blocks any of our 3 days
            foreach ($task_dates as $task_date) {
                if ($task_date === $yesterday) {
                    $rooms_by_id[$site_id]['bookings']['yesterday'] = 'blocked';
                } elseif ($task_date === $date) {
                    $rooms_by_id[$site_id]['bookings']['today'] = 'blocked';
                } elseif ($task_date === $tomorrow) {
                    $rooms_by_id[$site_id]['bookings']['tomorrow'] = 'blocked';
                }
            }
        }

        // Build final room cards array for the selected date
        $room_cards = array();
        foreach ($rooms_by_id as $room) {
            $today_booking = $room['bookings']['today'];
            $yesterday_booking = $room['bookings']['yesterday'];
            $tomorrow_booking = $room['bookings']['tomorrow'];

            // Determine today's booking status
            $booking_data = null;
            $booking_status = '';
            if ($today_booking && is_array($today_booking)) {
                $booking_data = $this->format_booking_data($today_booking, $date);
                $booking_status = $this->get_booking_status($today_booking);
            } elseif ($today_booking === 'blocked') {
                $booking_status = 'blocked';
            }

            // Determine arrival/departure/stopover
            $is_arriving = $today_booking && is_array($today_booking) &&
                          date('Y-m-d', strtotime($today_booking['booking_arrival'])) === $date;
            $is_departing = $today_booking && is_array($today_booking) &&
                           date('Y-m-d', strtotime($today_booking['booking_departure'])) === $date;
            $is_stopover = $today_booking && is_array($today_booking) && !$is_arriving && !$is_departing;

            // Determine spanning
            $spans_previous = !empty($yesterday_booking);
            $spans_next = !empty($tomorrow_booking);

            // Get adjacent booking statuses
            $prev_booking_status = '';
            if ($yesterday_booking && is_array($yesterday_booking)) {
                $prev_booking_status = $this->get_booking_status($yesterday_booking);
            } elseif ($yesterday_booking === 'blocked') {
                $prev_booking_status = 'blocked';
            }

            $next_booking_status = '';
            if ($tomorrow_booking && is_array($tomorrow_booking)) {
                $next_booking_status = $this->get_booking_status($tomorrow_booking);
            } elseif ($tomorrow_booking === 'blocked') {
                $next_booking_status = 'blocked';
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
                'spans_previous'       => $spans_previous,
                'spans_next'           => $spans_next,
                'prev_booking_status'  => $prev_booking_status,
                'next_booking_status'  => $next_booking_status,
                'booking'              => $booking_data,
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
            'cancelled'   => '#94a3b8'
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
     * Detect twin/sofabed from booking
     */
    private function detect_twin($booking) {
        // Check custom fields
        if (!empty($booking['custom_fields'])) {
            foreach ($booking['custom_fields'] as $field) {
                if (stripos($field, 'twin') !== false || stripos($field, 'sofabed') !== false) {
                    return true;
                }
            }
        }

        // Check booking custom fields
        if (!empty($booking['booking_custom_fields'])) {
            foreach ($booking['booking_custom_fields'] as $field) {
                if (isset($field['value'])) {
                    if (stripos($field['value'], 'twin') !== false || stripos($field['value'], 'sofabed') !== false) {
                        return true;
                    }
                }
            }
        }

        // Check notes
        if (!empty($booking['notes'])) {
            foreach ($booking['notes'] as $note) {
                if (isset($note['content'])) {
                    if (stripos($note['content'], 'twin') !== false || stripos($note['content'], 'sofabed') !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
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
