<?php
// includes/functions.php - SharePoint Functions
// author - Alex Nguyen

/**
 * Get Microsoft Graph API access token
 * @return string|false Access token or false on failure
 */
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
        'post_data' => array_merge($postData, ['client_secret' => '[HIDDEN]']),
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

/**
 * Find target SharePoint drive
 * @param string $accessToken
 * @return array|null Drive information or null on failure
 */
function getTargetDrive($accessToken) {
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
    curl_close($ch);
    
    $GLOBALS['debug_info']['drives_discovery'] = [
        'url' => $drivesUrl,
        'http_code' => $httpCode,
        'response_length' => strlen($response)
    ];
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $drivesData = json_decode($response, true);
    if (!isset($drivesData['value'])) {
        return null;
    }
    
    $availableDrives = [];
    foreach ($drivesData['value'] as $drive) {
        $availableDrives[] = [
            'id' => $drive['id'],
            'name' => $drive['name'],
            'driveType' => $drive['driveType']
        ];
    }
    $GLOBALS['debug_info']['available_drives'] = $availableDrives;
    
    // Find target drive by name patterns
    foreach ($drivesData['value'] as $drive) {
        $driveName = strtolower($drive['name']);
        if (stripos($driveName, 'casesandincidentsfiles') !== false ||
            stripos($driveName, 'cases and incidents files') !== false ||
            (stripos($driveName, 'cases') !== false && stripos($driveName, 'incidents') !== false) ||
            (stripos($driveName, 'case') !== false && stripos($driveName, 'incident') !== false)) {
            $GLOBALS['debug_info']['target_drive_found'] = $drive;
            return $drive;
        }
    }
    
    // Fallback to first document library
    foreach ($drivesData['value'] as $drive) {
        if ($drive['driveType'] === 'documentLibrary') {
            $GLOBALS['debug_info']['fallback_drive_used'] = $drive;
            return $drive;
        }
    }
    
    return null;
}

/**
 * Create SharePoint folder
 * @param string $folderName
 * @param string $accessToken
 * @return array Result array with success status
 */
function createSharePointFolder($folderName, $accessToken) {
    $GLOBALS['debug_info']['api']['function_inputs'] = [
        'folder_name_received' => $folderName,
        'folder_name_type' => gettype($folderName),
        'folder_name_empty' => empty($folderName),
        'access_token_length' => $accessToken ? strlen($accessToken) : 0
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
    
    $drive = getTargetDrive($accessToken);
    if (!$drive) {
        $GLOBALS['debug_info']['errors'][] = "No suitable drive found";
        return [
            'success' => false,
            'httpCode' => 0,
            'response' => ['error' => ['message' => 'No suitable document library found']],
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
    $driveId = $drive['id'];
    $driveName = $drive['name'];
    
    // Check if folder exists
    $checkUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$folderName}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $checkUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $GLOBALS['debug_info']['folder_existence_check'] = [
        'check_url' => $checkUrl,
        'http_code' => $httpCode,
        'folder_name' => $folderName
    ];
    
    if ($httpCode === 200) {
        // Folder exists
        $GLOBALS['debug_info']['folder_already_exists'] = true;
        return [
            'success' => true,
            'already_exists' => true,
            'httpCode' => 200,
            'response' => ['message' => 'Folder already exists'],
            'location' => $driveName,
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
    // Create folder
    $createUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/root/children";
    $folderData = [
        'name' => $folderName,
        'folder' => new stdClass(),
        '@microsoft.graph.conflictBehavior' => 'fail'
    ];
    
    $GLOBALS['debug_info']['api']['folder_creation'] = [
        'endpoint' => $createUrl,
        'drive_name' => $driveName,
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
    
    if (($httpCode === 201 || $httpCode === 200) && !$curlError) {
        $responseData = json_decode($response, true);
        return [
            'success' => true,
            'already_exists' => false,
            'httpCode' => $httpCode,
            'response' => $responseData,
            'location' => $driveName,
            'debug_info' => $GLOBALS['debug_info']
        ];
    }
    
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


/**
 * Create upload session for direct upload (FIXED VERSION)
 * @param string $folderName
 * @param string $fileName
 * @param string $accessToken
 * @return array Result with upload URL or error
 */
function createUploadSession($folderName, $fileName, $accessToken) {
    $drive = getTargetDrive($accessToken);
    if (!$drive) {
        return ['success' => false, 'error' => 'No suitable drive found'];
    }
    
    $driveId = $drive['id'];
    
    // Sanitize filename for SharePoint - FIXED to handle array return
    $sanitizationResult = sanitizeFileName($fileName);
    $sanitizedFileName = $sanitizationResult['sanitized']; // Extract the sanitized filename from array
    
    $uploadSessionUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$folderName}/{$sanitizedFileName}:/createUploadSession";
    
    $sessionData = [
        'item' => [
            '@microsoft.graph.conflictBehavior' => 'replace'
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadSessionUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sessionData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        $sessionInfo = json_decode($response, true);
        return [
            'success' => true,
            'upload_url' => $sessionInfo['uploadUrl'],
            'expiration' => $sessionInfo['expirationDateTime'],
            'drive_name' => $drive['name'],
            'sanitized_filename' => $sanitizedFileName,
            'original_filename' => $fileName,
            'filename_changed' => $sanitizationResult['changes_made']
        ];
    }
    
    $responseData = json_decode($response, true);
    return [
        'success' => false,
        'error' => $responseData['error']['message'] ?? 'Failed to create upload session',
        'http_code' => $httpCode
    ];
}

/**
 * Sanitize filename for SharePoint compatibility with best practices
 * @param string $fileName Original filename
 * @param bool $preserveSpaces Whether to preserve spaces (default: false, converts to underscores)
 * @return array Array with 'sanitized' filename and 'changes_made' flag
 */
function sanitizeFileName($fileName, $preserveSpaces = false) {
    $originalFileName = $fileName;
    $changesMade = false;
    
    // Extract file extension first
    $pathInfo = pathinfo($fileName);
    $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
    $nameWithoutExt = $pathInfo['filename'] ?? $fileName;
    
    // Step 1: Handle spaces
    if (!$preserveSpaces && strpos($nameWithoutExt, ' ') !== false) {
        $nameWithoutExt = str_replace(' ', '_', $nameWithoutExt);
        $changesMade = true;
    }
    
    // Step 2: Remove/replace SharePoint problematic characters
    // SharePoint doesn't allow: ~ " # % & * : < > ? / \ { | }
    // Also problematic: leading/trailing dots and spaces
    $problematicChars = [
        '~' => '-',
        '"' => '',
        '#' => '',
        '%' => '',
        '&' => 'and',
        '*' => '',
        ':' => '-',
        '<' => '',
        '>' => '',
        '?' => '',
        '/' => '-',
        '\\' => '-',
        '{' => '',
        '}' => '',
        '|' => '-'
    ];
    
    $originalName = $nameWithoutExt;
    foreach ($problematicChars as $char => $replacement) {
        if (strpos($nameWithoutExt, $char) !== false) {
            $nameWithoutExt = str_replace($char, $replacement, $nameWithoutExt);
            $changesMade = true;
        }
    }
    
    // Step 3: Remove multiple consecutive underscores/hyphens
    if (preg_match('/[_-]{2,}/', $nameWithoutExt)) {
        $nameWithoutExt = preg_replace('/[_-]+/', '_', $nameWithoutExt);
        $changesMade = true;
    }
    
    // Step 4: Trim leading/trailing dots, spaces, underscores, hyphens
    $trimmed = trim($nameWithoutExt, ' .-_');
    if ($trimmed !== $nameWithoutExt) {
        $nameWithoutExt = $trimmed;
        $changesMade = true;
    }
    
    // Step 5: Handle reserved Windows names
    $reservedNames = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'
    ];
    
    if (in_array(strtoupper($nameWithoutExt), $reservedNames)) {
        $nameWithoutExt = $nameWithoutExt . '_file';
        $changesMade = true;
    }
    
    // Step 6: Ensure filename isn't empty
    if (empty($nameWithoutExt)) {
        $nameWithoutExt = 'file_' . time();
        $changesMade = true;
    }
    
    // Step 7: Limit length (SharePoint has 255 char limit for full path)
    $maxNameLength = 100; // Conservative limit for filename part
    if (strlen($nameWithoutExt) > $maxNameLength) {
        $nameWithoutExt = substr($nameWithoutExt, 0, $maxNameLength);
        $nameWithoutExt = rtrim($nameWithoutExt, '.-_ ');
        $changesMade = true;
    }
    
    $sanitizedFileName = $nameWithoutExt . $extension;
    
    return [
        'sanitized' => $sanitizedFileName,
        'original' => $originalFileName,
        'changes_made' => $changesMade,
        'safe_for_sharepoint' => true
    ];
}

/**
 * URL encode filename for API calls while preserving readability
 * @param string $fileName Sanitized filename
 * @return string URL-encoded filename
 */
function urlEncodeFileName($fileName) {
    // Only encode characters that are problematic in URLs
    // Keep letters, numbers, hyphens, underscores, dots, and parentheses
    return preg_replace_callback('/[^a-zA-Z0-9._()-]/', function($matches) {
        return rawurlencode($matches[0]);
    }, $fileName);
}

/**
 * Enhanced function that replaces the original createUploadSession
 * @param string $folderName
 * @param string $fileName
 * @param string $accessToken
 * @return array Result with upload URL or error
 */
function createUploadSessionEnhanced($folderName, $fileName, $accessToken) {
    $drive = getTargetDrive($accessToken);
    if (!$drive) {
        return ['success' => false, 'error' => 'No suitable drive found'];
    }
    
    $driveId = $drive['id'];
    
    // Sanitize filename with detailed feedback
    $fileNameResult = sanitizeFileName($fileName);
    $sanitizedFileName = $fileNameResult['sanitized'];
    $urlEncodedFileName = urlEncodeFileName($sanitizedFileName);
    
    // Log filename changes for debugging
    $GLOBALS['debug_info']['filename_processing'] = [
        'original' => $fileName,
        'sanitized' => $sanitizedFileName,
        'url_encoded' => $urlEncodedFileName,
        'changes_made' => $fileNameResult['changes_made']
    ];
    
    $uploadSessionUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/root:/{$folderName}/{$urlEncodedFileName}:/createUploadSession";
    
    $sessionData = [
        'item' => [
            '@microsoft.graph.conflictBehavior' => 'replace'
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadSessionUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sessionData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Enhanced debugging for upload session creation
    $GLOBALS['debug_info']['upload_session'] = [
        'url' => $uploadSessionUrl,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
        'response_length' => strlen($response)
    ];
    
    if ($httpCode === 200 || $httpCode === 201) {
        $sessionInfo = json_decode($response, true);
        return [
            'success' => true,
            'upload_url' => $sessionInfo['uploadUrl'],
            'expiration' => $sessionInfo['expirationDateTime'],
            'drive_name' => $drive['name'],
            'original_filename' => $fileName,
            'sanitized_filename' => $sanitizedFileName,
            'filename_changed' => $fileNameResult['changes_made']
        ];
    }
    
    $responseData = json_decode($response, true);
    return [
        'success' => false,
        'error' => $responseData['error']['message'] ?? 'Failed to create upload session',
        'http_code' => $httpCode,
        'debug_info' => $GLOBALS['debug_info']
    ];
}

/**
 * Batch sanitize multiple filenames and return summary
 * @param array $fileNames Array of filenames
 * @return array Summary of sanitization results
 */
function batchSanitizeFileNames($fileNames) {
    $results = [
        'files' => [],
        'total_files' => count($fileNames),
        'files_changed' => 0,
        'potential_conflicts' => []
    ];
    
    $sanitizedNames = [];
    
    foreach ($fileNames as $fileName) {
        $result = sanitizeFileName($fileName);
        $results['files'][] = $result;
        
        if ($result['changes_made']) {
            $results['files_changed']++;
        }
        
        // Check for potential conflicts (same sanitized name)
        if (in_array($result['sanitized'], $sanitizedNames)) {
            $results['potential_conflicts'][] = $result['sanitized'];
        } else {
            $sanitizedNames[] = $result['sanitized'];
        }
    }
    
    return $results;
}

/**
 * Generate user-friendly message about filename changes
 * @param array $sanitizationResults Results from batchSanitizeFileNames
 * @return string HTML message for user
 */
function generateFileNameChangeMessage($sanitizationResults) {
    if ($sanitizationResults['files_changed'] === 0) {
        return '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>All filenames are SharePoint compatible.</div>';
    }
    
    $message = '<div class="alert alert-warning">';
    $message .= '<i class="fas fa-info-circle me-2"></i>';
    $message .= '<strong>Filename Changes:</strong> ';
    $message .= $sanitizationResults['files_changed'] . ' of ' . $sanitizationResults['total_files'] . ' files will be renamed for SharePoint compatibility.<br>';
    
    $message .= '<small class="mt-2 d-block"><strong>Changes made:</strong><ul class="mb-0">';
    foreach ($sanitizationResults['files'] as $file) {
        if ($file['changes_made']) {
            $message .= '<li>' . htmlspecialchars($file['original']) . ' → ' . htmlspecialchars($file['sanitized']) . '</li>';
        }
    }
    $message .= '</ul></small>';
    
    if (!empty($sanitizationResults['potential_conflicts'])) {
        $message .= '<div class="text-danger mt-2"><strong>Warning:</strong> Some files may have the same name after sanitization: ' . implode(', ', $sanitizationResults['potential_conflicts']) . '</div>';
    }
    
    $message .= '</div>';
    return $message;
}

/**
 * Display debug information in HTML format
 * @param array $debug_info
 * @return string HTML output
 */
function displayDebugInfo($debug_info) {
    $output = "<div class='debug-section'>";
    $output .= "<h4><i class='fas fa-bug'></i> Debug Information</h4>";
    
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
        $output .= "<h5>API Debug:</h5>";
        $output .= "<pre>" . htmlspecialchars(json_encode($debug_info['api'], JSON_PRETTY_PRINT)) . "</pre>";
        $output .= "</div>";
    }
    
    // Errors
    if (!empty($debug_info['errors'])) {
        $output .= "<div class='debug-subsection'>";
        $output .= "<h5 class='text-danger'>Errors:</h5>";
        foreach ($debug_info['errors'] as $error) {
            $output .= "<div class='text-danger'>• " . htmlspecialchars($error) . "</div>";
        }
        $output .= "</div>";
    }
    
    $output .= "</div>";
    return $output;
}
