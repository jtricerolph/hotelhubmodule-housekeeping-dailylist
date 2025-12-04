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
    let lastPollingCheck = new Date().toISOString();
    let checkoutNotifications = {}; // Track shown notifications to prevent duplicates
    let originalFilterCounts = {}; // Store original filter counts
    let totalRoomCount = 0; // Total number of rooms

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
        initFilterScrollArrows();
        initStatFilters();
        initStatFilterScrollArrows();
        initModal();
        initHeartbeat();
        initViewControls();  // Initialize view mode and reset controls
        initCategoryHeaders();  // Initialize category header interactions
        initActivityPanel();  // Initialize activity log panel

        // Load initial room list
        loadRoomList(currentDate);
    }

    /**
     * Initialize date picker handler and modal
     */
    let calendarDate = new Date(); // Track current month being viewed
    let selectedDate = null; // Track selected date

    function initDatePicker() {
        // Initialize selected date from current date
        const initialDate = $('#hhdl-date-picker').val();
        selectedDate = initialDate ? new Date(initialDate + 'T00:00:00') : new Date();
        calendarDate = new Date(selectedDate);

        // Open date picker modal
        $('#hhdl-open-date-picker').on('click', function() {
            renderCalendar();
            $('#hhdl-date-modal').addClass('hhdl-modal-open');
        });

        // Close date picker modal
        $('#hhdl-close-date-modal').on('click', function() {
            $('#hhdl-date-modal').removeClass('hhdl-modal-open');
        });

        // Close modal when clicking outside content
        $('#hhdl-date-modal').on('click', function(e) {
            if ($(e.target).is('#hhdl-date-modal')) {
                $(this).removeClass('hhdl-modal-open');
            }
        });

        // Close modal on Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#hhdl-date-modal').hasClass('hhdl-modal-open')) {
                $('#hhdl-date-modal').removeClass('hhdl-modal-open');
            }
        });

        // Previous month navigation
        $('#hhdl-prev-month').on('click', function() {
            calendarDate.setMonth(calendarDate.getMonth() - 1);
            renderCalendar();
        });

        // Next month navigation
        $('#hhdl-next-month').on('click', function() {
            calendarDate.setMonth(calendarDate.getMonth() + 1);
            renderCalendar();
        });

        // Handle date selection from calendar
        $(document).on('click', '.hhdl-calendar-day:not(.hhdl-calendar-day-other)', function() {
            const day = parseInt($(this).data('day'));
            const year = calendarDate.getFullYear();
            const month = calendarDate.getMonth();

            selectedDate = new Date(year, month, day);
            const dateString = formatDateForInput(selectedDate);

            $('#hhdl-date-picker').val(dateString);
            currentDate = dateString;
            updateDateDisplay(dateString);

            // Save selected date preference
            saveUserPreference('selected_date', dateString);

            // Keep current filter when date changes (persistent filters)
            loadRoomList(currentDate);

            // Trigger custom event for date change
            $(document).trigger('hhdl-date-changed', [currentDate]);

            // Close modal after selection
            $('#hhdl-date-modal').removeClass('hhdl-modal-open');
        });

        // Handle "Today" button
        $('#hhdl-calendar-today').on('click', function() {
            const today = new Date();
            selectedDate = today;
            calendarDate = new Date(today);

            const dateString = formatDateForInput(today);
            $('#hhdl-date-picker').val(dateString);
            currentDate = dateString;
            updateDateDisplay(dateString);

            // Save selected date preference
            saveUserPreference('selected_date', dateString);

            // Keep current filter when date changes (persistent filters)
            loadRoomList(currentDate);

            // Trigger custom event for date change
            $(document).trigger('hhdl-date-changed', [currentDate]);

            // Close modal after selection
            $('#hhdl-date-modal').removeClass('hhdl-modal-open');
        });
    }

    /**
     * Render calendar for current month
     * Always shows 6 weeks (42 cells) for consistent height
     */
    function renderCalendar() {
        const year = calendarDate.getFullYear();
        const month = calendarDate.getMonth();

        // Update title
        const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                           'July', 'August', 'September', 'October', 'November', 'December'];
        $('#hhdl-calendar-title').text(`${monthNames[month]} ${year}`);

        // Get first day of month and number of days
        const firstDay = new Date(year, month, 1).getDay(); // 0=Sunday, 6=Saturday
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        // Get today's date for highlighting
        const today = new Date();
        const isCurrentMonth = today.getFullYear() === year && today.getMonth() === month;
        const todayDate = today.getDate();

        // Get selected date for highlighting
        const isSelectedMonth = selectedDate && selectedDate.getFullYear() === year && selectedDate.getMonth() === month;
        const selectedDay = selectedDate ? selectedDate.getDate() : null;

        // Build calendar grid - always show 6 weeks (42 cells) for consistent height
        const totalCells = 42;
        let html = '';

        // Previous month days (fill from start of week to day before 1st of month)
        for (let i = firstDay - 1; i >= 0; i--) {
            const day = daysInPrevMonth - i;
            html += `<div class="hhdl-calendar-day hhdl-calendar-day-other">${day}</div>`;
        }

        // Current month days
        for (let day = 1; day <= daysInMonth; day++) {
            let classes = 'hhdl-calendar-day';
            if (isCurrentMonth && day === todayDate) {
                classes += ' hhdl-calendar-day-today';
            }
            if (isSelectedMonth && day === selectedDay) {
                classes += ' hhdl-calendar-day-selected';
            }
            html += `<div class="${classes}" data-day="${day}">${day}</div>`;
        }

        // Calculate how many cells we've used
        const cellsUsed = firstDay + daysInMonth;

        // Next month days - always fill to exactly 42 cells (6 weeks)
        const remainingCells = totalCells - cellsUsed;
        for (let day = 1; day <= remainingCells; day++) {
            html += `<div class="hhdl-calendar-day hhdl-calendar-day-other">${day}</div>`;
        }

        $('#hhdl-calendar-days').html(html);
    }

    /**
     * Format date for input (YYYY-MM-DD)
     */
    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
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
     * Implements three-state filtering:
     * - Click 1: Inclusive (green) - show only this type
     * - Click 2: Exclusive (red) - show everything EXCEPT this type
     * - Click 3: Clear filter - back to 'all'
     */
    function initFilters() {
        // Apply saved filter on init
        const $filters = $('.hhdl-filters');
        const savedFilter = $filters.data('active-filter');
        const savedMode = $filters.data('active-filter-mode');

        if (savedFilter && savedFilter !== 'all') {
            // Apply the saved filter
            const $activeBtn = $(`.hhdl-filter-btn[data-filter="${savedFilter}"]`);
            updateFilterButtonLabel($activeBtn, savedFilter, savedMode);
            filterRooms(savedFilter, savedMode);
            // Scroll to show the active filter
            scrollToActiveFilter();
            // Update sticky state
            setTimeout(updateFiltersStickyState, 50);
        }

        $('.hhdl-filter-btn').on('click', function() {
            const $btn = $(this);
            const filter = $btn.data('filter');

            // 'All' button always clears all filters
            if (filter === 'all') {
                // Reset all filter labels to inclusive state
                $('.hhdl-filter-btn').not($btn).each(function() {
                    const $otherBtn = $(this);
                    const otherFilter = $otherBtn.data('filter');
                    if (otherFilter !== 'all') {
                        updateFilterButtonLabel($otherBtn, otherFilter, 'inclusive');
                    }
                });
                $('.hhdl-filter-btn').removeClass('active filter-exclusive');
                $btn.addClass('active');
                filterRooms('all', 'inclusive');
                // Save filter preference
                saveFilterPreference('all', 'inclusive');
                // Update sticky state
                updateFiltersStickyState();
                return;
            }

            // Determine current state
            const isInclusive = $btn.hasClass('active') && !$btn.hasClass('filter-exclusive');
            const isExclusive = $btn.hasClass('filter-exclusive');

            // Clear all other filters and reset their labels
            $('.hhdl-filter-btn').not($btn).each(function() {
                const $otherBtn = $(this);
                const otherFilter = $otherBtn.data('filter');
                if (otherFilter !== 'all') {
                    updateFilterButtonLabel($otherBtn, otherFilter, 'inclusive');
                }
            });
            $('.hhdl-filter-btn').not($btn).removeClass('active filter-exclusive');
            $('.hhdl-filter-btn[data-filter="all"]').removeClass('active');

            // Cycle through states
            if (!isInclusive && !isExclusive) {
                // State 1: Set to inclusive (green)
                $btn.addClass('active').removeClass('filter-exclusive');
                updateFilterButtonLabel($btn, filter, 'inclusive');
                filterRooms(filter, 'inclusive');
                saveFilterPreference(filter, 'inclusive');
                scrollToActiveFilter();
                updateFiltersStickyState();
            } else if (isInclusive) {
                // State 2: Set to exclusive (red)
                $btn.addClass('filter-exclusive');
                updateFilterButtonLabel($btn, filter, 'exclusive');
                filterRooms(filter, 'exclusive');
                saveFilterPreference(filter, 'exclusive');
                scrollToActiveFilter();
                updateFiltersStickyState();
            } else {
                // State 3: Clear filter (back to 'all')
                $btn.removeClass('active filter-exclusive');
                updateFilterButtonLabel($btn, filter, 'inclusive');
                $('.hhdl-filter-btn[data-filter="all"]').addClass('active');
                filterRooms('all', 'inclusive');
                saveFilterPreference('all', 'inclusive');
                updateFiltersStickyState();
            }
        });
    }

    /**
     * Update filter button label based on mode
     * @param {jQuery} $btn - The button element
     * @param {string} filter - The filter type
     * @param {string} mode - 'inclusive' or 'exclusive'
     */
    function updateFilterButtonLabel($btn, filter, mode) {
        let label = '';
        let count = 0;

        // Get the original count for this filter
        let originalCount = 0;
        if (originalFilterCounts[filter]) {
            originalCount = originalFilterCounts[filter];
        }

        // Calculate count based on mode
        if (mode === 'exclusive') {
            // Inverse count = total rooms - original count
            count = totalRoomCount - originalCount;
        } else {
            // Use original count
            count = originalCount;
        }

        if (mode === 'exclusive') {
            // Red state labels
            switch(filter) {
                case 'arrivals':
                    label = 'Not Arrivals';
                    break;
                case 'departs':
                    label = 'Not Departs';
                    break;
                case 'stopovers':
                    label = 'Not Stopover';
                    break;
                case 'back-to-back':
                    label = 'Not Back to Back';
                    break;
                case 'twins':
                    label = 'Not Twin';
                    break;
                case 'blocked':
                    label = 'Not Blocked';
                    break;
                case 'no-booking':
                    label = 'Booked';
                    break;
                case 'unoccupied':
                    label = 'Occupied';
                    break;
            }
        } else {
            // Normal/inclusive state labels
            switch(filter) {
                case 'arrivals':
                    label = 'Arrivals';
                    break;
                case 'departs':
                    label = 'Departs';
                    break;
                case 'stopovers':
                    label = 'Stopovers';
                    break;
                case 'back-to-back':
                    label = 'Back to Back';
                    break;
                case 'twins':
                    label = 'Twins';
                    break;
                case 'blocked':
                    label = 'Blocked';
                    break;
                case 'no-booking':
                    label = 'No Booking';
                    break;
                case 'unoccupied':
                    label = 'Unoccupied';
                    break;
            }
        }

        $btn.html(label + ' <span class="hhdl-count-badge">' + count + '</span>');
    }

    /**
     * Filter visible rooms based on criteria
     * @param {string} filterType - The filter type (arrivals, departs, etc.)
     * @param {string} mode - 'inclusive' (show only) or 'exclusive' (show all except)
     */
    function filterRooms(filterType, mode) {
        mode = mode || 'inclusive'; // Default to inclusive

        $('.hhdl-room-card').each(function() {
            const card = $(this);
            let matchesFilter = false;
            const isBlocked = card.data('booking-status') === 'blocked';
            const isFilterExcluded = card.data('filter-excluded') === true;

            // If filtering (not "all"), exclude rooms marked as filter-excluded
            if (filterType !== 'all' && isFilterExcluded) {
                card.hide();
                return; // Skip further processing for this room
            }

            // Determine if room matches the filter criteria
            switch(filterType) {
                case 'arrivals':
                    // Exclude blocked rooms from arrivals
                    matchesFilter = !isBlocked && card.data('is-arriving') === true;
                    break;
                case 'departs':
                    // Include blocked rooms in departures ONLY if also departing
                    matchesFilter = card.data('is-departing') === true;
                    break;
                case 'stopovers':
                    // Exclude blocked rooms from stopovers
                    matchesFilter = !isBlocked && card.data('is-stopover') === true;
                    break;
                case 'back-to-back':
                    // Exclude blocked rooms from back-to-back
                    matchesFilter = !isBlocked && card.data('booking-type') === 'back-to-back';
                    break;
                case 'twins':
                    // Exclude blocked rooms from twins
                    matchesFilter = !isBlocked && card.data('has-twin') === true;
                    break;
                case 'blocked':
                    // Show only blocked rooms
                    matchesFilter = isBlocked;
                    break;
                case 'no-booking':
                    // Rooms without a guest booking for today (vacant, blocked, or departing with no new booking)
                    matchesFilter = card.data('booking-type') === 'vacant' ||
                                   card.data('booking-type') === 'blocked' ||
                                   card.data('booking-type') === 'depart';
                    break;
                case 'unoccupied':
                    // Rooms without guests currently in them
                    const isStopover = card.data('is-stopover') === true;
                    const isArrived = card.data('booking-status') === 'arrived';
                    const isDeparting = card.data('is-departing') === true;
                    const previousStatus = card.data('previous-status');
                    const bookingType = card.data('booking-type');

                    let isOccupied = false;

                    // Check various occupation states
                    if (isStopover) {
                        isOccupied = true;
                    } else if (isArrived) {
                        isOccupied = true;
                    } else if (isDeparting && previousStatus === 'arrived') {
                        isOccupied = true;
                    } else if (bookingType === 'back-to-back' && (isArrived || previousStatus === 'arrived')) {
                        isOccupied = true;
                    }

                    matchesFilter = !isOccupied;
                    break;
                case 'all':
                default:
                    matchesFilter = true;
            }

            // Apply mode logic
            let shouldShow;
            if (mode === 'exclusive') {
                // Exclusive mode: show everything EXCEPT matches
                shouldShow = !matchesFilter;
            } else {
                // Inclusive mode: show only matches
                shouldShow = matchesFilter;
            }

            card.toggle(shouldShow);
        });

        // Update category header counts after filtering
        updateCategoryFilterCounts();
    }

    /**
     * Filter rooms by stat filters
     */
    function filterRoomsByStats(statFilterType, mode) {
        $('.hhdl-room-card').each(function() {
            const card = $(this);
            let matchesFilter = false;

            // If 'all', show all rooms
            if (statFilterType === 'all' || !mode) {
                matchesFilter = true;
            } else {
                // Determine if room matches the stat filter criteria based on type and mode
                switch(statFilterType) {
                    case 'newbook-tasks':
                        if (mode === 'outstanding') {
                            matchesFilter = card.find('.hhdl-task-late, .hhdl-task-return').length > 0;
                        } else if (mode === 'complete') {
                            matchesFilter = card.find('.hhdl-task-complete').length > 0 ||
                                          card.find('.hhdl-task-late, .hhdl-task-return').length === 0;
                        }
                        break;

                    case 'recurring-tasks':
                        // TODO: Implement when recurring task tracking is added
                        matchesFilter = false;
                        break;

                    case 'linen-count':
                        if (mode === 'none') {
                            matchesFilter = card.find('.hhdl-linen-none').length > 0;
                        } else if (mode === 'unsaved') {
                            matchesFilter = card.find('.hhdl-linen-unsaved').length > 0;
                        } else if (mode === 'submitted') {
                            matchesFilter = card.find('.hhdl-linen-submitted').length > 0;
                        }
                        break;

                    case 'clean-dirty':
                        // TODO: Implement when clean/dirty status is available
                        matchesFilter = false;
                        break;
                }
            }

            card.toggle(matchesFilter);
        });

        // Update category header counts after filtering
        updateCategoryFilterCounts();
    }

    /**
     * Update filter button counts
     */
    function updateFilterCounts(counts) {
        // Store original counts and calculate total room count
        originalFilterCounts = {
            'arrivals': counts.arrivals || 0,
            'departs': counts.departures || 0,
            'stopovers': counts.stopovers || 0,
            'back-to-back': counts.back_to_back || 0,
            'twins': counts.twins || 0,
            'blocked': counts.blocked || 0,
            'no-booking': counts.no_booking || 0,
            'unoccupied': counts.unoccupied || 0
        };

        // Calculate total room count from DOM (excluding filter-excluded rooms)
        totalRoomCount = $('.hhdl-room-card').filter(function() {
            return $(this).data('filter-excluded') !== true;
        }).length;

        // Update button labels with counts
        $('.hhdl-filter-btn[data-filter="arrivals"]').html('Arrivals <span class="hhdl-count-badge">' + counts.arrivals + '</span>');
        $('.hhdl-filter-btn[data-filter="departs"]').html('Departs <span class="hhdl-count-badge">' + counts.departures + '</span>');
        $('.hhdl-filter-btn[data-filter="stopovers"]').html('Stopovers <span class="hhdl-count-badge">' + counts.stopovers + '</span>');
        $('.hhdl-filter-btn[data-filter="back-to-back"]').html('Back to Back <span class="hhdl-count-badge">' + counts.back_to_back + '</span>');
        $('.hhdl-filter-btn[data-filter="twins"]').html('Twins <span class="hhdl-count-badge">' + counts.twins + '</span>');
        $('.hhdl-filter-btn[data-filter="blocked"]').html('Blocked <span class="hhdl-count-badge">' + counts.blocked + '</span>');
        $('.hhdl-filter-btn[data-filter="no-booking"]').html('No Booking <span class="hhdl-count-badge">' + counts.no_booking + '</span>');
        $('.hhdl-filter-btn[data-filter="unoccupied"]').html('Unoccupied <span class="hhdl-count-badge">' + counts.unoccupied + '</span>');
    }

    /**
     * Initialize view controls (view mode toggle, filters toggle, and reset button)
     */
    function initViewControls() {
        // Handle view mode toggle (grouped/flat only)
        $(document).on('click', '.hhdl-view-mode-btn[data-view-mode]', function() {
            const $btn = $(this);
            const viewMode = $btn.data('view-mode');

            // Update active state only for view mode buttons
            $('.hhdl-view-mode-btn[data-view-mode]').removeClass('active');
            $btn.addClass('active');

            // Save preference and reload
            saveUserPreference('view_mode', viewMode);
        });

        // Handle filters toggle button
        $(document).on('click', '#hhdl-toggle-filters', function() {
            const $btn = $(this);
            const $filtersWrapper = $('.hhdl-filters-wrapper');
            const isCurrentlyVisible = !$filtersWrapper.hasClass('hhdl-filters-hidden');

            // If showing States, hide Stats (mutually exclusive)
            if (isCurrentlyVisible) {
                // Currently visible, will be hidden - no need to hide stats
            } else {
                // Will be shown - hide the other filter
                const $statFiltersWrapper = $('.hhdl-stat-filters-wrapper');
                const $statBtn = $('#hhdl-toggle-stat-filters');
                if (!$statFiltersWrapper.hasClass('hhdl-stat-filters-hidden')) {
                    $statFiltersWrapper.addClass('hhdl-stat-filters-hidden');
                    $statBtn.removeClass('active');
                    // Reset stat filters
                    $('.hhdl-stat-filter-btn').removeClass('active');
                    $('.hhdl-stat-filter-btn[data-stat-filter="all"]').addClass('active');
                    filterRoomsByStats('all');
                    saveUserPreference('stat_filters_visible', false);
                }
            }

            // Toggle visibility
            $filtersWrapper.toggleClass('hhdl-filters-hidden');
            $btn.toggleClass('active');

            // If hiding filters, reset to "all" filter
            if (isCurrentlyVisible) {
                $('.hhdl-filter-btn').removeClass('active filter-exclusive');
                $('.hhdl-filter-btn[data-filter="all"]').addClass('active');
                filterRooms('all');
                // Remove sticky state when hiding filters
                $filtersWrapper.removeClass('hhdl-filters-sticky');
            }

            // Save preference (new state is opposite of current)
            saveUserPreference('filters_visible', !isCurrentlyVisible);
        });

        // Handle stat filters toggle button
        $(document).on('click', '#hhdl-toggle-stat-filters', function() {
            const $btn = $(this);
            const $statFiltersWrapper = $('.hhdl-stat-filters-wrapper');
            const isCurrentlyVisible = !$statFiltersWrapper.hasClass('hhdl-stat-filters-hidden');

            // If showing Stats, hide States (mutually exclusive)
            if (isCurrentlyVisible) {
                // Currently visible, will be hidden - no need to hide states
            } else {
                // Will be shown - hide the other filter
                const $filtersWrapper = $('.hhdl-filters-wrapper');
                const $stateBtn = $('#hhdl-toggle-filters');
                if (!$filtersWrapper.hasClass('hhdl-filters-hidden')) {
                    $filtersWrapper.addClass('hhdl-filters-hidden');
                    $stateBtn.removeClass('active');
                    // Reset state filters
                    $('.hhdl-filter-btn').removeClass('active filter-exclusive');
                    $('.hhdl-filter-btn[data-filter="all"]').addClass('active');
                    filterRooms('all');
                    $filtersWrapper.removeClass('hhdl-filters-sticky');
                    saveUserPreference('filters_visible', false);
                }
            }

            // Toggle visibility
            $statFiltersWrapper.toggleClass('hhdl-stat-filters-hidden');
            $btn.toggleClass('active');

            // If hiding stat filters, reset to "all" filter
            if (isCurrentlyVisible) {
                $('.hhdl-stat-filter-btn').removeClass('active');
                $('.hhdl-stat-filter-btn[data-stat-filter="all"]').addClass('active');
                filterRoomsByStats('all');
            }

            // Save preference (new state is opposite of current)
            saveUserPreference('stat_filters_visible', !isCurrentlyVisible);
        });

        // Handle controls toggle button (gear icon in header)
        $(document).on('click', '#hhdl-toggle-controls', function(e) {
            e.stopPropagation(); // Prevent triggering date picker
            const $btn = $(this);
            const $controls = $('.hhdl-view-controls');
            const isCurrentlyVisible = !$controls.hasClass('hhdl-controls-hidden');

            // Toggle visibility of view controls only (filters maintain independent state)
            $controls.toggleClass('hhdl-controls-hidden');
            $btn.toggleClass('active');

            // Save preference (new state is opposite of current)
            saveUserPreference('controls_visible', !isCurrentlyVisible);
        });

        // Handle reset preferences button
        $(document).on('click', '#hhdl-reset-preferences', function() {
            if (confirm('Reset all view preferences to defaults?')) {
                resetUserPreferences();
            }
        });
    }

    /**
     * Initialize category header interactions
     */
    function initCategoryHeaders() {
        // Handle category header clicks for collapse/expand
        $(document).on('click', '.hhdl-category-header', function(e) {
            // Don't toggle if clicking on a link or button within the header
            if ($(e.target).is('a, button') || $(e.target).closest('a, button').length) {
                return;
            }

            const $header = $(this);
            const categoryId = $header.data('category-id');
            const $categoryRooms = $(`.hhdl-category-rooms[data-category-id="${categoryId}"]`);
            const $arrow = $header.find('.hhdl-category-arrow');

            // Toggle collapsed state
            // IMPORTANT: Save preference BEFORE updating DOM, so getCollapsedCategories() reads correct state
            if ($categoryRooms.hasClass('hhdl-collapsed')) {
                removeFromCollapsedCategories(categoryId);
                $categoryRooms.removeClass('hhdl-collapsed');
                $arrow.text('expand_more');
            } else {
                addToCollapsedCategories(categoryId);
                $categoryRooms.addClass('hhdl-collapsed');
                $arrow.text('chevron_right');
            }
        });
    }

    /**
     * Save user preference
     */
    function saveUserPreference(key, value) {
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhdl_save_user_preferences',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                preferences: JSON.stringify({
                    [key]: value
                })
            },
            success: function(response) {
                if (response.success) {
                    // Reload room list if view mode changed
                    if (key === 'view_mode') {
                        loadRoomList(currentDate);
                    }
                }
            },
            error: function() {
                console.error('[HHDL] Failed to save user preference');
            }
        });
    }

    /**
     * Save filter preference (filter type and mode)
     */
    function saveFilterPreference(filter, mode) {
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhdl_save_user_preferences',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                preferences: JSON.stringify({
                    active_filter: filter,
                    active_filter_mode: mode
                })
            },
            error: function() {
                console.error('[HHDL] Failed to save filter preference');
            }
        });
    }

    /**
     * Scroll filters container to keep active filter visible
     */
    function scrollToActiveFilter() {
        const $activeBtn = $('.hhdl-filter-btn.active');
        if (!$activeBtn.length) return;

        const $filtersContainer = $('.hhdl-filters');
        const containerScrollLeft = $filtersContainer.scrollLeft();
        const containerWidth = $filtersContainer.width();
        const btnOffsetLeft = $activeBtn.position().left;
        const btnWidth = $activeBtn.outerWidth();

        // Check if button is outside visible area
        if (btnOffsetLeft < 0) {
            // Button is to the left, scroll left
            $filtersContainer.scrollLeft(containerScrollLeft + btnOffsetLeft - 10);
        } else if (btnOffsetLeft + btnWidth > containerWidth) {
            // Button is to the right, scroll right
            $filtersContainer.scrollLeft(containerScrollLeft + btnOffsetLeft + btnWidth - containerWidth + 10);
        }
    }

    /**
     * Update filters sticky state based on active filter
     */
    function updateFiltersStickyState() {
        const $filtersWrapper = $('.hhdl-filters-wrapper');
        const $activeFilter = $('.hhdl-filter-btn.active');
        const activeFilterType = $activeFilter.data('filter');

        // Make sticky if any filter other than 'all' is active
        if (activeFilterType && activeFilterType !== 'all') {
            $filtersWrapper.addClass('hhdl-filters-sticky');
        } else {
            $filtersWrapper.removeClass('hhdl-filters-sticky');
        }
    }

    /**
     * Initialize filter scroll arrows
     */
    function initFilterScrollArrows() {
        const $filtersContainer = $('.hhdl-filters');
        const $leftArrow = $('#hhdl-scroll-filters-left');
        const $rightArrow = $('#hhdl-scroll-filters-right');

        if (!$filtersContainer.length) return;

        // Update arrow visibility based on scroll position
        function updateArrowVisibility() {
            const scrollLeft = $filtersContainer.scrollLeft();
            const scrollWidth = $filtersContainer[0].scrollWidth;
            const containerWidth = $filtersContainer.width();

            // Show left arrow if not at the start
            if (scrollLeft > 5) {
                $leftArrow.addClass('visible');
            } else {
                $leftArrow.removeClass('visible');
            }

            // Show right arrow if not at the end
            if (scrollLeft < scrollWidth - containerWidth - 5) {
                $rightArrow.addClass('visible');
            } else {
                $rightArrow.removeClass('visible');
            }
        }

        // Handle left arrow click
        $leftArrow.on('click', function() {
            const scrollAmount = $filtersContainer.width() * 0.8;
            $filtersContainer.scrollLeft($filtersContainer.scrollLeft() - scrollAmount);
        });

        // Handle right arrow click
        $rightArrow.on('click', function() {
            const scrollAmount = $filtersContainer.width() * 0.8;
            $filtersContainer.scrollLeft($filtersContainer.scrollLeft() + scrollAmount);
        });

        // Update arrows on scroll
        $filtersContainer.on('scroll', updateArrowVisibility);

        // Update arrows on window resize
        $(window).on('resize', updateArrowVisibility);

        // Initial arrow visibility check
        setTimeout(updateArrowVisibility, 100);

        // Initial sticky state check
        updateFiltersStickyState();
    }

    /**
     * Initialize stat filter button handlers
     */
    function initStatFilters() {
        // Apply saved stat filter on init
        const $statFilters = $('.hhdl-stat-filters');
        const savedStatFilter = $statFilters.data('active-stat-filter');
        const savedStatFilterMode = $statFilters.data('active-stat-filter-mode');

        // Initialize all stat filter buttons with counts
        $('.hhdl-stat-filter-btn').each(function() {
            const $btn = $(this);
            const statFilter = $btn.data('stat-filter');
            if (statFilter !== 'all') {
                const mode = $btn.data('stat-filter-mode') || 'outstanding';
                const isActive = $btn.hasClass('active');
                updateStatFilterButtonLabel($btn, statFilter, mode, isActive);
            }
        });

        if (savedStatFilter && savedStatFilter !== 'all') {
            // Apply the saved stat filter
            filterRoomsByStats(savedStatFilter, savedStatFilterMode);
            // Scroll to show the active filter
            scrollToActiveStatFilter();
        } else if (savedStatFilter === 'all') {
            // Make sure All Rooms button has the green class
            $('.hhdl-stat-filter-btn[data-stat-filter="all"]').addClass('stat-filter-all-rooms');
        }

        $('.hhdl-stat-filter-btn').on('click', function() {
            const $btn = $(this);
            const statFilter = $btn.data('stat-filter');

            // 'All Rooms' button always clears all filters
            if (statFilter === 'all') {
                $('.hhdl-stat-filter-btn').removeClass('active stat-filter-outstanding stat-filter-complete stat-filter-unsaved stat-filter-submitted stat-filter-none stat-filter-all-rooms');
                // Reset all other buttons to show their default counts
                $('.hhdl-stat-filter-btn').not($btn).each(function() {
                    const $otherBtn = $(this);
                    const otherFilter = $otherBtn.data('stat-filter');
                    if (otherFilter !== 'all') {
                        const defaultMode = $otherBtn.data('stat-filter-mode');
                        updateStatFilterButtonLabel($otherBtn, otherFilter, defaultMode, false);
                    }
                });
                $btn.addClass('active stat-filter-all-rooms');
                filterRoomsByStats('all', null);
                saveStatFilterPreference('all', 'outstanding');
                return;
            }

            // Get current state
            const currentMode = $btn.data('stat-filter-mode');
            const isActive = $btn.hasClass('active');

            // Define mode sequences for each filter type
            let modes = [];
            switch(statFilter) {
                case 'newbook-tasks':
                case 'recurring-tasks':
                    modes = ['outstanding', 'complete', null]; // 3 states: outstanding, complete, clear
                    break;
                case 'linen-count':
                    modes = ['none', 'unsaved', 'submitted', null]; // 4 states: none, unsaved, submitted, clear
                    break;
                case 'clean-dirty':
                    modes = ['dirty', 'clean', null]; // 3 states: dirty, clean, clear
                    break;
            }

            // Determine next mode
            let nextMode;
            if (!isActive) {
                nextMode = modes[0]; // Start with first mode
            } else {
                const currentIndex = modes.indexOf(currentMode);
                nextMode = modes[currentIndex + 1];
            }

            // Clear all other stat filters and reset them to inactive state
            $('.hhdl-stat-filter-btn').not($btn).removeClass('active stat-filter-outstanding stat-filter-complete stat-filter-unsaved stat-filter-submitted stat-filter-none');
            $('.hhdl-stat-filter-btn').not($btn).each(function() {
                const $otherBtn = $(this);
                const otherFilter = $otherBtn.data('stat-filter');
                if (otherFilter !== 'all') {
                    const defaultMode = $otherBtn.data('stat-filter-mode');
                    updateStatFilterButtonLabel($otherBtn, otherFilter, defaultMode, false);
                }
            });
            $('.hhdl-stat-filter-btn[data-stat-filter="all"]').removeClass('active stat-filter-all-rooms');

            if (nextMode === null) {
                // Clear this filter - go back to 'all'
                $btn.removeClass('active stat-filter-outstanding stat-filter-complete stat-filter-unsaved stat-filter-submitted stat-filter-none');
                $btn.data('stat-filter-mode', modes[0]); // Reset to first mode
                updateStatFilterButtonLabel($btn, statFilter, modes[0], false);
                $('.hhdl-stat-filter-btn[data-stat-filter="all"]').addClass('active stat-filter-all-rooms');
                filterRoomsByStats('all', null);
                saveStatFilterPreference('all', 'outstanding');
            } else {
                // Apply next mode
                $btn.addClass('active');
                $btn.data('stat-filter-mode', nextMode);
                updateStatFilterButtonLabel($btn, statFilter, nextMode, true);
                filterRoomsByStats(statFilter, nextMode);
                saveStatFilterPreference(statFilter, nextMode);
                scrollToActiveStatFilter();
            }
        });
    }

    /**
     * Update stat filter button label, icon, and count based on mode
     * @param {jQuery} $btn - The button element
     * @param {string} statFilter - The filter type
     * @param {string} mode - The current mode
     * @param {boolean} isActive - Whether the button is active (applies color classes)
     */
    function updateStatFilterButtonLabel($btn, statFilter, mode, isActive = false) {
        let label = '';
        let icon = '';
        let count = 0;

        // Remove all state classes
        $btn.removeClass('stat-filter-outstanding stat-filter-complete stat-filter-unsaved stat-filter-submitted stat-filter-none');

        // Calculate count for the current mode
        count = calculateStatFilterCount(statFilter, mode);

        // Update icon, label, and optionally class based on filter and mode
        switch(statFilter) {
            case 'newbook-tasks':
                if (mode === 'outstanding') {
                    icon = 'assignment_late';
                    label = isActive ? 'Outstanding' : 'NewBook Tasks';
                    if (isActive) $btn.addClass('stat-filter-outstanding');
                } else if (mode === 'complete') {
                    icon = 'assignment_turned_in';
                    label = isActive ? 'Complete' : 'NewBook Tasks';
                    if (isActive) $btn.addClass('stat-filter-complete');
                } else {
                    icon = 'assignment_late';
                    label = 'NewBook Tasks';
                }
                break;

            case 'recurring-tasks':
                if (mode === 'outstanding') {
                    icon = 'checklist_rtl';
                    label = isActive ? 'Outstanding' : 'Recurring Tasks';
                    if (isActive) $btn.addClass('stat-filter-outstanding');
                } else if (mode === 'complete') {
                    icon = 'task_alt';
                    label = isActive ? 'Complete' : 'Recurring Tasks';
                    if (isActive) $btn.addClass('stat-filter-complete');
                } else {
                    icon = 'checklist_rtl';
                    label = 'Recurring Tasks';
                }
                break;

            case 'linen-count':
                if (mode === 'none') {
                    icon = 'dry_cleaning';
                    label = isActive ? 'No Count' : 'Linen Count';
                    if (isActive) $btn.addClass('stat-filter-none');
                } else if (mode === 'unsaved') {
                    icon = 'dry_cleaning';
                    label = isActive ? 'Unsaved' : 'Linen Count';
                    if (isActive) $btn.addClass('stat-filter-unsaved');
                } else if (mode === 'submitted') {
                    icon = 'dry_cleaning';
                    label = isActive ? 'Submitted' : 'Linen Count';
                    if (isActive) $btn.addClass('stat-filter-submitted');
                } else {
                    icon = 'dry_cleaning';
                    label = 'Linen Count';
                }
                break;

            case 'clean-dirty':
                if (mode === 'dirty') {
                    icon = 'cleaning_services';
                    label = isActive ? 'Dirty' : 'Clean/Dirty';
                    if (isActive) $btn.addClass('stat-filter-outstanding');
                } else if (mode === 'clean') {
                    icon = 'cleaning_services';
                    label = isActive ? 'Clean' : 'Clean/Dirty';
                    if (isActive) $btn.addClass('stat-filter-complete');
                } else {
                    icon = 'cleaning_services';
                    label = 'Clean/Dirty';
                }
                break;
        }

        // Update button content
        $btn.find('.material-symbols-outlined').text(icon);
        $btn.find('.hhdl-stat-filter-label').text(label);
        $btn.find('.hhdl-stat-count-badge').text(count);
    }

    /**
     * Calculate count for a specific stat filter and mode
     */
    function calculateStatFilterCount(statFilter, mode) {
        let count = 0;

        $('.hhdl-room-card').each(function() {
            const card = $(this);
            let matchesFilter = false;

            switch(statFilter) {
                case 'newbook-tasks':
                    if (mode === 'outstanding') {
                        matchesFilter = card.find('.hhdl-task-late, .hhdl-task-return').length > 0;
                    } else if (mode === 'complete') {
                        matchesFilter = card.find('.hhdl-task-complete').length > 0 ||
                                      card.find('.hhdl-task-late, .hhdl-task-return').length === 0;
                    }
                    break;

                case 'recurring-tasks':
                    // TODO: Implement when recurring task tracking is added
                    matchesFilter = false;
                    break;

                case 'linen-count':
                    if (mode === 'none') {
                        matchesFilter = card.find('.hhdl-linen-none').length > 0;
                    } else if (mode === 'unsaved') {
                        matchesFilter = card.find('.hhdl-linen-unsaved').length > 0;
                    } else if (mode === 'submitted') {
                        matchesFilter = card.find('.hhdl-linen-submitted').length > 0;
                    }
                    break;

                case 'clean-dirty':
                    // TODO: Implement when clean/dirty status is available
                    matchesFilter = false;
                    break;
            }

            if (matchesFilter) {
                count++;
            }
        });

        return count;
    }

    /**
     * Save stat filter preference
     */
    function saveStatFilterPreference(filter, mode) {
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhdl_save_user_preferences',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                preferences: JSON.stringify({
                    active_stat_filter: filter,
                    active_stat_filter_mode: mode
                })
            },
            error: function() {
                console.error('[HHDL] Failed to save stat filter preference');
            }
        });
    }

    /**
     * Scroll stat filters container to keep active filter visible
     */
    function scrollToActiveStatFilter() {
        const $activeBtn = $('.hhdl-stat-filter-btn.active');
        if (!$activeBtn.length) return;

        const $filtersContainer = $('.hhdl-stat-filters');
        const containerScrollLeft = $filtersContainer.scrollLeft();
        const containerWidth = $filtersContainer.width();
        const btnOffsetLeft = $activeBtn.position().left;
        const btnWidth = $activeBtn.outerWidth();

        // Check if button is outside visible area
        if (btnOffsetLeft < 0) {
            // Button is to the left, scroll left
            $filtersContainer.scrollLeft(containerScrollLeft + btnOffsetLeft - 10);
        } else if (btnOffsetLeft + btnWidth > containerWidth) {
            // Button is to the right, scroll right
            $filtersContainer.scrollLeft(containerScrollLeft + btnOffsetLeft + btnWidth - containerWidth + 10);
        }
    }

    /**
     * Initialize stat filter scroll arrows
     */
    function initStatFilterScrollArrows() {
        const $filtersContainer = $('.hhdl-stat-filters');
        const $leftArrow = $('#hhdl-scroll-stat-filters-left');
        const $rightArrow = $('#hhdl-scroll-stat-filters-right');

        if (!$filtersContainer.length) return;

        // Update arrow visibility based on scroll position
        function updateArrowVisibility() {
            const scrollLeft = $filtersContainer.scrollLeft();
            const scrollWidth = $filtersContainer[0].scrollWidth;
            const containerWidth = $filtersContainer.width();

            // Show left arrow if not at the start
            if (scrollLeft > 5) {
                $leftArrow.addClass('visible');
            } else {
                $leftArrow.removeClass('visible');
            }

            // Show right arrow if not at the end
            if (scrollLeft < scrollWidth - containerWidth - 5) {
                $rightArrow.addClass('visible');
            } else {
                $rightArrow.removeClass('visible');
            }
        }

        // Handle left arrow click
        $leftArrow.on('click', function() {
            const scrollAmount = $filtersContainer.width() * 0.8;
            $filtersContainer.scrollLeft($filtersContainer.scrollLeft() - scrollAmount);
        });

        // Handle right arrow click
        $rightArrow.on('click', function() {
            const scrollAmount = $filtersContainer.width() * 0.8;
            $filtersContainer.scrollLeft($filtersContainer.scrollLeft() + scrollAmount);
        });

        // Update arrows on scroll
        $filtersContainer.on('scroll', updateArrowVisibility);

        // Update arrows on window resize
        $(window).on('resize', updateArrowVisibility);

        // Initial arrow visibility check
        setTimeout(updateArrowVisibility, 100);
    }

    /**
     * Add category to collapsed list
     */
    function addToCollapsedCategories(categoryId) {
        // Ensure category ID is a string
        categoryId = String(categoryId);
        console.log('[HHDL DEBUG] addToCollapsedCategories called with:', categoryId, typeof categoryId);

        // Get current collapsed categories
        let collapsed = getCollapsedCategories();
        console.log('[HHDL DEBUG] Current collapsed categories from DOM:', collapsed);

        if (!collapsed.includes(categoryId)) {
            collapsed.push(categoryId);
            console.log('[HHDL DEBUG] Saving collapsed categories:', collapsed);
            saveUserPreference('collapsed_categories', collapsed);
        } else {
            console.log('[HHDL DEBUG] Category already in collapsed list, not saving');
        }
    }

    /**
     * Remove category from collapsed list
     */
    function removeFromCollapsedCategories(categoryId) {
        // Ensure category ID is a string
        categoryId = String(categoryId);
        console.log('[HHDL DEBUG] removeFromCollapsedCategories called with:', categoryId);

        let collapsed = getCollapsedCategories();
        console.log('[HHDL DEBUG] Current collapsed categories from DOM:', collapsed);

        const index = collapsed.indexOf(categoryId);
        if (index > -1) {
            collapsed.splice(index, 1);
            console.log('[HHDL DEBUG] Saving collapsed categories after removal:', collapsed);
            saveUserPreference('collapsed_categories', collapsed);
        } else {
            console.log('[HHDL DEBUG] Category not in collapsed list, not saving');
        }
    }

    /**
     * Get collapsed categories from local state
     */
    function getCollapsedCategories() {
        // Extract from DOM (already rendered with user preferences)
        const collapsed = [];
        $('.hhdl-category-rooms.hhdl-collapsed').each(function() {
            // Ensure category ID is stored as string
            const catId = String($(this).data('category-id'));
            collapsed.push(catId);
        });
        console.log('[HHDL DEBUG] getCollapsedCategories from DOM:', collapsed);
        return collapsed;
    }

    /**
     * Reset user preferences
     */
    function resetUserPreferences() {
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhdl_reset_user_preferences',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId
            },
            success: function(response) {
                if (response.success) {
                    // Reload the page to apply default preferences
                    location.reload();
                }
            },
            error: function() {
                console.error('[HHDL] Failed to reset user preferences');
            }
        });
    }

    /**
     * Update filter counts in category headers
     */
    function updateCategoryFilterCounts() {
        $('.hhdl-category-rooms').each(function() {
            const $category = $(this);
            const categoryId = $category.data('category-id');
            const $header = $(`.hhdl-category-header[data-category-id="${categoryId}"]`);

            // Count visible rooms in this category
            const totalRooms = $category.find('.hhdl-room-card').length;
            const visibleRooms = $category.find('.hhdl-room-card:visible').length;

            // Update the room count in header
            const $visibleCount = $header.find('.hhdl-visible-count');
            const $ofTotal = $header.find('.hhdl-of-total');

            $visibleCount.text(visibleRooms);

            if (visibleRooms < totalRooms) {
                if ($ofTotal.length === 0) {
                    $visibleCount.after(' <span class="hhdl-of-total">of ' + totalRooms + '</span>');
                } else {
                    $ofTotal.text('of ' + totalRooms);
                }
            } else {
                $ofTotal.remove();
            }
        });
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

                    // Re-apply saved filter to new data
                    const $filters = $('.hhdl-filters');
                    const activeFilter = $filters.data('active-filter') || 'all';
                    const activeFilterMode = $filters.data('active-filter-mode') || 'inclusive';
                    if (activeFilter && activeFilter !== 'all') {
                        filterRooms(activeFilter, activeFilterMode);
                        // Scroll to show the active filter
                        setTimeout(scrollToActiveFilter, 100);
                        // Update sticky state
                        setTimeout(updateFiltersStickyState, 100);
                    } else {
                        // Ensure sticky state is removed if 'all' filter
                        setTimeout(updateFiltersStickyState, 100);
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

        // Status badge toggle handler
        $(document).on('click', '.hhdl-status-toggle-btn', function() {
            const badge = $(this);
            const hasTasks = badge.data('has-tasks') === true || badge.data('has-tasks') === 'true';

            // Don't allow toggle if there are outstanding tasks
            if (hasTasks) {
                showToast('Cannot change status while tasks are outstanding', 'error');
                return;
            }

            const roomId = badge.data('room-id');
            const currentStatus = badge.data('current-status');
            const newStatus = currentStatus === 'Clean' ? 'Dirty' : 'Clean';

            // Show confirmation modal
            showStatusChangeConfirmation(roomId, currentStatus, newStatus, badge);
        });

        // Status change card handler (for "No tasks but still marked Dirty" card)
        $(document).on('click', '.hhdl-status-change-card', function() {
            const card = $(this);
            const roomId = card.data('room-id');
            const currentStatus = card.data('current-status');
            const newStatus = card.data('new-status');

            // Show confirmation modal
            showStatusChangeConfirmation(roomId, currentStatus, newStatus, card);
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
                if (response.success) {
                    // Update modal header
                    if (response.data.header) {
                        modalHeader.html(response.data.header);
                    }
                    // Update modal body
                    if (response.data.body) {
                        modalBody.html(response.data.body);
                    }

                    initTaskCheckboxes();
                    initNotesTabs();
                } else {
                    console.error('[HHDL] Modal load failed', response);
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
     * Initialize notes tab handlers
     */
    function initNotesTabs() {
        const noteTabs = $('.hhdl-note-tab');

        if (noteTabs.length === 0) {
            return; // No notes section
        }

        // Remove any existing handlers
        noteTabs.off('click');

        // Add click handler for each tab
        noteTabs.on('click', function() {
            const clickedTab = $(this);
            const typeId = clickedTab.data('type-id');
            const contentSection = $(`.hhdl-notes-content[data-type-id="${typeId}"]`);

            // Check if this tab is already active
            const isActive = clickedTab.hasClass('active');

            if (isActive) {
                // Close the active tab
                clickedTab.removeClass('active');
                contentSection.removeClass('active');
            } else {
                // Close all tabs first
                noteTabs.removeClass('active');
                $('.hhdl-notes-content').removeClass('active');

                // Open the clicked tab
                clickedTab.addClass('active');
                contentSection.addClass('active');
            }
        });
    }

    /**
     * Initialize task checkbox handlers
     */
    function initTaskCheckboxes() {
        const checkboxes = $('.hhdl-task-checkbox');

        if (checkboxes.length === 0) {
            return;
        }

        // Remove all event handlers first
        checkboxes.off('change');

        // Attach new handler (using .on() not .one() so it persists after cancel)
        checkboxes.on('change', async function(e) {
            const checkbox = $(this);

            // Prevent duplicate events - check processing flag first
            if (checkbox.data('processing') === true) {
                e.preventDefault();
                e.stopImmediatePropagation();
                checkbox.prop('checked', false); // Force uncheck
                return false;
            }

            // Also check if disabled (belt and suspenders)
            if (checkbox.prop('disabled')) {
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

            if (checkbox.prop('checked')) {
                // Set processing flag IMMEDIATELY to prevent duplicate events
                checkbox.data('processing', true);
                checkbox.prop('disabled', true);

                // Show confirmation dialogs if needed
                if (!taskData.isDefault || taskData.isOccupy) {
                    try {
                        const confirmed = await confirmTaskCompletion(taskData);
                        if (!confirmed) {
                            // User cancelled, clear processing flag and re-enable
                            checkbox.data('processing', false);
                            checkbox.prop('checked', false);
                            checkbox.prop('disabled', false);
                            return;
                        }
                    } catch (error) {
                        console.error('[HHDL] Error in confirmation:', error);
                        checkbox.data('processing', false);
                        checkbox.prop('checked', false);
                        checkbox.prop('disabled', false);
                        showToast('Error: ' + error.message, 'error');
                        return;
                    }
                }

                completeTask(taskData, checkbox, taskItem);
            }
        });
    }

    /**
     * Show custom confirmation modal
     */
    function showConfirmModal(title, message, iconType, confirmText, confirmClass) {
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

                // Add to page
                $('body').append(modal);

                // Force active class immediately and force display to flex (workaround for cache issue)
                modal.addClass('active');
                // Force display property inline with !important to override any CSS
                modal[0].style.setProperty('display', 'flex', 'important');
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
        // Checkbox should already be disabled from change handler, but ensure it
        checkbox.prop('disabled', true);

        // Add processing overlay
        var overlay = $('<div class="hhdl-task-processing-overlay">' +
            '<div class="hhdl-processing-spinner"></div>' +
            '<div class="hhdl-processing-text">Completing task on NewBook</div>' +
            '</div>');
        taskItem.append(overlay);

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
                if (response.success) {
                    // Remove overlay immediately
                    overlay.remove();

                    // Update room status badge if NewBook returned site_status
                    if (response.data.site_status) {
                        updateRoomStatusBadge(taskData.roomId, response.data.site_status);
                    }

                    // Fade out and remove task
                    taskItem.fadeOut(400, function() {
                        $(this).remove();
                        // Update task count after removal
                        updateTaskCount(taskData.roomId);
                    });

                    showToast(hhdlAjax.strings.taskCompleted, 'success');
                } else {
                    // Rollback on error
                    console.error('[HHDL] Task completion failed:', response.data && response.data.message ? response.data.message : 'Unknown error');

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
        var taskList = $('.hhdl-task-list');

        if (!taskList.length) {
            return;
        }

        // Count remaining incomplete tasks (visible items only, not fading out)
        var visibleTaskItems = taskList.find('.hhdl-task-item:visible');
        var incompleteTasks = visibleTaskItems.length;

        // Update the task count badge in modal header
        var modalBadge = $('.hhdl-modal-header .hhdl-task-count-badge');
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

                // Replace empty task list with "No outstanding" message
                var tasksSection = $('.hhdl-tasks-section');
                if (tasksSection.length) {
                    var taskListDiv = tasksSection.find('.hhdl-task-list');
                    if (taskListDiv.length && taskListDiv.children().length === 0) {
                        taskListDiv.replaceWith('<p>No outstanding housekeeping tasks</p>');
                    }
                }

                // Update status badge to allow clicking (no tasks remaining)
                var statusBadge = $('.hhdl-status-toggle-btn');
                if (statusBadge.length) {
                    statusBadge.attr('data-has-tasks', 'false');
                }
            }
        }

        // Update the task count badge on the room card in main list
        if (roomId) {
            var roomCard = $('.hhdl-room-card[data-room-id="' + roomId + '"]');

            if (roomCard.length) {
                var roomBadge = roomCard.find('.hhdl-task-count-badge');

                if (roomBadge.length) {
                    if (incompleteTasks > 0) {
                        roomBadge.text(incompleteTasks).show();
                    } else {
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
            // Task completion monitoring
            data.hhdl_monitor = {
                location_id: currentLocationId,
                viewing_date: currentDate,
                last_check: lastCheckTimestamp
            };

            // NewBook polling monitoring (date range: yesterday to tomorrow)
            data.nbp_monitor = {
                location_id: currentLocationId,
                date_from: getDateOffset(currentDate, -1),
                date_to: getDateOffset(currentDate, +1),
                last_check: lastPollingCheck
            };

            // Activity log monitoring (only if panel is open)
            const panel = $('#hhdl-activity-panel');
            if (panel.hasClass('open')) {
                data.hhdl_activity_monitor = {
                    location_id: currentLocationId,
                    service_date: currentDate,
                    last_check: lastActivityCheck
                };
            }
        });

        // Receive updates from server
        $(document).on('heartbeat-tick', function(e, data) {
            // Handle task completion updates
            if (data.hhdl_updates && data.hhdl_updates.completions) {
                handleRemoteUpdates(data.hhdl_updates.completions);
                lastCheckTimestamp = data.hhdl_updates.timestamp;
            }

            // Handle NewBook booking updates
            if (data.nbp_updates && data.nbp_updates.bookings) {
                handleBookingUpdates(data.nbp_updates.bookings);
                lastPollingCheck = data.nbp_updates.timestamp;
            }

            // Handle activity log updates
            if (data.hhdl_activity_updates && data.hhdl_activity_updates.events) {
                console.log('[HHDL Activity] Heartbeat received', data.hhdl_activity_updates.events.length, 'new events');
                const panel = $('#hhdl-activity-panel');
                if (panel.hasClass('open')) {
                    console.log('[HHDL Activity] Panel is open, prepending events');
                    prependActivityEvents(data.hhdl_activity_updates.events);
                    lastActivityCheck = data.hhdl_activity_updates.timestamp;
                } else {
                    console.log('[HHDL Activity] Panel is closed, skipping event prepend');
                }
            }
        });
    }

    /**
     * Initialize Activity Log Panel
     */
    let lastActivityCheck = new Date().toISOString();
    let activityRefreshInterval = null;

    function initActivityPanel() {
        const toggleBtn = $('#hhdl-toggle-activity-log');
        const closeBtn = $('#hhdl-close-activity-panel');
        const panel = $('#hhdl-activity-panel');

        // Toggle button handler
        toggleBtn.on('click', function() {
            const isOpen = panel.hasClass('open');
            console.log('[HHDL Activity] Toggle button clicked, panel is currently:', isOpen ? 'open' : 'closed');

            if (isOpen) {
                // Close panel
                panel.removeClass('open');
                toggleBtn.removeClass('active');
                $('body').removeClass('hhdl-activity-panel-open');
                clearInterval(activityRefreshInterval);
                activityRefreshInterval = null;
            } else {
                // Open panel
                panel.addClass('open');
                toggleBtn.addClass('active');
                $('body').addClass('hhdl-activity-panel-open');
                console.log('[HHDL Activity] Opening panel, loading activity for date:', currentDate);
                loadActivityLog(currentDate);

                // Start time refresh interval (every 30 seconds)
                activityRefreshInterval = setInterval(refreshActivityTimes, 30000);
            }

            // Save preference
            saveUserPreference('activity_panel_open', !isOpen);
        });

        // Close button handler
        closeBtn.on('click', function() {
            panel.removeClass('open');
            toggleBtn.removeClass('active');
            $('body').removeClass('hhdl-activity-panel-open');
            clearInterval(activityRefreshInterval);
            activityRefreshInterval = null;
            saveUserPreference('activity_panel_open', false);
        });

        // Load initial activity if panel is open
        if (panel.hasClass('open')) {
            $('body').addClass('hhdl-activity-panel-open');
            loadActivityLog(currentDate);
            activityRefreshInterval = setInterval(refreshActivityTimes, 30000);
        }

        // Listen for date changes
        $(document).on('hhdl-date-changed', function(e, newDate) {
            if (panel.hasClass('open')) {
                loadActivityLog(newDate);

                // Update header date
                const dateObj = new Date(newDate + 'T00:00:00');
                const formattedDate = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                $('.hhdl-activity-date').text(formattedDate);
            }
        });
    }

    /**
     * Load activity log from server
     */
    function loadActivityLog(date) {
        const list = $('#hhdl-activity-list');

        console.log('[HHDL Activity] Loading activity log for date:', date, 'location:', currentLocationId);

        // Show loading state
        list.html('<div class="hhdl-activity-loading"><span class="spinner"></span><p>Loading activity...</p></div>');

        $.ajax({
            url: hhdlAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhdl_get_activity_log',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                service_date: date
            },
            success: function(response) {
                console.log('[HHDL Activity] AJAX response:', response);
                if (response.success && response.data.events) {
                    console.log('[HHDL Activity] Found', response.data.events.length, 'events');
                    renderActivityLog(response.data.events);
                } else {
                    console.log('[HHDL Activity] No events found or request failed');
                    list.html('<div class="hhdl-activity-empty"><span class="material-symbols-outlined">inbox</span><p>No activity yet</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('[HHDL Activity] AJAX error:', status, error, xhr.responseText);
                list.html('<div class="hhdl-activity-empty"><span class="material-symbols-outlined">error</span><p>Failed to load activity</p></div>');
            }
        });
    }

    /**
     * Render activity log entries
     */
    function renderActivityLog(events) {
        const list = $('#hhdl-activity-list');

        if (!events || events.length === 0) {
            list.html('<div class="hhdl-activity-empty"><span class="material-symbols-outlined">inbox</span><p>No activity yet</p></div>');
            return;
        }

        let html = '';

        events.forEach(function(event) {
            const icon = getActivityIcon(event.event_type);
            const message = getActivityMessage(event);
            const timeAgo = formatTimeAgo(event.occurred_at);

            html += '<div class="hhdl-activity-entry" data-event-type="' + event.event_type + '" data-event-id="' + event.id + '">';
            html += '  <div class="hhdl-activity-icon">';
            html += '    <span class="material-symbols-outlined">' + icon + '</span>';
            html += '  </div>';
            html += '  <div class="hhdl-activity-content">';
            html += '    <div class="hhdl-activity-message">' + message + '</div>';
            html += '    <div class="hhdl-activity-meta">';
            html += '      <span class="hhdl-activity-time" data-timestamp="' + event.occurred_at + '">' + timeAgo + '</span>';
            html += '    </div>';
            html += '  </div>';
            html += '</div>';
        });

        list.html(html);
    }

    /**
     * Get icon for activity event type
     */
    function getActivityIcon(eventType) {
        const icons = {
            'checkout': 'logout',
            'checkin': 'login',
            'status_clean': 'check_circle',
            'status_dirty': 'cancel',
            'tasks_complete': 'task_alt',
            'linen_submit': 'inventory_2'
        };
        return icons[eventType] || 'info';
    }

    /**
     * Generate activity message from event data
     */
    function getActivityMessage(event) {
        const roomId = '<strong>Room ' + event.room_id + '</strong>';
        const eventData = event.event_data || {};

        switch (event.event_type) {
            case 'checkout':
                const guestName = eventData.guest_name ? ' (' + eventData.guest_name + ')' : '';
                return roomId + ' checked out' + guestName;

            case 'checkin':
                const arrivalName = eventData.guest_name ? ' (' + eventData.guest_name + ')' : '';
                return roomId + ' checked in' + arrivalName;

            case 'status_clean':
                const cleanedBy = eventData.changed_by || 'Someone';
                return roomId + ' marked <span class="hhdl-status-badge clean">Clean</span> by ' + cleanedBy;

            case 'status_dirty':
                const dirtiedBy = eventData.changed_by || 'Someone';
                return roomId + ' marked <span class="hhdl-status-badge dirty">Dirty</span> by ' + dirtiedBy;

            case 'tasks_complete':
                const completedBy = eventData.completed_by || 'Someone';
                return roomId + ' - all tasks completed by ' + completedBy;

            case 'linen_submit':
                const submittedBy = eventData.submitted_by || 'Someone';
                const itemCount = eventData.item_count || 0;
                return roomId + ' - linen count submitted by ' + submittedBy + ' (' + itemCount + ' items)';

            default:
                return roomId + ' - ' + event.event_type;
        }
    }

    /**
     * Format timestamp as relative time
     */
    function formatTimeAgo(datetime) {
        const now = new Date();
        const eventTime = new Date(datetime);
        const diffMs = now - eventTime;
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) {
            return 'Just now';
        } else if (diffMins < 60) {
            return diffMins + ' min' + (diffMins !== 1 ? 's' : '') + ' ago';
        } else {
            const diffHours = Math.floor(diffMins / 60);
            if (diffHours < 24) {
                return diffHours + ' hour' + (diffHours !== 1 ? 's' : '') + ' ago';
            } else {
                const diffDays = Math.floor(diffHours / 24);
                return diffDays + ' day' + (diffDays !== 1 ? 's' : '') + ' ago';
            }
        }
    }

    /**
     * Refresh all relative timestamps
     */
    function refreshActivityTimes() {
        $('.hhdl-activity-time').each(function() {
            const timestamp = $(this).data('timestamp');
            if (timestamp) {
                $(this).text(formatTimeAgo(timestamp));
            }
        });
    }

    /**
     * Prepend new activity events with animation
     */
    function prependActivityEvents(events) {
        if (!events || events.length === 0) return;

        const list = $('#hhdl-activity-list');
        const isEmpty = list.find('.hhdl-activity-empty').length > 0;

        if (isEmpty) {
            renderActivityLog(events);
            return;
        }

        let html = '';
        let addedCount = 0;
        events.forEach(function(event) {
            // Check if this event already exists (by event ID)
            if (list.find('[data-event-id="' + event.id + '"]').length > 0) {
                console.log('[HHDL Activity] Skipping duplicate event ID:', event.id);
                return; // Skip this event
            }

            const icon = getActivityIcon(event.event_type);
            const message = getActivityMessage(event);
            const timeAgo = formatTimeAgo(event.occurred_at);

            html += '<div class="hhdl-activity-entry hhdl-activity-new" data-event-type="' + event.event_type + '" data-event-id="' + event.id + '">';
            html += '  <div class="hhdl-activity-icon">';
            html += '    <span class="material-symbols-outlined">' + icon + '</span>';
            html += '  </div>';
            html += '  <div class="hhdl-activity-content">';
            html += '    <div class="hhdl-activity-message">' + message + '</div>';
            html += '    <div class="hhdl-activity-meta">';
            html += '      <span class="hhdl-activity-time" data-timestamp="' + event.occurred_at + '">' + timeAgo + '</span>';
            html += '    </div>';
            html += '  </div>';
            html += '</div>';
            addedCount++;
        });

        if (html) {
            console.log('[HHDL Activity] Prepending', addedCount, 'new events (skipped', (events.length - addedCount), 'duplicates)');
            list.prepend(html);
        }
    }

    /**
     * Get date with offset (for date range calculations)
     */
    function getDateOffset(dateStr, days) {
        const d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() + days);
        return d.toISOString().split('T')[0];
    }

    /**
     * Handle task completions from other users
     */
    function handleRemoteUpdates(completions) {
        if (!completions || completions.length === 0) return;

        console.log('[HHDL] Processing ' + completions.length + ' task completions');

        // Group completions by room for efficient updates
        const roomUpdates = {};

        completions.forEach(function(completion) {
            console.log('[HHDL] Task completed:', completion);

            // Track completions per room
            if (!roomUpdates[completion.room_id]) {
                roomUpdates[completion.room_id] = 0;
            }
            roomUpdates[completion.room_id]++;

            // Find and update the task checkbox if modal is open
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

        // Update room card badges
        Object.keys(roomUpdates).forEach(function(roomId) {
            const completedCount = roomUpdates[roomId];
            updateRoomTaskBadge(roomId, -completedCount); // Decrement by number of completions
        });
    }

    /**
     * Update room card task badge count
     */
    function updateRoomTaskBadge(roomId, delta) {
        const roomCard = $('.hhdl-room-card[data-room-id="' + roomId + '"]');
        if (!roomCard.length) {
            console.log('[HHDL] Room card not found for:', roomId);
            return;
        }

        const badge = roomCard.find('.hhdl-task-count-badge');
        if (!badge.length) {
            console.log('[HHDL] Task badge not found for room:', roomId);
            return;
        }

        // Get current count
        let currentCount = parseInt(badge.text()) || 0;
        let newCount = Math.max(0, currentCount + delta); // Don't go below 0

        console.log('[HHDL] Room ' + roomId + ' task count: ' + currentCount + ' → ' + newCount);

        if (newCount > 0) {
            badge.text(newCount).show();
        } else {
            // All tasks complete - hide badge and update icon
            badge.hide();

            const taskStatusContainer = roomCard.find('.hhdl-task-status');
            if (taskStatusContainer.length) {
                taskStatusContainer.removeClass('hhdl-task-late hhdl-task-return hhdl-task-future hhdl-task-none');
                taskStatusContainer.addClass('hhdl-task-complete');
            }

            const taskIcon = roomCard.find('.hhdl-stat-content .material-symbols-outlined').first();
            if (taskIcon.length) {
                taskIcon.text('assignment_turned_in');
                taskIcon.css('color', '#10b981'); // Green
            }
        }
    }

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        type = type || 'info';

        const toast = $('<div class="hhdl-toast hhdl-toast-' + type + '">' + message + '</div>');

        // Calculate stacked position based on existing toasts
        const existingToasts = $('.hhdl-toast').length;
        const bottomOffset = 20 + (existingToasts * 70); // 70px spacing per toast
        toast.css('bottom', bottomOffset + 'px');

        // Append inside #hha-standalone-app if it exists, otherwise append to body
        const $container = $('#hha-standalone-app').length ? $('#hha-standalone-app') : $('body');
        $container.append(toast);

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

    /**
     * Handle booking updates from NewBook polling
     */
    function handleBookingUpdates(bookings) {
        if (!bookings || bookings.length === 0) return;

        console.log('[HHDL] Processing ' + bookings.length + ' booking updates');
        console.log('[HHDL] Currently viewing date: ' + currentDate);

        bookings.forEach(function(booking) {
            // Check if this booking update is relevant to the date we're viewing
            // For checkout detection, we need to check if the departure date matches
            const bookingDeparture = booking.booking_departure ?
                booking.booking_departure.split(' ')[0] : null;
            const bookingArrival = booking.booking_arrival ?
                booking.booking_arrival.split(' ')[0] : null;

            console.log('[HHDL] Booking dates - Arrival:', bookingArrival, 'Departure:', bookingDeparture, 'Viewing:', currentDate);

            // Find the room card - also check if it's the right booking for this date
            const roomCard = $('.hhdl-room-card[data-room-id="' + booking.site_id + '"]');

            if (!roomCard.length) {
                console.log('[HHDL] Room card not found for site_id: ' + booking.site_id);
                return;
            }

            // Only update if this is a departure on the date we're viewing OR
            // if it's a room status update (Clean/Dirty) which is always relevant
            // This prevents updating yesterday's cards with today's booking changes

            // For departure updates, check if we're viewing the departure date
            const newStatus = booking.booking_status;
            const isDepartureRelevant = bookingDeparture === currentDate;
            const isArrivalRelevant = bookingArrival === currentDate;

            console.log('[HHDL] Date relevance check - Departure relevant:', isDepartureRelevant, 'Arrival relevant:', isArrivalRelevant);

            // Detect checkout - only if we're viewing the departure date AND this card represents a departure
            if (newStatus && newStatus.toLowerCase() === 'departed' && isDepartureRelevant) {
                // Check if this room card is actually showing a departure (not a new arrival)
                const isDeparting = roomCard.attr('data-is-departing') === 'true';
                const cardBookingType = roomCard.attr('data-booking-type');

                console.log('[HHDL] Card type check - Is departing:', isDeparting, 'Booking type:', cardBookingType);

                // Only update if this card represents a departure or depart type booking
                if (isDeparting || cardBookingType === 'depart') {
                    const previousStatus = roomCard.attr('data-previous-status');
                    console.log('[HHDL] Checking checkout: Previous status:', previousStatus, 'New status:', newStatus);

                    if (previousStatus && previousStatus.toLowerCase() !== 'departed') {
                        console.log('[HHDL] Checkout detected for departure card: Room ' + booking.site_name);
                        handleCheckout(roomCard, booking);

                        // DO NOT update data-booking-status - that's for today's booking
                        // Only data-previous-status was updated in handleCheckout
                    } else {
                        console.log('[HHDL] Previous guest already marked as departed, skipping');
                    }
                } else {
                    console.warn('[HHDL] Room card is not a departure (might be new arrival), skipping status update');
                }
            } else if (newStatus && newStatus.toLowerCase() === 'departed' && !isDepartureRelevant) {
                // This is a checkout but NOT for the date we're viewing - log it but don't update
                console.warn('[HHDL] Checkout detected but NOT for current viewing date - Room:', booking.site_name,
                           'Departure date:', bookingDeparture, 'Viewing date:', currentDate, '- SKIPPING UPDATE');
            }

            // Detect check-in (arrival) - only if we're viewing the arrival date
            if (newStatus && newStatus.toLowerCase() === 'arrived' && isArrivalRelevant) {
                const previousBookingStatus = roomCard.attr('data-booking-status');
                console.log('[HHDL] Checking arrival: Previous booking status:', previousBookingStatus, 'New status:', newStatus);

                if (previousBookingStatus && previousBookingStatus.toLowerCase() !== 'arrived') {
                    console.log('[HHDL] Arrival detected for room: ' + booking.site_name);

                    // Update booking status attribute
                    roomCard.attr('data-booking-status', 'arrived');

                    // Update booking status badge
                    const statusBadge = roomCard.find('.hhdl-booking-status-badge');
                    if (statusBadge.length) {
                        statusBadge.removeClass('hhdl-status-departed')
                                   .addClass('hhdl-status-arrived')
                                   .text('Arrived');
                    }

                    // Check guest name permission
                    const hasGuestNamePermission = roomCard.find('.hhdl-guest-name').length > 0 &&
                                                  !roomCard.find('.hhdl-guest-name').hasClass('hhdl-guest-blurred');

                    let guestName = null;
                    if (hasGuestNamePermission && booking.guests && booking.guests.length > 0 && booking.guests[0].firstname) {
                        guestName = booking.guests[0].firstname;
                        if (booking.guests[0].lastname) {
                            guestName += ' ' + booking.guests[0].lastname;
                        }
                    }

                    // Log arrival event (use arrival date as service_date)
                    const arrivalDate = bookingArrival || currentDate;
                    logCheckInOutEvent('checkin', booking.site_id, guestName, booking.booking_ref, arrivalDate);

                    console.log('[HHDL] Logged arrival for room:', booking.site_name, 'guest:', guestName, 'date:', arrivalDate);
                } else {
                    console.log('[HHDL] Guest already marked as arrived, skipping');
                }
            }

            // Update room status (Clean/Dirty/Inspected) if provided - always relevant
            if (booking.site_status) {
                updateRoomStatusDisplay(roomCard, booking.site_status);
            }
        });
    }

    /**
     * Handle checkout detection
     */
    function handleCheckout(roomCard, booking) {
        console.log('[HHDL] Checkout detected: Room ' + booking.site_name);

        // Update CSS data attributes for styling
        roomCard.attr('data-show-wider-border', 'false');
        roomCard.attr('data-previous-status', 'departed');

        // Hide the departure time element (they've already departed)
        const departureTimeElement = roomCard.find('.hhdl-prev-departure-time');
        if (departureTimeElement.length) {
            console.log('[HHDL] Hiding departure time element');
            departureTimeElement.hide();
        }

        // Update booking status badge
        const statusBadge = roomCard.find('.hhdl-booking-status-badge');
        if (statusBadge.length) {
            statusBadge.removeClass('hhdl-status-arrived')
                       .addClass('hhdl-status-departed')
                       .text('Departed');
        }

        // Show dismissable notification
        // Check if user has permission to view guest names (based on room card)
        const hasGuestNamePermission = roomCard.find('.hhdl-guest-name').length > 0 &&
                                      !roomCard.find('.hhdl-guest-name').hasClass('hhdl-guest-blurred');

        // Get guest name with permission check
        let guestName = 'Guest';
        let shouldBlurName = !hasGuestNamePermission;

        if (hasGuestNamePermission && booking.guests && booking.guests.length > 0 && booking.guests[0].firstname) {
            guestName = booking.guests[0].firstname;
            if (booking.guests[0].lastname) {
                guestName += ' ' + booking.guests[0].lastname;
            }
        }

        // Log for debugging
        console.log('[HHDL] Showing checkout notification for room:', booking.site_name, 'guest:', guestName, 'blurred:', shouldBlurName);

        // Log checkout event to activity log (use departure date as service_date)
        const departureDate = booking.booking_departure ? booking.booking_departure.split(' ')[0] : currentDate;
        logCheckInOutEvent('checkout', booking.site_id, hasGuestNamePermission ? guestName : null, booking.booking_ref, departureDate);

        // Ensure notification shows with a slight delay to avoid race conditions
        setTimeout(function() {
            showCheckoutNotification(booking.site_name, guestName, shouldBlurName);
        }, 100);
    }

    /**
     * Log check-in/out event to activity log
     */
    function logCheckInOutEvent(eventType, roomId, guestName, bookingRef, serviceDate) {
        console.log('[HHDL Activity] Logging', eventType, 'event for room', roomId, 'guest:', guestName, 'date:', serviceDate);
        $.ajax({
            url: hhdlAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'hhdl_log_checkin_checkout',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                room_id: roomId,
                event_type: eventType,
                guest_name: guestName,
                booking_ref: bookingRef,
                service_date: serviceDate
            },
            success: function(response) {
                if (response.success) {
                    console.log('[HHDL Activity] Successfully logged ' + eventType + ' event for room ' + roomId);
                } else {
                    console.warn('[HHDL Activity] Failed to log ' + eventType + ' event:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('[HHDL Activity] Error logging ' + eventType + ' event:', status, error, xhr.responseText);
            }
        });
    }

    /**
     * Update room status display (Clean/Dirty/Inspected)
     */
    function updateRoomStatusDisplay(roomCard, newStatus) {
        const statusBadge = roomCard.find('.hhdl-status-badge');

        if (!statusBadge.length) return;

        // Remove old status classes
        statusBadge.removeClass('hhdl-status-clean hhdl-status-dirty hhdl-status-inspected');

        // Add new status
        const statusClass = 'hhdl-status-' + newStatus.toLowerCase();
        statusBadge.addClass(statusClass).text(newStatus);

        // Update modal if open for this room
        const roomId = roomCard.data('room-id');
        const openModal = $('.hhdl-modal[data-room-id="' + roomId + '"]');

        if (openModal.is(':visible')) {
            openModal.find('.hhdl-modal-status-badge')
                     .removeClass('hhdl-status-clean hhdl-status-dirty hhdl-status-inspected')
                     .addClass(statusClass)
                     .text(newStatus);
        }

        console.log('[HHDL] Updated room status: ' + newStatus);
    }

    /**
     * Show dismissable checkout notification
     */
    function showCheckoutNotification(roomNumber, guestName, shouldBlurName) {
        console.log('[HHDL] showCheckoutNotification called - Room:', roomNumber, 'Guest:', guestName, 'Blurred:', shouldBlurName);

        // Prevent duplicate notifications
        if (checkoutNotifications[roomNumber]) {
            console.log('[HHDL] Notification already shown for ' + roomNumber);
            return;
        }
        checkoutNotifications[roomNumber] = true;

        // Get notification timeout from location settings (default to 10 seconds)
        let timeoutSeconds = 10;
        if (hhdlAjax.locationSettings && hhdlAjax.locationSettings[currentLocationId]) {
            const locationSetting = hhdlAjax.locationSettings[currentLocationId];
            if (locationSetting.checkout_notification_timeout !== undefined) {
                timeoutSeconds = parseInt(locationSetting.checkout_notification_timeout);
            }
        }
        const timeoutMs = timeoutSeconds * 1000;
        const isIndefinite = timeoutSeconds === 0;
        console.log('[HHDL] Using notification timeout:', isIndefinite ? 'indefinite' : timeoutSeconds + ' seconds');

        const notificationId = 'checkout-notif-' + Date.now();

        // Build guest name HTML with blur if needed
        const guestNameHtml = shouldBlurName ?
            '<span style="filter: blur(4px); user-select: none;">' + guestName + '</span>' :
            guestName;

        const notification = $('<div class="hhdl-checkout-notification" id="' + notificationId + '">' +
            '<div class="hhdl-notification-header" style="padding: 8px 12px !important;">' +
                '<span class="material-symbols-outlined" style="font-size: 18px;">logout</span>' +
                '<h3 style="margin: 0; font-size: 14px;">Room ' + roomNumber + ' Checked Out</h3>' +
                '<button class="hhdl-notification-close" data-id="' + notificationId + '" style="padding: 2px 6px; font-size: 18px;">&times;</button>' +
            '</div>' +
        '</div>');

        // Create container if needed - use more aggressive styling to ensure visibility
        let container = $('.hhdl-notification-container');
        if (!container.length) {
            console.log('[HHDL] Creating notification container');
            // Create container with inline styles that force visibility
            const containerHtml = '<div class="hhdl-notification-container" style="' +
                'position: fixed !important; ' +
                'top: 80px !important; ' +
                'right: 20px !important; ' +
                'z-index: 2147483647 !important; ' + // Maximum z-index value
                'max-width: 380px !important; ' +
                'display: block !important; ' +
                'visibility: visible !important; ' +
                'pointer-events: none !important;' +
                '"></div>';

            // Always append to body for maximum visibility
            $('body').append(containerHtml);
            container = $('.hhdl-notification-container');

            // Double-check it was created
            if (!container.length) {
                console.error('[HHDL] Failed to create notification container!');
                return;
            }
        }

        container.append(notification);
        console.log('[HHDL] Notification appended to container');

        // Force the notification to be visible with very aggressive inline styles (reduced padding)
        notification.attr('style',
            'display: block !important; ' +
            'opacity: 0 !important; ' +
            'transform: translateX(100px) !important; ' +
            'position: relative !important; ' +
            'margin-bottom: 12px !important; ' +
            'background: white !important; ' +
            'border-left: 4px solid #8b5cf6 !important; ' +
            'box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important; ' +
            'padding: 0 !important; ' +  // No padding on container, it's on inner elements
            'border-radius: 4px !important; ' +
            'min-width: 280px !important; ' +
            'max-width: 350px !important; ' +
            'pointer-events: auto !important; ' +
            'z-index: 2147483647 !important;'
        );

        // Animate in with a slightly longer delay to ensure DOM is ready
        setTimeout(function() {
            // Use setAttribute to override all styles including !important
            notification.attr('style',
                'display: block !important; ' +
                'opacity: 1 !important; ' +
                'transform: translateX(0) !important; ' +
                'position: relative !important; ' +
                'margin-bottom: 12px !important; ' +
                'background: white !important; ' +
                'border-left: 4px solid #8b5cf6 !important; ' +
                'box-shadow: 0 2px 10px rgba(0,0,0,0.1) !important; ' +
                'padding: 0 !important; ' +  // No padding on container
                'border-radius: 4px !important; ' +
                'min-width: 280px !important; ' +
                'max-width: 350px !important; ' +
                'pointer-events: auto !important; ' +
                'z-index: 2147483647 !important; ' +
                'transition: all 0.3s ease !important;'
            );

            console.log('[HHDL] Notification show styles applied');
            console.log('[HHDL] Container visible:', container.is(':visible'), 'Position:', container.css('position'), 'Z-index:', container.css('z-index'));
            console.log('[HHDL] Notification visible:', notification.is(':visible'), 'Display:', notification.css('display'));

            // Log element positions for debugging
            const offset = notification.offset();
            const containerOffset = container.offset();
            console.log('[HHDL] Notification position - Top:', offset ? offset.top : 'N/A', 'Left:', offset ? offset.left : 'N/A');
            console.log('[HHDL] Container position - Top:', containerOffset ? containerOffset.top : 'N/A', 'Left:', containerOffset ? containerOffset.left : 'N/A');

            // Check parent visibility
            const parent = notification.parent();
            console.log('[HHDL] Parent element:', parent[0], 'Parent visible:', parent.is(':visible'));
        }, 150);

        // Close button handler
        notification.find('.hhdl-notification-close').on('click', function() {
            const id = $(this).data('id');
            $('#' + id).removeClass('show').css('opacity', '0');
            setTimeout(function() {
                $('#' + id).remove();
                delete checkoutNotifications[roomNumber];
            }, 300);
        });

        // Auto-dismiss after configured timeout (only if not indefinite)
        if (!isIndefinite) {
            setTimeout(function() {
                if ($('#' + notificationId).length) {
                    $('#' + notificationId).removeClass('show').css({
                        'opacity': '0',
                        'transform': 'translateX(100px)'
                    });
                    setTimeout(function() {
                        $('#' + notificationId).remove();
                        delete checkoutNotifications[roomNumber];
                    }, 300);
                }
            }, timeoutMs);
        }
    }

    /**
     * Show confirmation modal for status change
     */
    async function showStatusChangeConfirmation(roomId, currentStatus, newStatus, badge) {
        const confirmed = await showConfirmModal(
            'Change Room Status',
            'Change room status from <strong>' + currentStatus + '</strong> to <strong>' + newStatus + '</strong> in NewBook?',
            'warning',
            'Change to ' + newStatus,
            ''
        );

        if (confirmed) {
            updateRoomStatus(roomId, newStatus, badge);
        }
    }

    /**
     * Update room status via AJAX
     */
    function updateRoomStatus(roomId, newStatus, element) {
        // Determine if this is a badge or a card
        const isCard = element.hasClass('hhdl-status-change-card');
        const isBadge = element.hasClass('hhdl-status-toggle-btn');

        // Disable element during update
        element.css('opacity', '0.5').css('pointer-events', 'none');

        $.ajax({
            url: hhdlAjax.ajaxUrl,
            method: 'POST',
            data: {
                action: 'hhdl_update_room_status',
                nonce: hhdlAjax.nonce,
                location_id: currentLocationId,
                room_id: roomId,
                site_status: newStatus
            },
            success: function(response) {
                if (response.success) {
                    if (isBadge) {
                        // Update the status badge in modal header
                        element.removeClass('clean dirty inspected')
                            .addClass(newStatus.toLowerCase())
                            .text(newStatus)
                            .data('current-status', newStatus);
                    } else if (isCard) {
                        // Replace the card with "No outstanding housekeeping tasks" message
                        element.replaceWith('<p>No outstanding housekeeping tasks</p>');

                        // Update the modal header status badge
                        var modalBadge = $('.hhdl-status-toggle-btn');
                        if (modalBadge.length) {
                            modalBadge.removeClass('clean dirty inspected')
                                .addClass(newStatus.toLowerCase())
                                .text(newStatus)
                                .data('current-status', newStatus);
                        }
                    }

                    // Update room card status badge in main list
                    updateRoomStatusBadge(roomId, newStatus);

                    showToast('Room marked as ' + newStatus, 'success');
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : 'Failed to update status';
                    showToast('NewBook Error: ' + errorMsg, 'error');
                }
            },
            error: function() {
                showToast('Network error. Please try again.', 'error');
            },
            complete: function() {
                // Re-enable element (if it still exists)
                if (!isCard) {
                    element.css('opacity', '').css('pointer-events', '');
                }
            }
        });
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
            console.log('[HHDL] Received HHA module-loaded event, resetting initialization');
            initialized = false; // Reset flag to allow re-initialization
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

    // Expose showToast to window for use by other modules
    window.showToast = showToast;
    console.log('[HHDL] showToast exposed to window');

})(jQuery);
