# Multilingual Support Guide

## Overview
The Secure Login Collector plugin is fully internationalized using WordPress's built-in i18n system. This means all text can be translated into any language.

## How It Works

### Automatic Language Detection
The plugin automatically detects your WordPress site's language setting and loads the appropriate translation:

1. **WordPress Admin → Settings → General → Site Language**
2. If a translation exists for your language, it will be loaded automatically
3. If no translation exists, English (default) will be used

### Current Translations

#### English (Default)
- All original text in English
- Used as fallback when no translation is available

#### German (Deutsch)
- Complete translation included
- Covers all admin interface, frontend form, and email notifications
- File: `languages/secure-login-collector-de_DE.mo`

## Testing Multilingual Support

### To Test German Translation:
1. Go to **WordPress Admin → Settings → General**
2. Change **Site Language** to **Deutsch**
3. Save changes
4. Visit the plugin admin page: **Login Data**
5. All text should now appear in German

### To Test Frontend Form:
1. Add the shortcode `[secure_login_form]` to a page
2. View the page with German language active
3. Form labels and messages will be in German

## Translation Coverage

### Admin Interface
- ✅ Menu items and page titles
- ✅ Table headers and data
- ✅ Button labels
- ✅ Settings page
- ✅ Error and success messages

### Frontend Form
- ✅ Form labels and placeholders
- ✅ Validation messages
- ✅ Success/error notifications
- ✅ Button text

### Email Notifications
- ✅ Subject line
- ✅ Email body content
- ✅ All dynamic text

### JavaScript Messages
- ✅ Form validation errors
- ✅ AJAX response messages
- ✅ Loading states

## Adding New Languages

### Step 1: Create Translation Files
```bash
# Copy the template
cp languages/secure-login-collector.pot languages/secure-login-collector-fr_FR.po

# Edit with a PO editor (like Poedit)
# Translate all strings

# Compile to binary format
msgfmt languages/secure-login-collector-fr_FR.po -o languages/secure-login-collector-fr_FR.mo
```

### Step 2: WordPress Language Codes
Use the correct WordPress locale codes:
- French: `fr_FR`
- Spanish: `es_ES`
- Italian: `it_IT`
- Dutch: `nl_NL`
- Portuguese: `pt_BR`
- Russian: `ru_RU`
- Japanese: `ja`
- Chinese: `zh_CN`

### Step 3: Test Your Translation
1. Change WordPress site language to your new language
2. Check all plugin areas for proper translation
3. Test frontend form and admin interface

## Technical Implementation

### Text Domain
All translatable strings use the text domain: `secure-login-collector`

### Translation Functions Used
- `__()` - Returns translated string
- `_e()` - Echoes translated string
- `esc_html__()` - Returns escaped translated string
- `esc_attr__()` - Returns attribute-escaped translated string
- `sprintf()` - For strings with placeholders

### Loading Translations
Translations are loaded automatically via:
```php
load_plugin_textdomain('secure-login-collector', false, dirname(plugin_basename(__FILE__)) . '/languages/');
```

## Troubleshooting

### Translation Not Loading
1. Check WordPress site language setting
2. Verify `.mo` file exists in `languages/` directory
3. Clear any caching plugins
4. Check file permissions on language files

### Partial Translation
1. Ensure all strings in the `.po` file are translated
2. Recompile `.po` to `.mo` format
3. Clear WordPress cache

### JavaScript Not Translated
1. Check that localized script data is properly set
2. Verify frontend JavaScript is using `secureLoginAjax.strings`
3. Clear browser cache

## Contributing Translations

If you create a translation for a new language, please consider contributing it back to the project:

1. Create high-quality translation files
2. Test thoroughly in your language
3. Submit via GitHub or contact the plugin author
4. Include both `.po` and `.mo` files

Your contribution will help make this plugin accessible to more users worldwide! 