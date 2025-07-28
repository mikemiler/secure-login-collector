=== Secure Login Collector ===
Contributors: mike.miler
Tags: security, login, encryption, password, data collection
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Securely collect and manage login credentials with advanced encryption including RSA-2048 and passkey-derived encryption.

== Description ==

Secure Login Collector is a professional WordPress plugin designed for secure collection and management of login credentials. It provides multiple layers of encryption and advanced security features to ensure maximum protection of sensitive data.

= Key Features =

**🔐 Multiple Encryption Methods**
* **Ultra-Secure (Pro)**: Passkey-derived encryption for maximum security
* **RSA-2048**: Industry-standard RSA encryption

**🎯 User-Friendly Interface**
* Clean admin interface with icon-based actions
* Bulk operations with single passkey authentication
* Real-time search and filtering
* Customizable frontend forms

**📊 Data Management**
* Automatic data expiration (configurable)
* Bulk export to 8+ password managers
* Individual entry management
* Comprehensive audit logging

**🌍 Multilingual Support**
* Complete German translation
* Translation-ready for other languages
* Customizable form text

**🔒 Security Features**
* Client-side encryption before transmission
* Passkey authentication support
* IP address logging
* Secure AJAX handling

= Supported Export Formats =

Export your data to popular password managers:
* Bitwarden
* 1Password
* LastPass
* Chrome
* Firefox
* Safari
* Dashlane
* KeePass

= Pro Features =

* Passkey authentication and encryption
* Ultra-secure mode with double encryption
* Advanced security settings
* Priority support

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/secure-login-collector` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the 'Login Data' menu item to access the main interface.
4. Configure settings via 'Login Data' → 'Settings'.
5. Use the shortcode `[secure_login_form]` to display the collection form on any page.

== Frequently Asked Questions ==

= How secure is the encryption? =

The plugin uses multiple encryption methods:
- **RSA-2048**: Industry-standard encryption suitable for most use cases
- **Passkey-derived (Pro)**: Ultra-secure encryption that requires physical device authentication
- All data is encrypted client-side before transmission

= Can I export data to my password manager? =

Yes! The plugin supports export to 8 popular password managers with properly formatted CSV files. You can export individual entries or use bulk export for multiple entries.

= How does automatic data expiration work? =

You can configure automatic deletion after a specified number of days. Set to 0 to disable automatic deletion. This helps maintain data privacy and comply with data retention policies.

= What is passkey authentication? =

Passkey authentication (Pro feature) uses WebAuthn technology to provide passwordless authentication with your device's biometrics or security key. It provides the highest level of security.

= Is the plugin GDPR compliant? =

The plugin includes features to help with GDPR compliance:
- Configurable data retention periods
- Secure data handling
- Audit logging
- Data export capabilities

= Can I customize the frontend form? =

Yes! You can:
- Customize the form description text
- Modify the form title and button text
- Use CSS to style the form appearance

== Screenshots ==

1. Main admin interface showing encrypted login data with icon-based actions
2. Decrypted data display with copy buttons and export options
3. Bulk export selection with passkey authentication
4. Settings page with encryption options and customization
5. Frontend form with security information
6. Password manager export modal with multiple formats

== Changelog ==

= 1.0.0 =
* Initial release
* Basic encryption support
* Passkey encryption support
* Simple data collection form
* Basic admin interface
* PW manager export
* Multilingual support DE, DE formal, ES

== Technical Requirements ==

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher
* HTTPS recommended (required for passkey features)
* Modern browser with JavaScript enabled

== Security & Privacy ==

This plugin takes security seriously:
* All data is encrypted before storage
* No sensitive data is transmitted in plain text
* Comprehensive audit logging
* Regular security updates
* Optional automatic data expiration

== Support ==

For support and documentation, please visit [your support URL] or use the WordPress.org support forums.

== Development ==

This plugin is actively maintained and developed. Contributions and feedback are welcome. 