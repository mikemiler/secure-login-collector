=== Secure Login Collector ===
Contributors: mikemiler
Tags: login, password, credential management, password collection, data security
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure way for agencies to receive client login credentials. Stop asking clients to send passwords via email.

== Description ==

**Secure Login Collector** gives agencies and freelancers a safe hand-off point for client credentials. Clients fill in a branded form, everything is encrypted before it leaves their browser, and your team only unlocks it inside WordPress. No more password spreadsheets, chat messages, or liability-inducing emails.

= How a login data submission flows =
1. Client opens your credential intake page and fills in the required fields (name, email, service, username, password, notes (optiona)).
2. The data is locked on their device before it is sent anywhere [browser-based Web Crypto + RSA-2048 key exchange + AES-256-GCM payloads].
3. The encrypted package lands in your WP database table together with metadata for auditing [Zero-knowledge encryption].
4. Your team gets notified, signs in to WordPress, and decrypts items inside the admin dashboard.

= Free version features (included) =
- **Client-side sealing** – credentials are encrypted before they leave the browser, so email or transport leaks cannot expose them.
- **Zero-knowledge encryption** – the server never sees the unwrapped private key; secrets are only readable once an admin unlocks them locally inside WordPress.
- **WP admin decryption** – only logged-in admins with the proper capability and the correct password can unlock submissions inside the dashboard, keeping everything in one place.
- **Submission inbox & search** – view, sort, and filter all requests with name, service, timestamps, and notes, then copy credentials when you need them.
- **Instant notifications** – each submission triggers an email so projects keep moving without checking the dashboard every hour.
- **Accessible client experience** – responsive form, password visibility toggle, optional help text, and field-level validation keep clients confident while still being secure.

= Pro version extras (via Secure Login Collector Pro) =
- **Passkey-first approvals** – require Touch ID, Windows Hello, YubiKey, or password-manager passkeys before every decrypt/export event.
- **Spam and bot defense** – invisible honeypot fields, nonce verification, rate limiting, and IP-aware hooks block automated dumps without annoying clients.
- **Retention & cleanup controls** – choose how long data stays accessible and let the plugin redact expired payloads automatically.
- **Bulk decrypt & export** – decrypt multiple entries at once and export directly to Bitwarden, 1Password, LastPass, Dashlane, CSV, or JSON for team password vaults.
- **White-label mode** – remove Secure Login Collector branding from the client form and emails so the experience is 100% your agency.

= Freemius & privacy =
This plugin bundles the Freemius SDK for licensing, secure payments, and (optional) telemetry. Nothing is shared until you explicitly opt in. When you do, only environment details (site URL, WP/PHP version, plugin version) plus contact email/locale are sent to Freemius so upgrades and receipts work. Client submissions, encrypted payloads, and decrypted credentials never leave your hosting environment.

= Disclaimer =
Security is a shared responsibility. We ship the tools, but you control how and where they are used. Install SSL, keep WordPress updated, limit admin access, and review submissions promptly. We are not liable for any damage, data loss, or regulatory issues that arise from using this plugin—use it at your own risk.

== Installation ==

1. Install and activate the plugin
2. Go to Login Data → Settings to configure notifications (and retention if you're on Pro)
3. Create a page and add shortcode: `[secure_login_form]`
4. Share the page link with clients

For the Pro version (separate plugin available from Freemius), register your passkey device after installation.

== Frequently Asked Questions ==

= Why is this better than email? =

Email sends passwords in plain text. This plugin encrypts data in the browser using RSA-2048 + AES-256, and the Pro upgrade layers in automated spam filtering plus retention windows so nothing lingers longer than it should.

= How does spam protection work? =

The Pro version bundles dynamic honeypot fields, nonce rotation, rate limiting, and time-based validation to keep bots out without bothering humans.

= What's the difference between Free and Pro? =

Free: zero-knowledge encryption inside WordPress with manual cleanup and notifications. The Pro version (available as a separate plugin) adds hardware-backed approvals, built-in spam and bot defense, retention automation, bulk exports, and white-label controls.

= Can my team access credentials? =

Yes. Any WordPress admin can decrypt in free version. The Pro version (separate plugin) supports multiple passkey registrations. Export to password managers for team sharing.

= How long is data stored? =

Free users manually delete entries when a project wraps. Pro users set retention from 1-365 days so the plugin auto-redacts on schedule (most agencies use 7-30 days).

= What password managers are supported? =

Bitwarden, 1Password, LastPass, Dashlane, KeePass, Chrome, Firefox, Safari, and CSV.

== Screenshots ==

1. Client submission form
2. Admin dashboard with encrypted entries
3. Decryption view with copy buttons
4. Settings page

== Changelog ==

= 2.0.1 =
* Remove branding from frontend
* Code cleanup

= 2.0.0 =
* Refactoring for launch at wordpres.org

= 1.0.0 =
* Initial release with RSA-2048 + AES-256 encryption
* Auto-deletion
* Email notifications for new submissions

== External Services ==

This plugin bundles the Freemius SDK to handle optional telemetry, licensing, and upgrade flows. Opt-in is required before any data is shared.

What is sent (only after opt-in):
* Site URL, WordPress version, PHP version, and plugin version – for compatibility checks.
* Admin email and locale – so Freemius can send license receipts and support messages if you later purchase Pro.

No client submissions, passwords, or encrypted payloads ever leave your server. All credential data stays inside your WordPress database.

Freemius Terms: https://freemius.com/terms/  
Freemius Privacy: https://freemius.com/privacy/
