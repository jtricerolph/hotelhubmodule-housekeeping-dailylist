<?php
/**
 * Plugin Name: Hotel Hub Module - Housekeeping - Daily List
 * Plugin URI: https://github.com/jtricerolph/hotelhubmodule-housekeeping-dailylist
 * Description: Daily housekeeping task management with NewBook integration and real-time sync
 * Version: 2.2.3
 * Author: JTR
 * License: GPL v2 or later
 * Text Domain: hhdl
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('HHDL_VERSION', '2.2.3');
define('HHDL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HHDL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HHDL_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
class HotelHub_Housekeeping_DailyList {

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
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once HHDL_PLUGIN_DIR . 'includes/class-hhdl-core.php';
        require_once HHDL_PLUGIN_DIR . 'includes/class-hhdl-settings.php';
        require_once HHDL_PLUGIN_DIR . 'includes/class-hhdl-display.php';
        require_once HHDL_PLUGIN_DIR . 'includes/class-hhdl-ajax.php';
        require_once HHDL_PLUGIN_DIR . 'includes/class-hhdl-heartbeat.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize core functionality
        add_action('plugins_loaded', array($this, 'init'));

        // Activity log cleanup
        add_action('hhdl_cleanup_activity_log', array($this, 'cleanup_activity_log'));

        // Hook into linen count submission for activity logging
        add_action('hhlc_linen_submitted', array($this, 'log_linen_submission'), 10, 6);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize core components
        HHDL_Core::instance();
    }

    /**
     * Activation hook
     */
    public function activate() {
        $this->create_tables();
        $this->schedule_cleanup();
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'hhdl_task_completions';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            room_id VARCHAR(50) NOT NULL,
            task_id BIGINT(20) UNSIGNED DEFAULT NULL,
            task_type_id BIGINT(20) DEFAULT NULL COMMENT 'NewBook task type ID (supports negative IDs)',
            task_description VARCHAR(255) DEFAULT NULL COMMENT 'Task description/name',
            completed_by BIGINT(20) UNSIGNED NOT NULL COMMENT 'WordPress user ID',
            completed_at DATETIME NOT NULL,
            booking_ref VARCHAR(100) DEFAULT NULL,
            service_date DATE NOT NULL,
            PRIMARY KEY (id),
            KEY location_date_idx (location_id, service_date),
            KEY room_date_idx (room_id, service_date),
            KEY completed_by_idx (completed_by),
            KEY task_id_idx (task_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Migrate old task_type column to task_description if needed
        $old_column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'task_type'");
        if (!empty($old_column_exists)) {
            // Migrate data from old column to new column
            $wpdb->query("UPDATE {$table_name} SET task_description = task_type WHERE task_description IS NULL");
            // Drop old column
            $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN task_type");
        }

        // Migration: Change task_type_id to support negative integers (NewBook uses -1, -2, etc.)
        $column_check = $wpdb->get_row("SHOW COLUMNS FROM {$table_name} LIKE 'task_type_id'");
        if ($column_check && stripos($column_check->Type, 'unsigned') !== false) {
            $wpdb->query("ALTER TABLE {$table_name} MODIFY task_type_id BIGINT(20) DEFAULT NULL COMMENT 'NewBook task type ID (supports negative IDs)'");
            error_log('HHDL: Migrated task_type_id column to support negative integers');
        }

        // Activity log table
        $activity_table = $wpdb->prefix . 'hhdl_activity_log';

        $sql_activity = "CREATE TABLE {$activity_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT(20) UNSIGNED NOT NULL,
            room_id VARCHAR(50) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_data TEXT DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            user_name VARCHAR(255) DEFAULT NULL,
            occurred_at DATETIME NOT NULL,
            service_date DATE NOT NULL,
            booking_ref VARCHAR(100) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY location_date_idx (location_id, service_date),
            KEY room_date_idx (room_id, service_date),
            KEY event_type_idx (event_type),
            KEY occurred_at_idx (occurred_at)
        ) $charset_collate;";

        dbDelta($sql_activity);

        // Store database version
        update_option('hhdl_db_version', HHDL_VERSION);
    }

    /**
     * Schedule cleanup cron job
     */
    private function schedule_cleanup() {
        if (!wp_next_scheduled('hhdl_cleanup_activity_log')) {
            wp_schedule_event(time(), 'daily', 'hhdl_cleanup_activity_log');
        }
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        wp_clear_scheduled_hook('hhdl_cleanup_activity_log');
        flush_rewrite_rules();
    }

    /**
     * Cleanup activity log - delete events older than 5 days
     */
    public function cleanup_activity_log() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hhdl_activity_log';
        $cutoff_date = date('Y-m-d', strtotime('-5 days'));

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_name} WHERE service_date < %s",
                $cutoff_date
            )
        );

        if ($deleted !== false) {
            error_log("HHDL: Cleaned up {$deleted} activity log entries older than {$cutoff_date}");
        }
    }

    /**
     * Log linen count submission to activity log
     *
     * @param int $location_id Location ID
     * @param string $room_id Room ID (site_id)
     * @param string $service_date Service date
     * @param array $counts Linen counts
     * @param string $booking_ref Booking reference
     * @param string $user_name User display name
     */
    public function log_linen_submission($location_id, $room_id, $service_date, $counts, $booking_ref, $user_name) {
        // Only log if HHDL_Ajax class is available
        if (!class_exists('HHDL_Ajax')) {
            return;
        }

        // Get room_number (site_name) from NewBook API for display in activity log
        $room_number = $this->get_room_number_from_api($location_id, $room_id);

        // Calculate total count
        $total_count = array_sum($counts);

        HHDL_Ajax::log_activity(
            $location_id,
            $room_number, // Use room_number (site_name) for display in activity log
            'linen_submit',
            array(
                'submitted_by' => $user_name,
                'total_count' => $total_count,
                'item_count' => count($counts)
            ),
            $service_date,
            $booking_ref
        );
    }

    /**
     * Get room number (site_name) from NewBook API
     *
     * @param int $location_id Location ID
     * @param string $room_id Room ID (site_id)
     * @return string Room number or fallback to room_id
     */
    private function get_room_number_from_api($location_id, $room_id) {
        // Get NewBook API client
        if (!function_exists('hha')) {
            return $room_id;
        }

        $hotel = hha()->hotels->get($location_id);
        if (!$hotel) {
            return $room_id;
        }

        $integration = hha()->integrations->get_settings($hotel->id, 'newbook');
        if (empty($integration)) {
            return $room_id;
        }

        require_once HHA_PLUGIN_DIR . 'includes/class-hha-newbook-api.php';
        $api = new HHA_NewBook_API($integration);

        // Get site details
        $sites_response = $api->get_sites(true);
        $sites = isset($sites_response['data']) ? $sites_response['data'] : array();

        // Find room by site_id and return site_name
        foreach ($sites as $site) {
            if ($site['site_id'] === $room_id) {
                return isset($site['site_name']) ? $site['site_name'] : $room_id;
            }
        }

        // Fallback to room_id if not found
        return $room_id;
    }
}

/**
 * Initialize plugin
 */
function hhdl_init() {
    return HotelHub_Housekeeping_DailyList::instance();
}

// Start the plugin
hhdl_init();
