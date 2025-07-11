// assets/js/uploader.js - SharePoint Direct Upload

/**
 * Configuration and constants
 */
const UPLOAD_CONFIG = {
    maxFiles: 5,
    maxSizeApp: 50 * 1024 * 1024, // 50MB
    allowedExtensions: ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'],
    chunkSize: 320 * 1024 * 4 // 1.28MB chunks for SharePoint
};

/**
 * File validation function
 * @param {HTMLInputElement} input - File input element
 */
function validateFiles(input) {
    const files = input.files;
    const feedbackDiv = document.getElementById('file-validation-feedback');
    const selectedFilesDiv = document.getElementById('selected-files');
    
    // Clear previous feedback
    feedbackDiv.innerHTML = '';
    selectedFilesDiv.innerHTML = '';
    
    let isValid = true;
    let errorMessages = [];
    let warningMessages = [];
    
    // Show info about direct upload
    warningMessages.push('ℹ️ Using direct upload - no server file size limits!');
    
    // Check number of files
    if (files.length === 0) {
        errorMessages.push('At least 1 file is required.');
        isValid = false;
    } else if (files.length > UPLOAD_CONFIG.maxFiles) {
        errorMessages.push(`Maximum ${UPLOAD_CONFIG.maxFiles} files allowed. You selected ${files.length} files.`);
        isValid = false;
    }
    
    let totalSize = 0;
    
    // Validate each file
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const fileName = file.name;
        const fileSize = file.size;
        const fileSizeMB = Math.round(fileSize / 1024 / 1024 * 100) / 100;
        const fileExtension = fileName.split('.').pop().toLowerCase();
        
        totalSize += fileSize;
        
        // Check file extension
        if (!UPLOAD_CONFIG.allowedExtensions.includes(fileExtension)) {
            errorMessages.push(`"${fileName}" has an unsupported file type.`);
            isValid = false;
        }
        
        // Check against application limit
        if (fileSize > UPLOAD_CONFIG.maxSizeApp) {
            errorMessages.push(`"${fileName}" (${fileSizeMB}MB) exceeds application limit of 50MB.`);
            isValid = false;
        }
    }
    
    // Display feedback
    displayValidationFeedback(feedbackDiv, selectedFilesDiv, files, isValid, errorMessages, warningMessages, totalSize);
    
    // Set custom validity
    if (!isValid) {
        input.setCustomValidity('Please fix the file selection errors.');
    } else {
        input.setCustomValidity('');
    }
}

/**
 * Display validation feedback
 */
function displayValidationFeedback(feedbackDiv, selectedFilesDiv, files, isValid, errorMessages, warningMessages, totalSize) {
    let feedbackHtml = '';
    
    if (warningMessages.length > 0) {
        feedbackHtml += `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Upload Method:</strong>
                <ul class="mb-0 mt-2">
                    ${warningMessages.map(msg => `<li>${msg}</li>`).join('')}
                </ul>
            </div>
        `;
    }
    
    if (!isValid) {
        feedbackHtml += `
            <div class="alert alert-danger">
                <i class="fas fa-times-circle me-2"></i>
                <strong>File Validation Errors:</strong>
                <ul class="mb-0 mt-2">
                    ${errorMessages.map(msg => `<li>${msg}</li>`).join('')}
                </ul>
            </div>
        `;
    } else {
        // Show selected files
        selectedFilesDiv.innerHTML = generateSelectedFilesHtml(files);
        
        feedbackHtml += `
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                ${files.length} file(s) ready for direct upload (${(totalSize / 1024 / 1024).toFixed(1)}MB total).
            </div>
        `;
    }
    
    feedbackDiv.innerHTML = feedbackHtml;
}

/**
 * Generate HTML for selected files display
 */
function generateSelectedFilesHtml(files) {
    let filesHtml = '<div class="selected-files-container"><h6 class="text-success"><i class="fas fa-check-circle me-2"></i>Selected Files:</h6><div class="row">';
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(1);
        const fileExtension = file.name.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'].includes(fileExtension);
        const icon = isImage ? 'fa-file-image' : 'fa-file-video';
        
        filesHtml += `
            <div class="col-md-6 mb-2">
                <div class="file-item p-2 border rounded">
                    <i class="fas ${icon} me-2 text-primary"></i>
                    <strong>${file.name}</strong>
                    <small class="text-muted d-block">${fileSizeMB} MB</small>
                </div>
            </div>
        `;
    }
    
    filesHtml += '</div></div>';
    return filesHtml;
}

/**
 * SharePoint Direct Upload Class
 */
class SharePointDirectUpload {
    constructor() {
        this.uploadProgress = new Map();
    }

    /**
     * Upload files to SharePoint
     * @param {string} caseId - Case ID for folder name
     * @param {File[]} files - Array of files to upload
     * @returns {Object} Upload results
     */
    async uploadFiles(caseId, files) {
        const results = {
            success: [],
            failed: [],
            errors: []
        };

        this.showProgressContainer();

        try {
            // Create the folder first
            const folderResult = await this.createFolder(caseId);
            if (!folderResult.success && !folderResult.already_exists) {
                throw new Error('Failed to create folder: ' + (folderResult.error || 'Unknown error'));
            }
            
            this.addStatusMessage(
                `Folder '${caseId}' ${folderResult.already_exists ? 'exists' : 'created'} in ${folderResult.drive_name}`, 
                'info'
            );
        } catch (error) {
            results.errors.push('Folder creation failed: ' + error.message);
            this.addStatusMessage('Folder creation failed: ' + error.message, 'error');
            return results;
        }

        // Upload each file
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            this.updateProgress(file.name, 0, 'Getting upload URL...');

            try {
                const success = await this.uploadSingleFile(caseId, file);
                if (success) {
                    results.success.push(file.name);
                    this.updateProgress(file.name, 100, 'Complete ✓');
                } else {
                    results.failed.push(file.name);
                    this.updateProgress(file.name, 0, 'Failed ✗');
                }
            } catch (error) {
                results.failed.push(file.name);
                results.errors.push(`${file.name}: ${error.message}`);
                this.updateProgress(file.name, 0, 'Error: ' + error.message);
            }
        }

        return results;
    }

    /**
     * Create folder in SharePoint
     * @param {string} folderName - Name of folder to create
     * @returns {Object} Folder creation result
     */
    async createFolder(folderName) {
        const response = await fetch('includes/direct_upload_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'create_folder',
                folderName: folderName
            })
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Network error');
        }
        return data;
    }

    /**
     * Upload a single file
     * @param {string} folderName - Target folder name
     * @param {File} file - File to upload
     * @returns {boolean} Success status
     */
    async uploadSingleFile(folderName, file) {
        // Get upload URL from PHP API
        const urlResponse = await fetch('includes/direct_upload_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'get_upload_url',
                folderName: folderName,
                fileName: file.name
            })
        });

        const urlData = await urlResponse.json();
        if (!urlResponse.ok || !urlData.success) {
            throw new Error(urlData.error || 'Failed to get upload URL');
        }

        // Upload file directly to SharePoint
        return await this.uploadToSharePoint(file, urlData.upload_url);
    }

    /**
     * Upload file to SharePoint using resumable upload
     * @param {File} file - File to upload
     * @param {string} uploadUrl - SharePoint upload URL
     * @returns {boolean} Success status
     */
    async uploadToSharePoint(file, uploadUrl) {
        // For small files (under 4MB), upload in one piece
        if (file.size <= 4 * 1024 * 1024) {
            this.updateProgress(file.name, 50, 'Uploading...');
            
            const response = await fetch(uploadUrl, {
                method: 'PUT',
                headers: {
                    'Content-Range': `bytes 0-${file.size - 1}/${file.size}`,
                    'Content-Length': file.size.toString()
                },
                body: file
            });

            if (!response.ok) {
                throw new Error(`Upload failed: ${response.status} ${response.statusText}`);
            }

            return true;
        }

        // For larger files, use chunked upload
        const totalChunks = Math.ceil(file.size / UPLOAD_CONFIG.chunkSize);

        for (let i = 0; i < totalChunks; i++) {
            const start = i * UPLOAD_CONFIG.chunkSize;
            const end = Math.min(start + UPLOAD_CONFIG.chunkSize, file.size);
            const chunk = file.slice(start, end);

            this.updateProgress(file.name, Math.round(((i + 1) / totalChunks) * 90), `Uploading chunk ${i + 1}/${totalChunks}...`);

            const response = await fetch(uploadUrl, {
                method: 'PUT',
                headers: {
                    'Content-Range': `bytes ${start}-${end - 1}/${file.size}`,
                    'Content-Length': chunk.size.toString()
                },
                body: chunk
            });

            if (!response.ok) {
                throw new Error(`Upload chunk ${i + 1} failed: ${response.status} ${response.statusText}`);
            }

            // For the last chunk, we should get the final response
            if (i === totalChunks - 1) {
                return true;
            } else {
                // Check if we need to continue
                try {
                    const responseData = await response.json();
                    if (!responseData.nextExpectedRanges) {
                        // Upload complete earlier than expected
                        return true;
                    }
                } catch (e) {
                    // Some responses might not be JSON, continue
                }
            }
        }

        return true;
    }

    /**
     * Show progress container
     */
    showProgressContainer() {
        const existingProgress = document.getElementById('upload-progress-container');
        if (existingProgress) {
            existingProgress.remove();
        }

        const progressHtml = `
            <div id="upload-progress-container" class="mt-3">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-upload me-2"></i>Upload Progress</h6>
                    </div>
                    <div class="card-body">
                        <div id="upload-status-messages"></div>
                        <div id="upload-progress-files"></div>
                    </div>
                </div>
            </div>
        `;
        
        document.querySelector('.form-content').insertAdjacentHTML('beforeend', progressHtml);
    }

    /**
     * Add status message
     * @param {string} message - Message to display
     * @param {string} type - Message type (info, success, error)
     */
    addStatusMessage(message, type) {
        const statusContainer = document.getElementById('upload-status-messages');
        if (!statusContainer) return;

        const alertClass = type === 'error' ? 'alert-danger' : 
                          type === 'success' ? 'alert-success' : 'alert-info';
        
        const messageHtml = `
            <div class="alert ${alertClass} alert-sm py-2 mb-2">
                <small>${message}</small>
            </div>
        `;
        
        statusContainer.insertAdjacentHTML('beforeend', messageHtml);
    }

    /**
     * Update progress for a file
     * @param {string} fileName - Name of file
     * @param {number} percent - Progress percentage
     * @param {string} status - Status message
     */
    updateProgress(fileName, percent, status) {
        this.uploadProgress.set(fileName, { percent, status });
        this.displayProgress();
    }

    /**
     * Display progress for all files
     */
    displayProgress() {
        const progressContainer = document.getElementById('upload-progress-files');
        if (!progressContainer) return;

        let html = '';
        for (let [fileName, progress] of this.uploadProgress) {
            const progressColor = progress.percent === 100 ? 'bg-success' : 
                                 progress.status.includes('Error') ? 'bg-danger' : 'bg-primary';
            
            html += `
                <div class="progress-item mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="fw-bold text-truncate" style="max-width: 70%;">${fileName}</span>
                        <span class="text-muted small">${progress.status}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar ${progressColor}" style="width: ${progress.percent}%"></div>
                    </div>
                </div>
            `;
        }
        progressContainer.innerHTML = html;
    }
}

/**
 * Handle form submission
 */
function initializeUploader() {
    const form = document.getElementById('caseFolderForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const caseId = document.getElementById('caseid').value.trim();
        const fileInput = document.getElementById('case_files');
        const files = Array.from(fileInput.files);
        
        if (!caseId) {
            alert('Please enter a case ID');
            return;
        }
        
        if (files.length === 0) {
            alert('Please select at least one file');
            return;
        }
        
        const submitButton = this.querySelector('button[type="submit"]');
        const buttonText = submitButton.querySelector('span');
        const buttonIcon = submitButton.querySelector('i');
        
        // Disable form
        submitButton.disabled = true;
        buttonIcon.className = 'fas fa-spinner fa-spin me-2';
        buttonText.textContent = 'Uploading...';
        
        try {
            const uploader = new SharePointDirectUpload();
            const results = await uploader.uploadFiles(caseId, files);
            
            // Show results
            displayUploadResults(results, caseId);
            
            // Clear form on complete success
            if (results.success.length > 0 && results.failed.length === 0) {
                this.reset();
                document.getElementById('file-validation-feedback').innerHTML = '';
                document.getElementById('selected-files').innerHTML = '';
                
                // Remove progress container after a delay
                setTimeout(() => {
                    const progressContainer = document.getElementById('upload-progress-container');
                    if (progressContainer) {
                        progressContainer.remove();
                    }
                }, 5000);
            }
            
        } catch (error) {
            console.error('Upload error:', error);
            showAlert(`Upload failed: ${error.message}`, 'error');
        } finally {
            // Re-enable form
            submitButton.disabled = false;
            buttonIcon.className = 'fas fa-folder-plus me-2';
            buttonText.textContent = 'Create Folder';
        }
    });
}

/**
 * Display upload results
 * @param {Object} results - Upload results
 * @param {string} caseId - Case ID
 */
function displayUploadResults(results, caseId) {
    let message = '';
    let messageType = '';
    
    if (results.success.length > 0 && results.failed.length === 0) {
        message = `All ${results.success.length} files uploaded successfully to folder '${caseId}'!<br>` +
                 `<strong>Files:</strong> ${results.success.join(', ')}`;
        messageType = 'success';
    } else if (results.success.length > 0) {
        message = `${results.success.length} files uploaded, ${results.failed.length} failed.<br>` +
                 `<strong>Success:</strong> ${results.success.join(', ')}<br>` +
                 `<strong>Failed:</strong> ${results.failed.join(', ')}`;
        if (results.errors.length > 0) {
            message += `<br><strong>Errors:</strong> ${results.errors.join('; ')}`;
        }
        messageType = 'warning';
    } else {
        message = `All uploads failed.<br>`;
        if (results.errors.length > 0) {
            message += `<strong>Errors:</strong> ${results.errors.join('; ')}`;
        }
        messageType = 'error';
    }
    
    showAlert(message, messageType);
}

/**
 * Show alert message
 * @param {string} message - Message to show
 * @param {string} type - Alert type
 */
function showAlert(message, type) {
    const alertHtml = `
        <div class="alert-message alert-${type}">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 
                              type === 'warning' ? 'info-circle' : 'exclamation-triangle'} me-2"></i>
            ${message}
        </div>
    `;
    
    const existingAlert = document.querySelector('.alert-message');
    if (existingAlert) {
        existingAlert.remove();
    }
    
    const formContent = document.querySelector('.form-content');
    formContent.insertAdjacentHTML('afterbegin', alertHtml);
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeUploader();
});
