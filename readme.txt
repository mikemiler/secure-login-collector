=== Secure Login Collector ===
Contributors: Mike.Miler
Tags: login, password, credential management, password collection, data security
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure way for agencies to receive client login credentials. Stop asking clients to send passwords via email.

== Description ==

**Secure Login Collector** helps agencies receive login credentials from clients securely. Stop emailing passwords and use encrypted client-side submission instead.

= The Problem It Solves =

Asking clients to email passwords is:
- **Insecure** - Emails sent in plain text can be intercepted
- **Unprofessional** - Shows poor security practices
- **Risky** - Creates liability if data is compromised
- **Messy** - Hard to organize and track

This plugin provides military-grade encryption, automatic data expiration, and password manager integration.

= How the Free Version Works =

The free version uses **server-trusted encryption** suitable for most agencies:

**For Clients:**
1. Client visits your secure form page
2. Enters login credentials
3. Data is encrypted in their browser using RSA-2048 before transmission
4. Encrypted data sent to your WordPress database
5. Client receives confirmation

**For Your Agency:**
1. Receive email notification
2. Log into WordPress admin
3. View encrypted entries
4. Click decrypt (requires WordPress admin login)
5. Copy credentials to password manager
6. Data auto-deletes after set period

**Security Model:**
- Credentials encrypted client-side with RSA-2048 + AES-256
- Private key encrypted using WordPress salts (AUTH_KEY + SECURE_AUTH_KEY)
- Any WordPress administrator can decrypt with admin login
- Protects against email interception, casual database access, and transmission attacks
- Perfect for 95% of agencies

= Pro Version - Zero-Knowledge Encryption =

**Pro Version** adds passkey authentication for maximum security:

- Requires physical device (YubiKey, Touch ID, Face ID) to decrypt
- True zero-knowledge: Server cannot decrypt even with full database access
- Protects against server compromise, admin account takeover, and database theft
- Uses WebAuthn/FIDO2 standards
- Each team member registers their own passkey
- Ideal for high-security requirements (HIPAA, financial services)

= Key Features =

- **Client-Side Encryption**: RSA-2048 + AES-256 encryption
- **Automatic Deletion**: Configurable retention periods (7-30 days recommended)
- **Password Manager Export**: Bitwarden, 1Password, LastPass, and more
- **No Client Account**: Simple form submission without registration
- **Team Access**: All WordPress admins can decrypt (free) or register passkeys (pro)
- **Bulk Operations**: Export multiple entries at once
- **Email Notifications**: Get notified of new submissions
- **Audit Trail**: Track who accessed what and when

== Installation ==

1. Install and activate the plugin
2. Go to Login Data → Settings to configure retention period and notifications
3. Create a page and add shortcode: `[secure_login_form]`
4. Share the page link with clients

For Pro version, register your passkey device after installation.

== Frequently Asked Questions ==

= Why is this better than email? =

Email sends passwords in plain text. This plugin encrypts data in the browser using RSA-2048 + AES-256 and auto-deletes after your specified period.

= What's the difference between Free and Pro? =

Free: WordPress admin access for decryption. Pro: Requires physical passkey device (YubiKey, Touch ID) for zero-knowledge encryption.

= Can my team access credentials? =

Yes. Any WordPress admin can decrypt in free version. Pro version supports multiple passkey registrations. Export to password managers for team sharing.

= How long is data stored? =

You control retention from 1-365 days. Most agencies use 7-30 days.

= What password managers are supported? =

Bitwarden, 1Password, LastPass, Dashlane, KeePass, Chrome, Firefox, Safari, and CSV.

== Screenshots ==

1. Client submission form
2. Admin dashboard with encrypted entries
3. Decryption view with copy buttons
4. Settings page
5. Passkey setup (Pro version)

== Changelog ==

= 1.0.1 =
* Cache handling

= 1.0.0 =
* Initial release with RSA-2048 + AES-256 encryption
* Pro version with passkey authentication
* Password manager export support
* Auto-deletion and email notifications

== External Services ==

This plugin uses Freemius SDK for optional premium features (opt-in only).

**Important:** All client credentials are processed and stored ONLY on your server. No credentials are sent to external services.

Terms: https://freemius.com/terms/
Privacy: https://freemius.com/privacy/