<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration Constants - Update these with your values
define('TENANT_ID', 'x');
define('CLIENT_ID', 'y');
define('CLIENT_SECRET', 'z');
define('SHAREPOINT_SITE_ID', '11adac3a-680d-4a72-9acf-6699524b1d7f'); // Or use site URL format
define('DOCUMENT_LIBRARY_NAME', 'CasesandIncidentsFiles'); // Name of your document library
//define('DOCUMENT_LIBRARY_NAME', 'Documents'); // Test with default library first
define('SHAREPOINT_SITE_URL', 'https://cityofaustin.sharepoint.com/sites/APLIncidentNotifications'); // Alternative to site ID

session_start();

// Generate CSRF token if it doesn't exist
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

// Global debug array to collect all debugging information
$GLOBALS['debug_info'] = [
    'config' => [],
    'auth' => [],
    'api' => [],
    'errors' => [],
    'request' => [],
    'session' => []
];

// Capture initial request data
$GLOBALS['debug_info']['request'] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'get_data' => $_GET,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'session_id' => session_id(),
    'timestamp' => date('Y-m-d H:i:s')
];

// Configuration check for debugging
$GLOBALS['debug_info']['config'] = [
    'TENANT_ID' => defined('TENANT_ID') ? (TENANT_ID !== 'x' && !empty(TENANT_ID) ? 'Set (' . strlen(TENANT_ID) . ' chars)' : 'Not properly set') : 'Not defined',
    'CLIENT_ID' => defined('CLIENT_ID') ? (CLIENT_ID !== 'y' && !empty(CLIENT_ID) ? 'Set (' . strlen(CLIENT_ID) . ' chars)' : 'Not properly set') : 'Not defined',
    'CLIENT_SECRET' => defined('CLIENT_SECRET') ? (CLIENT_SECRET !== 'z' && !empty(CLIENT_SECRET) ? 'Set (' . strlen(CLIENT_SECRET) . ' chars)' : 'Not properly set') : 'Not defined',
    'SHAREPOINT_SITE_ID' => defined('SHAREPOINT_SITE_ID') ? (SHAREPOINT_SITE_ID ? 'Set: ' . SHAREPOINT_SITE_ID : 'Empty') : 'Not defined',
    'SHAREPOINT_SITE_URL' => defined('SHAREPOINT_SITE_URL') ? (SHAREPOINT_SITE_URL ? SHAREPOINT_SITE_URL : 'Empty') : 'Not defined',
    'DOCUMENT_LIBRARY_NAME' => defined('DOCUMENT_LIBRARY_NAME') ? (DOCUMENT_LIBRARY_NAME ? DOCUMENT_LIBRARY_NAME : 'Empty') : 'Not defined'
];

// Enhanced function to get access token with comprehensive debugging
function getAccessToken() {
    $tokenUrl = "https://login.microsoftonline.com/" . TENANT_ID . "/oauth2/v2.0/token";
    
    $postData = [
        'grant_type' => 'client_credentials',
        'client_id' => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'scope' => 'https://graph.microsoft.com/.default'
    ];
    
    $GLOBALS['debug_info']['auth']['request'] = [
        'url' => $tokenUrl,
        'post_data' => array_merge($postData, ['client_secret' => '[HIDDEN]']), // Hide secret in debug
        'tenant_id_valid' => (TENANT_ID !== 'x' && !empty(TENANT_ID)),
        'client_id_valid' => (CLIENT_ID !== 'y' && !empty(CLIENT_ID)),
        'client_secret_valid' => (CLIENT_SECRET !== 'z' && !empty(CLIENT_SECRET))
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    $GLOBALS['debug_info']['auth']['response'] = [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'curl_info' => $curlInfo,
        'response_length' => strlen($response),
        'response_preview' => substr($response, 0, 200) . '...'
    ];
    
    if ($curlError) {
        $GLOBALS['debug_info']['errors'][] = "cURL Error in getAccessToken: " . $curlError;
        return false;
    }
    
    if ($httpCode !== 200) {
        $GLOBALS['debug_info']['errors'][] = "HTTP Error in getAccessToken: " . $httpCode;
        $GLOBALS['debug_info']['auth']['response']['full_response'] = $response;
        return false;
    }
    
    if (!$response) {
        $GLOBALS['debug_info']['errors'][] = "Empty response from token endpoint";
        return false;
    }
    
    $tokenData = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $GLOBALS['debug_info']['errors'][] = "JSON decode error: " . json_last_error_msg();
        return false;
    }
    
    if (!isset($tokenData['access_token'])) {
        $GLOBALS['debug_info']['errors'][] = "No access token in response";
        if (isset($tokenData['error'])) {
            $GLOBALS['debug_info']['errors'][] = "Auth error: " . $tokenData['error'] . " - " . ($tokenData['error_description'] ?? 'No description');
        }
        $GLOBALS['debug_info']['auth']['token_response'] = $tokenData;
        return false;
    }
    
    $GLOBALS['debug_info']['auth']['success'] = [
        'token_type' => $tokenData['token_type'] ?? 'unknown',
        'expires_in' => $tokenData['expires_in'] ?? 'unknown',
        'token_length' => strlen($tokenData['access_token']),
        'token_preview' => substr($tokenData['access_token'], 0, 20) . '...'
    ];
    
    return $tokenData['access_token'];
}

// Fixed version - replace the discoverSharePointLibraries function
function discoverSharePointLibraries($accessToken) {
    // Try multiple approaches to get document libraries
    $endpoints = [
        "https://graph.microsoft.com/v1.0/sites/" . SHAREPOINT_SITE_ID . "/lists",
        "https://graph.microsoft.com/v1.0/sites/" . SHAREPOINT_SITE_ID . "/drives",
        "https://graph.microsoft.com/v1.0/sites/" . SHAREPOINT_SITE_ID . "/lists?\$filter=baseTemplate eq 101"
    ];
    
    $allResults = [];
    
    // FIXED: Remove the duplicate foreach line
    foreach ($endpoints as $index => $librariesUrl) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $librariesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $GLOBALS['debug_info']['library_discovery'][$index] = [
            'url' => $librariesUrl,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 500)
        ];
        
        if (!$curlError && $httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['value'])) {
                $allResults = array_merge($allResults, $data['value']);
            }
        }
    }
    
    return $allResults;
}

// Function to check if a folder exists in a specific drive
function checkFolderExists($folderName, $driveId, $accessToken) {
    // Use the children endpoint to get all items in the root folder
    $childrenUrl = "https://graph.microsoft.com/v1.0/drives/" . $driveId . "/root/children";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $childrenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $GLOBALS['debug_info']['folder_existence_check'] = [
        'search_url' => $childrenUrl,
        'folder_name_searching_for' => $folderName,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response_length' => strlen($response)
    ];
    
    if ($curlError || $httpCode !== 200) {
        $GLOBALS['debug_info']['folder_existence_check']['error'] = 'Failed to get children list';
        return false; // Error checking, assume doesn't exist
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['value'])) {
        $GLOBALS['debug_info']['folder_existence_check']['error'] = 'No value array in response';
        return false;
    }
    
    // Check each item to see if it's a folder with our target name
    $foundFolders = [];
    foreach ($data['value'] as $item) {
        // Check if it's a folder and if the name matches exactly
        if (isset($item['folder']) && isset($item['name'])) {
            $foundFolders[] = $item['name'];
            if (strcasecmp($item['name'], $folderName) === 0) {
                $GLOBALS['debug_info']['folder_existence_check']['found_exact_match'] = $item['name'];
                return true;
            }
        }
    }
    
    $GLOBALS['debug_info']['folder_existence_check']['all_folders_found'] = $foundFolders;
    $GLOBALS['debug_info']['folder_existence_check']['exact_match_found'] = false;
    
    return false;
}

// Enhanced function to create folder with existence check
function createSharePointFolder($folderName, $accessToken) {
    $GLOBALS['debug_info']['api']['function_inputs'] = [
        'folder_name_received' => $folderName,
        'folder_name_type' => gettype($folderName),
        'folder_name_empty' => empty($folderName),
        'access_token_length' => $accessToken ? strlen($accessToken) : 0,
        'access_token_preview' => $accessToken ? substr($accessToken, 0, 20) . '...' : 'NULL'
    ];
    
    if (empty($folderName)) {
        $GLOBALS['debug_info']['errors'][] = "Folder name is empty or null";
        return [
            'success' => false,
            'httpCode' => 0,
            'response' => ['error' => ['message' => 'Folder name is empty']],
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
    // Step 1: Get all drives
    $drivesUrl = "https://graph.microsoft.com/v1.0/sites/" . SHAREPOINT_SITE_ID . "/drives";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $drivesUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $GLOBALS['debug_info']['drives_discovery'] = [
        'url' => $drivesUrl,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response_length' => strlen($response)
    ];
    
    $targetDriveId = null;
    $targetDriveName = '';
    $availableDrives = [];
    
    if (!$curlError && $httpCode === 200) {
        $drivesData = json_decode($response, true);
        if (isset($drivesData['value'])) {
            foreach ($drivesData['value'] as $drive) {
                $availableDrives[] = [
                    'id' => $drive['id'],
                    'name' => $drive['name'],
                    'driveType' => $drive['driveType']
                ];
                
                // Look for our target library
                $driveName = strtolower($drive['name']);
                if (stripos($driveName, 'casesandincidentsfiles') !== false ||
                    stripos($driveName, 'cases and incidents files') !== false ||
                    stripos($driveName, 'cases') !== false && stripos($driveName, 'incidents') !== false ||
                    stripos($driveName, 'case') !== false && stripos($driveName, 'incident') !== false) {
                    $targetDriveId = $drive['id'];
                    $targetDriveName = $drive['name'];
                    $GLOBALS['debug_info']['target_drive_found'] = $drive;
                    break; // Use the first match
                }
            }
        }
    }
    
    $GLOBALS['debug_info']['available_drives'] = $availableDrives;
    
    // If no target drive found, use the default drive
    if (!$targetDriveId) {
        // Use the first document library drive or default drive
        foreach ($availableDrives as $drive) {
            if ($drive['driveType'] === 'documentLibrary') {
                $targetDriveId = $drive['id'];
                $targetDriveName = $drive['name'];
                $GLOBALS['debug_info']['fallback_drive_used'] = $drive;
                break;
            }
        }
    }
    
    if (!$targetDriveId) {
        $GLOBALS['debug_info']['errors'][] = "No suitable drive found";
        return [
            'success' => false,
            'httpCode' => 0,
            'response' => ['error' => ['message' => 'No suitable document library found']],
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
    // Step 2: Check if folder already exists
    $folderExists = checkFolderExists($folderName, $targetDriveId, $accessToken);
    
    if ($folderExists) {
        $GLOBALS['debug_info']['folder_already_exists'] = true;
        return [
            'success' => true,
            'already_exists' => true,
            'httpCode' => 200,
            'response' => ['message' => 'Folder already exists'],
            'location' => $targetDriveName,
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
    // Step 3: Create the folder since it doesn't exist
    $folderData = [
        'name' => $folderName,
        'folder' => new stdClass(),
        '@microsoft.graph.conflictBehavior' => 'fail' // Changed from 'rename' to 'fail'
    ];
    
    $createUrl = "https://graph.microsoft.com/v1.0/drives/" . $targetDriveId . "/root/children";
    
    $GLOBALS['debug_info']['api']['folder_creation'] = [
        'endpoint' => $createUrl,
        'drive_name' => $targetDriveName,
        'folder_data' => $folderData
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $createUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($folderData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $GLOBALS['debug_info']['api']['folder_creation']['response'] = [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response' => $response
    ];
    
    // Check if creation was successful
    if (($httpCode === 201 || $httpCode === 200) && !$curlError) {
        $responseData = json_decode($response, true);
        
        return [
            'success' => true,
            'already_exists' => false,
            'httpCode' => $httpCode,
            'response' => $responseData,
            'location' => $targetDriveName,
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
    // Creation failed
    $responseData = json_decode($response, true);
    if (isset($responseData['error'])) {
        $GLOBALS['debug_info']['errors'][] = "Folder creation failed: " . 
            $responseData['error']['code'] . " - " . $responseData['error']['message'];
    }
    
    return [
        'success' => false,
        'httpCode' => $httpCode,
        'response' => $responseData ?? ['error' => ['message' => 'Folder creation failed']],
        'debug_info' => $GLOBALS['debug_info']
    ];
}

// Function to get site information for debugging
function getSiteInfo($accessToken) {
    $siteUrl = "https://graph.microsoft.com/v1.0/sites/" . SHAREPOINT_SITE_ID;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $siteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $GLOBALS['debug_info']['site_info'] = [
        'url' => $siteUrl,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response' => $response
    ];
    
    if ($curlError || $httpCode !== 200) {
        return false;
    }
    
    return json_decode($response, true);
}

// Helper function to display debug information in a readable format
function displayDebugInfo($debug_info) {
    $output = "<div class='debug-section'>";
    $output .= "<h4><i class='fas fa-bug'></i> Detailed Debug Information</h4>";
    
    // Request Debug
    if (!empty($debug_info['request'])) {
        $output .= "<div class='debug-subsection'>";
        $output .= "<h5>Request Information:</h5>";
        $output .= "<pre>" . htmlspecialchars(json_encode($debug_info['request'], JSON_PRETTY_PRINT)) . "</pre>";
        $output .= "</div>";
    }
    
    // Configuration Debug
    $output .= "<div class='debug-subsection'>";
    $output .= "<h5>Configuration Status:</h5>";
    foreach ($debug_info['config'] as $key => $value) {
        $status_class = (strpos($value, 'Not') === 0) ? 'text-danger' : 'text-success';
        $output .= "<div class='{$status_class}'><strong>{$key}:</strong> {$value}</div>";
    }
    $output .= "</div>";
    
    // Authentication Debug
    if (!empty($debug_info['auth'])) {
        $output .= "<div class='debug-subsection'>";
        $output .= "<h5>Authentication Debug:</h5>";
        $output .= "<pre>" . htmlspecialchars(json_encode($debug_info['auth'], JSON_PRETTY_PRINT)) . "</pre>";
        $output .= "</div>";
    }
    
    // API Debug
    if (!empty($debug_info['api'])) {
        $output .= "<div class='debug-subsection'>";
        $output .= "<h5>API Call Debug:</h5>";
        $output .= "<pre>" . htmlspecialchars(json_encode($debug_info['api'], JSON_PRETTY_PRINT)) . "</pre>";
        $output .= "</div>";
    }
    
    // Errors
    if (!empty($debug_info['errors'])) {
        $output .= "<div class='debug-subsection'>";
        $output .= "<h5 class='text-danger'>Errors Encountered:</h5>";
        foreach ($debug_info['errors'] as $error) {
            $output .= "<div class='text-danger'>• " . htmlspecialchars($error) . "</div>";
        }
        $output .= "</div>";
    }
    
    $output .= "</div>";
    return $output;
}

// Handle form submission
$message = '';
$messageType = '';
$folderCreated = false;
$showDebug = true; // Always show debug info

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['caseid'])) {
    // Add debug info for form submission
    $GLOBALS['debug_info']['form_submission'] = [
        'post_method' => $_SERVER['REQUEST_METHOD'],
        'caseid_isset' => isset($_POST['caseid']),
        'caseid_value' => $_POST['caseid'] ?? 'NOT_SET',
        'caseid_type' => gettype($_POST['caseid'] ?? null),
        'post_data_keys' => array_keys($_POST),
        'all_post_data' => $_POST
    ];
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Security token mismatch. Please try again.';
        $messageType = 'error';
        $GLOBALS['debug_info']['errors'][] = 'CSRF token mismatch';
    } else {
        $caseId = trim($_POST['caseid']);
        
        // Add more detailed debugging for case ID processing
        $GLOBALS['debug_info']['case_id_processing'] = [
            'raw_caseid' => $_POST['caseid'],
            'after_trim' => $caseId,
            'is_empty_after_trim' => empty($caseId),
            'length_after_trim' => strlen($caseId)
        ];
        
        if (empty($caseId)) {
            $message = 'Case ID is required.';
            $messageType = 'error';
            $GLOBALS['debug_info']['errors'][] = 'Case ID was empty';
        } else {
            // Sanitize folder name (remove invalid characters)
            $folderName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $caseId);
            
            // Add debug info for the case ID processing
            $GLOBALS['debug_info']['form_processing'] = [
                'original_case_id' => $caseId,
                'sanitized_folder_name' => $folderName,
                'case_id_length' => strlen($caseId),
                'folder_name_length' => strlen($folderName)
            ];
            
            // Get access token with debugging
            $accessToken = getAccessToken();
            
            if ($accessToken) {
                // Create folder (this now includes existence check)
                $result = createSharePointFolder($folderName, $accessToken);
                
                if ($result['success']) {
                    $location = isset($result['location']) ? " in " . $result['location'] : "";
                    
                    if (isset($result['already_exists']) && $result['already_exists']) {
                        // Folder already exists
                        $message = "Folder '{$folderName}' already exists{$location}. No action needed.";
                        $messageType = 'warning'; // Use warning style for "already exists"
                        $folderCreated = false; // Don't clear the form since no new folder was created
                    } else {
                        // Folder was successfully created
                        $message = "Folder '{$folderName}' created successfully{$location}!";
                        $messageType = 'success';
                        $folderCreated = true;
                    }
                } else {
                    $message = "Failed to create folder. HTTP Code: " . $result['httpCode'];
                    if (isset($result['response']['error'])) {
                        $message .= " - " . $result['response']['error']['message'];
                    }
                    $messageType = 'error';
                }
            } else {
                $message = 'Failed to authenticate with Microsoft Graph API.';
                $messageType = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SharePoint Folder Creator - Enhanced Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #2C5282;
            --secondary-blue: #3182CE;
            --accent-blue: #4299E1;
            --light-blue: #EBF8FF;
            --success-green: #38A169;
            --gray-100: #F7FAFC;
            --gray-200: #EDF2F7;
            --gray-600: #718096;
            --gray-800: #2D3748;
        }

        body {
            background: linear-gradient(135deg, #E2E8F0 0%, #CBD5E0 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .main-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 2rem 0;
        }

        .form-wrapper {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            max-width: 1200px;
            margin: 0 auto;
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            color: white;
            padding: 3rem 2rem 2rem;
            position: relative;
            overflow: hidden;
        }

        .header-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .company-logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .form-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .form-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
            margin-bottom: 0;
        }

        .form-content {
            padding: 3rem;
        }

        .form-section {
            background: var(--gray-100);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border-left: 4px solid var(--accent-blue);
        }

        .section-title {
            color: var(--primary-blue);
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        .section-title i {
            margin-right: 0.5rem;
            width: 20px;
        }

        .form-label {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
        }

        .required-indicator {
            color: #E53E3E;
            margin-left: 0.25rem;
            font-size: 0.9rem;
        }

        .form-control {
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 0.2rem rgba(66, 153, 225, 0.25);
            background: white;
        }

        .btn-create {
            background: linear-gradient(135deg, var(--success-green), #2F855A);
            border: none;
            color: white;
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(56, 161, 105, 0.4);
            background: linear-gradient(135deg, #2F855A, #276749);
            color: white;
        }

        .alert-message {
            border: none;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .alert-success {
            background: linear-gradient(135deg, rgba(56, 161, 105, 0.1), rgba(47, 133, 90, 0.1));
            color: #2F855A;
            border-left: 4px solid var(--success-green);
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(229, 62, 62, 0.1), rgba(197, 48, 48, 0.1));
            color: #C53030;
            border-left: 4px solid #E53E3E;
        }

        .alert-warning {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(217, 119, 6, 0.1));
            color: #D97706;
            border-left: 4px solid #F59E0B;
        }


        .config-info {
            background: rgba(66, 153, 225, 0.1);
            border: 1px solid rgba(66, 153, 225, 0.2);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: var(--primary-blue);
            font-size: 0.9rem;
        }

        .debug-section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1rem;
            font-size: 0.85rem;
        }

        .debug-subsection {
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .debug-subsection:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .debug-subsection h5 {
            color: var(--primary-blue);
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
            font-weight: 600;
        }

        .debug-subsection pre {
            background: #f1f3f4;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 1rem;
            font-size: 0.8rem;
            max-height: 400px;
            overflow-y: auto;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .input-icon {
            position: relative;
        }

        .input-icon i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-600);
            z-index: 3;
        }

        .input-icon .form-control {
            padding-left: 2.5rem;
        }

        .debug-toggle {
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .form-content {
                padding: 2rem 1.5rem;
            }
            
            .form-header {
                padding: 2rem 1.5rem 1.5rem;
            }
            
            .form-title {
                font-size: 1.8rem;
            }
            
            .btn-create {
                width: 100%;
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="container">
            <div class="form-wrapper">
                <!-- Header Section -->
                <div class="form-header">
                    <div class="header-content">
                        <div class="company-logo">
                            <i class="fab fa-microsoft fa-2x"></i>
                        </div>
                        <h1 class="form-title">SharePoint Manager</h1>
                        <p class="form-subtitle">Enhanced Debug Version</p>
                    </div>
                </div>

                <!-- Form Content -->
                <div class="form-content">
                    <!-- Configuration Info -->
                     <!--
                    <div class="config-info">
                        <strong><i class="fas fa-cog me-2"></i>Configuration Status:</strong><br>
                        <?php foreach ($GLOBALS['debug_info']['config'] as $key => $value): ?>
                            <span class="<?php echo (strpos($value, 'Not') === 0) ? 'text-danger' : 'text-success'; ?>">
                                <?php echo htmlspecialchars($key); ?>: <?php echo htmlspecialchars($value); ?>
                            </span><br>
                        <?php endforeach; ?>
                    </div>
                        -->
                    <!-- Always show complete debug info -->
                     <!--
                    <?php if ($showDebug): ?>
                        <?php echo displayDebugInfo($GLOBALS['debug_info']); ?>
                    <?php endif; ?>
                    -->

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert-message alert-<?php echo $messageType; ?>">
                            <i class="fas fa-<?php 
                                        echo $messageType === 'success' ? 'check-circle' : 
                                            ($messageType === 'warning' ? 'info-circle' : 'exclamation-triangle'); 
                                    ?> me-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>

                    <form id="caseFolderForm" method="POST" action="" novalidate>
                        <!-- CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        
                        <!-- Case Information Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-folder-plus"></i>
                                Case Information
                            </div>
                            
                            <div class="row">
                                <div class="col-12 mb-4">
                                    <label for="caseid" class="form-label">
                                        <i class="fas fa-file-alt me-2"></i>Case ID
                                        <span class="required-indicator">*</span>
                                    </label>
                                    <div class="input-icon">
                                        <i class="fas fa-hashtag"></i>
                                        <input 
                                            type="text" 
                                            class="form-control" 
                                            id="caseid" 
                                            name="caseid"
                                            placeholder="Enter case ID (e.g., CASE-2024-001)"
                                            required
                                            maxlength="100"
                                            autocomplete="off"
                                            value="<?php echo isset($_POST['caseid']) && !$folderCreated ? htmlspecialchars($_POST['caseid']) : ''; ?>"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Section -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-create">
                                <i class="fas fa-folder-plus me-2"></i>
                                <span>Create Folder</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
