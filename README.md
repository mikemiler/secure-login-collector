# Secure Login Collector

A WordPress plugin for securely collecting and storing encrypted login credentials from clients with multiple encryption methods and advanced security features.

## Features

### 🔐 Multiple Encryption Methods

The plugin supports different encryption methods that can coexist in the same database:

#### **Ultra-Secure (Passkey-Derived)** - Pro Version
- **Icon**: 🔐 Ultra-Secure (Passkey)
- **Technology**: Passkey signature → PBKDF2 → AES-256-GCM
- **Security Level**: Maximum
- **Key Features**:
  - Encryption key derived from your physical passkey device
  - Even server compromise cannot decrypt data without your passkey
  - Uses PBKDF2 with 100,000 iterations for key derivation
  - AES-256-GCM authenticated encryption
  - No encryption keys stored on server

#### **RSA-2048** - All Versions
- **Icon**: 🔒 RSA-2048
- **Technology**: RSA-OAEP with 2048-bit keys
- **Security Level**: Secure
- **Key Features**:
  - Industry-standard RSA encryption
  - 2048-bit key strength
  - Available for all users (free and pro)
  - Server-side key management with WordPress salt encryption

#### **XOR (Legacy)** - Backward Compatibility
- **Icon**: 🔓 XOR (Legacy)
- **Technology**: XOR cipher with hostname+timestamp keys
- **Security Level**: Basic
- **Key Features**:
  - Simple XOR encryption for backward compatibility
  - Used by entries created before RSA implementation
  - Automatic key reconstruction from metadata

### 🎯 Mixed Encryption Support

**Yes, different rows can use different encryption methods!** The plugin automatically:

1. **Detects encryption method** from metadata stored with each entry
2. **Displays the method** in the admin interface with color-coded badges
3. **Uses appropriate decryption** based on the stored encryption type
4. **Maintains backward compatibility** with older entries

### 📊 Admin Interface

The admin interface shows encryption methods for each row:

| ID | Email | Name | Service | Date | **Encryption Method** | Expires | Actions |
|----|-------|------|---------|------|---------------------|---------|---------|
| 1  | user@example.com | John | Gmail | 2024-01-15 | **🔐 Ultra-Secure (Passkey)** | 29 days | Decrypt, Delete |
| 2  | admin@site.com | Admin | Hosting | 2024-01-14 | **🔒 RSA-2048** | 28 days | Decrypt, Delete |
| 3  | old@user.com | Legacy | Email | 2024-01-10 | **🔓 XOR (Legacy)** | 24 days | Decrypt, Delete |

### 🔄 Encryption Method Selection

The plugin automatically selects the encryption method based on:

1. **Pro Version + Ultra-Secure Mode + Passkey Registered** → Passkey-Derived
2. **RSA Keys Available** → RSA-2048
3. **Fallback** → XOR (Legacy)

### 🛡️ Security Features

- **Client-side encryption** - Data encrypted in browser before transmission
- **Multiple encryption layers** - Different methods for different security needs
- **Passkey authentication** - Physical device required for ultra-secure decryption
- **Automatic key management** - RSA keys generated and managed securely
- **Audit logging** - All encryption/decryption actions logged
- **Data expiration** - Automatic cleanup of old encrypted data
- **Email notifications** - Alerts when new data is received (no sensitive data in emails)

### 🚀 Usage

#### Frontend Form
Use the shortcode `[secure_login_form]` to display the secure submission form:

```php
[secure_login_form title="Submit Your Credentials" button_text="Send Securely"]
```

#### Manual Entry (Admin)
Administrators can manually add login data entries directly from the admin interface:

1. Navigate to **Login Data** in WordPress admin
2. Click **Add New Entry** button
3. Fill in the required fields:
   - Email Address (required)
   - Name (required) 
   - Service Name (required)
   - Login Data (required)
   - Encryption Method (choose from available options)
4. Click **Save Entry**

**Available Encryption Methods for Manual Entry:**
- **🔐 Ultra-Secure (Passkey)** - Pro version only, requires passkey registration
- **🔒 RSA-2048** - Recommended for most use cases
- **🔓 XOR (Legacy)** - For backward compatibility

#### Admin Management
1. Navigate to **Login Data** in WordPress admin
2. View all submissions with their encryption methods
3. Click **Decrypt** to view data (authentication required for passkey-encrypted data)
4. Edit metadata, extend retention, or delete entries
5. Use **Add New Entry** to manually add login data

#### Settings Configuration
1. Go to **Login Data > Settings**
2. Configure email notifications
3. Set data expiration periods:
   - **Positive number** (e.g., 30): Auto-delete after specified days
   - **0**: Disable auto-deletion (data retained until manually deleted)
4. Manage RSA encryption keys
5. Register passkeys for ultra-secure mode (Pro version)

### 🔧 Installation

1. Upload the plugin files to `/wp-content/plugins/secure-login-collector/`
2. Activate the plugin through WordPress admin
3. Configure settings in **Login Data > Settings**
4. Add the shortcode `[secure_login_form]` to any page/post

### 🎛️ Pro Version Features

To enable pro version features, add this line to your `wp-config.php`:

```php
define('SECURE_LOGIN_PRO', true);
```

Pro features include:
- **Ultra-secure passkey-derived encryption**
- **Passkey authentication for decryption**
- **Enhanced security settings**
- **Advanced encryption options**

### 🔍 Verification

To verify that passkey encryption is truly part of the encryption (not just a gatekeeper):

1. **Check database entries** - Look for `encryption_type: 'passkey_derived'` in metadata
2. **Test decryption** - Passkey-encrypted data cannot be decrypted without the physical passkey
3. **Code inspection** - The passkey signature is used directly in PBKDF2 key derivation
4. **Security audit** - All encryption/decryption actions are logged with method used

### 📝 Technical Details

#### Encryption Flow
```
Frontend Form → Client-side Encryption → Server Storage → Admin Decryption
```

#### Passkey-Derived Encryption
```
Passkey Signature → PBKDF2(signature, salt, 100k iterations) → AES-256-GCM(data, derived_key)
```

#### RSA Encryption
```
RSA Public Key → RSA-OAEP(data, public_key) → Server Storage → RSA-OAEP Decrypt(private_key)
```

#### XOR Encryption (Legacy)
```
Hostname + Timestamp → XOR(data, key) → Server Storage → XOR Decrypt(same_key)
```

### 🔒 Security Considerations

- **Passkey-derived encryption** provides maximum security - even plugin code modification cannot decrypt data without the physical passkey
- **RSA encryption** provides strong security for most use cases
- **XOR encryption** is maintained for backward compatibility only
- **All methods** encrypt data client-side before transmission
- **No sensitive data** is ever stored unencrypted on the server

### 📞 Support

For support and questions about the Secure Login Collector plugin, please refer to the plugin documentation or contact the developer.

---

**Version**: 2.4.0  
**Author**: Mike Miler  
**License**: GPL v2 or later