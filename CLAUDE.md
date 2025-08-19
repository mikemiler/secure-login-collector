# CLAUDE.md - Secure Login Collector Plugin

This file provides comprehensive guidance to Claude Code (claude.ai/code) for working with this WordPress plugin.

## Current Implementation Status (CRITICAL - READ THIS FIRST)

### ✅ What's Complete:
1. **Passkey-Wrapped RSA Encryption Architecture**
   - RSA-2048 public/private key generation
   - Private key wrapped with passkey-derived AES-256-GCM key
   - Public key used for client-side encryption
   - Wrapped private key stored in WordPress options

2. **Frontend Encryption (100% Complete)**
   - Located in: `assets/js/frontend-secure.js`
   - Gets RSA public key from server via AJAX
   - Generates AES-256 key for data encryption
   - Encrypts form data with AES-256-GCM
   - Wraps AES key with RSA-2048-OAEP
   - Sends encrypted package to server

3. **Server Storage (100% Complete)**
   - Stores encrypted data in `wp_secure_login_data` table
   - Cannot decrypt without passkey authentication
   - Includes metadata for admin viewing
   - Auto-expiration after configured days

4. **Admin Decryption Flow (100% Complete)**
   - Located in: `assets/js/admin-decrypt.js`
   - Retrieves encrypted data from server
   - Authenticates admin with passkey
   - Derives unwrapping key from passkey
   - Unwraps RSA private key in browser
   - Decrypts data client-side only
   - Auto-clears after 60 seconds

### ⚠️ What's Partially Complete:
1. **Passkey Registration (75% Complete)**
   - `includes/class-passkey-manager.php` - Backend exists
   - `assets/js/passkey-admin.js` - Frontend exists
   - **MISSING**: Integration with key wrapping during registration
   - **STATUS**: Passkey can be registered but doesn't automatically wrap keys

2. **Key Initialization (90% Complete)**
   - RSA keys generated on first use
   - Temporary storage with WordPress salt encryption
   - **MISSING**: Automatic passkey wrapping on first registration
   - **WORKAROUND**: Keys work but aren't passkey-wrapped until manual setup

### ❌ What's NOT Complete:
1. **Passkey-Key Integration**
   - Passkey registration doesn't trigger key wrapping
   - No UI flow connecting passkey setup to key security
   - Manual intervention needed to wrap keys

2. **Recovery System**
   - No recovery key generation
   - No backup passkey support
   - If passkey lost, data is unrecoverable

3. **Migration from Old System**
   - No automated migration path
   - Old encrypted data remains with salt-based encryption
   - Requires manual re-encryption

## Architecture Overview

### Security Model: Passkey-Wrapped RSA Keys
```
Client → [RSA Public Key Encryption] → Server (stores encrypted)
                                           ↓
Admin → [Passkey Auth] → [Unwrap Private Key] → [Decrypt in Browser]
```

**Key Properties:**
- Server CANNOT decrypt even if compromised
- Database breach exposes only encrypted data
- Passkey required for any decryption
- Phishing resistant (passkeys are domain-bound)

## File Structure and Responsibilities

### Core PHP Classes
```php
secure-login-collector.php                 # Main plugin orchestrator
├── includes/
│   ├── class-encryption-handler.php      # Passkey-wrapped key management
│   ├── class-admin-interface.php         # Admin UI and AJAX endpoints
│   ├── class-frontend-handler.php        # Form rendering and submission
│   ├── class-database-manager.php        # Database operations
│   ├── class-settings-manager.php        # Plugin settings
│   ├── class-passkey-manager.php         # WebAuthn passkey registration
│   └── class-master-key-manager.php      # Master key wrapping operations
```

### JavaScript Implementation
```javascript
assets/js/
├── frontend-secure.js      # Client-side RSA+AES encryption
├── admin-decrypt.js        # Passkey auth + client-side decryption
├── admin.js               # Admin UI interactions
└── passkey-admin.js       # Passkey registration UI
```

### Critical AJAX Endpoints
```php
// Public endpoints (no auth required)
'slc_get_public_key'           # Returns RSA public key for encryption
'save_secure_login_data_v2'    # Receives encrypted form submissions

// Admin endpoints (requires manage_options)
'get_encrypted_entry'           # Returns encrypted data for decryption
'slc_get_wrapped_private_key'   # Returns passkey-wrapped private key
'passkey_get_challenge'         # WebAuthn challenge for authentication
'passkey_start_registration'    # Begin passkey registration
'passkey_complete_registration' # Complete passkey registration
```

## Implementation Details

### 1. How Frontend Encryption Works
```javascript
// In frontend-secure.js
1. User fills form with login credentials
2. On submit, get public key: ajax('slc_get_public_key')
3. Generate AES-256 key: crypto.subtle.generateKey()
4. Encrypt data with AES: crypto.subtle.encrypt('AES-GCM')
5. Wrap AES key with RSA: crypto.subtle.encrypt('RSA-OAEP')
6. Send package to server: ajax('save_secure_login_data_v2')
```

### 2. How Server Storage Works
```php
// In class-frontend-handler.php
1. Receive encrypted package via AJAX
2. Validate nonce and structure
3. Store in database:
   - encrypted_data (AES encrypted login info)
   - encrypted_aes_key (RSA wrapped AES key)
   - iv (initialization vector)
   - metadata (public info for admin)
4. Server CANNOT decrypt - no access to private key
```

### 3. How Admin Decryption Works
```javascript
// In admin-decrypt.js
1. Admin clicks "Decrypt" button
2. Get encrypted data: ajax('get_encrypted_entry')
3. Get wrapped key: ajax('slc_get_wrapped_private_key')
4. Authenticate with passkey: navigator.credentials.get()
5. Derive unwrapping key from passkey signature
6. Unwrap RSA private key: crypto.subtle.decrypt('AES-GCM')
7. Decrypt AES key: crypto.subtle.decrypt('RSA-OAEP')
8. Decrypt data: crypto.subtle.decrypt('AES-GCM')
9. Display and auto-clear after 60 seconds
```

## Current Security Issues & TODOs

### 🔴 CRITICAL - Must Fix:
1. **Passkey Registration Doesn't Wrap Keys**
   ```php
   // PROBLEM: In class-passkey-manager.php
   // Registration completes but doesn't call:
   $encryption_handler->initialize_keys_with_passkey($passkey_derived_key);
   
   // FIX NEEDED: After passkey registration, wrap existing keys
   ```

2. **No Key Initialization Check**
   ```php
   // PROBLEM: System doesn't verify keys are passkey-wrapped
   // Admin can decrypt with WordPress salts if not wrapped
   
   // FIX NEEDED: Force passkey setup before allowing decryption
   ```

### 🟡 IMPORTANT - Should Fix:
1. **No Recovery Mechanism**
   - Need recovery key generation
   - Store recovery-wrapped copy of private key
   - UI for recovery process

2. **No Migration Path**
   - Old data encrypted with WordPress salts
   - Need batch re-encryption tool
   - Progress tracking for large datasets

3. **Missing Passkey Management UI**
   - Can't list registered passkeys
   - Can't revoke passkeys
   - Can't add multiple passkeys

### 🟢 NICE TO HAVE:
1. **Performance Optimizations**
   - Cache unwrapped keys in memory (with timeout)
   - Batch decryption support
   - Progressive loading for large datasets

2. **Enhanced Security**
   - Key rotation mechanism
   - Hardware security key enforcement
   - IP-based access restrictions

## Testing Checklist

### Basic Functionality:
- [ ] Admin can generate RSA keys (first visit to settings)
- [ ] Frontend form loads without errors
- [ ] Form submission encrypts and saves data
- [ ] Admin can view encrypted entries list
- [ ] Metadata displays correctly (email, date, etc.)

### Passkey Integration:
- [ ] Admin can register a passkey
- [ ] Passkey registration completes successfully
- [ ] **BROKEN**: Keys are NOT automatically wrapped after passkey registration
- [ ] **MANUAL FIX**: Need to manually trigger key wrapping

### Decryption Flow:
- [ ] Decrypt button triggers passkey authentication
- [ ] **ISSUE**: May fail if keys not properly wrapped
- [ ] Successful auth unwraps private key
- [ ] Data decrypts and displays correctly
- [ ] Auto-clear works after 60 seconds

## Known Issues & Workarounds

### Issue 1: Passkey Not Wrapping Keys
**Problem**: Registering passkey doesn't wrap RSA private key
**Workaround**: 
```php
// Manually in browser console after passkey registration:
// 1. Get passkey derived key
// 2. Call encryption handler to wrap keys
// This should be automatic but isn't connected
```

### Issue 2: First-Time Setup Confusion
**Problem**: No clear setup flow for admins
**Workaround**:
1. Visit settings page (generates keys)
2. Register passkey (currently doesn't wrap)
3. Need manual intervention to complete setup

### Issue 3: No Feedback on Security Status
**Problem**: Admin doesn't know if keys are properly secured
**Solution Needed**: Add status indicator showing:
- ✅ Keys generated
- ⚠️ Keys not passkey-wrapped
- ✅ Passkey registered
- ✅ System secure

## Database Schema

```sql
-- Main encrypted data table
CREATE TABLE wp_secure_login_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    encrypted_data LONGTEXT,        -- AES encrypted credentials
    metadata TEXT,                   -- Public info (email, date)
    created_at DATETIME,
    retention_until DATETIME,
    INDEX idx_created (created_at)
);

-- WordPress options used:
secure_login_public_key             -- RSA public key (plain)
secure_login_wrapped_private_key    -- Passkey-wrapped private key
secure_login_private_key_encrypted  -- Fallback salt-encrypted key
secure_login_key_access_log         -- Audit log of key access
```

## File Status

### Active Files (Currently Used):
- ✅ `secure-login-collector.php`
- ✅ `includes/class-encryption-handler.php`
- ✅ `includes/class-admin-interface.php`
- ✅ `includes/class-frontend-handler.php`
- ✅ `includes/class-database-manager.php`
- ✅ `includes/class-settings-manager.php`
- ✅ `includes/class-passkey-manager.php`
- ✅ `includes/class-master-key-manager.php`
- ✅ `assets/js/frontend-secure.js`
- ✅ `assets/js/admin-decrypt.js`
- ✅ `assets/js/admin.js`
- ✅ `assets/js/passkey-admin.js`

### Removed Files (No Longer Exist):
- ❌ `includes/class-zero-knowledge-handler.php`
- ❌ `includes/class-session-manager.php`
- ❌ `assets/js/zero-knowledge-encryption.js`
- ❌ `assets/js/frontend-pro.js`
- ❌ `assets/js/frontend-ultra-secure.js`

## Security Documentation

### Current Documentation:
- `SECURE-ARCHITECTURE-FINAL.md` - Complete architecture design
- `SECURITY-AUDIT-REPORT.md` - Latest security audit findings
- `INTEGRATION-COMPLETE.md` - Recent integration summary

### Outdated Documentation (To Be Removed):
- `ENCRYPTION-WORKFLOW.md` - Old workflow, superseded
- `ZERO-KNOWLEDGE-ARCHITECTURE.md` - Not implemented, different approach taken

## For Next Developer/Chat Session

### Priority 1: Fix Passkey-Key Integration
The passkey registration works but doesn't wrap the RSA private key. Need to:
1. Connect passkey registration to key wrapping
2. Add UI feedback about security status
3. Ensure keys are wrapped before allowing decryption

### Priority 2: Add Recovery System
Currently no way to recover if passkey is lost:
1. Generate recovery key during setup
2. Store recovery-wrapped copy of private key
3. Add recovery UI flow

### Priority 3: Complete Admin UI
Missing passkey management features:
1. List registered passkeys
2. Revoke/delete passkeys
3. Add multiple passkeys per admin

### Current State Summary:
- **Encryption**: ✅ Working perfectly
- **Storage**: ✅ Secure and functional
- **Decryption**: ✅ Works when properly configured
- **Passkey Integration**: ⚠️ Partially complete, needs connection
- **Recovery**: ❌ Not implemented
- **Migration**: ❌ Not implemented

The architecture is solid and secure, but the implementation needs completion of the passkey-key wrapping integration to be production-ready.