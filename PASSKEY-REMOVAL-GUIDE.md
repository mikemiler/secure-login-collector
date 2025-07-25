# How to Remove Old Passkeys from Your Browser/Device

Since WebAuthn passkeys cannot be programmatically removed by websites, you'll need to manually remove old passkeys from your browser or device after resetting.

## Browser-Specific Instructions

### Google Chrome / Microsoft Edge
1. Go to Settings → Privacy and security → Security → Manage passkeys
2. Or navigate to: `chrome://settings/passkeys` (Chrome) or `edge://settings/passkeys` (Edge)
3. Find the passkey for your WordPress site
4. Click the three dots (⋮) next to it
5. Select "Delete"

### Safari (macOS)
1. Open System Preferences → Passwords (macOS Ventura or later)
2. Or go to Safari → Preferences → Passwords
3. Find the entry for your WordPress site
4. Select it and press Delete

### Safari (iOS/iPadOS)
1. Go to Settings → Passwords
2. Find the entry for your WordPress site
3. Tap on it, then tap "Delete Passkey"

### Firefox
1. Firefox stores passkeys in the system's credential manager
2. Follow the instructions for your operating system below

### Windows (Windows Hello)
1. Go to Settings → Accounts → Sign-in options
2. Click on "Passkey" or "Security Key"
3. Click "Manage"
4. Find and remove the passkey for your site

### macOS (iCloud Keychain)
1. Open Keychain Access (found in Applications → Utilities)
2. Search for your website name
3. Look for entries of type "Passkey" or "public key credential"
4. Right-click and select "Delete"

### Password Managers

#### 1Password
1. Open 1Password
2. Search for your website
3. Find the passkey entry
4. Delete or archive it

#### Bitwarden
1. Open Bitwarden
2. Find the passkey entry for your site
3. Delete it from your vault

#### Dashlane
1. Open Dashlane
2. Go to Passkeys section
3. Find and remove the old passkey

## Alternative: Use Different Names

If you cannot remove the old passkey, the plugin will append a version number to new registrations (e.g., "My Site (v2)"). This allows you to distinguish between old and new passkeys.

## Best Practice

After resetting a passkey in the plugin:
1. First remove the old passkey from your browser/device using the instructions above
2. Then register a new passkey in the plugin
3. This ensures a clean setup without confusion

## Security Note

Old passkeys that remain in your browser/device cannot be used to decrypt data after a reset, as the plugin validates against the currently registered credential only.