<?php
// config/sharepoint_config.php - SharePoint Configuration

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SharePoint Configuration Constants
define('TENANT_ID', 'x');
define('CLIENT_ID', 'y');
define('CLIENT_SECRET', 'z');
define('SHAREPOINT_SITE_ID', '11adac3a-680d-4a72-9acf-6699524b1d7f'); // Or use site URL format
define('DOCUMENT_LIBRARY_NAME', 'CasesandIncidentsFiles');
define('SHAREPOINT_SITE_URL', 'https://cityofaustin.sharepoint.com/sites/APLIncidentNotifications');

// File Upload Settings
define('MAX_FILES', 5);
define('MAX_FILE_SIZE_MB', 50);
define('MAX_FILE_SIZE_BYTES', MAX_FILE_SIZE_MB * 1024 * 1024);

// Allowed file extensions
define('ALLOWED_EXTENSIONS', [
    'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', // Images
    'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'    // Videos
]);

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Global debug array
if (!isset($GLOBALS['debug_info'])) {
    $GLOBALS['debug_info'] = [
        'config' => [],
        'auth' => [],
        'api' => [],
        'errors' => [],
        'request' => [],
        'session' => []
    ];
}

// Configuration status for debugging
$GLOBALS['debug_info']['config'] = [
    'TENANT_ID' => defined('TENANT_ID') ? (TENANT_ID !== 'x' && !empty(TENANT_ID) ? 'Set (' . strlen(TENANT_ID) . ' chars)' : 'Not properly set') : 'Not defined',
    'CLIENT_ID' => defined('CLIENT_ID') ? (CLIENT_ID !== 'y' && !empty(CLIENT_ID) ? 'Set (' . strlen(CLIENT_ID) . ' chars)' : 'Not properly set') : 'Not defined',
    'CLIENT_SECRET' => defined('CLIENT_SECRET') ? (CLIENT_SECRET !== 'z' && !empty(CLIENT_SECRET) ? 'Set (' . strlen(CLIENT_SECRET) . ' chars)' : 'Not properly set') : 'Not defined',
    'SHAREPOINT_SITE_ID' => defined('SHAREPOINT_SITE_ID') ? (SHAREPOINT_SITE_ID ? 'Set: ' . SHAREPOINT_SITE_ID : 'Empty') : 'Not defined',
    'SHAREPOINT_SITE_URL' => defined('SHAREPOINT_SITE_URL') ? (SHAREPOINT_SITE_URL ? SHAREPOINT_SITE_URL : 'Empty') : 'Not defined',
    'DOCUMENT_LIBRARY_NAME' => defined('DOCUMENT_LIBRARY_NAME') ? (DOCUMENT_LIBRARY_NAME ? DOCUMENT_LIBRARY_NAME : 'Empty') : 'Not defined'
];

// Capture request data for debugging
$GLOBALS['debug_info']['request'] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'get_data' => $_GET,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'session_id' => session_id(),
    'timestamp' => date('Y-m-d H:i:s'),
    'files_data' => $_FILES ?? []
];
?>
