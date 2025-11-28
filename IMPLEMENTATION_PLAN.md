# Housekeeping Daily List Module - Implementation Plan

**Plugin Name**: `hotelhubmodule-housekeeping-dailylist`
**Prefix**: `hhdl`
**Version**: 1.0.0
**Department**: Housekeeping
**Repository**: `jtricerolph/hotelhubmodule-housekeeping-dailylist`

---

## Overview

Daily housekeeping task management module for Hotel Hub App with NewBook integration, real-time multi-user sync, and permission-based visibility controls.

### Key Features
- 3-day view (yesterday, today, tomorrow) from NewBook
- Vertical room list layout matching Chrome extension Staying tab
- Border slivers showing booking status for adjacent dates
- Per-location configurable default tasks with color coding
- Task completion tracking in NewBook + local logging
- Real-time multi-user synchronization via WordPress Heartbeat
- Four-tier permission system
- Mobile-optimized responsive design

---

## Permissions System

Register with Workforce Authentication:

1. **`hhdl_access_module`** - Access to Daily List module
2. **`hhdl_view_guest_details`** - View guest names and personal info
3. **`hhdl_view_rate_details`** - View pricing and rate information
4. **`hhdl_view_all_notes`** - View all booking notes (not just housekeeping)

---

## Directory Structure

```
hotelhubmodule-housekeeping-dailylist/
├── hotelhubmodule-housekeeping-dailylist.php  # Main plugin file
├── README.md                                   # User documentation
├── IMPLEMENTATION_PLAN.md                      # This file
├── .gitignore
│
├── includes/
│   ├── class-hhdl-core.php                    # Core singleton class
│   ├── class-hhdl-settings.php                # Settings page management
│   ├── class-hhdl-display.php                 # Frontend display rendering
│   ├── class-hhdl-ajax.php                    # AJAX request handlers
│   └── class-hhdl-heartbeat.php               # Real-time sync via Heartbeat API
│
├── admin/
│   └── views/
│       └── settings.php                       # Settings page template
│
└── assets/
    ├── css/
    │   └── daily-list.css                     # Vertical list styling
    └── js/
        └── daily-list.js                      # Frontend interactions
```

---

## Phase 1: Core Plugin Setup

### 1.1 Main Plugin File (`hotelhubmodule-housekeeping-dailylist.php`)

**Plugin Header**:
```php
/**
 * Plugin Name: Hotel Hub Module - Housekeeping - Daily List
 * Plugin URI: https://github.com/jtricerolph/hotelhubmodule-housekeeping-dailylist
 * Description: Daily housekeeping task management with NewBook integration and real-time sync
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: hhdl
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */
```

**Constants**:
```php
define('HHDL_VERSION', '1.0.0');
define('HHDL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HHDL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HHDL_PLUGIN_BASENAME', plugin_basename(__FILE__));
```

**Singleton Pattern**: Main class with `instance()` method
**Hooks**: Activation hook to create database table

### 1.2 Core Class (`includes/class-hhdl-core.php`)

**Responsibilities**:
- Load all include files
- Register module with Hotel Hub App
- Register permissions with Workforce Authentication
- Enqueue CSS/JS assets
- Initialize sub-components (Settings, Display, AJAX, Heartbeat)

**Module Registration** (via `hha_register_modules` hook):
```php
return array(
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
```

---

## Phase 2: Database Schema

### 2.1 Task Completions Table

**Table Name**: `{$wpdb->prefix}hhdl_task_completions`

**Schema**:
```sql
CREATE TABLE {$wpdb->prefix}hhdl_task_completions (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id BIGINT(20) UNSIGNED NOT NULL,
    room_id VARCHAR(50) NOT NULL,
    task_id BIGINT(20) UNSIGNED DEFAULT NULL,
    task_type VARCHAR(100) NOT NULL,
    completed_by BIGINT(20) UNSIGNED NOT NULL COMMENT 'WordPress user ID',
    completed_at DATETIME NOT NULL,
    booking_ref VARCHAR(100) DEFAULT NULL,
    service_date DATE NOT NULL,
    PRIMARY KEY (id),
    KEY location_date_idx (location_id, service_date),
    KEY room_date_idx (room_id, service_date),
    KEY completed_by_idx (completed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Creation**: Run in activation hook with `dbDelta()`

---

## Phase 3: Settings Page

### 3.1 Settings Class (`includes/class-hhdl-settings.php`)

**Pattern**: Follow Twin Optimiser settings structure

**Data Structure**:
```php
// Stored in option: hhdl_location_settings
array(
    {location_id} => array(
        'enabled' => true/false,
        'default_tasks' => array(
            array(
                'id' => unique_id,
                'name' => 'Clean Room',
                'color' => '#10b981',
                'order' => 0
            ),
            array(
                'id' => unique_id,
                'name' => 'Change Linen',
                'color' => '#3b82f6',
                'order' => 1
            ),
            // ...
        )
    )
)
```

**Methods**:
- `render()` - Display settings page
- `save_settings()` - Handle form submission
- `get_location_settings($location_id)` - Get settings for location
- `get_default_tasks($location_id)` - Get default tasks array
- `is_enabled($location_id)` - Check if enabled for location

### 3.2 Settings Template (`admin/views/settings.php`)

**Layout**:
- Table with one row per location (like Twin Optimiser)
- Enabled checkbox per location
- Task manager section:
  - List of tasks (sortable with drag handles)
  - Each task: Name input, Color picker, Delete button
  - "Add Task" button
  - Tasks stored as JSON in hidden field
- Save button

**JavaScript**:
- Add/remove task functionality
- Sortable task list (jQuery UI Sortable or plain JS)
- Update hidden field on changes
- Color picker interaction

---

## Phase 4: Frontend Display

### 4.1 Display Class (`includes/class-hhdl-display.php`)

**Responsibilities**:
- Check user permissions
- Render date picker header (sticky)
- Fetch 3-day data from NewBook
- Render vertical room list
- Render filter buttons
- Generate room card HTML
- Inject inline styles for dynamic colors

**Room Card Data Attributes**:
```html
<div class="hhdl-room-card"
     data-room-id="101"
     data-is-arriving="true"
     data-is-departing="false"
     data-is-stopover="false"
     data-has-twin="false"
     data-booking-status="confirmed"
     data-spans-previous="false"
     data-spans-next="true">
```

**Border Sliver Logic**:
- Check if booking exists yesterday → `data-spans-previous="true"`
- Check if booking exists tomorrow → `data-spans-next="true"`
- Apply status color to borders based on adjacent bookings

### 4.2 Room Card Content Structure

**Vacant Room**:
```html
<div class="hhdl-room-card hhdl-vacant">
    <div class="hhdl-room-content">
        <span class="hhdl-room-number">101</span>
        <span class="hhdl-vacant-label">No booking</span>
        <span class="hhdl-site-status dirty">Dirty</span>
    </div>
</div>
```

**Booked Room**:
```html
<div class="hhdl-room-card hhdl-booked" data-booking-status="confirmed">
    <div class="hhdl-room-header">
        <span class="hhdl-room-number">102</span>
        <span class="hhdl-site-status clean">Clean</span>
        <span class="hhdl-arrival-icon">→</span>
    </div>
    <div class="hhdl-booking-info">
        <span class="hhdl-guest-name">John Smith</span> <!-- if permission -->
        <span class="hhdl-ref-number">NB123456</span>   <!-- if no permission -->
        <span class="hhdl-checkin-time">14:00</span>
        <span class="hhdl-pax-badge">2 pax</span>
    </div>
    <div class="hhdl-booking-meta">
        <span class="hhdl-nights">2/5 nights</span>
        <span class="hhdl-occupancy-badge">🛏️ 2/3</span>
        <span class="hhdl-twin-icon">👥</span> <!-- if twin -->
    </div>
</div>
```

### 4.3 Room Detail Modal

**Structure**:
```html
<div class="hhdl-modal-overlay">
    <div class="hhdl-modal">
        <div class="hhdl-modal-header">
            <h2>Room 102 - John Smith</h2>
            <button class="hhdl-modal-close">&times;</button>
        </div>
        <div class="hhdl-modal-body">
            <!-- Booking Details Section -->
            <section class="hhdl-booking-section">
                <h3>Booking Details</h3>
                <!-- Booking info based on permissions -->
            </section>

            <!-- NewBook Tasks Section -->
            <section class="hhdl-tasks-section">
                <h3>Tasks</h3>
                <div class="hhdl-task-list">
                    <!-- Task checkboxes with colors from settings -->
                </div>
            </section>

            <!-- Future Placeholders -->
            <section class="hhdl-placeholder">
                <h3>Recurring Tasks</h3>
                <p class="hhdl-placeholder-text">Future module integration</p>
            </section>

            <section class="hhdl-placeholder">
                <h3>Spoilt Linen Tracking</h3>
                <p class="hhdl-placeholder-text">Future module integration</p>
            </section>
        </div>
    </div>
</div>
```

---

## Phase 5: Styling (CSS)

### 5.1 Color Scheme (from Chrome Extension Research)

**Booking Status Colors**:
- **Departed**: `#8b5cf6` (Purple)
- **Confirmed**: `#10b981` (Green)
- **Unconfirmed**: `#f59e0b` (Amber/Orange)
- **Arrived**: `#fb923c` (Light Red)
- **Seated**: `#dc2626` (Dark Red)
- **Waitlist**: `#eab308` (Yellow)
- **Cancelled**: `#94a3b8` (Light Gray)

**Typography Scale**:
- Room numbers: 13px, font-weight: 600
- Guest names: 14px, font-weight: 500
- Time/badges: 12px, font-weight: 500
- Meta info: 11px
- Primary text: `#1f2937`
- Secondary text: `#6b7280`
- Muted text: `#9ca3af`

**Spacing**:
- Card margin: `0 23px 5px 23px` (23px = 15px timeline + 8px gap)
- Card padding: 8px
- Gap between elements: 6-8px

### 5.2 Border Sliver Implementation

```css
/* Base room card */
.hhdl-room-card {
    background: #fff;
    border: 2px solid #d1d5db;
    border-left: 4px solid; /* Status color injected inline */
    border-radius: 8px;
    margin: 0 23px 5px 23px;
    padding: 8px;
    position: relative;
    overflow: visible;
    transition: all 0.2s;
}

/* Spans from previous day */
.hhdl-room-card[data-spans-previous="true"] {
    border-left: none;
    border-radius: 0 8px 8px 0;
}

/* Timeline indicator - absolute positioned outside card */
.hhdl-room-card[data-spans-previous="true"]::before {
    content: '';
    position: absolute;
    left: -15px;
    top: 0;
    bottom: 0;
    width: 4px;
    background-color: var(--previous-status-color);
    border-radius: 2px 0 0 2px;
}

/* Spans to next day */
.hhdl-room-card[data-spans-next="true"] {
    border-right: 4px solid var(--next-status-color);
    border-radius: 8px 0 0 8px;
}

/* Spans both directions */
.hhdl-room-card[data-spans-previous="true"][data-spans-next="true"] {
    border-left: none;
    border-right: 4px solid var(--next-status-color);
    border-radius: 0;
}
```

### 5.3 Responsive Design

**Breakpoints**:
- Mobile portrait: < 600px (base styles)
- Mobile landscape / tablet: 600px - 900px
- Desktop: > 900px

**Mobile-First Approach**: Base styles optimized for portrait phones

---

## Phase 6: NewBook API Integration

### 6.1 Data Fetching

**Bookings** (3-day period):
```php
// Fetch from NewBook API
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($selected_date)));
$tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($selected_date)));

// Get bookings for 3-day span
// Check each room for occupancy on all 3 dates
// Determine booking_status for border colors
```

**Site Status**:
```php
// From sites_list endpoint
// Fields: site_id, site_status ('Dirty' / 'Clean')
```

**Tasks**:
```php
// From tasks_list endpoint
// Filter: service_date = $selected_date
// Check for 'occupy site' task types
// Match against configured default tasks
```

### 6.2 Twin/Sofabed Detection

**Reuse Twin Optimiser Logic**:
- Check custom fields for twin indicators
- Search booking notes for keywords
- Return icons to display on room cards

### 6.3 Task Type Formatting

**From Hotel NewBook Integration Settings**:
- Each hotel/location has configured task types
- Task types have: name, icon, color
- Use this mapping to display tasks with proper styling

---

## Phase 7: AJAX Handlers

### 7.1 AJAX Class (`includes/class-hhdl-ajax.php`)

**Endpoints**:

1. **`hhdl_get_rooms`**
   - Input: `location_id`, `date`
   - Output: HTML for room list
   - Security: Check `hhdl_access_module` permission

2. **`hhdl_get_room_details`**
   - Input: `location_id`, `room_id`, `date`
   - Output: JSON with booking details, tasks
   - Security: Check permissions for each data field

3. **`hhdl_complete_task`**
   - Input: `location_id`, `room_id`, `task_id`, `task_type`, `booking_ref`, `service_date`
   - Process:
     - Begin database transaction
     - Check if already completed (prevent duplicates)
     - Mark complete in NewBook API
     - Insert into `hhdl_task_completions` table
     - Commit transaction
   - Output: JSON success/error
   - Security: Check `hhdl_access_module` permission

### 7.2 Database Locking for Task Completion

```php
global $wpdb;
$wpdb->query('START TRANSACTION');

// Check for existing completion
$exists = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}hhdl_task_completions
     WHERE room_id = %s AND task_type = %s AND service_date = %s
     FOR UPDATE", // Row lock
    $room_id, $task_type, $service_date
));

if ($exists) {
    $wpdb->query('ROLLBACK');
    return array('error' => 'Task already completed');
}

// Insert completion record
$wpdb->insert(...);

// Update NewBook
$newbook_result = update_newbook_task($task_id);

if ($newbook_result === false) {
    $wpdb->query('ROLLBACK');
    return array('error' => 'Failed to update NewBook');
}

$wpdb->query('COMMIT');
```

---

## Phase 8: Real-time Multi-User Sync

### 8.1 Heartbeat Class (`includes/class-hhdl-heartbeat.php`)

**WordPress Heartbeat API**:
- Default interval: 15-60 seconds (WordPress manages this)
- Custom interval: Set to 30 seconds for near real-time

**Hooks**:
```php
add_filter('heartbeat_received', array($this, 'heartbeat_received'), 10, 2);
add_filter('heartbeat_send', array($this, 'heartbeat_send'), 10, 1);
```

**Send (Frontend → Backend)**:
```javascript
// In assets/js/daily-list.js
$(document).on('heartbeat-send', function(e, data) {
    data.hhdl_monitor = {
        location_id: currentLocationId,
        viewing_date: currentDate,
        last_check: lastCheckTimestamp
    };
});
```

**Receive (Backend → Frontend)**:
```php
// In class-hhdl-heartbeat.php
public function heartbeat_received($response, $data) {
    if (isset($data['hhdl_monitor'])) {
        $location_id = $data['hhdl_monitor']['location_id'];
        $last_check = $data['hhdl_monitor']['last_check'];

        // Query recent completions
        global $wpdb;
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hhdl_task_completions
             WHERE location_id = %d AND completed_at > %s",
            $location_id, $last_check
        ));

        $response['hhdl_updates'] = $recent;
    }
    return $response;
}
```

### 8.2 Frontend Heartbeat Handler

```javascript
// In assets/js/daily-list.js
$(document).on('heartbeat-tick', function(e, data) {
    if (data.hhdl_updates && data.hhdl_updates.length > 0) {
        data.hhdl_updates.forEach(function(completion) {
            // Update UI for completed task
            updateTaskUI(completion.room_id, completion.task_type, true);

            // Show toast notification
            if (completion.completed_by !== currentUserId) {
                showToast(`Task completed by ${completion.completed_by_name}`);
            }
        });

        // Update last check timestamp
        lastCheckTimestamp = new Date().toISOString();
    }
});
```

---

## Phase 9: JavaScript Implementation

### 9.1 Daily List JS (`assets/js/daily-list.js`)

**Core Functions**:

1. **Initialization**
   ```javascript
   function initDailyList() {
       initDatePicker();
       initFilters();
       initRoomCards();
       initModal();
       initHeartbeat();
       initGlobalKeyHandlers();
   }
   ```

2. **Date Picker**
   ```javascript
   function initDatePicker() {
       $('#hhdl-date-picker').on('change', function() {
           const date = $(this).val();
           loadRoomList(date);
       });
   }
   ```

3. **Filter Buttons**
   ```javascript
   function initFilters() {
       $('.hhdl-filter-btn').on('click', function() {
           const filter = $(this).data('filter');
           filterRooms(filter);
       });
   }

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
   ```

4. **Room Card Click**
   ```javascript
   function initRoomCards() {
       $(document).on('click', '.hhdl-room-card', function() {
           const roomId = $(this).data('room-id');
           const date = $('#hhdl-date-picker').val();
           openRoomModal(roomId, date);
       });
   }
   ```

5. **Modal Management**
   ```javascript
   function openRoomModal(roomId, date) {
       // Show loading state
       showModalLoading();

       // Fetch room details via AJAX
       $.ajax({
           url: hhdlAjax.ajaxUrl,
           method: 'POST',
           data: {
               action: 'hhdl_get_room_details',
               nonce: hhdlAjax.nonce,
               room_id: roomId,
               date: date
           },
           success: function(response) {
               if (response.success) {
                   populateModal(response.data);
                   $('#hhdl-modal').addClass('active');
               }
           }
       });
   }

   function closeModal() {
       $('#hhdl-modal').removeClass('active');
   }
   ```

6. **Task Completion**
   ```javascript
   function initTaskCheckboxes() {
       $(document).on('change', '.hhdl-task-checkbox', function() {
           const checkbox = $(this);
           const taskId = checkbox.data('task-id');
           const taskType = checkbox.data('task-type');
           const roomId = checkbox.data('room-id');

           // Optimistic UI update
           checkbox.prop('disabled', true);

           // AJAX request
           $.ajax({
               url: hhdlAjax.ajaxUrl,
               method: 'POST',
               data: {
                   action: 'hhdl_complete_task',
                   nonce: hhdlAjax.nonce,
                   task_id: taskId,
                   task_type: taskType,
                   room_id: roomId,
                   // ... other params
               },
               success: function(response) {
                   if (response.success) {
                       showToast('Task completed successfully');
                       updateTaskUI(roomId, taskType, true);
                   } else {
                       // Rollback optimistic update
                       checkbox.prop('checked', false);
                       showToast('Error: ' + response.data.message, 'error');
                   }
               },
               complete: function() {
                   checkbox.prop('disabled', false);
               }
           });
       });
   }
   ```

7. **Toast Notifications**
   ```javascript
   function showToast(message, type = 'info') {
       const toast = $('<div class="hhdl-toast"></div>')
           .addClass('hhdl-toast-' + type)
           .text(message);

       $('body').append(toast);

       setTimeout(function() {
           toast.addClass('hhdl-toast-show');
       }, 10);

       setTimeout(function() {
           toast.removeClass('hhdl-toast-show');
           setTimeout(function() {
               toast.remove();
           }, 300);
       }, 3000);
   }
   ```

8. **ESC Key Handler**
   ```javascript
   function initGlobalKeyHandlers() {
       $(document).on('keydown', function(e) {
           if (e.key === 'Escape') {
               closeModal();
           }
       });
   }
   ```

---

## Phase 10: Testing & Quality Assurance

### 10.1 Functional Testing

**Module Registration**:
- [ ] Module tile appears in Hotel Hub modules overview
- [ ] Module shows in correct department (Housekeeping)
- [ ] Icon and color display correctly

**Settings Page**:
- [ ] Per-location sections render correctly
- [ ] Can enable/disable per location
- [ ] Can add new default tasks
- [ ] Can remove default tasks
- [ ] Can reorder tasks (drag/drop or up/down buttons)
- [ ] Color pickers work (HTML5 input type="color")
- [ ] Settings save successfully
- [ ] Settings persist after page reload

**Frontend Display**:
- [ ] Date picker renders and is sticky on scroll
- [ ] Room list displays for selected date
- [ ] Vacant rooms show correctly
- [ ] Booked rooms show correct information
- [ ] Border colors match booking status
- [ ] Border slivers appear for stayovers
- [ ] Filter buttons work (Arrivals, Departs, Stopovers, Twins)
- [ ] Responsive design works on mobile/tablet/desktop

**Room Modal**:
- [ ] Clicking room card opens modal
- [ ] Modal shows booking details (permission-based)
- [ ] Tasks display with correct colors from settings
- [ ] Checkboxes work for task completion
- [ ] Placeholder sections render
- [ ] Close button works
- [ ] ESC key closes modal
- [ ] Click outside modal closes it

**NewBook Integration**:
- [ ] 3-day bookings fetch correctly
- [ ] Site status (Dirty/Clean) displays
- [ ] Tasks fetch from NewBook
- [ ] Task completion updates NewBook
- [ ] Twin/sofabed detection works
- [ ] Task type formatting uses hotel settings

**Real-time Sync**:
- [ ] Heartbeat API initializes
- [ ] Task completions broadcast to other users
- [ ] UI updates without page refresh
- [ ] Toast notifications appear
- [ ] No duplicate completions
- [ ] Database locking prevents conflicts

**Permissions**:
- [ ] Users without `hhdl_access_module` cannot access
- [ ] Guest names hidden without `hhdl_view_guest_details`
- [ ] Reference numbers shown instead of names
- [ ] Rate information hidden without `hhdl_view_rate_details`
- [ ] All notes hidden without `hhdl_view_all_notes`

### 10.2 Performance Testing

- [ ] Page load time < 2 seconds
- [ ] AJAX requests complete < 1 second
- [ ] Heartbeat doesn't cause lag
- [ ] Large room lists (50+ rooms) render smoothly
- [ ] No memory leaks in JavaScript

### 10.3 Browser Testing

- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

---

## Phase 11: Documentation

### 11.1 README.md

**Sections**:
- Features overview
- Requirements (WordPress 5.8+, PHP 7.4+, Hotel Hub App, Workforce Authentication)
- Installation instructions
- Configuration guide
- Screenshots
- Changelog

### 11.2 Code Comments

- PHPDoc blocks for all classes and methods
- Inline comments for complex logic
- TODO comments for future enhancements

---

## Phase 12: Git & Deployment

### 12.1 Initial Commit Structure

```bash
# Create directory and initialize
cd C:\Users\JTR\Documents\GitHub
mkdir hotelhubmodule-housekeeping-dailylist
cd hotelhubmodule-housekeeping-dailylist
git init

# Create directory structure
mkdir -p includes admin/views assets/css assets/js

# Create initial files
# ... (create all files)

# Initial commit
git add .
git commit -m "Initial plugin structure with core files and implementation plan"

# Add remote
git remote add origin https://github.com/jtricerolph/hotelhubmodule-housekeeping-dailylist.git

# Push to GitHub
git branch -M main
git push -u origin main
```

### 12.2 Commit Strategy

- Commit after each phase completion
- Use semantic commit messages
- Create feature branches for major additions
- Tag releases (v1.0.0, v1.1.0, etc.)

---

## Future Enhancements

### Placeholder Module Integrations

1. **Recurring Tasks Module**
   - Routine housekeeping tasks (weekly deep clean, monthly maintenance)
   - Schedule-based task generation
   - Integration point already in room modal

2. **Spoilt Linen Tracking Module**
   - Track damaged/stained linen per room
   - Running count across all rooms cleaned
   - Daily/weekly reports
   - Integration point already in room modal

### Additional Features (Post v1.0)

- [ ] Export daily list to PDF
- [ ] Print-friendly view
- [ ] Task completion statistics/reports
- [ ] Cleaner performance metrics
- [ ] Push notifications (PWA integration)
- [ ] Offline support (Service Worker)
- [ ] Voice commands for task completion
- [ ] QR code scanning for room verification

---

## Technical References

### Chrome Extension Research

**File**: `C:\Users\JTR\Documents\GitHub\chrome-newbook-assistant\sidepanel\sidepanel.css`
- Lines 1604-1720: Staying tab styles
- Lines 1896-2425: Restaurant card styles (similar patterns)

**File**: `C:\Users\JTR\Documents\GitHub\chrome-newbook-assistant\sidepanel\sidepanel.js`
- Lines 5285-5411: `loadStayingTab()` function
- Lines 5464-5612: `initializeStayingCards()` function
- Lines 720-731: Status color definitions

**Status Colors**:
- Departed/Left: `#8b5cf6` (Purple)
- Confirmed/Approved: `#10b981` (Green)
- Unconfirmed/Request: `#f59e0b` (Orange)

### Twin Optimiser Reference

**Module Registration**: `hotelhubmodule-housekeeping-twinoptimiser\hotelhubmodule-housekeeping-twinoptimiser.php` lines 60-85

**Settings Pattern**: `hotelhubmodule-housekeeping-twinoptimiser\includes\class-hhtm-settings.php` lines 280-298

**Color Picker Implementation**: Lines 97-127 (native HTML5 color input)

---

## Notes

- **Mobile-First**: All designs optimized for portrait phone usage
- **Permission-Based**: Every data field respects user permissions
- **Real-time**: WordPress Heartbeat provides near real-time sync (30s intervals)
- **Extensible**: Placeholder sections for future module integrations
- **Consistent**: Follows patterns from existing Twin Optimiser module

---

**Document Version**: 1.0
**Last Updated**: 2025-01-28
**Status**: Ready for implementation
