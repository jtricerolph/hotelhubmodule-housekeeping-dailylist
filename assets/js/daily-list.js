/**
 * Daily List Module - Frontend JavaScript
 *
 * Handles room list loading, filtering, modal interactions, and task completion
 */

(function($) {
    'use strict';

    let currentLocationId = 0;
    let currentDate = '';
    let lastCheckTimestamp = new Date().toISOString();

    /**
     * Initialize Daily List
     */
    function initDailyList() {
        // Get initial state from DOM
        const roomList = $('#hhdl-room-list');
        currentLocationId = parseInt(roomList.data('location')) || 0;
        currentDate = roomList.data('date') || '';

        // Initialize components
        initDatePicker();
        initFilters();
        initModal();
        initHeartbeat();

        // Load initial room list
        loadRoomList(currentDate);
    }

    /**
     * Initialize date picker handler
     */
    function initDatePicker() {
        $('#hhdl-date-picker').on('change', function() {
            currentDate = $(this).val();
            updateDateDisplay(currentDate);
            loadRoomList(currentDate);
        });
    }

    /**
     * Update the displayed date text
     */
    function updateDateDisplay(date) {
        const dateObj = new Date(date + 'T00:00:00');
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        const formattedDate = dateObj.toLocaleDateString('en-US', options);
        $('.hhdl-viewing-date').text(formattedDate);
    }

    /**
     * Initialize filter button handlers
     */
    function initFilters() {
        $('.hhdl-filter-btn').on('click', function() {
            $('.hhdl-filter-btn').removeClass('active');
            $(this).addClass('active');
            const filter = $(this).data('filter');
            filterRooms(filter);
        });
    }

    /**
     * Filter visible rooms based on criteria
     */
    function filterRooms(filterType) {
        $('.hhdl-room-card').each(function() {
            const card = $(this);
            let shouldShow = false;

            // Always show blocked rooms (maintenance tasks are important)
            if (card.data('booking-status') === 'blocked') {
                shouldShow = true;
            } else {
                // Apply normal filtering for bookings and vacant rooms
                switch(filterType) {
                    case 'arrivals':
                        shouldShow = card.data('is-arriving') === true;
                        break;
                    case 'departs':
                        shouldShow = card.data('is-departing') === true;
                        break;
                    case 'stopovers':
                        shouldShow = card.data('is-stopover') === true;
                        break;
                    case 'back-to-back':
                        shouldShow = card.data('booking-type') === 'back-to-back';
                        break;
                    case 'twins':
                        shouldShow = card.data('has-twin') === true;
                        break;
                    case 'all':
                    default:
                        shouldShow = true;
                }
            }

            card.toggle(shouldShow);
        });
    }

    /**
     * Update filter button counts
     */
    function updateFilterCounts(counts) {
        $('.hhdl-filter-btn[data-filter="arrivals"]').html('Arrivals <span class="hhdl-count-badge">' + counts.arrivals + '</span>');
        $('.hhdl-filter-btn[data-filter="departs"]').html('Departures <span class="hhdl-count-badge">' + counts.departures + '</span>');
        $('.hhdl-filter-btn[data-filter="stopovers"]').html('Stopovers <span class="hhdl-count-badge">' + counts.stopovers + '</span>');
        $('.hhdl-filter-btn[data-filter="back-to-back"]').html('Back to Back <span class="hhdl-count-badge">' + counts.back_to_back + '</span>');
        $('.hhdl-filter-btn[data-filter="twins"]').html('Twins <span class="hhdl-count-badge">' + counts.twins + '</span>');
    }

    /**
     * Load room list via AJAX
     */
    function loadRoomList(date) {
        const roomList = $('#hhdl-room-list');
        roomList.html('<div class="hhdl-loading"><span class="spinner"></span><p>' + hhdlAjax.strings.loading + '</p></div>');

        $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'hhdl_get_rooms',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                date: date
            },
            success: function(response) {
                if (response.success) {
                    // Update room list HTML
                    roomList.html(response.data.html);
                    initRoomCards();

                    // Update filter counts
                    if (response.data.counts) {
                        updateFilterCounts(response.data.counts);
                    }
                } else {
                    roomList.html('<div class="hhdl-notice hhdl-notice-error"><p>' + (response.data.message || hhdlAjax.strings.error) + '</p></div>');
                }
            },
            error: function() {
                roomList.html('<div class="hhdl-notice hhdl-notice-error"><p>' + hhdlAjax.strings.error + '</p></div>');
            }
        });
    }

    /**
     * Initialize room card click handlers
     */
    function initRoomCards() {
        $('.hhdl-room-card').off('click').on('click', function() {
            const roomId = $(this).data('room-id');
            openRoomModal(roomId, currentDate);
        });
    }

    /**
     * Initialize modal handlers
     */
    function initModal() {
        // Close button
        $(document).on('click', '.hhdl-modal-close', closeModal);

        // Click outside modal to close
        $(document).on('click', '.hhdl-modal-overlay', function(e) {
            if ($(e.target).hasClass('hhdl-modal-overlay')) {
                closeModal();
            }
        });

        // ESC key to close
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#hhdl-modal').hasClass('active')) {
                closeModal();
            }
        });
    }

    /**
     * Open room details modal
     */
    function openRoomModal(roomId, date) {
        const modal = $('#hhdl-modal');
        const modalHeader = modal.find('.hhdl-modal-header');
        const modalBody = $('#hhdl-modal-body');

        // Clear previous content and show loading state
        modalHeader.html('');
        modalBody.html('<div class="hhdl-loading"><span class="spinner"></span><p>' + hhdlAjax.strings.loading + '</p></div>');
        modal.addClass('active');

        // Fetch room details
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'hhdl_get_room_details',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                room_id: roomId,
                date: date
            },
            success: function(response) {
                console.log('HHDL: Modal AJAX response received', response);

                if (response.success) {
                    console.log('HHDL: Modal loaded successfully, updating content');

                    // Update modal header
                    if (response.data.header) {
                        modalHeader.html(response.data.header);
                    }
                    // Update modal body
                    if (response.data.body) {
                        modalBody.html(response.data.body);
                    }

                    console.log('HHDL: About to call initTaskCheckboxes');
                    initTaskCheckboxes();
                } else {
                    console.error('HHDL: Modal load failed', response);
                    modalBody.html('<div class="hhdl-notice hhdl-notice-error"><p>' + (response.data.message || hhdlAjax.strings.error) + '</p></div>');
                }
            },
            error: function() {
                modalBody.html('<div class="hhdl-notice hhdl-notice-error"><p>' + hhdlAjax.strings.error + '</p></div>');
            }
        });
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('#hhdl-modal').removeClass('active');
    }

    /**
     * Initialize task checkbox handlers
     */
    function initTaskCheckboxes() {
        console.log('HHDL: initTaskCheckboxes called');

        const checkboxes = $('.hhdl-task-checkbox');
        console.log('HHDL: Found', checkboxes.length, 'task checkboxes');

        if (checkboxes.length === 0) {
            console.warn('HHDL: No task checkboxes found in DOM!');
            return;
        }

        // COMPLETELY remove all event handlers first
        console.log('HHDL: Removing all existing change handlers');
        checkboxes.off('change');

        // Attach new handler with ONE event
        console.log('HHDL: Attaching ONE new change handler');
        checkboxes.one('change', async function(e) {
            console.log('HHDL: Task checkbox changed');

            const checkbox = $(this);

            // Prevent duplicate events - check processing flag first
            if (checkbox.data('processing') === true) {
                console.log('HHDL: Task already processing, ignoring duplicate event');
                e.preventDefault();
                e.stopImmediatePropagation();
                checkbox.prop('checked', false); // Force uncheck
                return false;
            }

            // Also check if disabled (belt and suspenders)
            if (checkbox.prop('disabled')) {
                console.log('HHDL: Checkbox already disabled, ignoring event');
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }

            const taskItem = checkbox.closest('.hhdl-task-item');
            const taskData = {
                roomId: checkbox.data('room-id'),
                taskId: checkbox.data('task-id'),
                taskTypeId: checkbox.data('task-type-id') || null,
                taskDescription: checkbox.data('task-description'),
                bookingRef: checkbox.data('booking-ref') || '',
                isDefault: checkbox.data('is-default') == '1',
                isOccupy: checkbox.data('is-occupy') == '1'
            };

            console.log('HHDL: Task data:', taskData);

            if (checkbox.prop('checked')) {
                console.log('HHDL: Checkbox is checked, checking for confirmations needed');

                // Set processing flag IMMEDIATELY to prevent duplicate events
                console.log('HHDL: Setting processing flag and disabling checkbox');
                checkbox.data('processing', true);
                checkbox.prop('disabled', true);

                // Show confirmation dialogs if needed
                if (!taskData.isDefault || taskData.isOccupy) {
                    console.log('HHDL: Confirmation needed - isDefault:', taskData.isDefault, 'isOccupy:', taskData.isOccupy);

                    try {
                        const confirmed = await confirmTaskCompletion(taskData);
                        console.log('HHDL: confirmTaskCompletion returned:', confirmed);
                        if (!confirmed) {
                            // User cancelled, clear processing flag and re-enable
                            console.log('HHDL: User cancelled confirmation, clearing flag and re-enabling');
                            checkbox.data('processing', false);
                            checkbox.prop('checked', false);
                            checkbox.prop('disabled', false);
                            return;
                        }

                        console.log('HHDL: Confirmations passed');
                    } catch (error) {
                        console.error('HHDL: Error in confirmation process:', error);
                        checkbox.data('processing', false);
                        checkbox.prop('checked', false);
                        checkbox.prop('disabled', false);
                        showToast('Error: ' + error.message, 'error');
                        return;
                    }
                }

                console.log('HHDL: Calling completeTask');
                completeTask(taskData, checkbox, taskItem);
            }
        });
    }

    /**
     * Show custom confirmation modal
     */
    function showConfirmModal(title, message, iconType, confirmText, confirmClass) {
        console.log('HHDL: showConfirmModal called with:', {title: title, iconType: iconType, confirmText: confirmText});

        return new Promise(function(resolve) {
            try {
                // Create modal HTML
                var modal = $('<div class="hhdl-confirm-overlay">' +
                    '<div class="hhdl-confirm-modal">' +
                        '<div class="hhdl-confirm-header">' +
                            '<span class="material-symbols-outlined hhdl-confirm-icon ' + iconType + '">' +
                                (iconType === 'danger' ? 'warning' : 'help') +
                            '</span>' +
                            '<h3 class="hhdl-confirm-title">' + title + '</h3>' +
                        '</div>' +
                        '<div class="hhdl-confirm-body">' + message + '</div>' +
                        '<div class="hhdl-confirm-footer">' +
                            '<button class="hhdl-confirm-btn hhdl-confirm-btn-cancel">Cancel</button>' +
                            '<button class="hhdl-confirm-btn hhdl-confirm-btn-confirm ' + confirmClass + '">' + confirmText + '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>');

                console.log('HHDL: Modal HTML created, appending to body');

                // Add to page
                $('body').append(modal);

                console.log('HHDL: Modal appended, immediately adding active class for debugging');
                console.log('HHDL: Modal element:', modal[0]);

                // Force active class immediately and force display to flex (workaround for cache issue)
                modal.addClass('active');
                // Force display property inline with !important to override any CSS
                modal[0].style.setProperty('display', 'flex', 'important');
                console.log('HHDL: Modal active class added and display forced to flex');
                console.log('HHDL: Modal has active class:', modal.hasClass('active'));
                console.log('HHDL: Modal opacity:', modal.css('opacity'));
                console.log('HHDL: Modal z-index:', modal.css('z-index'));
                console.log('HHDL: Modal display:', modal.css('display'));
                console.log('HHDL: Modal visibility:', modal.css('visibility'));

                // Handle cancel
                modal.find('.hhdl-confirm-btn-cancel').on('click', function() {
                    console.log('HHDL: Cancel button clicked');
                    modal.removeClass('active');
                    setTimeout(function() {
                        modal.remove();
                        resolve(false);
                    }, 200);
                });

                // Handle confirm
                modal.find('.hhdl-confirm-btn-confirm').on('click', function() {
                    console.log('HHDL: Confirm button clicked');
                    modal.removeClass('active');
                    setTimeout(function() {
                        modal.remove();
                        resolve(true);
                    }, 200);
                });

                // Handle click outside
                modal.on('click', function(e) {
                    if ($(e.target).hasClass('hhdl-confirm-overlay')) {
                        console.log('HHDL: Clicked outside modal');
                        modal.removeClass('active');
                        setTimeout(function() {
                            modal.remove();
                            resolve(false);
                        }, 200);
                    }
                });

                console.log('HHDL: Event handlers attached');
            } catch (error) {
                console.error('HHDL: Error in showConfirmModal:', error);
                resolve(false);
            }
        });
    }

    /**
     * Show confirmation dialogs for task completion
     */
    async function confirmTaskCompletion(taskData) {
        try {
            console.log('HHDL: confirmTaskCompletion called with taskData:', taskData);

            // First check: Non-default task confirmation
            if (!taskData.isDefault) {
                console.log('HHDL: Showing non-default task confirmation');
                var confirmed = await showConfirmModal(
                    'Confirm Non-Default Task',
                    'Please confirm you want to mark this non-default task complete.<div class="hhdl-confirm-task-name">' + taskData.taskDescription + '</div>',
                    'warning',
                    'Mark Complete',
                    ''
                );
                console.log('HHDL: Non-default confirmation result:', confirmed);
                if (!confirmed) {
                    return false;
                }
            }

            // Second check: Occupy task confirmation (additional warning)
            if (taskData.isOccupy) {
                console.log('HHDL: Showing occupy task confirmation');
                var confirmedOccupy = await showConfirmModal(
                    'Unblock Room',
                    'This task is currently blocking off this room. Completing it will make the room available again.<br><br>Are you sure you wish to mark it as completed?',
                    'danger',
                    'Yes, Complete Task',
                    'danger'
                );
                console.log('HHDL: Occupy confirmation result:', confirmedOccupy);
                if (!confirmedOccupy) {
                    return false;
                }
            }

            console.log('HHDL: All confirmations passed');
            return true;
        } catch (error) {
            console.error('HHDL: Error in confirmTaskCompletion:', error);
            return false;
        }
    }

    /**
     * Complete a task
     */
    function completeTask(taskData, checkbox, taskItem) {
        console.log('HHDL: completeTask called with data:', taskData);
        console.log('HHDL: taskItem element:', taskItem);

        // Checkbox should already be disabled from change handler, but ensure it
        // This is a safety measure in case completeTask is called from elsewhere
        checkbox.prop('disabled', true);

        // Add processing overlay
        var overlay = $('<div class="hhdl-task-processing-overlay">' +
            '<div class="hhdl-processing-spinner"></div>' +
            '<div class="hhdl-processing-text">Completing task on NewBook</div>' +
            '</div>');
        taskItem.append(overlay);

        console.log('HHDL: Sending AJAX request to complete task');

        $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'hhdl_complete_task',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                room_id: taskData.roomId,
                task_id: taskData.taskId,
                task_type_id: taskData.taskTypeId,
                task_description: taskData.taskDescription,
                booking_ref: taskData.bookingRef,
                service_date: currentDate
            },
            success: function(response) {
                console.log('HHDL: AJAX response received');
                console.log('HHDL: Response type:', typeof response);
                console.log('HHDL: Response object:', response);
                console.log('HHDL: response.success value:', response.success);
                console.log('HHDL: response.data:', response.data);

                if (response.success) {
                    console.log('HHDL: Task completed successfully, starting fade out');
                    console.log('HHDL: taskItem before fadeOut:', taskItem, 'is visible:', taskItem.is(':visible'));

                    // Remove overlay immediately
                    overlay.remove();

                    // Update room status badge if NewBook returned site_status
                    if (response.data.site_status) {
                        updateRoomStatusBadge(taskData.roomId, response.data.site_status);
                    }

                    // Fade out and remove task
                    console.log('HHDL: Calling fadeOut on taskItem');
                    taskItem.fadeOut(400, function() {
                        console.log('HHDL: Fade complete, removing task from DOM');
                        $(this).remove();

                        // Update task count after removal
                        console.log('HHDL: Calling updateTaskCount with roomId:', taskData.roomId);
                        updateTaskCount(taskData.roomId);
                    });

                    showToast(hhdlAjax.strings.taskCompleted, 'success');
                } else {
                    // Rollback on error with detailed message
                    console.error('HHDL: Task completion failed!');
                    console.error('HHDL: Full response:', response);
                    console.error('HHDL: Error message:', response.data && response.data.message ? response.data.message : 'No error message provided');

                    checkbox.prop('checked', false);
                    checkbox.prop('disabled', false);
                    var errorMsg = response.data && response.data.message ? response.data.message : hhdlAjax.strings.error;
                    showToast('NewBook Error: ' + errorMsg, 'error');
                    overlay.remove();
                }
            },
            error: function() {
                // Rollback on error
                checkbox.prop('checked', false);
                checkbox.prop('disabled', false);
                showToast(hhdlAjax.strings.error, 'error');
                overlay.remove();
            }
        });
    }

    /**
     * Update room status badge in room card and modal header
     */
    function updateRoomStatusBadge(roomId, siteStatus) {
        // Update status badge in room card on main list
        var roomCard = $('.hhdl-room-card[data-room-id="' + roomId + '"]');
        if (roomCard.length) {
            var statusBadge = roomCard.find('.hhdl-site-status');
            if (statusBadge.length) {
                // Update badge text and class
                statusBadge.removeClass('clean dirty inspected unknown')
                    .addClass(siteStatus.toLowerCase())
                    .text(siteStatus);
            }
        }

        // Update status badge in modal header if modal is open for this room
        var modalRoomId = $('.hhdl-modal-header .hhdl-modal-room-number').text().trim();
        if (modalRoomId === roomId || modalRoomId === String(roomId)) {
            var modalStatusBadge = $('.hhdl-modal-site-status');
            if (modalStatusBadge.length) {
                // Update modal status badge
                modalStatusBadge.removeClass('clean dirty inspected unknown arrived')
                    .addClass(siteStatus.toLowerCase())
                    .text(siteStatus);
            }
        }
    }

    /**
     * Update task count badge in modal header, section header, and room card
     */
    function updateTaskCount(roomId) {
        console.log('HHDL: updateTaskCount called with roomId:', roomId);

        var taskList = $('.hhdl-task-list');
        console.log('HHDL: Found taskList:', taskList.length);

        if (!taskList.length) {
            console.warn('HHDL: No task list found!');
            return;
        }

        // Count remaining incomplete tasks (visible items only, not fading out)
        var allTaskItems = taskList.find('.hhdl-task-item');
        var visibleTaskItems = taskList.find('.hhdl-task-item:visible');
        var incompleteTasks = visibleTaskItems.length;

        console.log('HHDL: Total task items:', allTaskItems.length);
        console.log('HHDL: Visible task items:', visibleTaskItems.length);
        console.log('HHDL: Updating task count - ' + incompleteTasks + ' tasks remaining');

        // Update the task count badge in modal header
        var modalBadge = $('.hhdl-modal-header .hhdl-task-count-badge');
        console.log('HHDL: Found modal badge:', modalBadge.length);
        if (modalBadge.length) {
            if (incompleteTasks > 0) {
                modalBadge.text(incompleteTasks);
                modalBadge.parent().show();
            } else {
                // Hide the entire badge container
                modalBadge.parent().hide();
            }
        }

        // Update the section header icon and badge in modal
        var sectionHeader = $('.hhdl-section-header');
        if (sectionHeader.length) {
            var sectionBadge = sectionHeader.find('.hhdl-task-count-badge');
            var sectionIcon = sectionHeader.find('.material-symbols-outlined');

            if (incompleteTasks > 0) {
                // Update badge count
                if (sectionBadge.length) {
                    sectionBadge.text(incompleteTasks);
                }
                // Icon stays as assignment_late with red color
            } else {
                // All complete - update to green checkmark
                if (sectionBadge.length) {
                    sectionBadge.parent().remove(); // Remove entire badge container
                }
                if (sectionIcon.length) {
                    sectionIcon.text('assignment_turned_in');
                    sectionIcon.css('color', '#10b981'); // Green
                }
            }
        }

        // Update the task count badge on the room card in main list
        if (roomId) {
            console.log('HHDL: Looking for room card with id:', roomId);
            var roomCard = $('.hhdl-room-card[data-room-id="' + roomId + '"]');
            console.log('HHDL: Found room card:', roomCard.length);

            if (roomCard.length) {
                var roomBadge = roomCard.find('.hhdl-task-count-badge');
                console.log('HHDL: Found room badge:', roomBadge.length);

                if (roomBadge.length) {
                    if (incompleteTasks > 0) {
                        console.log('HHDL: Setting room badge to', incompleteTasks);
                        roomBadge.text(incompleteTasks).show();
                    } else {
                        console.log('HHDL: Hiding room badge and updating icon to completed');
                        roomBadge.hide();

                        // Find the task status container and update its class
                        var taskStatusContainer = roomCard.find('.hhdl-task-status');
                        if (taskStatusContainer.length) {
                            // Remove all task status classes
                            taskStatusContainer.removeClass('hhdl-task-late hhdl-task-return hhdl-task-future hhdl-task-none');
                            // Add complete class
                            taskStatusContainer.addClass('hhdl-task-complete');
                        }

                        // Update icon to show completion
                        var taskIcon = roomCard.find('.hhdl-stat-content .material-symbols-outlined').first();
                        if (taskIcon.length) {
                            taskIcon.text('assignment_turned_in');
                            taskIcon.css('color', '#10b981'); // Green
                        }
                    }
                }
            }
        } else {
            console.warn('HHDL: No roomId provided to updateTaskCount');
        }
    }

    /**
     * Initialize WordPress Heartbeat API for real-time updates
     */
    function initHeartbeat() {
        // Send monitoring data with each heartbeat
        $(document).on('heartbeat-send', function(e, data) {
            data.hhdl_monitor = {
                location_id: currentLocationId,
                viewing_date: currentDate,
                last_check: lastCheckTimestamp
            };
        });

        // Receive updates from server
        $(document).on('heartbeat-tick', function(e, data) {
            if (data.hhdl_updates && data.hhdl_updates.completions) {
                handleRemoteUpdates(data.hhdl_updates.completions);
                lastCheckTimestamp = data.hhdl_updates.timestamp;
            }
        });
    }

    /**
     * Handle task completions from other users
     */
    function handleRemoteUpdates(completions) {
        completions.forEach(function(completion) {
            // Find and update the task checkbox
            const taskCheckbox = $('.hhdl-task-checkbox[data-task-id="' + completion.task_id + '"]');
            if (taskCheckbox.length) {
                taskCheckbox.prop('checked', true);
                taskCheckbox.closest('.hhdl-task-item').addClass('completed');
            }

            // Show notification if completed by another user
            if (completion.completed_by != hhdlAjax.userId) {
                showToast('Task completed by ' + completion.completed_by_name, 'info');
            }
        });
    }

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        type = type || 'info';

        const toast = $('<div class="hhdl-toast hhdl-toast-' + type + '">' + message + '</div>');
        $('body').append(toast);

        // Trigger animation
        setTimeout(function() {
            toast.addClass('hhdl-toast-show');
        }, 10);

        // Auto-hide after 3 seconds
        setTimeout(function() {
            toast.removeClass('hhdl-toast-show');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    }

    // Initialize when document is ready OR when module content is dynamically loaded
    var initialized = false;

    function checkAndInit() {
        if (initialized) return true;

        if ($('#hhdl-room-list').length) {
            console.log('[HHDL] Daily List module content found, initializing...');
            initDailyList();
            initialized = true;
            return true;
        }
        return false;
    }

    // Try on document ready
    $(document).ready(function() {
        console.log('[HHDL] Document ready, checking for module...');
        checkAndInit();
    });

    // Listen for HHA custom module load event
    $(document).on('hha-module-loaded', function(e, moduleId) {
        if (moduleId === 'daily_list') {
            console.log('[HHDL] Received HHA module-loaded event');
            setTimeout(checkAndInit, 100); // Small delay to ensure DOM is ready
        }
    });

    // Also watch for dynamic content changes (for HHA SPA loading)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                if (checkAndInit()) {
                    observer.disconnect(); // Stop observing once initialized
                }
            }
        });
    });

    // Start observing after a short delay to let HHA set up
    setTimeout(function() {
        if (!initialized && document.body) {
            console.log('[HHDL] Starting MutationObserver...');
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }, 100);

})(jQuery);
