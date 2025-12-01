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
                    <th><?php _e('Twin Detection', 'hhdl'); ?></th>
                    <th><?php _e('Note Types', 'hhdl'); ?></th>
                    <th><?php _e('Category Exclusions', 'hhdl'); ?></th>
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

                    // Ensure tasks have default structure
                    if (empty($tasks)) {
                        $tasks = array(
                            array(
                                'id'                      => uniqid('task_'),
                                'task_type_id'            => '-1',
                                'task_description_filter' => 'Housekeeping',
                                'color'                   => '#10b981',
                                'order'                   => 0
                            )
                        );
                    }

                    // Get available task types for this location
                    $available_task_types = isset($task_types_by_location[$location_id]) ? $task_types_by_location[$location_id] : array();

                    // Get available note types for this location
                    $available_note_types = isset($note_types_by_location[$location_id]) ? $note_types_by_location[$location_id] : array();
                    $visible_note_types = isset($location_settings['visible_note_types']) ? $location_settings['visible_note_types'] : array();

                    // Get available categories for this location
                    $available_categories = isset($categories_by_location[$location_id]) ? $categories_by_location[$location_id] : array();
                    $excluded_categories = isset($location_settings['excluded_categories']) ? $location_settings['excluded_categories'] : array();
                    $hide_excluded_categories = isset($location_settings['hide_excluded_categories']) ? $location_settings['hide_excluded_categories'] : false;
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
                                            <input type="text"
                                                   class="hhdl-task-description-filter"
                                                   placeholder="<?php esc_attr_e('Default task filter (e.g., Housekeeping)...', 'hhdl'); ?>"
                                                   value="<?php echo esc_attr(isset($task['task_description_filter']) ? $task['task_description_filter'] : ''); ?>">
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
                        <td class="location-twin-settings">
                            <div class="hhdl-twin-config">
                                <!-- Bed Type Colors -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Bed Type Colors', 'hhdl'); ?></legend>

                                    <div class="hhdl-color-field">
                                        <label><?php _e('Default Bed Type (Double)', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_default]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_default']) ? $location_settings['bed_color_default'] : '#10b981'); ?>">
                                        <p class="description"><?php _e('Color for standard double/queen bed rooms', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-color-field">
                                        <label><?php _e('Confirmed Twin', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_twin_confirmed]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_twin_confirmed']) ? $location_settings['bed_color_twin_confirmed'] : '#10b981'); ?>">
                                        <p class="description"><?php _e('Color for confirmed twin rooms (from custom field match)', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-color-field">
                                        <label><?php _e('Potential Twin', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_twin_potential]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_twin_potential']) ? $location_settings['bed_color_twin_potential'] : '#f59e0b'); ?>">
                                        <p class="description"><?php _e('Color for potential twin rooms (from notes search)', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-color-field">
                                        <label><?php _e('Extra Bed/Sofabed', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_extra]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_extra']) ? $location_settings['bed_color_extra'] : '#3b82f6'); ?>">
                                        <p class="description"><?php _e('Color for rooms with extra bed or sofabed', 'hhdl'); ?></p>
                                    </div>
                                </fieldset>

                                <!-- Twin Bed Detection -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Twin Bed Detection', 'hhdl'); ?></legend>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Custom Field Names (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_custom_field_names]"
                                               placeholder="<?php esc_attr_e('e.g., Bed Type,Room Configuration', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_custom_field_names']) ? $location_settings['twin_custom_field_names'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Comma-separated list of custom field names to check for twin bed configuration', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Confirmed Twin Values (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_custom_field_values]"
                                               placeholder="<?php esc_attr_e('e.g., Twin,Twin Beds,2 Single Beds', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_custom_field_values']) ? $location_settings['twin_custom_field_values'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Comma-separated list of values that confirm a twin room', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Notes Search Terms (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_notes_search_terms]"
                                               placeholder="<?php esc_attr_e('e.g., twin,2 single,two single', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_notes_search_terms']) ? $location_settings['twin_notes_search_terms'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Comma-separated search terms to find potential twins in booking notes (case insensitive)', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Excluded Terms (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_excluded_terms]"
                                               placeholder="<?php esc_attr_e('e.g., Double or Twin:,Not twin', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_excluded_terms']) ? $location_settings['twin_excluded_terms'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Case-sensitive terms to remove from notes before searching (e.g., "Double or Twin: Double" won\'t match "twin")', 'hhdl'); ?></p>
                                    </div>
                                </fieldset>

                                <!-- Extra Bed/Sofabed Detection -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Extra Bed/Sofabed Detection', 'hhdl'); ?></legend>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Custom Field Names (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][extra_bed_custom_field_names]"
                                               placeholder="<?php esc_attr_e('e.g., Extra Bed,Sofabed', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['extra_bed_custom_field_names']) ? $location_settings['extra_bed_custom_field_names'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Comma-separated list of custom field names to check for extra bed/sofabed', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Custom Field Match Values (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][extra_bed_custom_field_values]"
                                               placeholder="<?php esc_attr_e('e.g., Yes,Sofabed,Pull-out', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['extra_bed_custom_field_values']) ? $location_settings['extra_bed_custom_field_values'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Comma-separated list of values that confirm an extra bed/sofabed', 'hhdl'); ?></p>
                                    </div>

                                    <div class="hhdl-twin-field">
                                        <label><?php _e('Notes Search Terms (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][extra_bed_notes_search_terms]"
                                               placeholder="<?php esc_attr_e('e.g., sofabed,sofa bed,extra bed,pull-out', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['extra_bed_notes_search_terms']) ? $location_settings['extra_bed_notes_search_terms'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Comma-separated search terms to find extra bed/sofabed in booking notes (case insensitive)', 'hhdl'); ?></p>
                                    </div>
                                </fieldset>
                            </div>
                        </td>
                        <td class="location-note-types">
                            <fieldset class="hhdl-fieldset">
                                <legend><?php _e('Applicable Note Types', 'hhdl'); ?></legend>
                                <p class="description">
                                    <?php _e('Select which note types are relevant to the Daily List module. User access is still controlled by permissions.', 'hhdl'); ?>
                                </p>
                                <?php if (empty($available_note_types)): ?>
                                    <p class="hhdl-no-note-types">
                                        <em><?php _e('No note types configured for this location. Please configure note types in Hotel Hub settings.', 'hhdl'); ?></em>
                                    </p>
                                <?php else: ?>
                                    <div class="hhdl-note-types-list">
                                        <?php foreach ($available_note_types as $note_type): ?>
                                            <label class="hhdl-note-type-item" style="display: block; margin-bottom: 8px;">
                                                <input type="checkbox"
                                                       name="locations[<?php echo $location_id; ?>][visible_note_types][]"
                                                       value="<?php echo esc_attr($note_type['id']); ?>"
                                                       <?php checked(in_array($note_type['id'], $visible_note_types)); ?>>
                                                <span class="hhdl-note-type-color"
                                                      style="display: inline-block; width: 16px; height: 16px; background: <?php echo esc_attr($note_type['color']); ?>; border-radius: 3px; vertical-align: middle; margin-right: 4px;"></span>
                                                <span class="material-symbols-outlined"
                                                      style="font-size: 16px; vertical-align: middle; margin-right: 4px;">
                                                    <?php echo esc_html($note_type['icon']); ?>
                                                </span>
                                                <span><?php echo esc_html($note_type['name']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </fieldset>
                        </td>
                        <td class="location-categories">
                            <fieldset class="hhdl-fieldset">
                                <legend><?php _e('Room Category Exclusions', 'hhdl'); ?></legend>
                                <p class="description">
                                    <?php _e('Exclude specific room categories from filters and optionally from the entire daily list.', 'hhdl'); ?>
                                </p>
                                <?php if (empty($available_categories)): ?>
                                    <p class="hhdl-no-categories">
                                        <em><?php _e('No room categories configured for this location. Please configure categories in Hotel Hub settings.', 'hhdl'); ?></em>
                                    </p>
                                <?php else: ?>
                                    <div class="hhdl-categories-list">
                                        <?php foreach ($available_categories as $category): ?>
                                            <?php
                                            // Count rooms in category
                                            $room_count = isset($category['sites']) ? count($category['sites']) : 0;
                                            ?>
                                            <label class="hhdl-category-item" style="display: block; margin-bottom: 8px;">
                                                <input type="checkbox"
                                                       name="locations[<?php echo $location_id; ?>][excluded_categories][]"
                                                       value="<?php echo esc_attr($category['id']); ?>"
                                                       <?php checked(in_array($category['id'], $excluded_categories)); ?>>
                                                <span><?php echo esc_html($category['name']); ?></span>
                                                <span class="category-count" style="color: #646970; font-size: 12px;">(<?php echo $room_count; ?> <?php echo _n('room', 'rooms', $room_count, 'hhdl'); ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #ddd;">
                                        <label>
                                            <input type="checkbox"
                                                   name="locations[<?php echo $location_id; ?>][hide_excluded_categories]"
                                                   value="1"
                                                   <?php checked($hide_excluded_categories, true); ?>>
                                            <strong><?php _e('Hide excluded categories from list entirely', 'hhdl'); ?></strong>
                                        </label>
                                        <p class="description" style="margin-left: 24px;">
                                            <?php _e('When unchecked, excluded categories will only be removed from filter counts but will still appear in "All Rooms" view.', 'hhdl'); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </fieldset>
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
    min-width: 180px;
}

.hhdl-task-description-filter {
    flex: 1;
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    background: white;
    min-width: 200px;
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

/* Twin Detection Settings */
.location-twin-settings {
    min-width: 350px;
}

.hhdl-twin-config {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.hhdl-twin-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    color: #1d2327;
}

.hhdl-twin-field input[type="text"] {
    width: 100%;
    max-width: 100%;
}

.hhdl-twin-field .description {
    margin-top: 4px;
    font-size: 12px;
    color: #646970;
    font-style: italic;
}

/* Fieldset Styling */
.hhdl-fieldset {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 16px;
    margin-bottom: 16px;
    background: #fafafa;
}

.hhdl-fieldset legend {
    font-weight: 600;
    font-size: 13px;
    color: #1d2327;
    padding: 0 8px;
}

/* Color Field Styling */
.hhdl-color-field {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.hhdl-color-field:last-child {
    margin-bottom: 0;
}

.hhdl-color-field label {
    min-width: 180px;
    font-weight: 600;
    margin-bottom: 0;
    color: #1d2327;
}

.hhdl-color-field input[type="color"] {
    width: 60px;
    height: 32px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
}

.hhdl-color-field .description {
    flex: 1;
    margin: 0;
    font-size: 12px;
    color: #646970;
    font-style: italic;
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
                <input type="text" class="hhdl-task-description-filter" placeholder="<?php esc_attr_e('Default task filter (e.g., Housekeeping)...', 'hhdl'); ?>">
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

    // Update JSON when any field changes
    $(document).on('input change', '.hhdl-task-type-select, .hhdl-task-description-filter, .hhdl-task-color', function() {
        const manager = $(this).closest('.hhdl-task-manager');
        updateTasksJSON(manager);
    });

    // Update hidden JSON field
    function updateTasksJSON(manager) {
        const tasks = [];
        manager.find('.hhdl-task-item').each(function(index) {
            const item = $(this);
            const taskTypeId = item.find('.hhdl-task-type-select').val();
            const taskDescriptionFilter = item.find('.hhdl-task-description-filter').val().trim();

            // Only add if both task type and filter are provided
            if (taskTypeId && taskDescriptionFilter) {
                tasks.push({
                    id: item.data('task-id'),
                    task_type_id: taskTypeId,
                    task_description_filter: taskDescriptionFilter,
                    color: item.find('.hhdl-task-color').val(),
                    order: index
                });
            }
        });
        manager.find('.hhdl-tasks-json').val(JSON.stringify(tasks));
    }
});
</script>
