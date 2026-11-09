=== Secure Login Collector ===
Contributors: mikemiler
Tags: login, password, credential management, password collection, data security
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.7
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect login details from clients securely. Everything is encrypted in the browser before it is sent. No more passwords in emails.

== Description ==

**Secure Login Collector** gives agencies and freelancers a safe way to receive login details from their clients. Your client fills in a simple form on your website, the data is encrypted in their browser before it is sent, and only you can decrypt it in your WordPress dashboard. No more passwords in emails, chat messages or shared spreadsheets.

= How it works =
1. Add the form to any page with the shortcode `[seculoco_form]` and send the link to your client.
2. Your client enters name, email and the service, and pastes all login details (username, password, backup codes, notes) into one "Login Credentials" field.
3. The client's browser encrypts everything before it is sent (AES-256-GCM for the data, RSA-2048 for the key, using the browser's built-in Web Crypto API). Your server only receives and stores the encrypted package.
4. You get an email notification with the sender's name and email. The login details are never part of the email.
5. You open the entry in your dashboard and decrypt it with your master password. Decryption happens in your browser.

= Free features =
- **Encryption in the browser**: login details are encrypted on the client's device, so they are never sent or stored in plain text.
- **Master password protection**: your private key is stored only in encrypted form, locked with your master password. The master password itself is never stored. Without it, nobody can read the entries, not even someone with a copy of your database.
- **Decryption in your dashboard**: only administrators who know the master password can decrypt entries.
- **Entry list**: search and sort entries by name, email, service and date, decrypt and copy login details with one click, and delete entries when a project is done.
- **Email notifications**: get notified about every new submission.
- **Simple form for clients**: one free-text field for all login details, clear help text and validation messages.
- **HTTPS check**: the plugin warns you in the dashboard if your site does not use HTTPS.

= Pro features (Secure Login Collector Pro) =
- **Passkey protection**: the Pro encryption key is created in your browser and locked with a secret that only your passkey can produce (WebAuthn PRF extension). Every decryption and export needs the passkey, and the server never holds the key or anything it could be derived from. Works with iCloud Keychain (macOS 15 / iOS 18 or newer), Google Password Manager, 1Password, Bitwarden and FIDO2 security keys such as the YubiKey 5.
- **Spam and bot protection**: a hidden honeypot field with a daily changing name, a minimum time before the form can be submitted, and rate limiting per IP address.
- **Automatic data expiry**: choose after how many days the encrypted data of an entry is deleted automatically.
- **Bulk decrypt and export**: decrypt several entries at once and export CSV files for Bitwarden, 1Password, LastPass, Dashlane, KeePass, Chrome, Firefox or Safari.

= Security notes =
- **Use HTTPS.** Without HTTPS, the form can be manipulated on its way to your client before anything is encrypted.
- **Choose a strong master password** (at least 12 characters) and store it in your password manager. If you lose it, the stored entries cannot be recovered, not by you and not by us.
- **How the master password is used**: during setup it is sent to your server once to create your key pair and is not stored. After that, it is only used in your browser.
- **Limits of browser encryption**: it protects login details on their way to your site and in your database. It cannot protect against a compromised WordPress site, because someone with administrator or file access could change the code that runs in the browser. Keep WordPress, themes and plugins updated and keep the number of administrator accounts small.

= Freemius and privacy =
This plugin bundles the Freemius SDK for licensing, payments and optional usage data. Nothing is shared until you explicitly opt in. See "External Services" below for details. Client submissions, encrypted data and decrypted login details never leave your server.

= Disclaimer =
Security is a shared responsibility. We provide the tools, but you control how and where they are used. We are not liable for any damage, data loss or regulatory issues that arise from using this plugin. Use it at your own risk.

== Installation ==

1. Install and activate the plugin.
2. Go to **Login Data → Settings** and set up your master password (at least 12 characters). Store it in your password manager: it cannot be recovered.
3. Optionally change the email address for notifications on the same page.
4. Add the shortcode `[seculoco_form]` to a page.
5. Exclude that page from your page cache (see FAQ) and share the link with your clients.

With Pro, you can also register a passkey under **Login Data → Settings**. New entries are then protected by your passkey.

== Frequently Asked Questions ==

= Why is this better than sending passwords by email? =

Emails are stored in plain text in several mailboxes and on several servers, often for years. With Secure Login Collector, login details are encrypted before they leave your client's browser and can only be decrypted with your master password (or your passkey in Pro).

= What does my client enter in the form? =

Name, email, the login URL or service name, and one "Login Credentials" field where they can paste everything at once: username, password, backup codes, security questions or notes. Entries submitted with the older three-field form (username, password, notes) remain fully readable.

= What happens if I forget my master password? =

The entries cannot be decrypted any more. The plugin never stores the master password, so there is no way to recover it. You can reset the master password in the settings, but all existing entries stay locked and are marked as undecryptable. Store your master password in a password manager.

= Do I need HTTPS? =

Yes. Without HTTPS, an attacker in the same network could manipulate the form before the data is encrypted. The plugin shows a warning in the dashboard if your site does not use HTTPS.

= Does the form work with page caching? =

Exclude the page with the form from your page cache (for example WP Rocket, LiteSpeed Cache or Cloudflare). The form contains a security token that expires after 12 to 24 hours, and a cached copy with an expired token cannot be submitted. Also clear your cache after every plugin update.

= Can my team access the login details? =

In the free version, every administrator who knows the master password can decrypt entries. In Pro with passkey protection, entries are decrypted with one site-wide passkey that must support the WebAuthn PRF extension (iCloud Keychain on macOS 15 / iOS 18 or newer, Google Password Manager, 1Password, Bitwarden, YubiKey 5). If your passkey provider does not support PRF, keep using the master password. To share login details with your team, export them to your team's password manager (Pro).

= How long is data stored? =

In the free version, entries are stored until you delete them. In Pro, you choose after how many days the encrypted data is deleted automatically. The entry itself (name, email, service, date) stays in the list and is marked as expired, so you keep an overview of what was submitted.

= How does spam protection work? =

Every submission is checked with a WordPress security token. Pro adds a hidden honeypot field with a daily changing name, a minimum time before the form can be submitted, and a rate limit per IP address (by default 10 submissions in 5 minutes, adjustable in the settings).

= Which password managers can I export to? =

Pro exports CSV files for Bitwarden, 1Password, LastPass, Dashlane, KeePass, Chrome, Firefox and Safari. Entries submitted with the single "Login Credentials" field are exported with all details in the notes field. Chrome, Firefox and Safari only import entries with a separate username and password, so those entries are skipped there and listed in the export summary; use Bitwarden, 1Password or KeePass for them.

= What is the difference between Free and Pro? =

Free includes browser encryption, master password protection, decryption in your dashboard and email notifications. Pro adds passkey protection, spam and bot protection, automatic data expiry, and bulk decrypt and export.

== Screenshots ==

1. The form your client fills in: one field for all login details
2. The login details are encrypted in the client's browser before they are sent
3. Confirmation that the login details were sent securely
4. All submitted entries in your WordPress dashboard
5. Enter your master password to decrypt an entry
6. The decrypted login details in your dashboard
7. Set up your master password in the settings

== Changelog ==

= 2.1.0 =
* Security: Pro passkey protection rebuilt on the WebAuthn PRF extension. The Pro encryption key is now created in the admin browser and locked with a secret only the passkey can produce; the server never holds the key or anything it could be derived from. Existing passkey setups must be reset and registered again, and entries from the old setup can no longer be decrypted. Requires a PRF-capable passkey: iCloud Keychain (macOS 15 / iOS 18 or newer), Google Password Manager, 1Password, Bitwarden or a FIDO2 security key such as the YubiKey 5.
* Security: encryption keys are no longer created automatically; the form stays disabled until a master password is set up
* Security: stronger protection of new master passwords (600,000 PBKDF2 iterations, at least 12 characters); existing master passwords keep working
* Security: warning in the dashboard when the site does not use HTTPS
* Security: stricter server-side validation and size limits for form submissions
* Security: CSV exports neutralise values that spreadsheet apps would run as formulas (Pro)
* New: simplified client form with a single "Login Credentials" field instead of separate username, password and notes fields
* Fully backwards compatible: existing entries keep decrypting and displaying with their separate fields, no migration needed
* Note: if you use a page cache (for example WP Rocket, LiteSpeed Cache or Cloudflare), clear it after updating so clients get the new form
* Fix: uninstalling the plugin no longer deletes data shared with other Freemius-based plugins and now removes all of its own data
* Fix: automatic data expiry now runs on schedule (Pro)
* Fix: master passwords with leading or trailing spaces can be used for decryption
* Fix: the success message after submitting the form is shown again
* Fix: bulk export of password-protected entries (Pro)
* Fix: bulk export to Chrome, Firefox and Safari no longer silently drops entries from the single credentials field; they are listed in the summary (Pro)
* Fix: rate limit default now matches the settings page: 10 submissions in 5 minutes (Pro)
* Fix: form validation messages are visible again
* Fix: a service name entered instead of a login URL is stored as entered
* Tested with WordPress 7.1
* Updated German translations

= 2.0.7 =
* Fix error message when sending form

= 2.0.6 =
* Update Freemius SDK

= 2.0.5 =
* Fix Translations

= 2.0.4 =
* Fix translations

= 2.0.3 =
* Remove branding from frontend
* Code quality improvements

= 2.0.0 =
* Refactoring for launch at wordpress.org

= 1.0.0 =
* Initial release with RSA-2048 + AES-256 encryption
* Auto-deletion
* Email notifications for new submissions

== Upgrade Notice ==

= 2.1.0 =
New single-field client form and security hardening. Clear your page cache after updating. Pro: passkey setups from earlier versions must be reset and registered again; entries protected by the old passkey setup can no longer be decrypted.

== External Services ==

This plugin bundles the Freemius SDK to handle optional usage data, licensing and upgrades. You have to opt in before any data is shared.

What is sent (only after opt-in):
* Site URL, WordPress version, PHP version and plugin version: for compatibility checks.
* Admin email and locale: so Freemius can send license receipts and support messages if you purchase Pro.

No client submissions, passwords or encrypted data ever leave your server. All login data stays in your WordPress database.

Freemius Terms: https://freemius.com/terms/
Freemius Privacy: https://freemius.com/privacy/
