<?php
/**
 * Core Class - Main functionality coordinator
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core class for Daily List module
 */
class HHDL_Core {

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
        $this->init_hooks();
        $this->init_components();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register module with Hotel Hub App
        add_filter('hha_register_modules', array($this, 'register_module'));

        // Register permissions with Workforce Authentication
        add_filter('wfa_register_permissions', array($this, 'register_permissions'));

        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Initialize sub-components
     */
    private function init_components() {
        HHDL_Settings::instance();
        HHDL_Display::instance();
        HHDL_Ajax::instance();
        HHDL_Heartbeat::instance();
    }

    /**
     * Register module with Hotel Hub App
     *
     * @param array $modules Existing modules
     * @return array Modified modules array
     */
    public function register_module($modules) {
        $modules['daily_list'] = array(
            'id'             => 'daily_list',
            'name'           => __('Daily List', 'hhdl'),
            'description'    => __('Daily housekeeping task management', 'hhdl'),
            'department'     => 'housekeeping',
            'icon'           => 'dashicons-clipboard',
            'color'          => '#10b981',
            'permissions'    => array(
                'hhdl_access_module',
                'hhdl_view_guest_details',
                'hhdl_view_rate_details',
                'hhdl_view_all_notes'
            ),
            'integrations'   => array('newbook'),
            'settings_pages' => array(
                array(
                    'slug'       => 'hhdl-settings',
                    'title'      => __('Daily List Settings', 'hhdl'),
                    'menu_title' => __('Daily List', 'hhdl'),
                    'callback'   => array('HHDL_Settings', 'render')
                )
            )
        );

        return $modules;
    }

    /**
     * Register permissions with Workforce Authentication
     *
     * @param WFA_Permissions $permissions_manager WFA Permissions object
     */
    public function register_permissions($permissions_manager) {
        // Register permission: Access module
        $permissions_manager->register_permission(
            'hhdl_access_module',
            __('Access Daily List Module', 'hhdl'),
            __('View and use the Daily List module', 'hhdl'),
            'daily_list'
        );

        // Register permission: View guest details
        $permissions_manager->register_permission(
            'hhdl_view_guest_details',
            __('View Guest Details', 'hhdl'),
            __('View guest names and personal information', 'hhdl'),
            'daily_list'
        );

        // Register permission: View rate details
        $permissions_manager->register_permission(
            'hhdl_view_rate_details',
            __('View Rate Details', 'hhdl'),
            __('View pricing and rate information', 'hhdl'),
            'daily_list'
        );

        // Register permission: View all notes
        $permissions_manager->register_permission(
            'hhdl_view_all_notes',
            __('View All Notes', 'hhdl'),
            __('View all booking notes (not just housekeeping)', 'hhdl'),
            'daily_list'
        );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        // Only load on module pages
        if (!$this->is_module_page()) {
            return;
        }

        // Check permission
        if (!$this->user_can_access()) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'hhdl-daily-list',
            HHDL_PLUGIN_URL . 'assets/css/daily-list.css',
            array(),
            HHDL_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'hhdl-daily-list',
            HHDL_PLUGIN_URL . 'assets/js/daily-list.js',
            array('jquery', 'heartbeat'),
            HHDL_VERSION,
            true
        );

        // Localize script
        wp_localize_script('hhdl-daily-list', 'hhdlAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hhdl_ajax_nonce'),
            'userId'  => get_current_user_id(),
            'strings' => array(
                'error'           => __('An error occurred', 'hhdl'),
                'taskCompleted'   => __('Task completed successfully', 'hhdl'),
                'loading'         => __('Loading...', 'hhdl'),
                'noRooms'         => __('No rooms found for this date', 'hhdl'),
            )
        ));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on settings page
        if (!$this->is_settings_page($hook)) {
            return;
        }

        // Admin CSS for settings page
        wp_enqueue_style(
            'hhdl-admin',
            HHDL_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            HHDL_VERSION
        );

        // Color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');
    }

    /**
     * Check if current page is a module page
     *
     * @return bool
     */
    private function is_module_page() {
        // Check if Hotel Hub App function exists
        if (function_exists('hha_is_module_page')) {
            return hha_is_module_page('daily_list');
        }

        // Fallback check
        return isset($_GET['module']) && $_GET['module'] === 'daily_list';
    }

    /**
     * Check if current page is settings page
     *
     * @param string $hook Current admin page hook
     * @return bool
     */
    private function is_settings_page($hook) {
        return strpos($hook, 'hhdl-settings') !== false;
    }

    /**
     * Check if current user can access module
     *
     * @return bool
     */
    private function user_can_access() {
        // Check if Workforce Authentication function exists
        if (function_exists('wfa_user_has_permission')) {
            return wfa_user_has_permission('hhdl_access_module');
        }

        // Fallback to WordPress capability
        return current_user_can('edit_posts');
    }
}
