# Secure Login Collector

**Version:** 2.1.0  
**Author:** Mike Miler  
**License:** GPL v2 or later

A WordPress plugin that allows clients to securely submit login credentials through a frontend form. All data is encrypted client-side with RSA-2048 encryption before transmission and stored securely in the database.

## Features

- **Frontend Form**: Simple shortcode `[secure_login_form]` for client submissions
- **No Login Required**: Clients can submit data without WordPress accounts
- **RSA-2048 Encryption**: Enterprise-grade client-side encryption for all users
- **Secure Storage**: Encrypted data stored in custom database table
- **Admin Interface**: View and decrypt submissions in WordPress admin
- **Email Notifications**: Optional notifications when new data is received
- **Auto-Expiration**: Configurable automatic deletion of old data
- **Multilingual Support**: Full German translation included
- **Delete Functionality**: Manual deletion with confirmation dialogs
- **Auto-Protocol**: Automatically adds https:// to website URLs if missing
- **Pro Version**: Enhanced security with passkey authentication

## Pro Version Features

To enable pro version features, add this line to your `wp-config.php`:
```php
define('SECURE_LOGIN_PRO', true);
```

**Pro Features:**
- **Passkey Authentication**: Use your phone, tablet, or security key to decrypt data
- **Enhanced Security**: WebAuthn-based authentication for viewing sensitive data
- **Biometric Protection**: Face ID, Touch ID, or PIN protection for data access
- **Audit Logging**: Enhanced security logging for passkey operations

## Installation

1. Upload the plugin files to `/wp-content/plugins/secure-login-collector/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings under 'Login Data' → 'Settings' in admin
4. (Optional) Enable pro version by adding `define('SECURE_LOGIN_PRO', true);` to wp-config.php

## Usage

### Frontend Form

Add the shortcode to any page or post where you want the form to appear:

```
[secure_login_form]
```

The form includes:
- **Email Address** (required)
- **Name** (required) 
- **Website URL** (optional - https:// added automatically if missing)
- **Login Credentials** (free text area for login details)

### Admin Interface

1. Go to **Login Data** in the WordPress admin menu
2. View all submitted encrypted data
3. Click **Decrypt** to view data inline
4. For pro version: Choose between traditional decryption or passkey authentication
5. Use **Delete** to remove entries with confirmation
6. Configure settings under **Login Data** → **Settings**

### Settings

#### Email Notifications
- Enable/disable notifications for new submissions
- Set custom notification email address

#### Data Expiration
- Set automatic deletion after X days (0 = never expires)
- Extend retention period for individual entries

#### Encryption Settings (All Users)
- Generate new RSA-2048 key pairs
- Export public keys for external use
- View key generation status and timestamps

#### Pro Version Settings
- Register passkey for enhanced authentication
- Test passkey functionality
- View passkey registration status

## Security Features

- **RSA-2048 encryption** with SHA-256 hashing for all users
- **Client-side encryption** before data transmission
- **Secure key storage** with WordPress salt-based encryption
- **IP address logging** for audit trails
- **Admin-only access** to view encrypted data
- **Automatic key generation** on first use
- **Security logging** for all actions
- **Pro: Passkey authentication** using WebAuthn standard

## Technical Details

### Encryption

#### RSA Encryption (All Users)
- **Algorithm**: RSA-OAEP with SHA-256
- **Key Size**: 2048-bit keys
- **Frontend**: Web Crypto API for browser-based encryption
- **Backend**: OpenSSL for server-side decryption
- **Key Storage**: Private keys encrypted with WordPress salts

#### Legacy XOR Support
- Maintains compatibility with older submissions
- Automatic detection of encryption type
- Graceful fallback for legacy data

### Passkey Authentication (Pro Version)

- **Standard**: WebAuthn/FIDO2 compliant
- **Authenticators**: Platform authenticators (Face ID, Touch ID, Windows Hello)
- **Security**: Biometric or PIN-based authentication
- **Privacy**: No biometric data stored on server
- **Compatibility**: Modern browsers with WebAuthn support

### Database

Custom table: `wp_secure_login_data`
- `id` - Auto-increment primary key
- `encrypted_data` - RSA encrypted content (base64 encoded)
- `metadata` - JSON with email, name, website, encryption type
- `user_id` - Always 0 for anonymous frontend submissions
- `ip_address` - Client IP for audit trail
- `user_agent` - Client browser information
- `created_at` - Submission timestamp
- `retention_until` - Automatic expiration timestamp

### Automatic Cleanup

- Daily cron job removes expired entries
- Configurable expiration period in settings
- Logs cleanup actions for audit

## Multilingual Support

The plugin includes full internationalization support:

- **Text Domain**: `secure-login-collector`
- **German Translation**: Complete translation included
- **Translation Files**: POT template, German PO/MO files
- **Localized JavaScript**: Frontend messages translated

### Adding New Languages

1. Use the POT file as template: `languages/secure-login-collector.pot`
2. Create PO file for your language: `secure-login-collector-{locale}.po`
3. Compile to MO file: `msgfmt file.po -o file.mo`
4. Place in `languages/` directory

## Browser Compatibility

### RSA Encryption (All Users)
- **Chrome**: 37+
- **Firefox**: 34+
- **Safari**: 10.1+
- **Edge**: 79+

### Passkey Authentication (Pro Version)
- **Chrome**: 67+
- **Firefox**: 60+
- **Safari**: 14+
- **Edge**: 18+

## Changelog

### Version 2.1.0
- **MAJOR**: RSA-2048 encryption now available for all users (not just pro)
- **NEW**: Passkey authentication for pro version using WebAuthn
- **NEW**: Biometric authentication (Face ID, Touch ID, Windows Hello)
- **NEW**: Enhanced security settings section for all users
- **NEW**: Pro version settings with passkey management
- **IMPROVED**: Frontend always uses RSA encryption when available
- **IMPROVED**: Automatic fallback to XOR for legacy compatibility
- **IMPROVED**: Enhanced admin interface with passkey options
- **SECURITY**: Enterprise-grade encryption for all installations

### Version 2.0.0
- **NEW**: Pro version with RSA-2048 encryption
- **NEW**: Dual encryption system (RSA for pro, XOR for basic)
- **NEW**: Secure key management with WordPress salt encryption
- **NEW**: Web Crypto API integration for modern browsers
- **NEW**: Key generation and export functionality
- **IMPROVED**: Enhanced security architecture
- **IMPROVED**: Automatic script selection based on version

### Version 1.6.2
- Removed IP address column from admin interface
- Improved button layout and spacing
- Enhanced actions column design
- Better responsive table layout

### Version 1.6.1
- Added retention extension functionality
- Implemented proper `retention_until` field system
- Enhanced database upgrade mechanism
- Improved expiration calculation accuracy

### Version 1.5.1
- Added security information to frontend form
- Dynamic retention period display
- Enhanced user privacy communication

### Version 1.5.0
- **BREAKING**: Removed login requirement for frontend submissions
- Removed "Submitted By" column from admin interface
- All submissions now anonymous (user_id = 0)
- Updated logging to focus on client information

### Version 1.4.0
- Added full multilingual support with German translation
- Implemented WordPress i18n system with text domain
- Added auto-protocol feature for website URLs

### Version 1.3.0
- Added name field to frontend form (required)
- Enhanced admin table with name column
- Updated email notifications to include sender name

### Version 1.2.0
- Added delete functionality with confirmation dialogs
- Implemented automatic data expiration system
- Added "Expires In" column showing remaining time

### Version 1.1.0
- Added email notification system
- Created settings page for notifications and expiration
- Enhanced admin interface with better formatting

### Version 1.0.0
- Initial release with core functionality
- Frontend form with XOR encryption
- Admin interface for viewing data

## Support

For support, feature requests, or bug reports, please contact the plugin author.

## License

This plugin is licensed under the GPL v2 or later license.