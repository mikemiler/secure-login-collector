# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Secure Login Collector is a WordPress plugin that securely collects and stores encrypted login credentials from clients. It offers multiple encryption methods including RSA-2048 (free) and passkey-derived encryption (pro version via Freemius licensing).

## Key Architecture Components

### Main Class Structure
- `secure-login-collector.php` - Main plugin file and orchestrator class
- `includes/class-encryption-handler.php` - Handles RSA key generation and encryption/decryption operations
- `includes/class-admin-interface.php` - Admin dashboard UI and AJAX handlers for decryption
- `includes/class-frontend-handler.php` - Frontend form rendering and submission handling
- `includes/class-database-manager.php` - Database operations and data expiration
- `includes/class-settings-manager.php` - Settings page and configuration management
- `includes/class-license-manager.php` - Pro version license management

### JavaScript Files
- `assets/js/frontend-secure.js` - Client-side AES + RSA encryption for standard mode
- `assets/js/frontend-ultra-secure.js` - Client-side encryption with passkey support (pro)
- `assets/js/admin-decrypt.js` - Admin-side decryption handling
- `assets/js/admin.js` - Admin UI interactions and bulk operations

### Encryption Flow
1. **Frontend**: User submits credentials → AES-256-GCM encryption → RSA-2048 key wrapping → Server storage
2. **Admin**: Decrypt request → RSA unwrap on server → Send to client → AES decrypt in browser
3. **Pro Mode**: Additional passkey authentication layer using WebAuthn

## Development Commands

### Testing
- **Manual Testing**: Use `test-encryption.php` for encryption/decryption tests
- **Activation Test**: Run `activation-test.php` to verify plugin activation

### Localization
- Generate POT file: `wp i18n make-pot . languages/secure-login-collector.pot`
- Update translations in `languages/` directory

### Code Standards
- No automated linting configured - follow WordPress Coding Standards manually
- Use proper sanitization/escaping per WordPress guidelines

## Important Considerations

### Security Model
- Free version: Protected against database-only breaches
- Pro version goal: Protected even with full server access (requires passkey)
- Current pro implementation needs work - see `ENCRYPTION-WORKFLOW.md`

### Pro Version Detection
1. Freemius license check: `slc_fs()->is_paying()`
2. Manual override: `define('SECURE_LOGIN_PRO', true);` in wp-config.php

### Database Structure
- Table: `{prefix}_secure_login_data`
- Stores encrypted data as JSON with metadata
- Version field indicates encryption format (v2 current)

### Freemius Integration
- SDK in `vendor/freemius/` directory
- Configuration in `includes/freemius-config.php`
- Requires plugin ID and public key from Freemius dashboard

## Common Tasks

### Adding New Encryption Method
1. Update `includes/class-encryption-handler.php` for server-side handling
2. Create new frontend JS file in `assets/js/`
3. Update `includes/class-frontend-handler.php` to load correct script
4. Modify `includes/class-admin-interface.php` for decryption flow

### Modifying Admin Interface
- Main UI in `includes/class-admin-interface.php`
- JavaScript interactions in `assets/js/admin.js`
- Decryption logic in `assets/js/admin-decrypt.js`

### Working with Passkey/WebAuthn
- See `ENCRYPTION-WORKFLOW.md` for current implementation status
- Client-side passkey code in `assets/js/frontend-ultra-secure.js`
- Server cannot derive passkey signatures - must handle client-side

## Known Issues
- Passkey encryption implementation incomplete (see `ENCRYPTION-WORKFLOW.md`)
- No automated build process or minification
- No automated tests configured