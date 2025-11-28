<?php
/**
 * Settings Page Template
 *
 * @package HotelHub_Housekeeping_DailyList
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap hhdl-settings-wrap">
    <h1><?php _e('Daily List Settings', 'hhdl'); ?></h1>

    <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully.', 'hhdl'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="hhdl-settings-form">
        <input type="hidden" name="action" value="hhdl_save_settings">
        <?php wp_nonce_field('hhdl_save_settings', 'hhdl_settings_nonce'); ?>

        <table class="form-table hhdl-locations-table">
            <thead>
                <tr>
                    <th><?php _e('Location', 'hhdl'); ?></th>
                    <th><?php _e('Enabled', 'hhdl'); ?></th>
                    <th><?php _e('Default Tasks', 'hhdl'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $location): ?>
                    <?php
                    $location_id = $location['id'];
                    $location_settings = isset($settings[$location_id]) ? $settings[$location_id] : array(
                        'enabled' => false,
                        'default_tasks' => array()
                    );
                    $is_enabled = $location_settings['enabled'];
                    $tasks = $location_settings['default_tasks'];

                    // Ensure tasks have default structure (task type mappings)
                    if (empty($tasks)) {
                        $tasks = array(
                            array(
                                'id'           => uniqid('task_'),
                                'task_type_id' => '-1',
                                'name'         => __('Housekeeping', 'hhdl'),
                                'color'        => '#10b981',
                                'order'        => 0
                            )
                        );
                    }

                    // Get available task types for this location
                    $available_task_types = isset($task_types_by_location[$location_id]) ? $task_types_by_location[$location_id] : array();
                    ?>
                    <tr class="hhdl-location-row">
                        <td class="location-name">
                            <strong><?php echo esc_html($location['name']); ?></strong>
                        </td>
                        <td class="location-enabled">
                            <label class="hhdl-switch">
                                <input type="checkbox"
                                       name="locations[<?php echo $location_id; ?>][enabled]"
                                       value="1"
                                       <?php checked($is_enabled, true); ?>>
                                <span class="hhdl-slider"></span>
                            </label>
                        </td>
                        <td class="location-tasks">
                            <div class="hhdl-task-manager" data-location-id="<?php echo $location_id; ?>">
                                <!-- Task List -->
                                <div class="hhdl-task-list">
                                    <?php foreach ($tasks as $index => $task): ?>
                                        <div class="hhdl-task-item" data-task-id="<?php echo esc_attr($task['id']); ?>">
                                            <span class="hhdl-task-handle">☰</span>
                                            <select class="hhdl-task-type-select">
                                                <option value=""><?php _e('Select task type...', 'hhdl'); ?></option>
                                                <?php foreach ($available_task_types as $task_type): ?>
                                                    <option value="<?php echo esc_attr($task_type['id']); ?>"
                                                            data-name="<?php echo esc_attr($task_type['name']); ?>"
                                                            <?php selected(isset($task['task_type_id']) ? $task['task_type_id'] : '', $task_type['id']); ?>>
                                                        <?php echo esc_html($task_type['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="color"
                                                   class="hhdl-task-color"
                                                   value="<?php echo esc_attr($task['color']); ?>">
                                            <button type="button" class="hhdl-task-remove button" title="<?php esc_attr_e('Remove task', 'hhdl'); ?>">×</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Add Task Button -->
                                <button type="button" class="hhdl-add-task button button-secondary">
                                    <?php _e('+ Add Task', 'hhdl'); ?>
                                </button>

                                <!-- Hidden field to store tasks JSON -->
                                <input type="hidden"
                                       name="locations[<?php echo $location_id; ?>][tasks_json]"
                                       class="hhdl-tasks-json"
                                       value="<?php echo esc_attr(json_encode($tasks)); ?>">
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php submit_button(__('Save Settings', 'hhdl')); ?>
    </form>
</div>

<style>
.hhdl-settings-wrap {
    max-width: 1200px;
}

.hhdl-locations-table {
    width: 100%;
    border-collapse: collapse;
}

.hhdl-locations-table thead th {
    background: #f0f0f1;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #c3c4c7;
}

.hhdl-location-row td {
    padding: 16px 12px;
    border-bottom: 1px solid #c3c4c7;
    vertical-align: top;
}

.location-name {
    width: 200px;
}

.location-enabled {
    width: 100px;
}

/* Toggle Switch */
.hhdl-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.hhdl-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.hhdl-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: 0.3s;
    border-radius: 24px;
}

.hhdl-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: 0.3s;
    border-radius: 50%;
}

input:checked + .hhdl-slider {
    background-color: #10b981;
}

input:checked + .hhdl-slider:before {
    transform: translateX(26px);
}

/* Task Manager */
.hhdl-task-manager {
    min-width: 400px;
}

.hhdl-task-list {
    margin-bottom: 12px;
}

.hhdl-task-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 6px;
    cursor: move;
}

.hhdl-task-handle {
    cursor: grab;
    color: #666;
    font-size: 16px;
    user-select: none;
}

.hhdl-task-type-select {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    background: white;
}

.hhdl-task-color {
    width: 50px;
    height: 32px;
    border: 1px solid #ddd;
    border-radius: 3px;
    cursor: pointer;
}

.hhdl-task-remove {
    padding: 4px 10px;
    min-width: 32px;
    font-size: 20px;
    line-height: 1;
    color: #dc2626;
    border-color: #dc2626;
}

.hhdl-task-remove:hover {
    background: #dc2626;
    color: white;
}

.hhdl-add-task {
    margin-top: 8px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Store task types data for each location
    const taskTypesByLocation = <?php echo json_encode($task_types_by_location); ?>;

    // Initialize sortable for each task list
    $('.hhdl-task-list').sortable({
        handle: '.hhdl-task-handle',
        placeholder: 'hhdl-task-placeholder',
        update: function(event, ui) {
            updateTasksJSON($(this).closest('.hhdl-task-manager'));
        }
    });

    // Add task button
    $('.hhdl-add-task').on('click', function() {
        const manager = $(this).closest('.hhdl-task-manager');
        const taskList = manager.find('.hhdl-task-list');
        const taskId = 'task_' + Date.now();
        const locationId = manager.data('location-id');
        const taskTypes = taskTypesByLocation[locationId] || [];

        // Build task type options
        let optionsHtml = '<option value=""><?php esc_attr_e('Select task type...', 'hhdl'); ?></option>';
        taskTypes.forEach(function(taskType) {
            optionsHtml += `<option value="${taskType.id}" data-name="${taskType.name}">${taskType.name}</option>`;
        });

        const taskHtml = `
            <div class="hhdl-task-item" data-task-id="${taskId}">
                <span class="hhdl-task-handle">☰</span>
                <select class="hhdl-task-type-select">${optionsHtml}</select>
                <input type="color" class="hhdl-task-color" value="#10b981">
                <button type="button" class="hhdl-task-remove button">×</button>
            </div>
        `;

        taskList.append(taskHtml);
        updateTasksJSON(manager);
    });

    // Remove task button
    $(document).on('click', '.hhdl-task-remove', function() {
        const manager = $(this).closest('.hhdl-task-manager');
        $(this).closest('.hhdl-task-item').remove();
        updateTasksJSON(manager);
    });

    // Update JSON when task type or color changes
    $(document).on('change', '.hhdl-task-type-select, .hhdl-task-color', function() {
        const manager = $(this).closest('.hhdl-task-manager');
        updateTasksJSON(manager);
    });

    // Update hidden JSON field
    function updateTasksJSON(manager) {
        const tasks = [];
        manager.find('.hhdl-task-item').each(function(index) {
            const item = $(this);
            const taskTypeSelect = item.find('.hhdl-task-type-select');
            const taskTypeId = taskTypeSelect.val();
            const taskTypeName = taskTypeSelect.find('option:selected').data('name') || taskTypeSelect.find('option:selected').text();

            // Only add if task type is selected
            if (taskTypeId) {
                tasks.push({
                    id: item.data('task-id'),
                    task_type_id: taskTypeId,
                    name: taskTypeName,
                    color: item.find('.hhdl-task-color').val(),
                    order: index
                });
            }
        });
        manager.find('.hhdl-tasks-json').val(JSON.stringify(tasks));
    }
});
</script>
