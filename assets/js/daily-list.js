/**
 * Daily List JavaScript
 * Handles frontend interactions, AJAX, and real-time sync
 */

(function($) {
    'use strict';

    // Global state
    let currentLocationId = null;
    let currentDate = null;
    let lastCheckTimestamp = null;

    /**
     * Initialize Daily List
     */
    function initDailyList() {
        // Get initial state from DOM
        const roomList = $('#hhdl-room-list');
        if (roomList.length === 0) {
            return; // Not on Daily List page
        }

        currentLocationId = roomList.data('location');
        currentDate = roomList.data('date');
        lastCheckTimestamp = getCurrentTimestamp();

        // Initialize components
        initDatePicker();
        initFilters();
        initRoomCards();
        initModal();
        initHeartbeat();
        initGlobalKeyHandlers();

        // Load initial room data
        loadRoomList(currentLocationId, currentDate);
    }

    /**
     * Initialize date picker
     */
    function initDatePicker() {
        $('#hhdl-date-picker').on('change', function() {
            currentDate = $(this).val();
            loadRoomList(currentLocationId, currentDate);

            // Update viewing date display
            const dateObj = new Date(currentDate);
            const formatted = dateObj.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            $('.hhdl-viewing-date').text(formatted);
        });
    }

    /**
     * Initialize filter buttons
     */
    function initFilters() {
        $('.hhdl-filter-btn').on('click', function() {
            // Update active state
            $('.hhdl-filter-btn').removeClass('active');
            $(this).addClass('active');

            // Apply filter
            const filter = $(this).data('filter');
            filterRooms(filter);
        });
    }

    /**
     * Filter rooms based on type
     */
    function filterRooms(filterType) {
        $('.hhdl-room-card').each(function() {
            const card = $(this);
            let shouldShow = false;

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
                case 'twins':
                    shouldShow = card.data('has-twin') === true;
                    break;
                case 'all':
                default:
                    shouldShow = true;
            }

            card.toggle(shouldShow);
        });
    }

    /**
     * Initialize room card click handlers
     */
    function initRoomCards() {
        $(document).on('click', '.hhdl-room-card', function() {
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

        // Click outside to close
        $(document).on('click', '.hhdl-modal-overlay', function(e) {
            if ($(e.target).hasClass('hhdl-modal-overlay')) {
                closeModal();
            }
        });

        // Task checkbox handler
        initTaskCheckboxes();
    }

    /**
     * Initialize task checkboxes
     */
    function initTaskCheckboxes() {
        $(document).on('change', '.hhdl-task-checkbox', function() {
            const checkbox = $(this);
            const taskId = checkbox.data('task-id');
            const taskType = checkbox.data('task-type');
            const roomId = checkbox.data('room-id');
            const bookingRef = checkbox.data('booking-ref') || '';

            // Check if being checked or unchecked
            if (!checkbox.is(':checked')) {
                // Don't allow unchecking (tasks can only be marked complete, not undone)
                checkbox.prop('checked', true);
                showToast('Tasks cannot be unchecked once completed', 'error');
                return;
            }

            // Disable checkbox during request
            checkbox.prop('disabled', true);

            // Complete task via AJAX
            completeTask({
                location_id: currentLocationId,
                room_id: roomId,
                task_id: taskId,
                task_type: taskType,
                booking_ref: bookingRef,
                service_date: currentDate
            }).then(function(response) {
                if (response.success) {
                    showToast(hhdlAjax.strings.taskCompleted, 'success');
                    updateTaskUI(roomId, taskType, true);
                } else {
                    // Rollback on error
                    checkbox.prop('checked', false);
                    showToast(response.data.message || hhdlAjax.strings.error, 'error');
                }
            }).catch(function(error) {
                // Rollback on error
                checkbox.prop('checked', false);
                showToast(hhdlAjax.strings.error, 'error');
            }).always(function() {
                checkbox.prop('disabled', false);
            });
        });
    }

    /**
     * Initialize heartbeat for real-time sync
     */
    function initHeartbeat() {
        // Send data with heartbeat
        $(document).on('heartbeat-send', function(e, data) {
            data.hhdl_monitor = {
                location_id: currentLocationId,
                viewing_date: currentDate,
                last_check: lastCheckTimestamp
            };
        });

        // Receive heartbeat updates
        $(document).on('heartbeat-tick', function(e, data) {
            if (data.hhdl_updates && data.hhdl_updates.completions) {
                const completions = data.hhdl_updates.completions;

                if (completions.length > 0) {
                    completions.forEach(function(completion) {
                        // Update UI for completed task
                        updateTaskUI(completion.room_id, completion.task_type, true);

                        // Show notification if completed by another user
                        if (completion.completed_by != hhdlAjax.userId) {
                            const message = completion.completed_by_name + ' completed: ' + completion.task_type;
                            showToast(message, 'info');
                        }
                    });

                    // Update last check timestamp
                    lastCheckTimestamp = data.hhdl_updates.timestamp;
                }
            }
        });
    }

    /**
     * Initialize global keyboard handlers
     */
    function initGlobalKeyHandlers() {
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    /**
     * Load room list via AJAX
     */
    function loadRoomList(locationId, date) {
        const roomList = $('#hhdl-room-list');

        // Show loading state
        roomList.html('<div class="hhdl-loading"><span class="spinner is-active"></span><p>' + hhdlAjax.strings.loading + '</p></div>');

        // AJAX request
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'hhdl_get_rooms',
                nonce: hhdlAjax.nonce,
                location_id: locationId,
                date: date
            },
            success: function(response) {
                if (response.success) {
                    roomList.html(response.data.html);
                } else {
                    roomList.html('<div class="hhdl-no-rooms"><p>' + (response.data.message || hhdlAjax.strings.error) + '</p></div>');
                }
            },
            error: function() {
                roomList.html('<div class="hhdl-no-rooms"><p>' + hhdlAjax.strings.error + '</p></div>');
            }
        });
    }

    /**
     * Open room details modal
     */
    function openRoomModal(roomId, date) {
        const modal = $('#hhdl-modal');
        const modalBody = $('#hhdl-modal-body');
        const modalTitle = $('#hhdl-modal-title');

        // Show modal with loading state
        modalBody.html('<div class="hhdl-loading"><span class="spinner is-active"></span><p>' + hhdlAjax.strings.loading + '</p></div>');
        modalTitle.text('Room ' + roomId);
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
                    populateModal(response.data);
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
     * Populate modal with room details
     */
    function populateModal(data) {
        const modalBody = $('#hhdl-modal-body');
        const modalTitle = $('#hhdl-modal-title');

        // Update title
        if (data.booking && data.booking.guest_name) {
            modalTitle.text('Room ' + data.room_number + ' - ' + data.booking.guest_name);
        } else {
            modalTitle.text('Room ' + data.room_number);
        }

        let html = '';

        // Booking details section
        if (data.booking) {
            html += '<section class="hhdl-booking-section">';
            html += '<h3>Booking Details</h3>';
            html += '<div class="hhdl-booking-details">';

            if (data.booking.guest_name) {
                html += '<p><strong>Guest:</strong> ' + escapeHtml(data.booking.guest_name) + '</p>';
            } else {
                html += '<p><strong>Reference:</strong> ' + escapeHtml(data.booking.reference) + '</p>';
            }

            if (data.booking.email) {
                html += '<p><strong>Email:</strong> ' + escapeHtml(data.booking.email) + '</p>';
            }

            if (data.booking.phone) {
                html += '<p><strong>Phone:</strong> ' + escapeHtml(data.booking.phone) + '</p>';
            }

            html += '<p><strong>Check-in:</strong> ' + data.booking.checkin_date + ' ' + data.booking.checkin_time + '</p>';
            html += '<p><strong>Check-out:</strong> ' + data.booking.checkout_date + ' ' + data.booking.checkout_time + '</p>';
            html += '<p><strong>Guests:</strong> ' + data.booking.pax + '</p>';
            html += '<p><strong>Nights:</strong> ' + data.booking.current_night + '/' + data.booking.nights + '</p>';
            html += '<p><strong>Room Type:</strong> ' + escapeHtml(data.booking.room_type) + '</p>';

            if (data.booking.rate_plan) {
                html += '<p><strong>Rate Plan:</strong> ' + escapeHtml(data.booking.rate_plan) + '</p>';
            }

            if (data.booking.rate_amount) {
                html += '<p><strong>Rate:</strong> $' + parseFloat(data.booking.rate_amount).toFixed(2) + '</p>';
            }

            if (data.booking.notes) {
                html += '<p><strong>Notes:</strong> ' + escapeHtml(data.booking.notes) + '</p>';
            }

            html += '</div>';
            html += '</section>';
        }

        // Tasks section
        if (data.tasks && data.tasks.length > 0) {
            html += '<section class="hhdl-tasks-section">';
            html += '<h3>Tasks</h3>';
            html += '<div class="hhdl-task-list">';

            data.tasks.forEach(function(task) {
                const taskId = task.id || '';
                const isChecked = task.completed ? 'checked' : '';
                const isDisabled = task.completed ? 'disabled' : '';

                html += '<div class="hhdl-task-checkbox-wrapper">';
                html += '<input type="checkbox" ';
                html += 'class="hhdl-task-checkbox" ';
                html += 'data-task-id="' + escapeHtml(taskId) + '" ';
                html += 'data-task-type="' + escapeHtml(task.name) + '" ';
                html += 'data-room-id="' + escapeHtml(data.room_number) + '" ';

                if (data.booking) {
                    html += 'data-booking-ref="' + escapeHtml(data.booking.reference) + '" ';
                }

                html += isChecked + ' ' + isDisabled + '>';
                html += '<label class="hhdl-task-label">';
                html += '<span class="hhdl-task-color-indicator" style="background-color: ' + task.color + '"></span>';
                html += escapeHtml(task.name);
                html += '</label>';
                html += '</div>';
            });

            html += '</div>';
            html += '</section>';
        }

        // Placeholder sections (for future module integrations)
        html += '<section class="hhdl-placeholder">';
        html += '<h3>Recurring Tasks</h3>';
        html += '<p class="hhdl-placeholder-text">Future module integration</p>';
        html += '</section>';

        html += '<section class="hhdl-placeholder">';
        html += '<h3>Spoilt Linen Tracking</h3>';
        html += '<p class="hhdl-placeholder-text">Future module integration</p>';
        html += '</section>';

        modalBody.html(html);
    }

    /**
     * Close modal
     */
    function closeModal() {
        $('#hhdl-modal').removeClass('active');
    }

    /**
     * Complete task via AJAX
     */
    function completeTask(data) {
        return $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: $.extend({
                action: 'hhdl_complete_task',
                nonce: hhdlAjax.nonce
            }, data)
        });
    }

    /**
     * Update task UI after completion
     */
    function updateTaskUI(roomId, taskType, completed) {
        // Update checkbox in modal if open
        const checkbox = $('.hhdl-task-checkbox[data-room-id="' + roomId + '"][data-task-type="' + taskType + '"]');
        if (checkbox.length > 0) {
            checkbox.prop('checked', completed);
            checkbox.prop('disabled', completed);
        }

        // Could also update room card visual indicator here if needed
    }

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        type = type || 'info';

        const toast = $('<div class="hhdl-toast hhdl-toast-' + type + '"></div>').text(message);

        $('body').append(toast);

        // Trigger animation
        setTimeout(function() {
            toast.addClass('hhdl-toast-show');
        }, 10);

        // Remove after 3 seconds
        setTimeout(function() {
            toast.removeClass('hhdl-toast-show');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    }

    /**
     * Get current timestamp for heartbeat
     */
    function getCurrentTimestamp() {
        return new Date().toISOString().slice(0, 19).replace('T', ' ');
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';

        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return String(text).replace(/[&<>"']/g, function(m) {
            return map[m];
        });
    }

    // Initialize on document ready
    $(document).ready(initDailyList);

})(jQuery);
