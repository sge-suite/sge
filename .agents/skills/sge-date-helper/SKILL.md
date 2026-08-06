---
name: sge-date-helper
description: "Use this skill whenever working with dates, formatting dates, or displaying dates in the SGE application (UI, exports, reports, etc). It enforces the usage of the project's custom DateHelper and global date functions."
license: MIT
metadata:
  author: SGE Project
---

# SGE Date Helper Usage

When working with dates in this project, **always** use the existing `App\Helpers\DateHelper` class or its global helper functions rather than formatting dates manually using `Carbon` or PHP's `date()` function. This ensures consistency across the application regarding timezone and locale.

## Available Global Helpers

You can use these global functions directly in your Blade views or PHP code:

- `formatDate($date)`: Formats a date as 'D de MMMM de YYYY' (e.g., '10 de Janeiro de 2024').
- `formatShort($date)`: Formats a date as 'DD/MM/YYYY' (e.g., '10/01/2024').
- `formatDateTime($date)`: Formats a date as 'd/m/Y \à\s H:i' (e.g., '10/01/2024 às 14:30').
- `formatRelative($date)`: Formats a date relatively using `diffForHumans()` (e.g., 'há 2 dias').
- `formatMonthYear($date)`: Formats a date as 'MM/YYYY' (e.g., '01/2024').
- `formatMonthYearFull($date)`: Formats a date as 'MMMM YYYY' in title case (e.g., 'Janeiro 2024').

*Note: All functions accept `string`, `Carbon`, or `null`. If `null` is passed, they return `'-'`.*

## Usage Examples

### In Blade Templates

```blade
<!-- Recommended -->
<span>{{ formatDate($user->created_at) }}</span>
<span>{{ formatShort($order->date) }}</span>
<span>{{ formatDateTime($event->start) }}</span>

<!-- Avoid doing this directly in views -->
<span>{{ $user->created_at->format('d/m/Y') }}</span>
```

### In PHP Code (Controllers, Resources, etc.)

```php
use App\Helpers\DateHelper;

// Using the global helpers (Recommended)
$formatted = formatShort($model->created_at);

// Or using the class statically
$formatted = DateHelper::formatShort($model->created_at);
```
