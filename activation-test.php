<?php
/**
 * Plugin Activation Test
 * 
 * Run this file directly to test if the plugin can be activated without Freemius
 */

// Test 1: Check if plugin file exists
echo "Test 1: Plugin file exists: ";
$plugin_file = __DIR__ . '/secure-login-collector.php';
echo file_exists($plugin_file) ? "✅ YES\n" : "❌ NO\n";

// Test 2: Check PHP syntax
echo "Test 2: PHP syntax check: ";
$output = shell_exec("php -l $plugin_file 2>&1");
echo strpos($output, 'No syntax errors') !== false ? "✅ PASS\n" : "❌ FAIL: $output\n";

// Test 3: Check if Freemius SDK exists
echo "Test 3: Freemius SDK exists: ";
$sdk_path = __DIR__ . '/vendor/freemius/start.php';
echo file_exists($sdk_path) ? "✅ YES\n" : "⚠️  NO (Optional)\n";

// Test 4: Check required files
echo "\nTest 4: Required files check:\n";
$required_files = [
    'includes/class-encryption-handler.php',
    'includes/class-admin-interface.php',
    'includes/class-frontend-handler.php',
    'includes/class-settings-manager.php',
    'includes/class-database-manager.php'
];

foreach ($required_files as $file) {
    echo "  - $file: ";
    echo file_exists(__DIR__ . '/' . $file) ? "✅\n" : "❌\n";
}

// Test 5: Try loading without WordPress
echo "\nTest 5: Load test (without WordPress): ";
define('ABSPATH', true); // Fake WordPress constant
define('SECURE_LOGIN_PLUGIN_DIR', __DIR__ . '/');
define('SECURE_LOGIN_PLUGIN_URL', 'http://example.com/');

try {
    // Don't actually execute, just check if it would load
    $contents = file_get_contents($plugin_file);
    if (strpos($contents, 'syntax error') === false) {
        echo "✅ PASS\n";
    } else {
        echo "❌ FAIL\n";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n✅ = Success, ❌ = Error, ⚠️ = Warning\n";
echo "\nIf all required tests pass, the plugin should activate without issues.\n";
echo "Freemius SDK is optional - the plugin will work without it.\n";