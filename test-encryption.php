<?php
/**
 * Test file for encryption functionality
 * This file helps test the encryption flow.
 * 
 * Usage: Load this file in browser after activating the plugin
 */

// Load WordPress
require_once( dirname( __FILE__ ) . '/../../../../wp-load.php' );

// Check if user is admin
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'You need to be an admin to run this test.' );
}

// Check if plugin is active
if ( ! class_exists( 'SecureLoginCollector' ) ) {
    wp_die( 'Secure Login Collector plugin is not active.' );
}

// Get pro version status
$is_pro = function_exists( 'slc_fs' ) && slc_fs()->is_paying();
$passkey_registered = get_option( 'secure_login_passkey_registered', false );
$passkey_credential_id = get_option( 'secure_login_passkey_credential_id', '' );

?>
<!DOCTYPE html>
<html>
<head>
    <title>Secure Login Collector - Encryption Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
        }
        .status-box {
            background: #f0f0f0;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 30px;
        }
        .status-item {
            margin: 10px 0;
            padding: 8px 12px;
            background: white;
            border-radius: 4px;
        }
        .status-yes {
            color: green;
            font-weight: bold;
        }
        .status-no {
            color: red;
            font-weight: bold;
        }
        .test-section {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background: #0073aa;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin: 5px;
        }
        button:hover {
            background: #005a87;
        }
        .log {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin-top: 20px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            max-height: 400px;
            overflow-y: auto;
        }
        .log-entry {
            margin: 5px 0;
            padding: 5px;
        }
        .log-success {
            color: green;
        }
        .log-error {
            color: red;
        }
        .log-info {
            color: blue;
        }
        .encrypted-data {
            word-break: break-all;
            background: #f0f0f0;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Secure Login Collector - Encryption Test</h1>
        
        <div class="status-box">
            <h2>System Status</h2>
            <div class="status-item">
                Pro Version: <span class="<?php echo $is_pro ? 'status-yes' : 'status-no'; ?>">
                    <?php echo $is_pro ? 'YES' : 'NO'; ?>
                </span>
            </div>
            <div class="status-item">
                Passkey Registered: <span class="<?php echo $passkey_registered ? 'status-yes' : 'status-no'; ?>">
                    <?php echo $passkey_registered ? 'YES' : 'NO'; ?>
                </span>
            </div>
            <?php if ( $passkey_credential_id ) : ?>
            <div class="status-item">
                Credential ID: <code><?php echo esc_html( substr( $passkey_credential_id, 0, 20 ) . '...' ); ?></code>
            </div>
            <?php endif; ?>
            <div class="status-item">
                WebAuthn Support: <span id="webauthn-status">Checking...</span>
            </div>
        </div>

        <div class="test-section">
            <h2>Test Frontend Encryption</h2>
            <p>This test simulates the frontend encryption process.</p>
            
            <div>
                <h3>Test Data:</h3>
                <input type="text" id="test-username" value="test@example.com" style="width: 300px; padding: 8px;">
                <input type="password" id="test-password" value="TestPassword123!" style="width: 300px; padding: 8px;">
            </div>
            
            <div style="margin-top: 20px;">
                <button onclick="testBasicEncryption()">Test Basic Encryption (Free)</button>
                <button onclick="testProEncryption()">Test Pro Encryption (With Passkey)</button>
                <button onclick="clearLog()">Clear Log</button>
            </div>
            
            <div id="encryption-log" class="log"></div>
        </div>

        <?php if ( $is_pro && $passkey_registered ) : ?>
        <div class="test-section">
            <h2>Test Passkey Functions</h2>
            <button onclick="testPasskeyAuth()">Test Passkey Authentication</button>
            <button onclick="testPasskeyKeyDerivation()">Test Key Derivation</button>
        </div>
        <?php endif; ?>

        <div class="test-section">
            <h2>Create Test Entry</h2>
            <p>Create a test entry in the database with the new encryption format.</p>
            <button onclick="createTestEntry()">Create Test Entry</button>
            <div id="test-entry-result"></div>
        </div>
    </div>

    <script>
        // Check WebAuthn support
        document.addEventListener('DOMContentLoaded', function() {
            const webauthnStatus = document.getElementById('webauthn-status');
            if (window.PublicKeyCredential) {
                webauthnStatus.textContent = 'YES';
                webauthnStatus.className = 'status-yes';
            } else {
                webauthnStatus.textContent = 'NO';
                webauthnStatus.className = 'status-no';
            }
        });

        // Logging functions
        function log(message, type = 'info') {
            const logDiv = document.getElementById('encryption-log');
            const entry = document.createElement('div');
            entry.className = 'log-entry log-' + type;
            entry.textContent = new Date().toLocaleTimeString() + ' - ' + message;
            logDiv.appendChild(entry);
            logDiv.scrollTop = logDiv.scrollHeight;
        }

        function clearLog() {
            document.getElementById('encryption-log').innerHTML = '';
        }

        // Test basic encryption
        async function testBasicEncryption() {
            log('Starting basic encryption test...', 'info');
            
            try {
                // Generate AES key
                log('Generating AES-256-GCM key...', 'info');
                const aesKey = await window.crypto.subtle.generateKey(
                    { name: "AES-GCM", length: 256 },
                    true,
                    ["encrypt", "decrypt"]
                );
                log('✓ AES key generated', 'success');

                // Create test data
                const testData = {
                    username_email: document.getElementById('test-username').value,
                    password: document.getElementById('test-password').value,
                    timestamp: new Date().toISOString()
                };
                log('Test data: ' + JSON.stringify(testData), 'info');

                // Encrypt with AES
                log('Encrypting data with AES-GCM...', 'info');
                const encoder = new TextEncoder();
                const dataBuffer = encoder.encode(JSON.stringify(testData));
                const iv = window.crypto.getRandomValues(new Uint8Array(12));
                
                const encrypted = await window.crypto.subtle.encrypt(
                    { name: "AES-GCM", iv: iv },
                    aesKey,
                    dataBuffer
                );
                log('✓ Data encrypted with AES', 'success');
                log('Encrypted size: ' + encrypted.byteLength + ' bytes', 'info');

                // Export AES key
                log('Exporting AES key...', 'info');
                const exportedKey = await window.crypto.subtle.exportKey("raw", aesKey);
                log('✓ AES key exported (' + exportedKey.byteLength + ' bytes)', 'success');

                // Display results
                const encryptedB64 = btoa(String.fromCharCode(...new Uint8Array(encrypted)));
                const ivB64 = btoa(String.fromCharCode(...new Uint8Array(iv)));
                const keyB64 = btoa(String.fromCharCode(...new Uint8Array(exportedKey)));
                
                log('Results:', 'info');
                log('IV (base64): ' + ivB64.substring(0, 20) + '...', 'info');
                log('Encrypted data (base64): ' + encryptedB64.substring(0, 40) + '...', 'info');
                log('AES key (base64): ' + keyB64.substring(0, 20) + '...', 'info');
                
                log('✓ Basic encryption test completed!', 'success');
                
            } catch (error) {
                log('✗ Error: ' + error.message, 'error');
                console.error(error);
            }
        }

        // Test pro encryption with passkey
        async function testProEncryption() {
            log('Starting pro encryption test...', 'info');
            
            if (!window.PublicKeyCredential) {
                log('✗ WebAuthn not supported in this browser', 'error');
                return;
            }

            log('This would test passkey-based encryption (Pro feature)', 'info');
            log('Passkey registered: <?php echo $passkey_registered ? "YES" : "NO"; ?>', 'info');
            
            if ('<?php echo $passkey_credential_id; ?>') {
                log('Credential ID available: ' + '<?php echo substr($passkey_credential_id, 0, 20); ?>...', 'info');
            }
        }

        // Test passkey authentication
        async function testPasskeyAuth() {
            log('Testing passkey authentication...', 'info');
            
            try {
                const challenge = new Uint8Array(32);
                window.crypto.getRandomValues(challenge);
                
                const assertion = await navigator.credentials.get({
                    publicKey: {
                        challenge: challenge,
                        timeout: 60000,
                        userVerification: "required"
                    }
                });
                
                log('✓ Passkey authentication successful!', 'success');
                log('Authenticator data length: ' + assertion.response.authenticatorData.byteLength, 'info');
                log('Signature length: ' + assertion.response.signature.byteLength, 'info');
                
            } catch (error) {
                log('✗ Passkey authentication failed: ' + error.message, 'error');
            }
        }

        // Test key derivation
        async function testPasskeyKeyDerivation() {
            log('Testing passkey key derivation...', 'info');
            
            try {
                // Create test challenge
                const salt = btoa(String.fromCharCode(...window.crypto.getRandomValues(new Uint8Array(32))));
                const challenge = "wcd-decrypt-login:" + salt;
                log('Challenge: ' + challenge, 'info');
                
                const encoder = new TextEncoder();
                const challengeBuffer = encoder.encode(challenge);
                
                log('Requesting passkey signature...', 'info');
                
                const credentialId = '<?php echo $passkey_credential_id; ?>';
                if (!credentialId) {
                    log('✗ No credential ID available', 'error');
                    return;
                }
                
                // Base64 decode credential ID
                const credIdBinary = atob(credentialId);
                const credIdBytes = new Uint8Array(credIdBinary.length);
                for (let i = 0; i < credIdBinary.length; i++) {
                    credIdBytes[i] = credIdBinary.charCodeAt(i);
                }
                
                const assertion = await navigator.credentials.get({
                    publicKey: {
                        challenge: challengeBuffer,
                        allowCredentials: [{
                            id: credIdBytes.buffer,
                            type: 'public-key'
                        }],
                        userVerification: "required",
                        timeout: 60000
                    }
                });
                
                log('✓ Got passkey signature', 'success');
                
                // Derive key using HKDF
                const signature = new Uint8Array(assertion.response.signature);
                log('Signature length: ' + signature.byteLength + ' bytes', 'info');
                
                const keyMaterial = await window.crypto.subtle.importKey(
                    "raw",
                    signature,
                    { name: "HKDF" },
                    false,
                    ["deriveKey"]
                );
                
                const derivedKey = await window.crypto.subtle.deriveKey(
                    {
                        name: "HKDF",
                        salt: encoder.encode(salt),
                        info: encoder.encode("wcd-passkey-encryption"),
                        hash: "SHA-256"
                    },
                    keyMaterial,
                    { name: "AES-GCM", length: 256 },
                    true,
                    ["encrypt", "decrypt"]
                );
                
                log('✓ Successfully derived AES key from passkey!', 'success');
                
                const exportedKey = await window.crypto.subtle.exportKey("raw", derivedKey);
                log('Derived key size: ' + exportedKey.byteLength + ' bytes', 'info');
                
            } catch (error) {
                log('✗ Key derivation failed: ' + error.message, 'error');
                console.error(error);
            }
        }

        // Create test entry
        async function createTestEntry() {
            const resultDiv = document.getElementById('test-entry-result');
            resultDiv.innerHTML = '<p>Creating test entry...</p>';
            
            // This would need to make an AJAX call to create a test entry
            // For now, just show a message
            resultDiv.innerHTML = '<p style="color: blue;">To create a test entry, use the frontend form on your website.</p>';
        }
    </script>
</body>
</html>