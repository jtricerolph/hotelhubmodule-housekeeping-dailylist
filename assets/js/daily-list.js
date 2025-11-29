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

            switch(filterType) {
                case 'arrivals':
                    shouldShow = card.data('is-arriving') === 'true';
                    break;
                case 'departs':
                    shouldShow = card.data('is-departing') === 'true';
                    break;
                case 'stopovers':
                    shouldShow = card.data('is-stopover') === 'true';
                    break;
                case 'twins':
                    shouldShow = card.data('has-twin') === 'true';
                    break;
                case 'all':
                default:
                    shouldShow = true;
            }

            card.toggle(shouldShow);
        });
    }

    /**
     * Load room list via AJAX
     */
    function loadRoomList(date) {
        const roomList = $('#hhdl-room-list');
        roomList.html('<div class="hhdl-loading"><span class="spinner is-active"></span><p>' + hhdlAjax.strings.loading + '</p></div>');

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
                    roomList.html(response.data);
                    initRoomCards();
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
        const modalBody = $('#hhdl-modal-body');

        // Show loading state
        modalBody.html('<div class="hhdl-loading"><span class="spinner is-active"></span><p>' + hhdlAjax.strings.loading + '</p></div>');
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
                if (response.success) {
                    modalBody.html(response.data);
                    initTaskCheckboxes();
                } else {
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
        $('.hhdl-task-checkbox').off('change').on('change', function() {
            const checkbox = $(this);
            const taskItem = checkbox.closest('.hhdl-task-item');
            const taskData = {
                roomId: checkbox.data('room-id'),
                taskId: checkbox.data('task-id'),
                taskType: checkbox.data('task-type'),
                bookingRef: checkbox.data('booking-ref') || ''
            };

            if (checkbox.prop('checked')) {
                completeTask(taskData, checkbox, taskItem);
            }
        });
    }

    /**
     * Complete a task
     */
    function completeTask(taskData, checkbox, taskItem) {
        // Disable checkbox during request
        checkbox.prop('disabled', true);

        $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'hhdl_complete_task',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                room_id: taskData.roomId,
                task_id: taskData.taskId,
                task_type: taskData.taskType,
                booking_ref: taskData.bookingRef,
                service_date: currentDate
            },
            success: function(response) {
                if (response.success) {
                    taskItem.addClass('completed');
                    showToast(hhdlAjax.strings.taskCompleted, 'success');
                } else {
                    // Rollback on error
                    checkbox.prop('checked', false);
                    showToast(response.data.message || hhdlAjax.strings.error, 'error');
                }
            },
            error: function() {
                // Rollback on error
                checkbox.prop('checked', false);
                showToast(hhdlAjax.strings.error, 'error');
            },
            complete: function() {
                checkbox.prop('disabled', false);
            }
        });
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
