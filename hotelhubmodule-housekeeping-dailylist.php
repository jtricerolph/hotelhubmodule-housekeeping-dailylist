<?php
/**
 * Plugin Name: Hotel Hub Module - Housekeeping - Daily List
 * Plugin URI: https://github.com/jtricerolph/hotelhubmodule-housekeeping-dailylist
 * Description: Daily housekeeping task management with NewBook integration and real-time sync
 * Version: 2.1.6
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
define('HHDL_VERSION', '2.1.6');
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

        // Store database version
        update_option('hhdl_db_version', HHDL_VERSION);
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        flush_rewrite_rules();
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
