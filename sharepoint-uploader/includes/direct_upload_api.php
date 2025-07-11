<?php
// includes/direct_upload_api.php - API endpoint for direct uploads

require_once '../config/sharepoint_config.php';
require_once 'functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Handle the API requests
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    echo json_encode(['error' => 'No action specified']);
    exit;
}

$accessToken = getAccessToken();
if (!$accessToken) {
    echo json_encode(['error' => 'Authentication failed - check your SharePoint configuration']);
    exit;
}

switch ($input['action']) {
    case 'create_folder':
        $folderName = trim($input['folderName'] ?? '');
        if (empty($folderName)) {
            echo json_encode(['error' => 'Folder name required']);
            exit;
        }
        
        // Sanitize folder name
        $folderName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $folderName);
        
        $result = createSharePointFolder($folderName, $accessToken);
        echo json_encode($result);
        break;
        
    case 'get_upload_url':
        $folderName = trim($input['folderName'] ?? '');
        $fileName = trim($input['fileName'] ?? '');
        
        if (empty($folderName) || empty($fileName)) {
            echo json_encode(['error' => 'Folder name and file name required']);
            exit;
        }
        
        // Sanitize folder name
        $folderName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $folderName);
        
        $result = createUploadSession($folderName, $fileName, $accessToken);
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['error' => 'Unknown action: ' . $input['action']]);
}
?>
