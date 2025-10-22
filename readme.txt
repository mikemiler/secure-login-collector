=== Secure Login Collector ===
Contributors: mike.miler
Tags: login, password, credential management, password collection, data security
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The secure way for agencies to receive client login credentials. Stop asking clients to send passwords via email.

== Description ==

**Secure Login Collector** is built specifically for agencies who need to receive login credentials from clients for various services. Whether you're managing Google Ads accounts, social media profiles, or website maintenance, this plugin provides a secure, professional way to collect passwords without the risks of email transmission.

= 🎯 What This Plugin Does =

This plugin creates a secure, encrypted credential collection system that allows your clients to submit login credentials safely through your website. The data is encrypted in their browser before transmission and stored securely in your WordPress database. You can then decrypt and access these credentials when needed through a clean admin interface.

**The Problem It Solves:**

As an agency, asking clients to email passwords is:
- **Insecure** - Emails are sent in plain text and can be intercepted
- **Unprofessional** - Shows poor security practices
- **Risky** - Creates liability if data is compromised
- **Messy** - Hard to organize and track
- **Compliance Issues** - Violates data protection regulations

**The Solution:**

This plugin provides military-grade encryption, automatic data expiration, password manager integration, and a professional collection workflow that protects both you and your clients.

= 🔐 How the Free Version Works =

The free version uses a **server-trusted encryption model** suitable for most agency use cases:

**Encryption Architecture:**
1. **Client-Side Encryption:** When clients submit credentials, data is encrypted in their browser using RSA-2048 public key cryptography
2. **Hybrid Encryption:** Actual credentials are encrypted with AES-256-GCM (fast), then the AES key is encrypted with RSA-2048 (secure)
3. **Server Storage:** Encrypted data is stored in your WordPress database along with metadata (client email, domain, submission date)
4. **Private Key Protection:** The RSA private key (needed for decryption) is encrypted using AES-256-CBC with WordPress authentication salts (AUTH_KEY + SECURE_AUTH_KEY)
5. **Admin Decryption:** When you click decrypt, the server decrypts the RSA private key using WordPress salts, sends it to your browser, and your browser decrypts the credentials

**Security Model:**
- **What's Protected:** Client credentials are never transmitted or stored in plain text
- **Who Can Decrypt:** Any WordPress administrator with admin login access
- **Server Role:** Server can access the private key using WordPress configuration salts
- **Threat Protection:** Protects against email interception, casual database access, and client-side attacks

**Key Storage:**
- Public key: `secure_login_public_key_free` (stored in plain text, safe to expose)
- Private key: `secure_login_private_key_free_encrypted` (encrypted with WordPress salts)

= 🛡️ How the Pro Version Works =

The pro version adds **true zero-knowledge encryption** with passkey authentication for maximum security:

**Encryption Architecture:**
1. **Dual-Key System:** Pro version creates a separate RSA key pair dedicated to ultra-secure entries
2. **Passkey Registration:** When you register your first passkey (hardware key or biometric), the plugin:
   - Generates a new RSA-2048 key pair specifically for pro entries
   - Derives a wrapping key from your passkey credential using PBKDF2 (100,000 iterations)
   - Wraps (encrypts) the RSA private key with the passkey-derived key using AES-256-GCM
3. **Client-Side Encryption:** Clients submit credentials (same process as free version) but data is encrypted with the pro public key
4. **Zero-Knowledge Storage:** The wrapped private key is stored in database but cannot be decrypted without the physical passkey device
5. **Passkey Decryption:** When you decrypt, you must authenticate with your passkey (YubiKey, Touch ID, Face ID, etc.):
   - Browser performs WebAuthn authentication with your physical device
   - Server derives the unwrapping key from your passkey credential
   - Browser unwraps the RSA private key (never sent to server)
   - Browser decrypts the credentials client-side

**Security Model:**
- **True Zero-Knowledge:** Server cannot decrypt data even with full database access
- **Passkey Required:** Physical device (YubiKey, Touch ID, Face ID) required for decryption
- **WebAuthn Security:** Uses FIDO2 standards with attestation validation and signature verification
- **Threat Protection:** Protects against database compromise, server compromise, admin account takeover, and phishing attacks

**Key Storage:**
- Public key: `secure_login_public_key_pro` (stored in plain text)
- Private key: `secure_login_wrapped_private_key_pro` (wrapped with passkey-derived key - cannot be decrypted without physical passkey device)

**Passkey Authentication:**
- Credential registered via WebAuthn/FIDO2 standard
- Supports hardware keys (YubiKey, Titan), platform authenticators (Touch ID, Face ID, Windows Hello)
- Key derivation: PBKDF2-SHA256 from (credential_id + user_id + WordPress salt) with 100,000 iterations
- Authentication requires: valid challenge + origin verification + signature verification

= ⚖️ Free vs Pro: Detailed Comparison =

**FREE VERSION - Server-Trusted Encryption**

✅ **Pros:**
- **Simple Setup:** Works immediately after installation, no additional configuration needed
- **Easy Access:** Any WordPress admin can decrypt with their normal login credentials
- **Team Friendly:** Multiple admins can access credentials without additional setup
- **No Hardware Required:** No need for physical security keys or specific devices
- **Password Manager Integration:** Full export support to Bitwarden, 1Password, LastPass, etc.
- **Sufficient Security:** Military-grade RSA-2048 + AES-256 encryption protects against:
  - Email interception and transmission attacks
  - Casual database access without WordPress configuration
  - Client-side attacks and browser vulnerabilities
  - Man-in-the-middle attacks during submission
- **Quick Recovery:** No risk of losing access if device is lost (admin login always works)
- **Cost:** Completely free forever
- **Suitable For:** 95% of agencies and use cases

❌ **Cons:**
- **Server Can Decrypt:** If someone gains full server access (database + wp-config.php with salts), they can decrypt all credentials
- **Not Zero-Knowledge:** The server has the ability to decrypt private keys using WordPress salts
- **Admin Account Risk:** Compromised admin account = access to all credentials
- **Database Vulnerability:** Database backup with WordPress config = potential exposure
- **No Physical Security:** No hardware-based security layer (no YubiKey, no biometrics)
- **Compliance Limitations:** May not meet strict compliance requirements for healthcare (HIPAA) or financial data
- **Single Factor:** Decryption only requires WordPress admin login (username + password)

**PRO VERSION - Zero-Knowledge Encryption**

✅ **Pros:**
- **True Zero-Knowledge:** Server cannot decrypt data even with complete database and server access
- **Physical Security:** Requires physical passkey device (YubiKey) or biometric (Touch ID/Face ID) for decryption
- **Maximum Security:** Protects against:
  - Complete server compromise (database + configuration + code)
  - Admin account takeover (attacker still needs your physical device)
  - Database theft or backup exposure
  - Insider threats (hosting provider, developers, etc.)
  - Remote attacks (phishing, malware cannot steal physical device)
- **WebAuthn/FIDO2 Standard:** Industry-standard phishing-resistant authentication
- **Hardware-Backed:** Keys protected by secure enclaves (YubiKey chip, Apple Secure Enclave, TPM)
- **Compliance Ready:** Meets strict requirements for HIPAA, GDPR, financial services, enterprise security
- **Audit Trail:** Enhanced logging for who accessed what and when
- **Multi-Device Support:** Register multiple passkeys (primary + backup)
- **Team Security:** Each team member can register their own passkey
- **Platform Flexible:** Works with hardware keys, Touch ID, Face ID, Windows Hello
- **Dual-Key System:** Free version credentials remain accessible if needed

= 🔄 How It Works =

**For Your Clients:**
1. Client visits your secure form page
2. Enters their login credentials
3. Data is encrypted in their browser
4. Encrypted data is sent to your server
5. Client receives confirmation

**For Your Agency:**
1. Receive email notification of new submission
2. Log into WordPress admin dashboard
3. View encrypted entry with client details
4. Click decrypt and authenticate (passkey in Pro)
5. Copy credentials to your password manager
6. Data auto-deletes after set period

= 🏆 Why Agencies Choose This Plugin =

**Security First**
- Eliminate the risk of email interception
- Meet client security expectations
- Reduce liability with encryption
- Comply with data protection regulations

**Professional Image**
- Show clients you take their security seriously
- Stand out from competitors still using email
- Build trust with secure processes
- Demonstrate technical competence

**Operational Efficiency**
- Centralize all client credentials
- Export directly to team password managers
- Automatic cleanup reduces manual work
- Quick search saves time finding credentials

**Peace of Mind**
- Know that data is always encrypted
- Automatic deletion prevents data hoarding
- Audit trail for compliance
- No plain text passwords anywhere

== Installation ==

= Quick Setup for Agencies =

1. **Install the Plugin**
   - Upload to `/wp-content/plugins/` or install via WordPress admin
   - Activate through the 'Plugins' menu

2. **Configure Settings**
   - Go to Login Data → Settings
   - Set retention period (7-30 days recommended)
   - Configure email notifications
   - Customize form text with your agency info

3. **Add Collection Form**
   - Create a new page (e.g., "Secure Credential Submission")
   - Add shortcode: `[secure_login_form]`
   - Publish and share link with clients

4. **Test the System**
   - Submit a test entry
   - Verify email notification
   - Test decryption in admin
   - Confirm auto-deletion works

5. **For Pro Version**
   - Register your passkey device
   - Test passkey authentication
   - Configure team member access

== Frequently Asked Questions ==

= Why is this better than receiving passwords via email? =

Email sends passwords in plain text that can be intercepted, logged by email servers, and remains in sent/received folders indefinitely. This plugin:
- Encrypts data before it leaves the client's browser
- Uses RSA-2048 + AES-256 encryption (military-grade)
- Automatically deletes data after your specified period
- Provides audit trail for compliance
- Shows clients you prioritize security

= What's the difference between Free and Pro versions? =

**Free Version:** Uses RSA-2048 encryption with keys protected by WordPress salts. Perfect for most agencies. Admin users can decrypt data with their WordPress login.

**Pro Version:** Adds passkey authentication that wraps the encryption keys. Even if someone gains admin access to WordPress, they cannot decrypt without your physical passkey device (YubiKey, Touch ID, etc.). Ideal for high-security requirements.

= Can my whole team access the submitted credentials? =

Yes! Any WordPress admin can view and decrypt credentials in the free version. In the Pro version, each team member can register their own passkey. You can also export credentials to shared password managers like Bitwarden or 1Password for team access.

= How long is data stored? =

You control retention periods from 1-365 days (or set to 0 for no auto-deletion). Most agencies use 7-30 days. After decrypting and saving credentials to your password manager, data can be deleted immediately or left to auto-expire.

= What happens if I lose my passkey (Pro version)? =

The free version credentials remain accessible with WordPress admin access. Decrypting login data without the passkey won't be possible anymore. 

= Can clients update previously submitted credentials? =

Clients can submit new credentials with a note explaining the change. Each submission is separate to maintain an audit trail. The old entry can be deleted once you've updated your records.

= Is this GDPR compliant? =

The plugin provides tools for GDPR compliance:
- All data is only saved at your server. No 3rd party is interfering with transfering and saving the login data.
- Automatic data deletion (right to be forgotten)
- Strong encryption (data protection)
- No unnecessary data collection
- Audit trails for accountability
- You control all data retention policies

= Can I customize the form appearance? =

Yes! You can:
- Customize all form text and messages
- Style with CSS to match your brand
- Add custom instructions for clients
- Translate to other languages

= Do clients need to create an account? =

No! Clients simply fill out the form with their credentials. No registration, no passwords to remember, no friction. This makes it easy for clients to quickly send you what you need.

= What password managers can I export to? =

The plugin supports export to: Bitwarden, 1Password, LastPass, Dashlane, KeePass, Chrome, Firefox, Safari, and generic CSV format. This covers all major password managers used by agencies.

== Screenshots ==

1. **Client Submission Form** - Clean, professional form your clients see
2. **Encryption in Progress** - Real-time status showing data being encrypted
3. **Admin Dashboard** - Central interface showing all encrypted submissions
4. **Decryption View** - Secure display with one-click copy buttons
5. **Bulk Export** - Select multiple entries for password manager export
6. **Settings Page** - Configure retention, notifications, and form text
7. **Passkey Setup (Pro)** - Register hardware keys or biometric authentication
8. **Export Options** - Choose from 8+ password manager formats

== Changelog ==

= 1.0.0 =
* Initial public release
* Free version with RSA-2048 + AES-256 encryption
* Pro version with passkey authentication
* Support for 8 password manager export formats
* Bulk operations and management
* Auto-retention with customizable periods
* Email notifications
* Multi-language support (DE, ES)
* WordPress 6.4 compatibility

== Upgrade Notice ==

= 1.0.0 =
First stable release. Upgrade from beta versions recommended for security enhancements.

== Support ==

- WordPress.org support forums
- Documentation and FAQs
- Community assistance

== Privacy & Security ==

This plugin prioritizes security:
- All data encrypted before transmission
- Zero-knowledge architecture
- No telemetry or data collection
- No external API calls (except Pro license validation)
- All processing happens on your server
- Regular security updates