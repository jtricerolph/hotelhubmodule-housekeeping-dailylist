# Hotel Hub Module - Housekeeping - Daily List

Daily housekeeping task management module for Hotel Hub App with NewBook integration and real-time multi-user synchronization.

## Features

- **3-Day View**: Display yesterday, today, and tomorrow bookings for comprehensive planning
- **Visual Status Indicators**: Color-coded border slivers showing booking status for adjacent dates
- **Task Management**: NewBook-integrated task tracking with local completion logging
- **Real-time Sync**: Multi-user synchronization via WordPress Heartbeat API
- **Permission-Based Access**: Four-tier permission system for flexible access control
- **Mobile-Optimized**: Responsive vertical list layout matching Chrome extension patterns
- **Configurable Tasks**: Per-location default task configuration with custom colors

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- **Hotel Hub App** plugin (core system)
- **Workforce Authentication** plugin (permission management)
- NewBook API integration configured

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Hotel Hub > Modules to verify installation
4. Configure settings at Hotel Hub > Modules > Daily List

## Configuration

### Settings Page

1. **Enable Per Location**: Toggle Daily List functionality for each hotel/location
2. **Default Tasks**: Configure task list per location
   - Add tasks with custom names
   - Assign color codes for visual identification
   - Reorder tasks by priority
3. **Save Settings**: Changes apply immediately

### Permissions

Grant users appropriate permissions via Workforce Authentication:

- `hhdl_access_module` - Access to Daily List module
- `hhdl_view_guest_details` - View guest names and personal information
- `hhdl_view_rate_details` - View pricing and rate information
- `hhdl_view_all_notes` - View all booking notes (not just housekeeping)

## Usage

### Daily List View

1. Select date using date picker
2. View room list with status indicators:
   - **Left border sliver**: Booking status from yesterday
   - **Main card border**: Today's booking status
   - **Right border**: Booking status for tomorrow
3. Use filter buttons to show:
   - Arrivals only
   - Departures only
   - Stopovers
   - Twin/sofabed requirements
   - All rooms (default)

### Task Completion

1. Click any room card to open details modal
2. Review booking information (permission-based)
3. Check off completed tasks
4. Tasks sync to NewBook and log locally with your user ID
5. Other users see updates in real-time via Heartbeat

### Color Codes

**Booking Status**:
- 🟢 Green: Confirmed booking
- 🟠 Orange: Unconfirmed/request
- 🟣 Purple: Departed/completed

**Task Colors**: Configured per location in settings

## Development

### Directory Structure

```
hotelhubmodule-housekeeping-dailylist/
├── includes/               # PHP classes
├── admin/views/           # Admin templates
├── assets/
│   ├── css/              # Stylesheets
│   └── js/               # JavaScript
└── IMPLEMENTATION_PLAN.md # Detailed technical documentation
```

### Database Schema

**Table**: `wp_hhdl_task_completions`

Tracks local task completions with WordPress user attribution (NewBook API only logs API user).

## Support

For issues, feature requests, or contributions, please visit:
https://github.com/jtricerolph/hotelhubmodule-housekeeping-dailylist

## Changelog

### 1.0.0 (2025-01-28)
- Initial release
- 3-day booking view with status indicators
- Per-location configurable default tasks
- Real-time multi-user synchronization
- Permission-based access control
- NewBook API integration

## License

GPL v2 or later

## Credits

Developed for Hotel Hub App ecosystem.
