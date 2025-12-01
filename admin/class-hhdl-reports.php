<?php
/**
 * Reports Class - Handles report registration and rendering
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * HHDL Reports class
 */
class HHDL_Reports {

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
        // Register reports with Hotel Hub
        add_action('hha_register_reports', array($this, 'register_reports'));
    }

    /**
     * Register reports with Hotel Hub
     *
     * @param HHA_Reports $reports Reports manager instance
     */
    public function register_reports($reports) {
        $reports->register('hhdl-task-completions', array(
            'title'       => __('Housekeeping Task Completions', 'hhdl'),
            'description' => __('View task completion history with detailed filters and export options.', 'hhdl'),
            'callback'    => array($this, 'render_task_completions_report'),
            'capability'  => 'view_reports',
            'module'      => 'housekeeping-daily-list',
            'icon'        => 'dashicons-yes-alt'
        ));
    }

    /**
     * Render task completions report
     */
    public function render_task_completions_report() {
        global $wpdb;

        // Get filter values
        $location_id = isset($_GET['location_id']) ? intval($_GET['location_id']) : 0;
        $from_date = isset($_GET['from_date']) ? sanitize_text_field($_GET['from_date']) : date('Y-m-d', strtotime('-7 days'));
        $num_days = isset($_GET['num_days']) ? intval($_GET['num_days']) : 7;
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : 'task_date';
        $room_filter = isset($_GET['room_filter']) ? sanitize_text_field($_GET['room_filter']) : '';
        $task_type_filter = isset($_GET['task_type_filter']) ? sanitize_text_field($_GET['task_type_filter']) : '';
        $staff_filter = isset($_GET['staff_filter']) ? intval($_GET['staff_filter']) : 0;

        // Calculate to_date based on from_date + num_days
        $to_date = date('Y-m-d', strtotime($from_date . ' +' . ($num_days - 1) . ' days'));

        // Handle CSV export
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->export_csv($location_id, $from_date, $to_date, $filter_type, $room_filter, $task_type_filter, $staff_filter);
            exit;
        }

        // Get locations list
        $locations = array();
        if (function_exists('hha')) {
            $hotels = hha()->hotels->get_all();
            foreach ($hotels as $hotel) {
                $locations[$hotel->id] = $hotel->name;
            }
        }

        // Build query
        $table_name = $wpdb->prefix . 'hhdl_task_completions';

        $where_clauses = array();
        $query_params = array();

        if ($location_id > 0) {
            $where_clauses[] = 'tc.location_id = %d';
            $query_params[] = $location_id;
        }

        // Date filter based on filter type
        if ($filter_type === 'task_date') {
            $where_clauses[] = 'tc.service_date >= %s';
            $where_clauses[] = 'tc.service_date <= %s';
        } else {
            $where_clauses[] = 'DATE(tc.completed_at) >= %s';
            $where_clauses[] = 'DATE(tc.completed_at) <= %s';
        }
        $query_params[] = $from_date;
        $query_params[] = $to_date;

        // Room filter
        if (!empty($room_filter)) {
            $where_clauses[] = 'tc.room_id LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($room_filter) . '%';
        }

        // Task type filter
        if (!empty($task_type_filter)) {
            $where_clauses[] = 'tc.task_type LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($task_type_filter) . '%';
        }

        // Staff filter
        if ($staff_filter > 0) {
            $where_clauses[] = 'tc.completed_by = %d';
            $query_params[] = $staff_filter;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$table_name} tc {$where_sql}";
        if (!empty($query_params)) {
            $count_query = $wpdb->prepare($count_query, $query_params);
        }
        $total_records = $wpdb->get_var($count_query);

        // Pagination
        $per_page = 50;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        $total_pages = ceil($total_records / $per_page);

        // Get records (explicitly include task_type_id and task_description)
        $query = "SELECT tc.*, u.display_name as staff_name
                  FROM {$table_name} tc
                  LEFT JOIN {$wpdb->users} u ON tc.completed_by = u.ID
                  {$where_sql}
                  ORDER BY tc.completed_at DESC
                  LIMIT %d OFFSET %d";

        $final_params = array_merge($query_params, array($per_page, $offset));
        $records = $wpdb->get_results($wpdb->prepare($query, $final_params));

        // Enhance records with room names and task type names
        $records = $this->enhance_records_with_names($records);

        // Get staff list for filter
        $staff_query = "SELECT DISTINCT u.ID, u.display_name
                        FROM {$wpdb->users} u
                        INNER JOIN {$table_name} tc ON u.ID = tc.completed_by
                        ORDER BY u.display_name ASC";
        $staff_list = $wpdb->get_results($staff_query);

        // Load view template
        include HHDL_PLUGIN_DIR . 'admin/views/report-completions.php';
    }

    /**
     * Export report to CSV
     */
    private function export_csv($location_id, $from_date, $to_date, $filter_type, $room_filter, $task_type_filter, $staff_filter) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hhdl_task_completions';

        $where_clauses = array();
        $query_params = array();

        if ($location_id > 0) {
            $where_clauses[] = 'tc.location_id = %d';
            $query_params[] = $location_id;
        }

        if ($filter_type === 'task_date') {
            $where_clauses[] = 'tc.service_date >= %s';
            $where_clauses[] = 'tc.service_date <= %s';
        } else {
            $where_clauses[] = 'DATE(tc.completed_at) >= %s';
            $where_clauses[] = 'DATE(tc.completed_at) <= %s';
        }
        $query_params[] = $from_date;
        $query_params[] = $to_date;

        if (!empty($room_filter)) {
            $where_clauses[] = 'tc.room_id LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($room_filter) . '%';
        }

        if (!empty($task_type_filter)) {
            $where_clauses[] = 'tc.task_type LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($task_type_filter) . '%';
        }

        if ($staff_filter > 0) {
            $where_clauses[] = 'tc.completed_by = %d';
            $query_params[] = $staff_filter;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        $query = "SELECT tc.*, u.display_name as staff_name
                  FROM {$table_name} tc
                  LEFT JOIN {$wpdb->users} u ON tc.completed_by = u.ID
                  {$where_sql}
                  ORDER BY tc.completed_at DESC";

        if (!empty($query_params)) {
            $query = $wpdb->prepare($query, $query_params);
        }

        $records = $wpdb->get_results($query);

        // Enhance records with room names and task type names
        $records = $this->enhance_records_with_names($records);

        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="housekeeping-task-completions-' . date('Y-m-d') . '.csv"');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Write CSV headers (updated to include Task Description)
        fputcsv($output, array('Task Date', 'Room', 'Task Type', 'Task Description', 'Completed Date/Time', 'Completed By'));

        // Write data rows
        foreach ($records as $record) {
            fputcsv($output, array(
                $record->service_date,
                $record->room_name,
                $record->task_type_name,
                isset($record->task_description) ? $record->task_description : '',
                $record->completed_at,
                $record->staff_name
            ));
        }

        fclose($output);
    }

    /**
     * Enhance records with room names and task type names
     *
     * @param array $records Task completion records from database
     * @return array Enhanced records with room_name and task_type_name properties
     */
    private function enhance_records_with_names($records) {
        if (empty($records)) {
            return $records;
        }

        // Extract unique locations and room IDs
        $locations_rooms = array();
        foreach ($records as $record) {
            if (!isset($locations_rooms[$record->location_id])) {
                $locations_rooms[$record->location_id] = array();
            }
            if (!in_array($record->room_id, $locations_rooms[$record->location_id])) {
                $locations_rooms[$record->location_id][] = $record->room_id;
            }
        }

        // Build lookup arrays for room names and task types per location
        $room_name_lookup = array();
        $task_type_lookup = array();

        foreach ($locations_rooms as $location_id => $room_ids) {
            // Get hotel from location
            $hotel = null;
            if (function_exists('hha')) {
                $hotel = hha()->hotels->get($location_id);
            }

            if (!$hotel) {
                error_log('HHDL Reports: Hotel not found for location_id: ' . $location_id);
                continue;
            }

            // Get room and task type data from Hotel Hub integration settings
            $integration = hha()->integrations->get_settings($hotel->id, 'newbook');
            if (empty($integration)) {
                error_log('HHDL Reports: NewBook integration not configured for hotel: ' . $hotel->id);
                continue;
            }

            // Build room name lookup from categories_sort
            $categories_sort = isset($integration['categories_sort']) ? $integration['categories_sort'] : array();
            $room_count = 0;

            foreach ($categories_sort as $category) {
                // Skip excluded categories
                if (!empty($category['excluded'])) {
                    continue;
                }

                if (isset($category['sites']) && is_array($category['sites'])) {
                    foreach ($category['sites'] as $site) {
                        $site_id = isset($site['site_id']) ? $site['site_id'] : '';
                        $site_name = isset($site['site_name']) ? $site['site_name'] : '';

                        if ($site_id && $site_name) {
                            // Store with both string and int keys for flexibility
                            $room_name_lookup[$location_id][(string)$site_id] = $site_name;
                            $room_name_lookup[$location_id][(int)$site_id] = $site_name;
                            $room_count++;
                        }
                    }
                }
            }

            error_log('HHDL Reports: Loaded ' . $room_count . ' room names from settings for location_id: ' . $location_id);

            // Build task type name lookup from task_types
            $task_types = isset($integration['task_types']) ? $integration['task_types'] : array();
            $task_type_count = 0;

            // Debug: Log the raw task_types structure
            error_log('HHDL Reports: Raw task_types data: ' . print_r($task_types, true));

            foreach ($task_types as $task_type) {
                if (isset($task_type['id']) && isset($task_type['name'])) {
                    // Store with both string and int keys for flexibility
                    $task_type_lookup[$location_id][(string)$task_type['id']] = $task_type['name'];
                    $task_type_lookup[$location_id][(int)$task_type['id']] = $task_type['name'];
                    $task_type_count++;
                    error_log('HHDL Reports: Added task type - ID: ' . $task_type['id'] . ' Name: ' . $task_type['name']);
                }
            }

            error_log('HHDL Reports: Loaded ' . $task_type_count . ' task types from settings for location_id: ' . $location_id);
        }

        // Enhance each record with room name and task type name
        foreach ($records as $record) {
            // Add room name (try both string and int lookups)
            if (isset($room_name_lookup[$record->location_id][$record->room_id])) {
                $record->room_name = $room_name_lookup[$record->location_id][$record->room_id];
            } elseif (isset($room_name_lookup[$record->location_id][(string)$record->room_id])) {
                $record->room_name = $room_name_lookup[$record->location_id][(string)$record->room_id];
            } elseif (isset($room_name_lookup[$record->location_id][(int)$record->room_id])) {
                $record->room_name = $room_name_lookup[$record->location_id][(int)$record->room_id];
            } else {
                error_log('HHDL Reports: Room name not found for room_id: ' . $record->room_id . ' at location_id: ' . $record->location_id);
                $record->room_name = $record->room_id; // Fallback to ID if name not found
            }

            // Add task type name (try both string and int lookups)
            if (isset($record->task_type_id) && $record->task_type_id) {
                error_log('HHDL Reports: Looking up task_type_id: ' . $record->task_type_id . ' (type: ' . gettype($record->task_type_id) . ') for location_id: ' . $record->location_id);
                error_log('HHDL Reports: Available task type IDs: ' . print_r(array_keys($task_type_lookup[$record->location_id] ?? array()), true));

                if (isset($task_type_lookup[$record->location_id][$record->task_type_id])) {
                    $record->task_type_name = $task_type_lookup[$record->location_id][$record->task_type_id];
                    error_log('HHDL Reports: Found task type name (direct match): ' . $record->task_type_name);
                } elseif (isset($task_type_lookup[$record->location_id][(string)$record->task_type_id])) {
                    $record->task_type_name = $task_type_lookup[$record->location_id][(string)$record->task_type_id];
                    error_log('HHDL Reports: Found task type name (string match): ' . $record->task_type_name);
                } elseif (isset($task_type_lookup[$record->location_id][(int)$record->task_type_id])) {
                    $record->task_type_name = $task_type_lookup[$record->location_id][(int)$record->task_type_id];
                    error_log('HHDL Reports: Found task type name (int match): ' . $record->task_type_name);
                } else {
                    error_log('HHDL Reports: Task type name not found for task_type_id: ' . $record->task_type_id . ' at location_id: ' . $record->location_id);
                    $record->task_type_name = ''; // Empty if not found
                }
            } else {
                error_log('HHDL Reports: task_type_id is null or empty for record');
                $record->task_type_name = ''; // Empty if task_type_id is null
            }
        }

        return $records;
    }
}
