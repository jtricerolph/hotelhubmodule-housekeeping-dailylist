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

        <div class="hhdl-locations-list">
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

                <div class="hhdl-location-card">
                    <div class="hhdl-location-header">
                        <h2><?php echo esc_html($location['name']); ?></h2>
                        <label class="hhdl-switch">
                            <input type="checkbox"
                                   name="locations[<?php echo $location_id; ?>][enabled]"
                                   value="1"
                                   <?php checked($is_enabled, true); ?>>
                            <span class="hhdl-slider"></span>
                        </label>
                    </div>

                    <div class="hhdl-location-settings">
                        <!-- Default Tasks -->
                        <div class="hhdl-settings-section">
                            <h3><?php _e('Default Tasks', 'hhdl'); ?></h3>
                            <div class="hhdl-task-manager" data-location-id="<?php echo $location_id; ?>">
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
                                <button type="button" class="hhdl-add-task button button-secondary">
                                    <?php _e('+ Add Task', 'hhdl'); ?>
                                </button>
                                <input type="hidden"
                                       name="locations[<?php echo $location_id; ?>][tasks_json]"
                                       class="hhdl-tasks-json"
                                       value="<?php echo esc_attr(json_encode($tasks)); ?>">
                            </div>
                        </div>

                        <!-- Twin Detection -->
                        <div class="hhdl-settings-section">
                            <h3><?php _e('Twin & Bed Detection', 'hhdl'); ?></h3>
                            <div class="hhdl-settings-grid">
                                <!-- Bed Type Colors -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Bed Type Colors', 'hhdl'); ?></legend>
                                    <div class="hhdl-color-field">
                                        <label><?php _e('Default (Double)', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_default]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_default']) ? $location_settings['bed_color_default'] : '#10b981'); ?>">
                                        <p class="description"><?php _e('Standard double/queen', 'hhdl'); ?></p>
                                    </div>
                                    <div class="hhdl-color-field">
                                        <label><?php _e('Confirmed Twin', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_twin_confirmed]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_twin_confirmed']) ? $location_settings['bed_color_twin_confirmed'] : '#10b981'); ?>">
                                        <p class="description"><?php _e('From custom field', 'hhdl'); ?></p>
                                    </div>
                                    <div class="hhdl-color-field">
                                        <label><?php _e('Potential Twin', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_twin_potential]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_twin_potential']) ? $location_settings['bed_color_twin_potential'] : '#f59e0b'); ?>">
                                        <p class="description"><?php _e('From notes search', 'hhdl'); ?></p>
                                    </div>
                                    <div class="hhdl-color-field">
                                        <label><?php _e('Extra Bed', 'hhdl'); ?></label>
                                        <input type="color"
                                               name="locations[<?php echo $location_id; ?>][bed_color_extra]"
                                               value="<?php echo esc_attr(isset($location_settings['bed_color_extra']) ? $location_settings['bed_color_extra'] : '#3b82f6'); ?>">
                                        <p class="description"><?php _e('Extra bed/sofabed', 'hhdl'); ?></p>
                                    </div>
                                </fieldset>

                                <!-- Twin Detection -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Twin Bed Detection', 'hhdl'); ?></legend>
                                    <div class="hhdl-field">
                                        <label><?php _e('Custom Field Names (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_custom_field_names]"
                                               placeholder="<?php esc_attr_e('e.g., Bed Type,Room Configuration', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_custom_field_names']) ? $location_settings['twin_custom_field_names'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Custom fields to check for twin config', 'hhdl'); ?></p>
                                    </div>
                                    <div class="hhdl-field">
                                        <label><?php _e('Confirmed Values (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_custom_field_values]"
                                               placeholder="<?php esc_attr_e('e.g., Twin,Twin Beds,2 Single', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_custom_field_values']) ? $location_settings['twin_custom_field_values'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Values that confirm twin room', 'hhdl'); ?></p>
                                    </div>
                                    <div class="hhdl-field">
                                        <label><?php _e('Notes Search Terms (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_notes_search_terms]"
                                               placeholder="<?php esc_attr_e('e.g., twin,2 single,two single', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_notes_search_terms']) ? $location_settings['twin_notes_search_terms'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Terms to find potential twins in notes', 'hhdl'); ?></p>
                                    </div>
                                    <div class="hhdl-field">
                                        <label><?php _e('Excluded Terms (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][twin_excluded_terms]"
                                               placeholder="<?php esc_attr_e('e.g., Double or Twin:', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['twin_excluded_terms']) ? $location_settings['twin_excluded_terms'] : ''); ?>"
                                               class="widefat">
                                        <p class="description"><?php _e('Case-sensitive terms to exclude before searching', 'hhdl'); ?></p>
                                    </div>
                                </fieldset>

                                <!-- Extra Bed Detection -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Extra Bed/Sofabed Detection', 'hhdl'); ?></legend>
                                    <div class="hhdl-field">
                                        <label><?php _e('Custom Field Names (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][extra_bed_custom_field_names]"
                                               placeholder="<?php esc_attr_e('e.g., Extra Bed,Sofabed', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['extra_bed_custom_field_names']) ? $location_settings['extra_bed_custom_field_names'] : ''); ?>"
                                               class="widefat">
                                    </div>
                                    <div class="hhdl-field">
                                        <label><?php _e('Match Values (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][extra_bed_custom_field_values]"
                                               placeholder="<?php esc_attr_e('e.g., Yes,Sofabed,Pull-out', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['extra_bed_custom_field_values']) ? $location_settings['extra_bed_custom_field_values'] : ''); ?>"
                                               class="widefat">
                                    </div>
                                    <div class="hhdl-field">
                                        <label><?php _e('Notes Search Terms (CSV)', 'hhdl'); ?></label>
                                        <input type="text"
                                               name="locations[<?php echo $location_id; ?>][extra_bed_notes_search_terms]"
                                               placeholder="<?php esc_attr_e('e.g., sofabed,sofa bed,extra bed', 'hhdl'); ?>"
                                               value="<?php echo esc_attr(isset($location_settings['extra_bed_notes_search_terms']) ? $location_settings['extra_bed_notes_search_terms'] : ''); ?>"
                                               class="widefat">
                                    </div>
                                </fieldset>
                            </div>
                        </div>

                        <!-- Note Types & Categories Grid -->
                        <div class="hhdl-settings-section">
                            <div class="hhdl-settings-grid-2col">
                                <!-- Note Types -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Applicable Note Types', 'hhdl'); ?></legend>
                                    <p class="description"><?php _e('Select note types relevant to Daily List. Access controlled by permissions.', 'hhdl'); ?></p>
                                    <?php if (empty($available_note_types)): ?>
                                        <p class="hhdl-empty"><em><?php _e('No note types configured for this location.', 'hhdl'); ?></em></p>
                                    <?php else: ?>
                                        <div class="hhdl-checkbox-list">
                                            <?php foreach ($available_note_types as $note_type): ?>
                                                <label>
                                                    <input type="checkbox"
                                                           name="locations[<?php echo $location_id; ?>][visible_note_types][]"
                                                           value="<?php echo esc_attr($note_type['id']); ?>"
                                                           <?php checked(in_array($note_type['id'], $visible_note_types)); ?>>
                                                    <span class="hhdl-note-color" style="background: <?php echo esc_attr($note_type['color']); ?>;"></span>
                                                    <span class="material-symbols-outlined"><?php echo esc_html($note_type['icon']); ?></span>
                                                    <span><?php echo esc_html($note_type['name']); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </fieldset>

                                <!-- Category Exclusions -->
                                <fieldset class="hhdl-fieldset">
                                    <legend><?php _e('Room Category Exclusions', 'hhdl'); ?></legend>
                                    <p class="description"><?php _e('Exclude categories from filters and optionally from list.', 'hhdl'); ?></p>
                                    <?php if (empty($available_categories)): ?>
                                        <p class="hhdl-empty"><em><?php _e('No categories configured for this location.', 'hhdl'); ?></em></p>
                                    <?php else: ?>
                                        <div class="hhdl-checkbox-list">
                                            <?php foreach ($available_categories as $category): ?>
                                                <?php $room_count = isset($category['sites']) ? count($category['sites']) : 0; ?>
                                                <label>
                                                    <input type="checkbox"
                                                           name="locations[<?php echo $location_id; ?>][excluded_categories][]"
                                                           value="<?php echo esc_attr($category['id']); ?>"
                                                           <?php checked(in_array($category['id'], $excluded_categories)); ?>>
                                                    <span><?php echo esc_html($category['name']); ?></span>
                                                    <span class="count">(<?php echo $room_count; ?>)</span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="hhdl-hide-option">
                                            <label>
                                                <input type="checkbox"
                                                       name="locations[<?php echo $location_id; ?>][hide_excluded_categories]"
                                                       value="1"
                                                       <?php checked($hide_excluded_categories, true); ?>>
                                                <strong><?php _e('Hide excluded from list entirely', 'hhdl'); ?></strong>
                                            </label>
                                            <p class="description"><?php _e('When unchecked, excluded categories removed from filters but visible in "All Rooms".', 'hhdl'); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </fieldset>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php submit_button(__('Save Settings', 'hhdl')); ?>
    </form>
</div>

<style>
.hhdl-settings-wrap {
    max-width: 1400px;
}

.hhdl-locations-list {
    display: flex;
    flex-direction: column;
    gap: 24px;
    margin-top: 20px;
}

.hhdl-location-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 6px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}

.hhdl-location-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #c3c4c7;
    background: #f6f7f7;
}

.hhdl-location-header h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.hhdl-location-settings {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.hhdl-settings-section h3 {
    margin: 0 0 16px 0;
    font-size: 15px;
    font-weight: 600;
    color: #1d2327;
}

.hhdl-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 16px;
}

.hhdl-settings-grid-2col {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 16px;
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
    min-width: 180px;
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    background: white;
}

.hhdl-task-description-filter {
    flex: 1;
    min-width: 200px;
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

/* Fieldsets */
.hhdl-fieldset {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 16px;
    background: #fafafa;
}

.hhdl-fieldset legend {
    font-weight: 600;
    font-size: 13px;
    color: #1d2327;
    padding: 0 8px;
}

.hhdl-fieldset > .description {
    margin: 0 0 12px 0;
    font-size: 12px;
    color: #646970;
}

.hhdl-field {
    margin-bottom: 16px;
}

.hhdl-field:last-child {
    margin-bottom: 0;
}

.hhdl-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
    color: #1d2327;
    font-size: 13px;
}

.hhdl-field .description {
    margin-top: 4px;
    font-size: 11px;
    color: #646970;
}

/* Color Fields */
.hhdl-color-field {
    display: grid;
    grid-template-columns: 140px 60px 1fr;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.hhdl-color-field:last-child {
    margin-bottom: 0;
}

.hhdl-color-field label {
    font-weight: 600;
    margin: 0;
    font-size: 13px;
}

.hhdl-color-field input[type="color"] {
    height: 32px;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
}

.hhdl-color-field .description {
    margin: 0;
    font-size: 11px;
    color: #646970;
}

/* Checkbox Lists */
.hhdl-checkbox-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.hhdl-checkbox-list label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px;
    background: #fff;
    border-radius: 3px;
    cursor: pointer;
}

.hhdl-checkbox-list label:hover {
    background: #f0f0f1;
}

.hhdl-note-color {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 3px;
}

.hhdl-checkbox-list .material-symbols-outlined {
    font-size: 16px;
}

.hhdl-checkbox-list .count {
    color: #646970;
    font-size: 12px;
    margin-left: auto;
}

.hhdl-hide-option {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #ddd;
}

.hhdl-hide-option label {
    display: block;
    margin-bottom: 4px;
}

.hhdl-hide-option .description {
    margin-left: 24px;
    font-size: 11px;
    color: #646970;
}

.hhdl-empty {
    color: #646970;
    font-style: italic;
    margin: 8px 0;
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
