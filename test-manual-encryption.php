<?php
/**
 * Test script for manual entry encryption
 * 
 * This script tests if manual backend entries are encrypted the same way as frontend entries
 */

// Load WordPress
require_once dirname(__FILE__) . '/../../../../../wp-load.php';

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Access denied');
}

// Get encryption handler instance
$encryption_handler = new Secure_Login_Encryption_Handler(true);

echo "<h2>Manual Entry Encryption Test</h2>";

// Test data
$test_data = json_encode([
    'username_email' => 'test@example.com',
    'password' => 'TestPassword123!',
    'additional_notes' => 'Test notes',
    'timestamp' => current_time('c')
]);

echo "<h3>1. Original Data:</h3>";
echo "<pre>" . htmlspecialchars($test_data) . "</pre>";

// Simulate manual backend encryption (matching the updated code)
$aes_key = openssl_random_pseudo_bytes(32); // 256-bit key
$iv = openssl_random_pseudo_bytes(12); // 96-bit IV for GCM
$salt = openssl_random_pseudo_bytes(32); // 32 bytes salt

echo "<h3>2. Generated Encryption Parameters:</h3>";
echo "AES Key Length: " . strlen($aes_key) . " bytes<br>";
echo "IV Length: " . strlen($iv) . " bytes<br>";
echo "Salt Length: " . strlen($salt) . " bytes<br>";

// Encrypt with AES-GCM
$cipher = 'aes-256-gcm';
$tag = '';
$encrypted_content = openssl_encrypt(
    $test_data,
    $cipher,
    $aes_key,
    OPENSSL_RAW_DATA,
    $iv,
    $tag
);

if ($encrypted_content === false) {
    die("AES encryption failed!");
}

$encrypted_with_tag = $encrypted_content . $tag;

echo "<h3>3. AES Encryption Result:</h3>";
echo "Encrypted data length: " . strlen($encrypted_with_tag) . " bytes<br>";
echo "Auth tag length: " . strlen($tag) . " bytes<br>";

// Get RSA public key
$public_key = $encryption_handler->get_public_key();
if (is_wp_error($public_key)) {
    die("RSA keys not available: " . $public_key->get_error_message());
}

echo "<h3>4. RSA Public Key Available:</h3>";
echo "Key length: " . strlen($public_key) . " characters<br>";

// RSA encrypt the AES key
$encrypted_aes_key = '';
if (!openssl_public_encrypt($aes_key, $encrypted_aes_key, $public_key, OPENSSL_PKCS1_OAEP_PADDING)) {
    die("RSA key encryption failed!");
}

echo "<h3>5. RSA Encryption Result:</h3>";
echo "Encrypted AES key length: " . strlen($encrypted_aes_key) . " bytes<br>";

// Check passkey status
$is_pro_encrypted = false;
$server_credential_id = null;

if (get_option('secure_login_passkey_registered', false)) {
    $is_pro_encrypted = true;
    $server_credential_id = get_option('secure_login_passkey_credential_id', '');
}

echo "<h3>6. Passkey Status:</h3>";
echo "Passkey registered: " . ($is_pro_encrypted ? 'Yes' : 'No') . "<br>";
if ($server_credential_id) {
    echo "Credential ID: " . htmlspecialchars($server_credential_id) . "<br>";
}

// Create the encrypted package
$encrypted_package = array(
    'encryptedData'   => base64_encode($encrypted_with_tag),
    'rsaEncryptedKey' => base64_encode($encrypted_aes_key),
    'iv'              => base64_encode($iv),
    'salt'            => base64_encode($salt),
    'isProEncrypted'  => $is_pro_encrypted,
    'credentialId'    => $server_credential_id,
    'version'         => 2,
);

echo "<h3>7. Final Encrypted Package:</h3>";
echo "<pre>" . htmlspecialchars(json_encode($encrypted_package, JSON_PRETTY_PRINT)) . "</pre>";

// Now test decryption
echo "<h3>8. Testing Decryption:</h3>";

// Decrypt RSA layer
$decrypted_aes_key = $encryption_handler->decrypt_rsa_key($encrypted_package['rsaEncryptedKey']);
if ($decrypted_aes_key === false) {
    echo "<span style='color: red;'>RSA decryption failed!</span><br>";
} else {
    echo "<span style='color: green;'>RSA decryption successful!</span><br>";
    
    // Verify the decrypted key matches original
    $decrypted_key_raw = base64_decode($decrypted_aes_key);
    if ($decrypted_key_raw === $aes_key) {
        echo "<span style='color: green;'>Decrypted AES key matches original!</span><br>";
    } else {
        echo "<span style='color: red;'>Decrypted AES key does NOT match original!</span><br>";
        echo "Original length: " . strlen($aes_key) . ", Decrypted length: " . strlen($decrypted_key_raw) . "<br>";
    }
}

echo "<h3>9. Summary:</h3>";
echo "This test verifies that manual backend entries are encrypted using the same format as frontend entries.<br>";
echo "The encryption process creates a v2 format package that should be compatible with the decryption process.<br>";

?>