<?php
// index.php - Main SharePoint Uploader Page

require_once 'config/sharepoint_config.php';
require_once 'includes/functions.php';

// Initialize variables
$message = '';
$messageType = '';
$folderCreated = false;
$showDebug = false; // Set to true to show debug info

// Handle legacy form submission (fallback method)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['caseid']) && isset($_POST['use_legacy'])) {
    // CSRF token verification
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Security token mismatch. Please try again.';
        $messageType = 'error';
        $GLOBALS['debug_info']['errors'][] = 'CSRF token mismatch';
    } else {
        $caseId = trim($_POST['caseid']);
        
        if (empty($caseId)) {
            $message = 'Case ID is required.';
            $messageType = 'error';
        } else {
            // Sanitize folder name
            $folderName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $caseId);
            
            // Get access token
            $accessToken = getAccessToken();
            
            if ($accessToken) {
                $result = createSharePointFolder($folderName, $accessToken);
                
                if ($result['success']) {
                    $location = isset($result['location']) ? " in " . $result['location'] : "";
                    
                    if (isset($result['already_exists']) && $result['already_exists']) {
                        $message = "Folder '{$folderName}' already exists{$location}.";
                        $messageType = 'warning';
                    } else {
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
    <title>SharePoint Case Media Uploader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/uploader.css" rel="stylesheet">
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
                        <h1 class="form-title">APL Security</h1>
                        <p class="form-subtitle">SharePoint Media Uploader</p>
                    </div>
                </div>

                <!-- Form Content -->
                <div class="form-content">
                    <!-- Debug Info (only shown if enabled) -->
                    <?php if ($showDebug): ?>
                        <?php echo displayDebugInfo($GLOBALS['debug_info']); ?>
                    <?php endif; ?>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert-message alert-<?php echo $messageType; ?>">
                            <i class="fas fa-<?php 
                                        echo $messageType === 'success' ? 'check-circle' : 
                                            ($messageType === 'warning' ? 'info-circle' : 'exclamation-triangle'); 
                                    ?> me-2"></i>
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <form id="caseFolderForm" method="POST" action="" enctype="multipart/form-data" novalidate>
                        <!-- CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
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
                                            placeholder="Enter case ID (e.g., 2025-001)"
                                            required
                                            maxlength="100"
                                            autocomplete="off"
                                            value="<?php echo isset($_POST['caseid']) && !$folderCreated ? htmlspecialchars($_POST['caseid']) : ''; ?>"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                       

                        <!-- File Upload Section -->
                        <div class="form-section">
                            <div class="section-title">
                                <i class="fas fa-cloud-upload-alt"></i>
                                File Upload
                            </div>
                            
                            <div class="row">
                                <div class="col-12 mb-4">
                                    <label for="case_files" class="form-label">
                                        <i class="fas fa-file-image me-2"></i>Case Files (Images/Videos)
                                        <span class="required-indicator">*</span>
                                    </label>
                                    <input 
                                        type="file" 
                                        class="form-control" 
                                        id="case_files" 
                                        name="case_files[]"
                                        accept=".jpg,.jpeg,.png,.gif,.bmp,.tiff,.webp,.mp4,.avi,.mov,.wmv,.flv,.webm,.mkv"
                                        multiple
                                        required
                                        onchange="validateFiles(this)"
                                    >
                                    <div class="form-text text-muted mt-2">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Select 1-<?php echo MAX_FILES; ?> image or video files. Maximum <?php echo MAX_FILE_SIZE_MB; ?>MB per file.
                                        <br><strong>Supported formats:</strong> JPG, PNG, GIF, BMP, TIFF, WebP, MP4, AVI, MOV, WMV, FLV, WebM, MKV
                                    </div>
                                    <div id="file-validation-feedback" class="mt-2"></div>
                                    <div id="selected-files" class="mt-3"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Section -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-create">
                                <i class="fas fa-folder-plus me-2"></i>
                                <span>Create Folder & Upload Files</span>
                            </button>
                        </div>

                        <!-- Legacy Upload Option (hidden by default) -->
                        <div class="mt-3 text-center" style="display: none;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="use_legacy" name="use_legacy">
                                <label class="form-check-label text-muted small" for="use_legacy">
                                    Use legacy upload method (folder only, no files)
                                </label>
                            </div>
                        </div>

                        <!-- Footer Information -->
                        <div class="mt-4 pt-3 border-top">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Files are uploaded to the 
                                        <a href="https://cityofaustin.sharepoint.com/sites/APLIncidentNotifications/CasesandIncidentsFiles/Forms/AllItems.aspx" 
                                        target="_blank" 
                                        class="text-decoration-none">
                                            APL Notifications SharePoint site
                                            <i class="fas fa-external-link-alt ms-1" style="font-size: 0.7em;"></i>
                                        </a>
                                    </small>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <small class="text-muted">
                                        <i class="fas fa-shield-alt me-1"></i>
                                        Secure Direct Upload
                                    </small>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/uploader.js"></script>
</body>
</html>
