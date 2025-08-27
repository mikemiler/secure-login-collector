=== Secure Login Collector ===
Contributors: mike.miler
Tags: security, login, encryption, password, agency, client management, credential management, password collection, data security
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The secure way for agencies to receive client login credentials. Stop asking clients to send passwords via email - use military-grade encryption instead.

== Description ==

**Secure Login Collector** is built specifically for agencies who need to receive login credentials from clients for various services. Whether you're managing Google Ads accounts, social media profiles, or website maintenance, this plugin provides a secure, professional way to collect passwords without the risks of email transmission.

= 🎯 Built for Agencies Receiving Client Credentials =

As an agency, you need access to your clients' various accounts and services. But asking clients to email passwords is:
- **Insecure** - Emails are sent in plain text
- **Unprofessional** - Shows poor security practices
- **Risky** - Creates liability if data is compromised
- **Messy** - Hard to organize and track

This plugin solves these problems with a secure, encrypted collection system that protects both you and your clients.

= 📋 Common Agency Use Cases =

**Digital Marketing Agencies**
When taking on new clients, you need access to:
* Google Ads accounts for campaign management
* Facebook Business Manager for social advertising
* Google Analytics for performance tracking
* Social media accounts (Instagram, Twitter, LinkedIn)
* Email marketing platforms (Mailchimp, Klaviyo)
* CMS logins for content updates

**Web Development Agencies**
For website projects, you need:
* WordPress admin credentials for maintenance
* FTP/SFTP access for file uploads
* Hosting control panel logins (cPanel, Plesk)
* Domain registrar access for DNS management
* Database credentials for migrations
* API keys for third-party integrations

**SEO & Content Agencies**
For optimization work, you need:
* Google Search Console access
* Website admin panels for content updates
* Analytics platforms for reporting
* SEO tool accounts (Ahrefs, SEMrush)
* Content management systems
* Client email accounts for outreach

**IT Support Companies**
For technical support, you need:
* System administrator credentials
* Cloud service logins (AWS, Azure, Google Cloud)
* Software license keys and accounts
* VPN access credentials
* Server root access
* Backup system logins

= 🆓 What's Included in the FREE Version =

The free version provides everything most agencies need for secure credential collection:

**Core Security Features**
✅ **RSA-2048 Encryption** - Military-grade encryption before data leaves client's browser
✅ **AES-256-GCM Data Encryption** - Additional layer of encryption for stored data
✅ **Zero-Knowledge Architecture** - Your server never sees unencrypted passwords
✅ **Secure Storage** - Encrypted data stored safely in your WordPress database

**Collection & Management**
✅ **Professional Collection Form** - Clean, responsive form with `[secure_login_form]` shortcode
✅ **Real-Time Encryption Status** - Clients see their data being encrypted
✅ **Email Notifications** - Get notified when clients submit credentials
✅ **Admin Dashboard** - Central interface to manage all submissions
✅ **Search & Filter** - Find credentials by client email or domain
✅ **Individual Decryption** - Decrypt entries one at a time as needed

**Data Handling**
✅ **Auto-Expiration** - Automatically delete old data (1-365 days)
✅ **Manual Entry Addition** - Add credentials received via phone/email
✅ **One-Click Copy** - Copy decrypted credentials instantly
✅ **Retention Management** - Extend or reduce retention per entry
✅ **Bulk Delete** - Remove multiple entries at once

**Export Options**
✅ **Password Manager Export** - Export to 8+ formats including:
  - Bitwarden (recommended for teams)
  - 1Password
  - LastPass
  - Chrome, Firefox, Safari
  - Dashlane
  - KeePass
  - Generic CSV

**Customization**
✅ **Custom Form Text** - Add your agency's instructions
✅ **Multi-Language Support** - German and Spanish translations included
✅ **Email Settings** - Configure notification recipients
✅ **Retention Policies** - Set default expiration periods

The free version is perfect for:
- Small to medium agencies
- Freelancers and consultants
- Anyone who needs basic secure credential collection
- Teams using standard password managers

= 🚀 What's Added in the PRO Version =

The Pro version adds advanced security features for agencies handling sensitive enterprise clients:

**🔐 Passkey Authentication**
⭐ **Hardware Security Keys** - Support for YubiKey, Titan, and other FIDO2 devices
⭐ **Biometric Authentication** - Use Touch ID, Face ID, or Windows Hello
⭐ **Phishing-Resistant** - Passkeys are domain-bound and cannot be phished
⭐ **No Master Password** - Nothing to remember or potentially leak

**🛡️ Enhanced Encryption**
⭐ **Passkey-Wrapped Keys** - RSA private keys are additionally encrypted with passkey
⭐ **True Zero-Knowledge** - Even with database access, data cannot be decrypted without your physical device
⭐ **Separate Pro Keys** - Dedicated RSA key pair for ultra-secure entries
⭐ **Double-Layer Protection** - Optional double encryption for maximum security

**⚡ Advanced Features**
⭐ **Bulk Export with Single Authentication** - Decrypt multiple entries with one passkey authentication
⭐ **Passkey Management** - Register multiple passkeys for team members
⭐ **Enhanced Audit Logging** - Track who accessed what and when
⭐ **Priority Security Updates** - Get security patches first

**💼 Premium Support**
⭐ **Priority Email Support** - Get help within 24 hours
⭐ **Setup Assistance** - We'll help you configure everything
⭐ **Security Consultation** - Best practices for your agency
⭐ **Custom Integration Help** - Assistance with special requirements

The Pro version is ideal for:
- Large agencies with enterprise clients
- Agencies handling financial or healthcare data
- Teams requiring hardware security key enforcement
- Organizations with strict compliance requirements
- Agencies wanting the absolute best security available

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

The free version credentials remain accessible with WordPress admin access. For Pro version, we recommend:
- Registering multiple passkeys (one primary, one backup)
- Keeping encrypted backups of your keys
- Using the free version for critical credentials if concerned

= Can clients update previously submitted credentials? =

Clients can submit new credentials with a note explaining the change. Each submission is separate to maintain an audit trail. The old entry can be deleted once you've updated your records.

= Is this GDPR compliant? =

The plugin provides tools for GDPR compliance:
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

**Free Version Support**
- WordPress.org support forums
- Documentation and FAQs
- Community assistance

**Pro Version Support**
- Priority email support (24-hour response)
- Setup assistance included
- Security best practices consultation
- Direct support from developers

For pre-sales questions: [your-email@domain.com]

== Privacy & Security ==

This plugin prioritizes security:
- All data encrypted before transmission
- Zero-knowledge architecture
- No telemetry or data collection
- No external API calls (except Pro license validation)
- All processing happens on your server
- Regular security updates

Found a security issue? Please responsibly disclose to [security@your-domain.com]

== Pro Version Pricing ==

The Pro version is available as an annual subscription that includes:
- All Pro features mentioned above
- Unlimited client submissions
- Unlimited team members
- Priority support
- Regular security updates
- No per-credential fees

Visit [your-website.com/pricing] for current pricing and to purchase.