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
     * Get user display preferences
     *
     * @param int $user_id
     * @param int $location_id
     * @return array
     */
    public static function get_user_preferences($user_id = null, $location_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$location_id && function_exists('hha_get_current_location')) {
            $location_id = hha_get_current_location();
        }

        $meta_key = 'hhdl_display_prefs_' . $location_id;
        $preferences = get_user_meta($user_id, $meta_key, true);

        // Default preferences if none exist
        if (empty($preferences)) {
            $preferences = array(
                'view_mode' => 'grouped',  // 'grouped' or 'flat'
                'collapsed_categories' => array(),  // Array of collapsed category IDs
                'default_filter' => 'all',  // Default filter to apply
            );
        }

        return $preferences;
    }

    /**
     * Save user display preferences
     *
     * @param int $user_id
     * @param int $location_id
     * @param array $preferences
     * @return bool
     */
    public static function save_user_preferences($user_id = null, $location_id = null, $preferences = array()) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$location_id && function_exists('hha_get_current_location')) {
            $location_id = hha_get_current_location();
        }

        $meta_key = 'hhdl_display_prefs_' . $location_id;

        // Get existing preferences to merge with new ones
        $existing_prefs = self::get_user_preferences($user_id, $location_id);

        // Merge and sanitize preferences (preserve existing values not being updated)
        $clean_preferences = array(
            'view_mode' => isset($preferences['view_mode']) && in_array($preferences['view_mode'], array('grouped', 'flat'))
                ? $preferences['view_mode'] : $existing_prefs['view_mode'],
            'collapsed_categories' => isset($preferences['collapsed_categories']) && is_array($preferences['collapsed_categories'])
                ? array_map('sanitize_text_field', $preferences['collapsed_categories']) : $existing_prefs['collapsed_categories'],
            'default_filter' => isset($preferences['default_filter'])
                ? sanitize_text_field($preferences['default_filter']) : $existing_prefs['default_filter'],
        );

        return update_user_meta($user_id, $meta_key, $clean_preferences);
    }

    /**
     * Reset user display preferences to defaults
     *
     * @param int $user_id
     * @param int $location_id
     * @return bool
     */
    public static function reset_user_preferences($user_id = null, $location_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$location_id && function_exists('hha_get_current_location')) {
            $location_id = hha_get_current_location();
        }

        $meta_key = 'hhdl_display_prefs_' . $location_id;

        // Delete the meta to reset to defaults
        return delete_user_meta($user_id, $meta_key);
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
     * Render header with date picker and view controls
     */
    private function render_header($selected_date) {
        // Get user preferences
        $location_id = $this->get_current_location();
        $user_prefs = self::get_user_preferences(null, $location_id);
        $view_mode = isset($user_prefs['view_mode']) ? $user_prefs['view_mode'] : 'grouped';
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
            <div class="hhdl-view-controls">
                <div class="hhdl-view-mode-toggle">
                    <button class="hhdl-view-mode-btn <?php echo $view_mode === 'grouped' ? 'active' : ''; ?>"
                            data-view-mode="grouped"
                            title="<?php esc_attr_e('Group by Category', 'hhdl'); ?>">
                        <span class="material-symbols-outlined">view_list</span>
                        <?php _e('Grouped', 'hhdl'); ?>
                    </button>
                    <button class="hhdl-view-mode-btn <?php echo $view_mode === 'flat' ? 'active' : ''; ?>"
                            data-view-mode="flat"
                            title="<?php esc_attr_e('Flat List', 'hhdl'); ?>">
                        <span class="material-symbols-outlined">format_list_bulleted</span>
                        <?php _e('Flat', 'hhdl'); ?>
                    </button>
                </div>
                <button class="hhdl-reset-view-btn"
                        id="hhdl-reset-preferences"
                        title="<?php esc_attr_e('Reset view to defaults', 'hhdl'); ?>">
                    <span class="material-symbols-outlined">restart_alt</span>
                    <?php _e('Reset View', 'hhdl'); ?>
                </button>
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
                <?php _e('Departs', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="stopovers">
                <?php _e('Stopovers', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="back-to-back">
                <?php _e('Back to Back', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="twins">
                <?php _e('Twins', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="blocked">
                <?php _e('Blocked', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="no-booking">
                <?php _e('No Booking', 'hhdl'); ?>
            </button>
            <button class="hhdl-filter-btn" data-filter="unoccupied">
                <?php _e('Unoccupied', 'hhdl'); ?>
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
                <span class="spinner"></span>
                <p><?php _e('Loading rooms...', 'hhdl'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render category header with task counts
     */
    private function render_category_header($category, $room_cards, $location_id) {
        // Calculate task counts for this category
        $newbook_tasks = 0;
        $recurring_tasks_incomplete = 0;
        $room_count = count($room_cards);
        $visible_room_count = $room_count;  // Will be updated based on filters

        // Get default tasks for comparison
        $default_tasks = HHDL_Settings::get_default_tasks($location_id);

        foreach ($room_cards as $room) {
            // Count NewBook tasks
            if (!empty($room['newbook_tasks'])) {
                $newbook_tasks += count($room['newbook_tasks']);
            }

            // Count incomplete recurring tasks (comparing against defaults)
            // This is a simplified check - expand based on your actual task tracking
            if (!empty($default_tasks)) {
                // Check if room has completed today's default tasks
                // This would need to check against your completion tracking
                $recurring_tasks_incomplete += count($default_tasks);
            }
        }

        // Check if this category is in the user's collapsed list
        $user_prefs = self::get_user_preferences(null, $location_id);
        $collapsed_categories = isset($user_prefs['collapsed_categories']) ? $user_prefs['collapsed_categories'] : array();
        $is_collapsed = in_array($category['id'], $collapsed_categories);
        $arrow_icon = $is_collapsed ? 'chevron_right' : 'expand_more';

        ?>
        <div class="hhdl-category-header" data-category-id="<?php echo esc_attr($category['id']); ?>">
            <div class="hhdl-category-toggle">
                <span class="material-symbols-outlined hhdl-category-arrow"><?php echo esc_html($arrow_icon); ?></span>
            </div>
            <div class="hhdl-category-name">
                <?php echo esc_html($category['name']); ?>
            </div>
            <div class="hhdl-category-counts">
                <span class="hhdl-room-count">
                    <span class="hhdl-visible-count"><?php echo $visible_room_count; ?></span>
                    <?php if ($visible_room_count !== $room_count): ?>
                        <span class="hhdl-of-total">of <?php echo $room_count; ?></span>
                    <?php endif; ?>
                    rooms
                </span>

                <!-- NewBook Tasks Badge -->
                <?php if ($newbook_tasks > 0): ?>
                    <span class="hhdl-task-badge hhdl-task-outstanding" title="<?php echo esc_attr($newbook_tasks . ' NewBook tasks outstanding'); ?>">
                        <span class="material-symbols-outlined">assignment_late</span>
                        <span class="hhdl-task-count"><?php echo $newbook_tasks; ?></span>
                    </span>
                <?php else: ?>
                    <span class="hhdl-task-badge hhdl-task-complete" title="<?php esc_attr_e('No NewBook tasks outstanding', 'hhdl'); ?>">
                        <span class="material-symbols-outlined">assignment_turned_in</span>
                    </span>
                <?php endif; ?>

                <!-- Recurring Tasks Badge - Always show as complete for now -->
                <span class="hhdl-task-badge hhdl-task-complete" title="<?php esc_attr_e('Recurring tasks complete', 'hhdl'); ?>">
                    <span class="material-symbols-outlined">checklist_rtl</span>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Calculate category task counts
     */
    private function calculate_category_task_counts($room_cards, $location_id) {
        $counts = array(
            'newbook_tasks' => 0,
            'recurring_tasks' => 0,
            'room_count' => count($room_cards)
        );

        $default_tasks = HHDL_Settings::get_default_tasks($location_id);

        foreach ($room_cards as $room) {
            // Count NewBook tasks
            if (!empty($room['newbook_tasks'])) {
                $counts['newbook_tasks'] += count($room['newbook_tasks']);
            }

            // Count incomplete recurring tasks
            if (!empty($default_tasks)) {
                // Simplified - would need actual completion checking
                $counts['recurring_tasks'] += count($default_tasks);
            }
        }

        return $counts;
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
                'back_to_back' => 0,
                'twins' => 0
            );
        }

        // Calculate filter counts
        $counts = array(
            'arrivals' => 0,
            'departures' => 0,
            'stopovers' => 0,
            'back_to_back' => 0,
            'twins' => 0,
            'blocked' => 0,
            'no_booking' => 0,
            'unoccupied' => 0
        );

        foreach ($rooms_data as $room) {
            // Skip rooms that are excluded from filters
            if (isset($room['filter_excluded']) && $room['filter_excluded']) {
                continue;
            }

            if ($room['is_arriving']) $counts['arrivals']++;
            if ($room['is_departing']) $counts['departures']++;
            if ($room['is_stopover']) $counts['stopovers']++;
            if (isset($room['booking_type']) && $room['booking_type'] === 'back-to-back') $counts['back_to_back']++;
            if ($room['has_twin']) $counts['twins']++;
            if ($room['booking_status'] === 'blocked') $counts['blocked']++;

            // No booking: no guest booking for today (vacant, blocked, or departing with no new booking)
            if ($room['booking_type'] === 'vacant' || $room['booking_type'] === 'blocked' || $room['booking_type'] === 'depart') {
                $counts['no_booking']++;
            }

            // Unoccupied: rooms without guests currently in them
            // Occupied if: stopover, arrived booking, departure not checked out, or back-to-back with present guest
            $is_occupied = false;

            // Stopovers are always occupied (guest staying through)
            if ($room['is_stopover']) {
                $is_occupied = true;
            }
            // Current booking has arrived = occupied
            elseif ($room['booking_status'] === 'arrived') {
                $is_occupied = true;
            }
            // Departure where previous guest hasn't left yet = occupied
            elseif ($room['is_departing'] && isset($room['prev_booking_status']) && $room['prev_booking_status'] === 'arrived') {
                $is_occupied = true;
            }
            // Back-to-back: occupied if either guest is present
            elseif ($room['booking_type'] === 'back-to-back') {
                if ($room['booking_status'] === 'arrived' ||
                    (isset($room['prev_booking_status']) && $room['prev_booking_status'] === 'arrived')) {
                    $is_occupied = true;
                }
            }

            if (!$is_occupied) {
                $counts['unoccupied']++;
            }
        }

        // Check if viewing today's date
        $is_viewing_today = ($date === date('Y-m-d'));

        // Get user preferences
        $user_prefs = self::get_user_preferences(null, $location_id);
        $view_mode = isset($user_prefs['view_mode']) ? $user_prefs['view_mode'] : 'grouped';
        $collapsed_categories = isset($user_prefs['collapsed_categories']) ? $user_prefs['collapsed_categories'] : array();

        // Render based on view mode
        if ($view_mode === 'grouped') {
            // Group rooms by category
            $rooms_by_category = array();
            $categories_order = array();

            foreach ($rooms_data as $room) {
                $category_id = isset($room['category_id']) ? $room['category_id'] : 'uncategorized';

                if (!isset($rooms_by_category[$category_id])) {
                    $rooms_by_category[$category_id] = array();
                    // Store category info for header rendering
                    $categories_order[$category_id] = array(
                        'id' => $category_id,
                        'name' => isset($room['category_name']) ? $room['category_name'] : 'Uncategorized',
                        'order' => isset($room['order']['category_order']) ? $room['order']['category_order'] : 999
                    );
                }

                $rooms_by_category[$category_id][] = $room;
            }

            // Sort categories by their order
            uasort($categories_order, function($a, $b) {
                return $a['order'] - $b['order'];
            });

            // Render each category with its rooms
            foreach ($categories_order as $category_id => $category_info) {
                $is_collapsed = in_array($category_id, $collapsed_categories);

                // Render category header
                $this->render_category_header($category_info, $rooms_by_category[$category_id], $location_id);

                // Render category container
                echo '<div class="hhdl-category-rooms' . ($is_collapsed ? ' hhdl-collapsed' : '') . '" data-category-id="' . esc_attr($category_id) . '">';

                // Render room cards in this category
                foreach ($rooms_by_category[$category_id] as $room) {
                    $this->render_room_card($room, $is_viewing_today);
                }

                echo '</div>';
            }
        } else {
            // Flat view - render all rooms without grouping
            foreach ($rooms_data as $room) {
                $this->render_room_card($room, $is_viewing_today);
            }
        }

        return $counts;
    }

    /**
     * Render individual room card
     */
    private function render_room_card($room, $is_viewing_today = true) {
        $is_vacant = empty($room['booking']) && $room['booking_status'] !== 'blocked';
        $is_blocked = $room['booking_status'] === 'blocked';
        $card_class = $is_blocked ? 'hhdl-blocked' : ($is_vacant ? 'hhdl-vacant' : 'hhdl-booked');

        // Check if early arrival
        $is_early_arrival = !empty($room['booking']) && isset($room['booking']['is_early_arrival']) && $room['booking']['is_early_arrival'];

        // Check if previous booking has late checkout (for glow effect)
        $is_late_checkout = false;
        $show_wider_border = false;

        if (!$room['spans_previous'] &&
            !empty($room['prev_booking_status']) &&
            $room['prev_booking_status'] === 'arrived' &&
            $room['is_departing'] &&
            !empty($room['prev_booking_departure'])) {

            // Get default checkout time from hotel settings
            $hotel = hha()->hotels->get(hha_get_current_location());
            $default_checkout_time = isset($hotel->default_departure_time) ? $hotel->default_departure_time : '10:00';

            // Compare against departure date to determine late checkout
            $departure_date = date('Y-m-d', strtotime($room['prev_booking_departure']));
            $default_checkout_timestamp = strtotime($departure_date . ' ' . $default_checkout_time);
            $actual_departure_timestamp = strtotime($room['prev_booking_departure']);
            $is_late_checkout = ($actual_departure_timestamp > $default_checkout_timestamp);

            // Determine if wider border should be shown
            if ($is_viewing_today) {
                // For today: show wider border for all rooms still arrived and departing
                $show_wider_border = true;
            } else {
                // For future dates: only show wider border for late checkouts
                $show_wider_border = $is_late_checkout;
            }
        }

        // Data attributes for filtering
        $data_attrs = array(
            'data-room-id'         => $room['room_id'],
            'data-is-arriving'     => $room['is_arriving'] ? 'true' : 'false',
            'data-is-departing'    => $room['is_departing'] ? 'true' : 'false',
            'data-is-stopover'     => $room['is_stopover'] ? 'true' : 'false',
            'data-booking-type'    => isset($room['booking_type']) ? $room['booking_type'] : 'vacant',
            'data-has-twin'        => $room['has_twin'] ? 'true' : 'false',
            'data-twin-type'       => isset($room['twin_info']['type']) ? $room['twin_info']['type'] : 'none',
            'data-booking-status'  => $room['booking_status'],
            'data-spans-previous'  => $room['spans_previous'] ? 'true' : 'false',
            'data-spans-next'      => $room['spans_next'] ? 'true' : 'false',
            'data-early-arrival'   => $is_early_arrival ? 'true' : 'false',
            'data-late-checkout'   => $is_late_checkout ? 'true' : 'false',
            'data-show-wider-border' => $show_wider_border ? 'true' : 'false',
            'data-filter-excluded' => (isset($room['filter_excluded']) && $room['filter_excluded']) ? 'true' : 'false'
        );

        // Add previous night data attributes
        if (!$room['spans_previous']) {
            if (!empty($room['prev_booking_status'])) {
                $data_attrs['data-previous-status'] = $room['prev_booking_status'];
            } elseif (!empty($room['prev_is_vacant'])) {
                $data_attrs['data-previous-vacant'] = 'true';
            }
        }

        // Add next night data attributes
        if (!$room['spans_next']) {
            if (!empty($room['next_booking_status'])) {
                $data_attrs['data-next-status'] = $room['next_booking_status'];
            } elseif (!empty($room['next_is_vacant'])) {
                $data_attrs['data-next-vacant'] = 'true';
            }
        }

        // Build inline styles for status colors and task backgrounds
        $inline_style = '';

        // For blocked rooms, apply task background color to entire card
        if ($is_blocked && isset($room['blocking_task']['color'])) {
            $task_color = $room['blocking_task']['color'];
            $inline_style = sprintf('background-color: %s;', $task_color);
        } elseif (!$is_vacant) {
            // For bookings, use border color
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

            <?php
            // Render departure time for previous booking if still arrived and departing today
            if (!$room['spans_previous'] &&
                !empty($room['prev_booking_status']) &&
                $room['prev_booking_status'] === 'arrived' &&
                $room['is_departing'] &&
                !empty($room['prev_booking_departure'])) {

                // Extract time from booking_departure (format: "2025-11-30 11:00:00")
                $departure_time = date('H:i', strtotime($room['prev_booking_departure']));
                $late_class = $is_late_checkout ? 'hhdl-late-checkout' : '';
                ?>
                <div class="hhdl-prev-departure-time <?php echo esc_attr($late_class); ?>">
                    <span class="material-symbols-outlined">snooze</span>
                    <span class="hhdl-time-text"><?php echo esc_html($departure_time); ?></span>
                </div>
                <?php
            }
            ?>

            <?php if ($is_blocked): ?>
                <?php $this->render_blocked_room($room, $is_viewing_today); ?>
            <?php elseif ($is_vacant): ?>
                <?php $this->render_vacant_room($room, $is_viewing_today); ?>
            <?php else: ?>
                <?php $this->render_booked_room($room, $is_viewing_today); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render vacant room content
     */
    private function render_vacant_room($room, $is_viewing_today = true) {
        ?>
        <div class="hhdl-room-header">
            <span class="hhdl-room-number"><?php echo esc_html($room['room_number']); ?></span>
            <span class="hhdl-vacant-label"><?php _e('No booking', 'hhdl'); ?></span>
            <div class="hhdl-status-wrapper">
                <?php if ($is_viewing_today && strtolower($room['site_status']) !== 'unknown'): ?>
                    <span class="hhdl-site-status <?php echo esc_attr(strtolower($room['site_status'])); ?>">
                        <?php echo esc_html($room['site_status']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="hhdl-room-stats">
            <!-- Block 1: Arrival Time (Empty for spacing) -->
            <div class="hhdl-stat-block">
                <div class="hhdl-stat-content"></div>
            </div>

            <!-- Block 2: Bed Type Indicator (Empty for spacing) -->
            <div class="hhdl-stat-block">
                <div class="hhdl-stat-content"></div>
            </div>

            <!-- Block 3: NewBook/Default Tasks -->
            <div class="hhdl-stat-block">
                <?php
                // Count NewBook tasks for this room
                $newbook_tasks = 0;
                $task_class = 'hhdl-task-status hhdl-task-none';
                $task_title = __('No tasks', 'hhdl');
                $task_icon = 'assignment_turned_in';

                if (isset($room['newbook_tasks']) && is_array($room['newbook_tasks'])) {
                    $viewing_date = isset($room['date']) ? $room['date'] : date('Y-m-d');
                    $today = date('Y-m-d');
                    $is_future_date = ($viewing_date > $today);

                    // For future dates, only count tasks for that specific date (no rollover)
                    if ($is_future_date) {
                        $future_tasks = 0;
                        foreach ($room['newbook_tasks'] as $task) {
                            $task_dates = $this->get_task_dates($task);
                            if (!empty($task_dates) && in_array($viewing_date, $task_dates)) {
                                $future_tasks++;
                            }
                        }
                        $newbook_tasks = $future_tasks;

                        // Future dates always show grey styling
                        if ($newbook_tasks > 0) {
                            $task_class = 'hhdl-task-status hhdl-task-future';
                            $task_title = sprintf(_n('%d scheduled task', '%d scheduled tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                            $task_icon = 'assignment';
                        } else {
                            $task_class = 'hhdl-task-status hhdl-task-none';
                            $task_title = __('No tasks scheduled', 'hhdl');
                            $task_icon = 'assignment_turned_in';
                        }
                    } else {
                        // Today or past: count all tasks including rollover and show red/amber
                        $newbook_tasks = count($room['newbook_tasks']);

                        if ($newbook_tasks > 0) {
                            // Check if tasks are current (for today) or rollover (from before today)
                            $has_late = false;
                            $has_rollover = false;

                            foreach ($room['newbook_tasks'] as $task) {
                                $task_dates = $this->get_task_dates($task);
                                if (!empty($task_dates)) {
                                    $latest_task_date = max($task_dates);
                                    if ($latest_task_date < $viewing_date) {
                                        // Task is from before today - rollover (amber)
                                        $has_rollover = true;
                                    } elseif (in_array($viewing_date, $task_dates)) {
                                        // Task is for today - outstanding (red)
                                        $has_late = true;
                                    }
                                }
                            }

                            if ($has_late) {
                                $task_class = 'hhdl-task-status hhdl-task-late';
                                $task_title = sprintf(_n('%d outstanding task', '%d outstanding tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                                $task_icon = 'assignment_late';
                            } elseif ($has_rollover) {
                                $task_class = 'hhdl-task-status hhdl-task-return';
                                $task_title = sprintf(_n('%d rollover task', '%d rollover tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                                $task_icon = 'assignment_late';
                            }
                        } else {
                            $task_class = 'hhdl-task-status hhdl-task-complete';
                            $task_title = __('All tasks complete', 'hhdl');
                            $task_icon = 'assignment_turned_in';
                        }
                    }
                }
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($task_class); ?>" title="<?php echo esc_attr($task_title); ?>">
                    <span class="hhdl-task-count">
                        <span class="material-symbols-outlined"><?php echo esc_html($task_icon); ?></span>
                        <?php if ($newbook_tasks > 0): ?>
                            <span class="hhdl-task-count-badge"><?php echo esc_html($newbook_tasks); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Block 4: Future Tasks Module -->
            <div class="hhdl-stat-block">
                <?php
                // TODO: Add future tasks module integration
                // checklist_rtl - red if waiting tasks, green if all complete, grey if no current tasks
                $future_class = 'hhdl-future-tasks hhdl-tasks-none';
                $future_title = __('No current tasks', 'hhdl');
                $module_tasks = 0; // TODO: Set actual count from future tasks module
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($future_class); ?>" title="<?php echo esc_attr($future_title); ?>">
                    <span class="hhdl-task-count">
                        <span class="material-symbols-outlined">checklist_rtl</span>
                        <?php if ($module_tasks > 0): ?>
                            <span class="hhdl-task-count-badge"><?php echo esc_html($module_tasks); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Block 5: Spoilt Linen Module -->
            <div class="hhdl-stat-block">
                <?php
                // TODO: Add spoilt linen module integration
                // dry_cleaning - grey if no values, amber if unsubmitted, green if submitted
                $linen_class = 'hhdl-linen-status hhdl-linen-none';
                $linen_title = __('No linen data', 'hhdl');
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($linen_class); ?>" title="<?php echo esc_attr($linen_title); ?>">
                    <span class="material-symbols-outlined">dry_cleaning</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render blocked room content
     */
    private function render_blocked_room($room, $is_viewing_today = true) {
        $task = $room['blocking_task'];
        $task_color = isset($task['color']) ? $task['color'] : '#ef4444';
        $task_description = isset($task['description']) ? $task['description'] : 'Blocked';
        $task_icon = isset($task['icon']) ? $task['icon'] : 'construction';

        // Apply uppercase to task description
        $task_description_display = strtoupper($task_description);
        ?>
        <div class="hhdl-room-header">
            <span class="hhdl-room-number"><?php echo esc_html($room['room_number']); ?></span>
            <span class="hhdl-blocked-label">
                <span class="material-symbols-outlined hhdl-task-icon">
                    <?php echo esc_html($task_icon); ?>
                </span>
                <?php echo esc_html($task_description_display); ?>
            </span>
            <div class="hhdl-status-wrapper">
                <?php if ($is_viewing_today && strtolower($room['site_status']) !== 'unknown'): ?>
                    <span class="hhdl-site-status <?php echo esc_attr(strtolower($room['site_status'])); ?>">
                        <?php echo esc_html($room['site_status']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="hhdl-room-stats">
            <!-- Block 1: Arrival Time (Empty for spacing) -->
            <div class="hhdl-stat-block">
                <div class="hhdl-stat-content"></div>
            </div>

            <!-- Block 2: Bed Type Indicator (Empty for spacing) -->
            <div class="hhdl-stat-block">
                <div class="hhdl-stat-content"></div>
            </div>

            <!-- Block 3: NewBook/Default Tasks -->
            <div class="hhdl-stat-block">
                <?php
                // Count NewBook tasks for this room
                $newbook_tasks = 0;
                $task_class = 'hhdl-task-status hhdl-task-none';
                $task_title = __('No tasks', 'hhdl');
                $task_icon_stat = 'assignment_turned_in';

                if (isset($room['newbook_tasks']) && is_array($room['newbook_tasks'])) {
                    $viewing_date = isset($room['date']) ? $room['date'] : date('Y-m-d');
                    $today = date('Y-m-d');
                    $is_future_date = ($viewing_date > $today);

                    // For future dates, only count tasks for that specific date (no rollover)
                    if ($is_future_date) {
                        $future_tasks = 0;
                        foreach ($room['newbook_tasks'] as $task) {
                            $task_dates = $this->get_task_dates($task);
                            if (!empty($task_dates) && in_array($viewing_date, $task_dates)) {
                                $future_tasks++;
                            }
                        }
                        $newbook_tasks = $future_tasks;

                        // Future dates always show grey styling
                        if ($newbook_tasks > 0) {
                            $task_class = 'hhdl-task-status hhdl-task-future';
                            $task_title = sprintf(_n('%d scheduled task', '%d scheduled tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                            $task_icon_stat = 'assignment';
                        } else {
                            $task_class = 'hhdl-task-status hhdl-task-none';
                            $task_title = __('No tasks scheduled', 'hhdl');
                            $task_icon_stat = 'assignment_turned_in';
                        }
                    } else {
                        // Today or past: count all tasks including rollover and show red/amber
                        $newbook_tasks = count($room['newbook_tasks']);

                        if ($newbook_tasks > 0) {
                            // Check if tasks are current (for today) or rollover (from before today)
                            $has_late = false;
                            $has_rollover = false;

                            foreach ($room['newbook_tasks'] as $task) {
                                $task_dates = $this->get_task_dates($task);
                                if (!empty($task_dates)) {
                                    $latest_task_date = max($task_dates);
                                    if ($latest_task_date < $viewing_date) {
                                        // Task is from before today - rollover (amber)
                                        $has_rollover = true;
                                    } elseif (in_array($viewing_date, $task_dates)) {
                                        // Task is for today - outstanding (red)
                                        $has_late = true;
                                    }
                                }
                            }

                            if ($has_late) {
                                $task_class = 'hhdl-task-status hhdl-task-late';
                                $task_title = sprintf(_n('%d outstanding task', '%d outstanding tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                                $task_icon_stat = 'assignment_late';
                            } elseif ($has_rollover) {
                                $task_class = 'hhdl-task-status hhdl-task-return';
                                $task_title = sprintf(_n('%d rollover task', '%d rollover tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                                $task_icon_stat = 'assignment_late';
                            }
                        } else {
                            $task_class = 'hhdl-task-status hhdl-task-complete';
                            $task_title = __('All tasks complete', 'hhdl');
                            $task_icon_stat = 'assignment_turned_in';
                        }
                    }
                }
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($task_class); ?>" title="<?php echo esc_attr($task_title); ?>">
                    <span class="hhdl-task-count">
                        <span class="material-symbols-outlined"><?php echo esc_html($task_icon_stat); ?></span>
                        <?php if ($newbook_tasks > 0): ?>
                            <span class="hhdl-task-count-badge"><?php echo esc_html($newbook_tasks); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Block 4: Future Tasks Module -->
            <div class="hhdl-stat-block">
                <?php
                // TODO: Add future tasks module integration
                // checklist_rtl - red if waiting tasks, green if all complete, grey if no current tasks
                $future_class = 'hhdl-future-tasks hhdl-tasks-none';
                $future_title = __('No current tasks', 'hhdl');
                $module_tasks = 0; // TODO: Set actual count from future tasks module
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($future_class); ?>" title="<?php echo esc_attr($future_title); ?>">
                    <span class="hhdl-task-count">
                        <span class="material-symbols-outlined">checklist_rtl</span>
                        <?php if ($module_tasks > 0): ?>
                            <span class="hhdl-task-count-badge"><?php echo esc_html($module_tasks); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Block 5: Spoilt Linen Module -->
            <div class="hhdl-stat-block">
                <?php
                // TODO: Add spoilt linen module integration
                // dry_cleaning - grey if no values, amber if unsubmitted, green if submitted
                $linen_class = 'hhdl-linen-status hhdl-linen-none';
                $linen_title = __('No linen data', 'hhdl');
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($linen_class); ?>" title="<?php echo esc_attr($linen_title); ?>">
                    <span class="material-symbols-outlined">dry_cleaning</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render booked room content
     */
    private function render_booked_room($room, $is_viewing_today = true) {
        $booking = $room['booking'];
        $can_view_guest = $this->user_can_view_guest_details();

        ?>
        <div class="hhdl-room-header">
            <span class="hhdl-room-number"><?php echo esc_html($room['room_number']); ?></span>
            <?php if ($can_view_guest && !empty($booking['guest_name'])): ?>
                <span class="hhdl-guest-name"><?php echo esc_html($booking['guest_name']); ?></span>
            <?php else: ?>
                <span class="hhdl-guest-name hhdl-guest-blurred">Guest Name</span>
            <?php endif; ?>
            <?php
            // Check for locked booking
            $is_locked = isset($booking['booking_locked']) && $booking['booking_locked'] == '1';
            if ($is_locked):
            ?>
                <span class="hhdl-locked-icon" title="Locked to Room">
                    <span class="material-symbols-outlined">lock</span>
                </span>
            <?php endif; ?>
            <?php if (!empty($booking['night_info'])): ?>
                <span class="hhdl-nights">
                    <span class="material-symbols-outlined">bedtime</span>
                    <?php echo esc_html(preg_replace('/\s*nights?$/i', '', $booking['night_info'])); ?>
                </span>
            <?php endif; ?>
            <div class="hhdl-status-wrapper">
                <?php
                // Show status if:
                // - Viewing today AND arriving today, OR
                // - Booking is already arrived (checked in) - show on all dates
                // - But never show "unknown" status
                $show_status = (($is_viewing_today && $room['is_arriving']) || $room['booking_status'] === 'arrived')
                    && strtolower($room['site_status']) !== 'unknown';
                if ($show_status):
                    if ($room['booking_status'] === 'arrived'):
                ?>
                    <span class="hhdl-site-status arrived">ARRIVED</span>
                <?php else: ?>
                    <span class="hhdl-site-status <?php echo esc_attr(strtolower($room['site_status'])); ?>">
                        <?php echo esc_html($room['site_status']); ?>
                    </span>
                <?php
                    endif;
                endif;
                ?>
            </div>
        </div>

        <div class="hhdl-room-stats">
            <!-- Block 1: Arrival Time -->
            <div class="hhdl-stat-block">
                <?php
                // Show arrival time with glow effect for early arrivals (before default time)
                $early_class = '';
                if (!empty($booking['checkin_time'])) {
                    if (isset($booking['is_early_arrival']) && $booking['is_early_arrival']) {
                        // Early arrival - show with glow unless already arrived
                        $early_class = $room['booking_status'] === 'arrived' ? 'hhdl-checkin-time' : 'hhdl-checkin-time hhdl-early-arrival';
                    } else {
                        // Regular arrival time - show without glow
                        $early_class = 'hhdl-checkin-time';
                    }
                }
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($early_class); ?>">
                    <?php if (!empty($booking['checkin_time'])): ?>
                        <span class="material-symbols-outlined">schedule</span>
                        <span class="hhdl-time-text"><?php echo esc_html($booking['checkin_time']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Block 2: Bed Type Indicator -->
            <div class="hhdl-stat-block">
                <?php
                // Show bed type indicator for all arriving bookings
                if ($room['is_arriving']):
                    // Get location settings for bed colors
                    $location_id = $this->get_current_location();
                    $bed_settings = HHDL_Settings::get_location_settings($location_id);

                    // Get detection info
                    $twin_info = isset($room['twin_info']) ? $room['twin_info'] : array('type' => 'none', 'matched_term' => '', 'source' => '');
                    $extra_bed_info = isset($room['extra_bed_info']) ? $room['extra_bed_info'] : array('has_extra_bed' => false, 'matched_term' => '', 'source' => '');

                    // Determine bed type and color based on priority
                    // Priority: 1. Matched twin 2. Extra bed 3. Potential twin 4. Default
                    $bed_class = 'hhdl-bed-type';
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
                <div class="hhdl-stat-content <?php echo esc_attr($bed_class); ?>"
                     title="<?php echo esc_attr($bed_title); ?>"
                     style="<?php echo esc_attr($bed_inline_style); ?>">
                    <?php if ($room['has_twin']): ?>
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
                <?php endif; ?>
            </div>

            <!-- Block 3: NewBook/Default Tasks -->
            <div class="hhdl-stat-block">
                <?php
                // Count NewBook tasks for this room
                $newbook_tasks = 0;
                $task_class = 'hhdl-task-status hhdl-task-none';
                $task_title = __('No tasks', 'hhdl');
                $task_icon = 'assignment_turned_in';

                if (isset($room['newbook_tasks']) && is_array($room['newbook_tasks'])) {
                    $viewing_date = isset($room['date']) ? $room['date'] : date('Y-m-d');
                    $today = date('Y-m-d');
                    $is_future_date = ($viewing_date > $today);

                    // For future dates, only count tasks for that specific date (no rollover)
                    if ($is_future_date) {
                        $future_tasks = 0;
                        foreach ($room['newbook_tasks'] as $task) {
                            $task_dates = $this->get_task_dates($task);
                            if (!empty($task_dates) && in_array($viewing_date, $task_dates)) {
                                $future_tasks++;
                            }
                        }
                        $newbook_tasks = $future_tasks;

                        // Future dates always show grey styling
                        if ($newbook_tasks > 0) {
                            $task_class = 'hhdl-task-status hhdl-task-future';
                            $task_title = sprintf(_n('%d scheduled task', '%d scheduled tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                            $task_icon = 'assignment';
                        } else {
                            $task_class = 'hhdl-task-status hhdl-task-none';
                            $task_title = __('No tasks scheduled', 'hhdl');
                            $task_icon = 'assignment_turned_in';
                        }
                    } else {
                        // Today or past: count all tasks including rollover
                        $newbook_tasks = count($room['newbook_tasks']);

                        if ($newbook_tasks > 0) {
                            // Check if tasks are current (for today) or rollover (from before today)
                            $has_late = false;
                            $has_rollover = false;

                            foreach ($room['newbook_tasks'] as $task) {
                                $task_dates = $this->get_task_dates($task);
                                if (!empty($task_dates)) {
                                    $latest_task_date = max($task_dates);
                                    if ($latest_task_date < $viewing_date) {
                                        // Task is from before today - rollover (amber)
                                        $has_rollover = true;
                                    } elseif (in_array($viewing_date, $task_dates)) {
                                        // Task is for today - outstanding (red)
                                        $has_late = true;
                                    }
                                }
                            }

                            if ($has_late) {
                                $task_class = 'hhdl-task-status hhdl-task-late';
                                $task_title = sprintf(_n('%d outstanding task', '%d outstanding tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                                $task_icon = 'assignment_late';
                            } elseif ($has_rollover) {
                                $task_class = 'hhdl-task-status hhdl-task-return';
                                $task_title = sprintf(_n('%d rollover task', '%d rollover tasks', $newbook_tasks, 'hhdl'), $newbook_tasks);
                                $task_icon = 'assignment_late';
                            }
                        } else {
                            $task_class = 'hhdl-task-status hhdl-task-complete';
                            $task_title = __('All tasks complete', 'hhdl');
                            $task_icon = 'assignment_turned_in';
                        }
                    }
                }
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($task_class); ?>" title="<?php echo esc_attr($task_title); ?>">
                    <span class="hhdl-task-count">
                        <span class="material-symbols-outlined"><?php echo esc_html($task_icon); ?></span>
                        <?php if ($newbook_tasks > 0): ?>
                            <span class="hhdl-task-count-badge"><?php echo esc_html($newbook_tasks); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Block 4: Future Tasks Module -->
            <div class="hhdl-stat-block">
                <?php
                // TODO: Add future tasks module integration
                // checklist_rtl - red if waiting tasks, green if all complete, grey if no current tasks
                $future_class = 'hhdl-future-tasks hhdl-tasks-none';
                $future_title = __('No current tasks', 'hhdl');
                $module_tasks = 0; // TODO: Set actual count from future tasks module
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($future_class); ?>" title="<?php echo esc_attr($future_title); ?>">
                    <span class="hhdl-task-count">
                        <span class="material-symbols-outlined">checklist_rtl</span>
                        <?php if ($module_tasks > 0): ?>
                            <span class="hhdl-task-count-badge"><?php echo esc_html($module_tasks); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Block 5: Spoilt Linen Module -->
            <div class="hhdl-stat-block">
                <?php
                // TODO: Add spoilt linen module integration
                // dry_cleaning - grey if no values, amber if unsubmitted, green if submitted
                $linen_class = 'hhdl-linen-status hhdl-linen-none';
                $linen_title = __('No linen data', 'hhdl');
                ?>
                <div class="hhdl-stat-content <?php echo esc_attr($linen_class); ?>" title="<?php echo esc_attr($linen_title); ?>">
                    <span class="material-symbols-outlined">dry_cleaning</span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render booking type indicator badge
     */
    private function render_booking_type_indicator($room, $is_viewing_today = true) {
        // Only show booking type badges when viewing today's date
        if (!$is_viewing_today) {
            return;
        }

        $booking_type = isset($room['booking_type']) ? $room['booking_type'] : 'vacant';

        // Only show indicator for arrive and depart (not stopover or back-to-back)
        if ($booking_type === 'vacant' || $booking_type === 'blocked' || $booking_type === 'stopover' || $booking_type === 'back-to-back') {
            return;
        }

        // Define icon and label for arrive and depart only
        $type_config = array(
            'arrive' => array(
                'icon'  => 'flight_land',
                'label' => 'Arrive',
                'title' => 'Arrival - Room was vacant yesterday'
            ),
            'depart' => array(
                'icon'  => 'flight_takeoff',
                'label' => 'Depart',
                'title' => 'Departure - Room becoming vacant today'
            )
        );

        if (!isset($type_config[$booking_type])) {
            return;
        }

        $config = $type_config[$booking_type];
        ?>
        <span class="hhdl-booking-type-badge hhdl-booking-type-<?php echo esc_attr($booking_type); ?>"
              title="<?php echo esc_attr($config['title']); ?>">
            <span class="material-symbols-outlined"><?php echo esc_html($config['icon']); ?></span>
            <span class="hhdl-booking-type-label"><?php echo esc_html($config['label']); ?></span>
        </span>
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
                    <!-- Header content will be populated via AJAX -->
                </div>
                <div class="hhdl-modal-body" id="hhdl-modal-body">
                    <div class="hhdl-loading">
                        <span class="spinner"></span>
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
        // show_uncomplete=true includes outstanding tasks from before today (rollover tasks)
        $tasks_response = $api->get_tasks($yesterday . ' 00:00:00', $tomorrow_end . ' 00:00:00', $task_type_ids, true, null, true);

        // Process responses
        $sites = isset($sites_response['data']) ? $sites_response['data'] : array();
        $bookings = isset($bookings_response['data']) ? $bookings_response['data'] : array();
        $tasks = isset($tasks_response['data']) ? $tasks_response['data'] : array();

        // Build site-to-category map and exclusion lists
        list($site_to_category, $excluded_sites, $site_order_map, $filter_excluded_sites) = $this->build_category_maps($categories_sort, $location_id);

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
                'twin_info'   => array('type' => 'none', 'matched_term' => '', 'source' => ''),
                'extra_bed_info' => array('has_extra_bed' => false, 'matched_term' => '', 'source' => '')
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

                // Check for extra bed
                $extra_bed_detection = $this->detect_extra_bed($booking, $location_id);
                $rooms_by_id[$site_id]['extra_bed_info'] = $extra_bed_detection;
            }
        }

        // Build task type map from integration settings for colors and icons
        $task_type_map = array();
        if (isset($integration['task_types']) && is_array($integration['task_types'])) {
            foreach ($integration['task_types'] as $task_type) {
                if (isset($task_type['id'])) {
                    $task_type_map[$task_type['id']] = array(
                        'name'  => isset($task_type['name']) ? $task_type['name'] : '',
                        'color' => isset($task_type['color']) ? $task_type['color'] : '#9e9e9e',
                        'icon'  => isset($task_type['icon']) ? $task_type['icon'] : 'task'
                    );
                }
            }
        }

        // Process occupy tasks (blocked rooms)
        $occupy_task_count = 0;
        foreach ($tasks as $task) {
            $task_id = isset($task['task_id']) ? $task['task_id'] : 'unknown';
            $task_desc = isset($task['task_description']) ? $task['task_description'] : 'no description';
            $task_occupy = isset($task['task_location_occupy']) ? $task['task_location_occupy'] : 'null';

            if (empty($task['task_location_occupy']) || $task['task_location_occupy'] != 1) {
                continue;
            }

            $occupy_task_count++;

            // Get site ID - check task_location_type to determine which field contains the site ID
            $site_id = '';
            if (!empty($task['task_location_type']) && $task['task_location_type'] === 'bookings') {
                // For booking tasks, use booking_site_id (task_location_id contains booking ID)
                $site_id = !empty($task['booking_site_id']) ? $task['booking_site_id'] : '';
            } else {
                // For other task types, use task_location_id
                $site_id = !empty($task['task_location_id']) ? $task['task_location_id'] : '';
            }

            if (empty($site_id) || !isset($rooms_by_id[$site_id])) {
                continue;
            }

            // Get color and icon from integration task type settings
            $task_type_id = isset($task['task_type_id']) ? $task['task_type_id'] : '';
            $task_color = '#ef4444'; // Default red
            $task_icon = 'construction'; // Default icon

            // Check if we have a matching task type configuration
            if (!empty($task_type_id) && isset($task_type_map[$task_type_id])) {
                $task_color = $task_type_map[$task_type_id]['color'];
                $task_icon = $task_type_map[$task_type_id]['icon'];
            }

            // Build task info array
            $task_info = array(
                'task_id' => $task_id,
                'description' => $task_desc,
                'color' => $task_color,
                'icon' => $task_icon
            );

            // Determine task dates
            $task_dates = $this->get_task_dates($task);

            // Check if task blocks any of our 3 days
            foreach ($task_dates as $task_date) {
                if ($task_date === $yesterday) {
                    $rooms_by_id[$site_id]['bookings']['yesterday'] = $task_info;
                } elseif ($task_date === $date) {
                    $rooms_by_id[$site_id]['bookings']['today'] = $task_info;

                    // Also add occupy task to newbook_tasks array for task count stats
                    if (!isset($rooms_by_id[$site_id]['newbook_tasks'])) {
                        $rooms_by_id[$site_id]['newbook_tasks'] = array();
                    }
                    $rooms_by_id[$site_id]['newbook_tasks'][] = $task;
                } elseif ($task_date === $tomorrow) {
                    $rooms_by_id[$site_id]['bookings']['tomorrow'] = $task_info;
                }
            }
        }

        // Process non-blocking NewBook tasks and group by site for today's date
        $tasks_added = 0;
        foreach ($tasks as $task) {
            // Skip occupy/blocking tasks (already processed above)
            if (!empty($task['task_location_occupy']) && $task['task_location_occupy'] == 1) {
                continue;
            }

            // Get site ID - check task_location_type to determine which field contains the site ID
            $site_id = '';
            if (!empty($task['task_location_type']) && $task['task_location_type'] === 'bookings') {
                // For booking tasks, use booking_site_id (task_location_id contains booking ID)
                $site_id = !empty($task['booking_site_id']) ? $task['booking_site_id'] : '';
            } else {
                // For other task types, use task_location_id
                $site_id = !empty($task['task_location_id']) ? $task['task_location_id'] : '';
            }

            if (empty($site_id) || !isset($rooms_by_id[$site_id])) {
                continue;
            }

            // Check if this task applies to today's date
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
                    $include_task = true;
                }
            }

            if ($include_task) {
                // Initialize newbook_tasks array if not exists
                if (!isset($rooms_by_id[$site_id]['newbook_tasks'])) {
                    $rooms_by_id[$site_id]['newbook_tasks'] = array();
                }

                // Add task to room
                $rooms_by_id[$site_id]['newbook_tasks'][] = $task;
                $tasks_added++;
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
            $blocking_task = null;
            $booking_status = '';

            // Pre-calculate is_arriving for passing to format_booking_data
            $is_arriving = false;
            if ($today_booking && is_array($today_booking) && !isset($today_booking['description'])) {
                // It's a regular booking (not a blocking task)
                $is_arriving = isset($today_booking['booking_arrival']) &&
                              date('Y-m-d', strtotime($today_booking['booking_arrival'])) === $date;
            }

            if ($today_booking && is_array($today_booking)) {
                // Check if it's a blocking task (has 'description' key) or a booking
                if (isset($today_booking['description'])) {
                    // It's a blocking task
                    $booking_status = 'blocked';
                    $blocking_task = $today_booking;
                } else {
                    // It's a regular booking
                    $booking_data = $this->format_booking_data($today_booking, $date, $location_id, $is_arriving);
                    $booking_status = $this->get_booking_status($today_booking);
                }
            }

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

            // Determine spanning - check if SAME booking/task by booking_id/task_id OR both vacant
            $spans_previous = false;
            $spans_next = false;

            // Check if today has a booking or blocking task
            $today_booking_id = !empty($today_booking['booking_id']) ? $today_booking['booking_id'] : null;
            $today_task_id = !empty($today_booking['task_id']) ? $today_booking['task_id'] : null;
            $today_is_vacant = empty($today_booking);

            // Check spanning with previous day
            if ($today_booking_id && $yesterday_booking && is_array($yesterday_booking) && !isset($yesterday_booking['description'])) {
                // Today has booking, check if same booking yesterday
                $yesterday_booking_id = !empty($yesterday_booking['booking_id']) ? $yesterday_booking['booking_id'] : null;
                $spans_previous = ($today_booking_id === $yesterday_booking_id);
            } elseif ($today_task_id && $yesterday_booking && is_array($yesterday_booking) && isset($yesterday_booking['task_id'])) {
                // Today has blocking task, check if same task yesterday
                $yesterday_task_id = !empty($yesterday_booking['task_id']) ? $yesterday_booking['task_id'] : null;
                $spans_previous = ($today_task_id === $yesterday_task_id);
            } elseif ($today_is_vacant && empty($yesterday_booking)) {
                // Both today and yesterday are vacant
                $spans_previous = true;
            }

            // Check spanning with next day
            if ($today_booking_id && $tomorrow_booking && is_array($tomorrow_booking) && !isset($tomorrow_booking['description'])) {
                // Today has booking, check if same booking tomorrow
                $tomorrow_booking_id = !empty($tomorrow_booking['booking_id']) ? $tomorrow_booking['booking_id'] : null;
                $spans_next = ($today_booking_id === $tomorrow_booking_id);
            } elseif ($today_task_id && $tomorrow_booking && is_array($tomorrow_booking) && isset($tomorrow_booking['task_id'])) {
                // Today has blocking task, check if same task tomorrow
                $tomorrow_task_id = !empty($tomorrow_booking['task_id']) ? $tomorrow_booking['task_id'] : null;
                $spans_next = ($today_task_id === $tomorrow_task_id);
            } elseif ($today_is_vacant && empty($tomorrow_booking)) {
                // Both today and tomorrow are vacant
                $spans_next = true;
            }

            // Get adjacent booking statuses and vacancy info
            $prev_booking_status = '';
            $prev_booking_departure = '';
            $prev_is_vacant = false;
            if ($yesterday_booking && is_array($yesterday_booking)) {
                if (isset($yesterday_booking['description'])) {
                    // It's a blocking task
                    $prev_booking_status = 'blocked';
                } else {
                    $prev_booking_status = $this->get_booking_status($yesterday_booking);
                    // Extract departure time from yesterday's booking
                    if (isset($yesterday_booking['booking_departure'])) {
                        $prev_booking_departure = $yesterday_booking['booking_departure'];
                    }
                }
            } else {
                $prev_is_vacant = true;
            }

            $next_booking_status = '';
            $next_is_vacant = false;
            if ($tomorrow_booking && is_array($tomorrow_booking)) {
                if (isset($tomorrow_booking['description'])) {
                    // It's a blocking task
                    $next_booking_status = 'blocked';
                } else {
                    $next_booking_status = $this->get_booking_status($tomorrow_booking);
                }
            } else {
                $next_is_vacant = true;
            }

            // Calculate booking type based on yesterday and today occupancy
            $booking_type = $this->calculate_booking_type(
                $is_arriving,
                $is_departing,
                $is_stopover,
                $prev_is_vacant,
                $today_booking,
                $booking_status
            );

            // Get category information for this room
            $category_info = isset($site_to_category[$room['room_id']]) ? $site_to_category[$room['room_id']] : array(
                'category_id' => 'uncategorized',
                'category_name' => 'Uncategorized'
            );

            $room_cards[] = array(
                'room_id'                 => $room['room_id'],
                'room_number'             => $room['room_number'],
                'site_status'             => $room['site_status'],
                'booking_status'          => $booking_status,
                'is_arriving'             => $is_arriving,
                'is_departing'            => $is_departing,
                'is_stopover'             => $is_stopover,
                'booking_type'            => $booking_type,
                'has_twin'                => $room['has_twin'],
                'twin_info'               => $room['twin_info'],
                'filter_excluded'         => in_array($room['room_id'], $filter_excluded_sites),
                'extra_bed_info'          => $room['extra_bed_info'],
                'spans_previous'          => $spans_previous,
                'spans_next'              => $spans_next,
                'prev_booking_status'     => $prev_booking_status,
                'prev_booking_departure'  => $prev_booking_departure,
                'next_booking_status'     => $next_booking_status,
                'prev_is_vacant'          => $prev_is_vacant,
                'next_is_vacant'          => $next_is_vacant,
                'booking'                 => $booking_data,
                'blocking_task'           => $blocking_task,
                'newbook_tasks'           => isset($room['newbook_tasks']) ? $room['newbook_tasks'] : array(),
                'date'                    => $date,  // Add viewing date for task rollover detection
                'order'                   => $room['order'],
                'category_id'             => $category_info['category_id'],
                'category_name'           => $category_info['category_name']
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
    private function build_category_maps($categories_sort, $location_id) {
        $site_to_category = array();
        $excluded_sites = array();
        $site_order_map = array();
        $filter_excluded_sites = array();

        // Get Daily List module exclusion settings
        $dl_settings = HHDL_Settings::get_location_settings($location_id);
        $dl_excluded_categories = isset($dl_settings['excluded_categories']) ? $dl_settings['excluded_categories'] : array();
        $hide_excluded_categories = isset($dl_settings['hide_excluded_categories']) ? $dl_settings['hide_excluded_categories'] : false;

        foreach ($categories_sort as $cat_index => $category) {
            // Check if excluded at Hotel Hub App level
            if (!empty($category['excluded'])) {
                continue; // Skip excluded categories
            }

            // Check if excluded at Daily List module level
            $is_dl_excluded = in_array($category['id'], $dl_excluded_categories);

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

                // Site-level exclusion (Hotel Hub App level)
                if (!empty($site['excluded'])) {
                    $excluded_sites[] = $site_id;
                }

                // Category-level exclusion (Daily List module level)
                if ($is_dl_excluded) {
                    if ($hide_excluded_categories) {
                        // Hide completely from daily list
                        $excluded_sites[] = $site_id;
                    } else {
                        // Only exclude from filter counts
                        $filter_excluded_sites[] = $site_id;
                    }
                }
            }
        }

        return array($site_to_category, $excluded_sites, $site_order_map, $filter_excluded_sites);
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
     * Format booking data for display
     */
    private function format_booking_data($booking, $date, $location_id, $is_arriving) {
        $arrival_date = date('Y-m-d', strtotime($booking['booking_arrival']));
        $departure_date = date('Y-m-d', strtotime($booking['booking_departure']));

        // Calculate nights
        $total_nights = (strtotime($departure_date) - strtotime($arrival_date)) / 86400;
        $current_night = (strtotime($date) - strtotime($arrival_date)) / 86400 + 1;

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

        // Early arrival detection (only for arrivals)
        $checkin_time = '';
        $is_early_arrival = false;

        if ($is_arriving) {
            // Get hotel's default arrival time
            $hotel = $this->get_hotel_from_location($location_id);
            $default_arrival_time = ($hotel && isset($hotel->default_arrival_time)) ? $hotel->default_arrival_time : '15:00';

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
                // Not early but still show ETA
                $checkin_time = $eta_time;
            } elseif ($arrival_time) {
                // Not early but still show arrival time
                $checkin_time = $arrival_time;
            }
        }

        return array(
            'reference'         => isset($booking['booking_reference_id']) ? $booking['booking_reference_id'] : '',
            'guest_name'        => $guest_name,
            'checkin_time'      => $checkin_time,
            'is_early_arrival'  => $is_early_arrival,
            'pax'               => isset($booking['pax']) ? $booking['pax'] : 0,
            'night_info'        => $current_night . '/' . $total_nights . ' nights',
            'occupancy'         => isset($booking['occupancy']) ? $booking['occupancy'] : '',
            'booking_locked'    => isset($booking['booking_locked']) ? $booking['booking_locked'] : '0'
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

    /**
     * Calculate booking type based on occupancy pattern
     *
     * @param bool $is_arriving Whether the room has an arrival today
     * @param bool $is_departing Whether the room has a departure today (from yesterday's booking)
     * @param bool $is_stopover Whether the room is a stopover (continuing booking)
     * @param bool $prev_is_vacant Whether the room was vacant yesterday
     * @param mixed $today_booking Today's booking data (or null if vacant)
     * @param string $booking_status Current booking status
     * @return string Booking type: 'arrive', 'depart', 'stopover', 'back-to-back', or 'vacant'
     */
    private function calculate_booking_type($is_arriving, $is_departing, $is_stopover, $prev_is_vacant, $today_booking, $booking_status) {
        // Back to Back: Room departing from yesterday AND arriving today (different bookings - turnover)
        if ($is_arriving && $is_departing) {
            return 'back-to-back';
        }

        // Arrive: Room was vacant yesterday, arriving today
        if ($is_arriving && $prev_is_vacant) {
            return 'arrive';
        }

        // Depart: Room departing from yesterday, no booking today (becoming vacant)
        if ($is_departing && empty($today_booking)) {
            return 'depart';
        }

        // Stopover: Room occupied yesterday and tonight (same booking continuing)
        if ($is_stopover && !$prev_is_vacant) {
            return 'stopover';
        }

        // Blocked rooms
        if ($booking_status === 'blocked') {
            return 'blocked';
        }

        // Default: Vacant or other state
        return 'vacant';
    }
}
