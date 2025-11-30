<?php
/**
 * Task Completions Report View
 *
 * @package HotelHub_Housekeeping_DailyList
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Housekeeping Task Completions', 'hhdl'); ?></h1>

    <p class="description">
        <?php _e('View and export task completion history with customizable filters.', 'hhdl'); ?>
    </p>

    <!-- Filters Form -->
    <form method="get" action="" class="hhdl-report-filters" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
        <input type="hidden" name="page" value="hotel-hub-reports">
        <input type="hidden" name="report" value="hhdl-task-completions">

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <!-- Location Filter -->
            <div>
                <label for="location_id" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('Location', 'hhdl'); ?>
                </label>
                <select name="location_id" id="location_id" style="width: 100%;">
                    <option value="0"><?php _e('All Locations', 'hhdl'); ?></option>
                    <?php foreach ($locations as $loc_id => $loc_name): ?>
                        <option value="<?php echo esc_attr($loc_id); ?>" <?php selected($location_id, $loc_id); ?>>
                            <?php echo esc_html($loc_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- From Date -->
            <div>
                <label for="from_date" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('From Date', 'hhdl'); ?>
                </label>
                <input type="date" name="from_date" id="from_date" value="<?php echo esc_attr($from_date); ?>" style="width: 100%;">
            </div>

            <!-- Number of Days -->
            <div>
                <label for="num_days" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('Number of Days', 'hhdl'); ?>
                </label>
                <input type="number" name="num_days" id="num_days" value="<?php echo esc_attr($num_days); ?>" min="1" max="365" style="width: 100%;">
                <small style="color: #646970;"><?php printf(__('To: %s', 'hhdl'), esc_html($to_date)); ?></small>
            </div>

            <!-- Filter Type -->
            <div>
                <label for="filter_type" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('Filter By', 'hhdl'); ?>
                </label>
                <select name="filter_type" id="filter_type" style="width: 100%;">
                    <option value="task_date" <?php selected($filter_type, 'task_date'); ?>>
                        <?php _e('Task Date', 'hhdl'); ?>
                    </option>
                    <option value="completion_date" <?php selected($filter_type, 'completion_date'); ?>>
                        <?php _e('Completion Date', 'hhdl'); ?>
                    </option>
                </select>
            </div>

            <!-- Room Filter -->
            <div>
                <label for="room_filter" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('Room', 'hhdl'); ?>
                </label>
                <input type="text" name="room_filter" id="room_filter" value="<?php echo esc_attr($room_filter); ?>" placeholder="<?php esc_attr_e('All rooms', 'hhdl'); ?>" style="width: 100%;">
            </div>

            <!-- Task Type Filter -->
            <div>
                <label for="task_type_filter" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('Task Type', 'hhdl'); ?>
                </label>
                <input type="text" name="task_type_filter" id="task_type_filter" value="<?php echo esc_attr($task_type_filter); ?>" placeholder="<?php esc_attr_e('All tasks', 'hhdl'); ?>" style="width: 100%;">
            </div>

            <!-- Staff Filter -->
            <div>
                <label for="staff_filter" style="display: block; margin-bottom: 5px; font-weight: 600;">
                    <?php _e('Staff Member', 'hhdl'); ?>
                </label>
                <select name="staff_filter" id="staff_filter" style="width: 100%;">
                    <option value="0"><?php _e('All Staff', 'hhdl'); ?></option>
                    <?php foreach ($staff_list as $staff): ?>
                        <option value="<?php echo esc_attr($staff->ID); ?>" <?php selected($staff_filter, $staff->ID); ?>>
                            <?php echo esc_html($staff->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="button button-primary">
                <span class="dashicons dashicons-filter" style="margin-top: 3px;"></span>
                <?php _e('Apply Filters', 'hhdl'); ?>
            </button>

            <button type="submit" name="export" value="csv" class="button">
                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                <?php _e('Export to CSV', 'hhdl'); ?>
            </button>

            <a href="<?php echo esc_url(admin_url('admin.php?page=hotel-hub-reports&report=hhdl-task-completions')); ?>" class="button">
                <?php _e('Reset Filters', 'hhdl'); ?>
            </a>
        </div>
    </form>

    <!-- Results Summary -->
    <div style="background: #fff; padding: 15px 20px; margin-bottom: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
        <strong><?php _e('Results:', 'hhdl'); ?></strong>
        <?php
        printf(
            _n('%s record found', '%s records found', $total_records, 'hhdl'),
            number_format_i18n($total_records)
        );
        ?>
        <?php if ($total_pages > 1): ?>
            <?php printf(__('(Page %d of %d)', 'hhdl'), $page, $total_pages); ?>
        <?php endif; ?>
    </div>

    <!-- Results Table -->
    <?php if (!empty($records)): ?>
        <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="width: 100px;"><?php _e('Task Date', 'hhdl'); ?></th>
                    <th style="width: 80px;"><?php _e('Room', 'hhdl'); ?></th>
                    <th><?php _e('Task Type', 'hhdl'); ?></th>
                    <th style="width: 160px;"><?php _e('Completed Date/Time', 'hhdl'); ?></th>
                    <th style="width: 150px;"><?php _e('Completed By', 'hhdl'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?php echo esc_html($record->service_date); ?></td>
                        <td><strong><?php echo esc_html($record->room_id); ?></strong></td>
                        <td><?php echo esc_html($record->task_type); ?></td>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($record->completed_at))); ?></td>
                        <td><?php echo esc_html($record->staff_name); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $page_links = paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $page
                    ));
                    echo $page_links;
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="notice notice-info">
            <p><?php _e('No task completions found for the selected criteria.', 'hhdl'); ?></p>
        </div>
    <?php endif; ?>
</div>
