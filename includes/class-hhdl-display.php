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
        // TODO: Implement NewBook API integration
        // For now, return mock data for development

        return array(
            array(
                'room_id'              => '101',
                'room_number'          => '101',
                'site_status'          => 'Clean',
                'booking_status'       => 'confirmed',
                'is_arriving'          => true,
                'is_departing'         => false,
                'is_stopover'          => false,
                'has_twin'             => false,
                'spans_previous'       => false,
                'spans_next'           => true,
                'next_booking_status'  => 'confirmed',
                'prev_booking_status'  => '',
                'booking'              => array(
                    'reference'    => 'NB123456',
                    'guest_name'   => 'John Smith',
                    'checkin_time' => '14:00',
                    'pax'          => 2,
                    'night_info'   => '1/3 nights',
                    'occupancy'    => '2/2'
                )
            ),
            array(
                'room_id'              => '102',
                'room_number'          => '102',
                'site_status'          => 'Dirty',
                'booking_status'       => '',
                'is_arriving'          => false,
                'is_departing'         => false,
                'is_stopover'          => false,
                'has_twin'             => false,
                'spans_previous'       => false,
                'spans_next'           => false,
                'next_booking_status'  => '',
                'prev_booking_status'  => '',
                'booking'              => null
            )
        );
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
}
