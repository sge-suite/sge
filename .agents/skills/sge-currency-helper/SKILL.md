---
name: sge-currency-helper
description: "Use this skill whenever working with monetary values, currency formatting, or displaying prices in the SGE application. It enforces the usage of the project's custom CurrencyHelper."
license: MIT
metadata:
  author: SGE Project
---

# SGE Currency Helper Usage

When displaying or formatting monetary values in this project, **always** use the `App\Helpers\CurrencyHelper` class or the `formatCurrency` global function instead of native PHP `number_format()`, `Number::currency()` manually, or other manual formatting. This ensures all currency formatting uses the correct BRL standard across the application.

## Available Global Helper

- `formatCurrency($value, string $currency = 'BRL', ?string $locale = 'pt_BR')`

*Note: It accepts `int`, `float`, or `null`. If `null` is passed, it formats `0`.*

## Usage Examples

### In Blade Templates

```blade
<!-- Recommended -->
<span>{{ formatCurrency($product->price) }}</span>

<!-- Avoid doing this directly in views -->
<span>{{ 'R$ ' . number_format($product->price, 2, ',', '.') }}</span>
<span>{{ Illuminate\Support\Number::currency($product->price, 'BRL', 'pt_BR') }}</span>
```

### In PHP Code (Controllers, Resources, etc.)

```php
use App\Helpers\CurrencyHelper;

// Using the global helper (Recommended)
$formatted = formatCurrency($model->total_amount);

// Or using the class statically
$formatted = CurrencyHelper::format($model->total_amount);
```
