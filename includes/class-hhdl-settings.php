<?php
/**
 * Settings Class - Manages plugin settings and configuration
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings class for Daily List module
 */
class HHDL_Settings {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Settings option name
     */
    const OPTION_NAME = 'hhdl_location_settings';

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
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_hhdl_save_settings', array($this, 'save_settings'));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('hhdl_settings', self::OPTION_NAME);
    }

    /**
     * Render settings page
     */
    public static function render() {
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'hhdl'));
        }

        // Get locations from Hotel Hub App
        $locations = self::get_locations();

        // Get current settings
        $settings = get_option(self::OPTION_NAME, array());

        // Load template
        include HHDL_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /**
     * Save settings
     */
    public function save_settings() {
        // Check nonce
        if (!isset($_POST['hhdl_settings_nonce']) ||
            !wp_verify_nonce($_POST['hhdl_settings_nonce'], 'hhdl_save_settings')) {
            wp_die(__('Security check failed', 'hhdl'));
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'hhdl'));
        }

        $settings = array();

        // Process each location
        if (isset($_POST['locations']) && is_array($_POST['locations'])) {
            foreach ($_POST['locations'] as $location_id => $location_data) {
                $location_id = intval($location_id);

                $settings[$location_id] = array(
                    'enabled' => isset($location_data['enabled']) ? true : false,
                    'default_tasks' => array()
                );

                // Process tasks if provided
                if (isset($location_data['tasks_json']) && !empty($location_data['tasks_json'])) {
                    $tasks = json_decode(stripslashes($location_data['tasks_json']), true);
                    if (is_array($tasks)) {
                        $settings[$location_id]['default_tasks'] = $this->sanitize_tasks($tasks);
                    }
                }
            }
        }

        // Save settings
        update_option(self::OPTION_NAME, $settings);

        // Redirect back with success message
        wp_redirect(add_query_arg(
            array(
                'page' => 'hhdl-settings',
                'updated' => 'true'
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Sanitize tasks array
     *
     * @param array $tasks Raw tasks data
     * @return array Sanitized tasks
     */
    private function sanitize_tasks($tasks) {
        $sanitized = array();

        foreach ($tasks as $task) {
            if (!isset($task['name']) || empty($task['name'])) {
                continue;
            }

            $sanitized[] = array(
                'id'    => isset($task['id']) ? sanitize_text_field($task['id']) : uniqid('task_'),
                'name'  => sanitize_text_field($task['name']),
                'color' => isset($task['color']) ? sanitize_hex_color($task['color']) : '#10b981',
                'order' => isset($task['order']) ? intval($task['order']) : 0
            );
        }

        return $sanitized;
    }

    /**
     * Get locations from Hotel Hub App
     *
     * @return array Locations array
     */
    public static function get_locations() {
        // Check if Hotel Hub App function exists
        if (function_exists('hha_get_locations')) {
            return hha_get_locations();
        }

        // Fallback - return mock data for development
        return array(
            array(
                'id'   => 1,
                'name' => 'Sample Location 1'
            ),
            array(
                'id'   => 2,
                'name' => 'Sample Location 2'
            )
        );
    }

    /**
     * Get settings for a specific location
     *
     * @param int $location_id Location ID
     * @return array Location settings
     */
    public static function get_location_settings($location_id) {
        $all_settings = get_option(self::OPTION_NAME, array());

        if (isset($all_settings[$location_id])) {
            return $all_settings[$location_id];
        }

        // Return defaults
        return array(
            'enabled' => false,
            'default_tasks' => self::get_default_tasks_template()
        );
    }

    /**
     * Get default tasks for a location
     *
     * @param int $location_id Location ID
     * @return array Tasks array
     */
    public static function get_default_tasks($location_id) {
        $settings = self::get_location_settings($location_id);
        return isset($settings['default_tasks']) ? $settings['default_tasks'] : array();
    }

    /**
     * Check if module is enabled for a location
     *
     * @param int $location_id Location ID
     * @return bool
     */
    public static function is_enabled($location_id) {
        $settings = self::get_location_settings($location_id);
        return isset($settings['enabled']) ? $settings['enabled'] : false;
    }

    /**
     * Get default tasks template
     *
     * @return array Default tasks structure
     */
    private static function get_default_tasks_template() {
        return array(
            array(
                'id'    => uniqid('task_'),
                'name'  => __('Clean Room', 'hhdl'),
                'color' => '#10b981',
                'order' => 0
            ),
            array(
                'id'    => uniqid('task_'),
                'name'  => __('Change Linen', 'hhdl'),
                'color' => '#3b82f6',
                'order' => 1
            ),
            array(
                'id'    => uniqid('task_'),
                'name'  => __('Restock Amenities', 'hhdl'),
                'color' => '#f59e0b',
                'order' => 2
            )
        );
    }
}
