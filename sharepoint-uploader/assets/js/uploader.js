// Enhanced uploader.js with filename sanitization preview
// author - Alex Nguyen

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
 * Client-side filename sanitization (mirrors PHP logic)
 * @param {string} fileName - Original filename
 * @returns {Object} Sanitization result
 */
function sanitizeFileNameJS(fileName) {
    const originalFileName = fileName;
    let changesMade = false;
    
    // Extract extension
    const lastDotIndex = fileName.lastIndexOf('.');
    let extension = '';
    let nameWithoutExt = fileName;
    
    if (lastDotIndex > 0) {
        extension = fileName.substring(lastDotIndex);
        nameWithoutExt = fileName.substring(0, lastDotIndex);
    }
    
    // Handle spaces - convert to underscores
    if (nameWithoutExt.includes(' ')) {
        nameWithoutExt = nameWithoutExt.replace(/ /g, '_');
        changesMade = true;
    }
    
    // Replace problematic characters
    const problematicChars = {
        '~': '-', '"': '', '#': '', '%': '', '&': 'and', '*': '',
        ':': '-', '<': '', '>': '', '?': '', '/': '-', '\\': '-',
        '{': '', '}': '', '|': '-'
    };
    
    for (const [char, replacement] of Object.entries(problematicChars)) {
        if (nameWithoutExt.includes(char)) {
            nameWithoutExt = nameWithoutExt.replace(new RegExp('\\' + char, 'g'), replacement);
            changesMade = true;
        }
    }
    
    // Remove multiple consecutive underscores/hyphens
    if (/[_-]{2,}/.test(nameWithoutExt)) {
        nameWithoutExt = nameWithoutExt.replace(/[_-]+/g, '_');
        changesMade = true;
    }
    
    // Trim problematic characters from edges
    const trimmed = nameWithoutExt.replace(/^[ .\-_]+|[ .\-_]+$/g, '');
    if (trimmed !== nameWithoutExt) {
        nameWithoutExt = trimmed;
        changesMade = true;
    }
    
    // Check reserved names
    const reservedNames = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'
    ];
    
    if (reservedNames.includes(nameWithoutExt.toUpperCase())) {
        nameWithoutExt = nameWithoutExt + '_file';
        changesMade = true;
    }
    
    // Ensure not empty
    if (!nameWithoutExt) {
        nameWithoutExt = 'file_' + Date.now();
        changesMade = true;
    }
    
    // Limit length
    if (nameWithoutExt.length > 100) {
        nameWithoutExt = nameWithoutExt.substring(0, 100).replace(/[ .\-_]+$/, '');
        changesMade = true;
    }
    
    const sanitizedFileName = nameWithoutExt + extension;
    
    return {
        original: originalFileName,
        sanitized: sanitizedFileName,
        changes_made: changesMade,
        name_without_ext: nameWithoutExt,
        extension: extension
    };
}

/**
 * Enhanced file validation with filename sanitization preview
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
    
    // Process filename sanitization for all files
    const fileProcessingResults = [];
    const sanitizedNames = new Set();
    let duplicateNames = [];
    
    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const sanitizationResult = sanitizeFileNameJS(file.name);
        fileProcessingResults.push({
            file: file,
            sanitization: sanitizationResult
        });
        
        // Check for duplicates after sanitization
        if (sanitizedNames.has(sanitizationResult.sanitized.toLowerCase())) {
            duplicateNames.push(sanitizationResult.sanitized);
        } else {
            sanitizedNames.add(sanitizationResult.sanitized.toLowerCase());
        }
    }
    
    // Show info about direct upload
    warningMessages.push('ℹ️ Using direct upload - no server file size limits!');
    
    // Show filename changes warning if any
    const changedFiles = fileProcessingResults.filter(result => result.sanitization.changes_made);
    if (changedFiles.length > 0) {
        warningMessages.push(`📝 ${changedFiles.length} filename(s) will be modified for SharePoint compatibility`);
    }
    
    // Show duplicate warning
    if (duplicateNames.length > 0) {
        errorMessages.push(`⚠️ Duplicate filenames after sanitization: ${duplicateNames.join(', ')}`);
        isValid = false;
    }
    
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
    for (let result of fileProcessingResults) {
        const file = result.file;
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
    displayValidationFeedback(feedbackDiv, selectedFilesDiv, fileProcessingResults, isValid, errorMessages, warningMessages, totalSize);
    
    // Set custom validity
    if (!isValid) {
        input.setCustomValidity('Please fix the file selection errors.');
    } else {
        input.setCustomValidity('');
    }
}

/**
 * Enhanced validation feedback display
 */
function displayValidationFeedback(feedbackDiv, selectedFilesDiv, fileProcessingResults, isValid, errorMessages, warningMessages, totalSize) {
    let feedbackHtml = '';
    
    if (warningMessages.length > 0) {
        feedbackHtml += `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Upload Information:</strong>
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
        // Show selected files with filename changes
        selectedFilesDiv.innerHTML = generateSelectedFilesHtml(fileProcessingResults);
        
        feedbackHtml += `
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                ${fileProcessingResults.length} file(s) ready for direct upload (${(totalSize / 1024 / 1024).toFixed(1)}MB total).
            </div>
        `;
    }
    
    feedbackDiv.innerHTML = feedbackHtml;
}

/**
 * Enhanced selected files display with filename changes
 */
function generateSelectedFilesHtml(fileProcessingResults) {
    if (fileProcessingResults.length === 0) return '';
    
    let filesHtml = '<div class="selected-files-container">';
    filesHtml += '<h6 class="text-success"><i class="fas fa-check-circle me-2"></i>Selected Files:</h6>';
    
    // Show filename changes summary if any
    const changedFiles = fileProcessingResults.filter(result => result.sanitization.changes_made);
    if (changedFiles.length > 0) {
        filesHtml += `
            <div class="alert alert-warning py-2 mb-3">
                <small><i class="fas fa-edit me-1"></i>
                <strong>Filename Changes:</strong> ${changedFiles.length} file(s) will be renamed for SharePoint compatibility.
                </small>
            </div>
        `;
    }
    
    filesHtml += '<div class="row">';
    
    for (let result of fileProcessingResults) {
        const file = result.file;
        const sanitization = result.sanitization;
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(1);
        const fileExtension = file.name.split('.').pop().toLowerCase();
        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'].includes(fileExtension);
        const icon = isImage ? 'fa-file-image' : 'fa-file-video';
        
        const borderClass = sanitization.changes_made ? 'border-warning' : 'border';
        const bgClass = sanitization.changes_made ? 'bg-warning bg-opacity-10' : '';
        
        filesHtml += `
            <div class="col-md-6 mb-2">
                <div class="file-item p-2 ${borderClass} ${bgClass} rounded">
                    <div class="d-flex align-items-start">
                        <i class="fas ${icon} me-2 text-primary mt-1 flex-shrink-0"></i>
                        <div class="flex-grow-1 min-width-0">
        `;
        
        if (sanitization.changes_made) {
            filesHtml += `
                            <div class="text-decoration-line-through text-muted small">${sanitization.original}</div>
                            <div class="fw-bold text-warning">→ ${sanitization.sanitized}</div>
                            <small class="text-muted">${fileSizeMB} MB • <span class="text-warning">renamed</span></small>
            `;
        } else {
            filesHtml += `
                            <div class="fw-bold">${sanitization.original}</div>
                            <small class="text-muted">${fileSizeMB} MB</small>
            `;
        }
        
        filesHtml += `
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    filesHtml += '</div></div>';
    return filesHtml;
}

/**
 * SharePoint Direct Upload Class (Enhanced)
 */
class SharePointDirectUpload {
    constructor() {
        this.uploadProgress = new Map();
    }

    /**
     * Upload files to SharePoint with enhanced filename handling
     */
    async uploadFiles(caseId, files) {
        const results = {
            success: [],
            failed: [],
            errors: [],
            filename_changes: []
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
            const sanitization = sanitizeFileNameJS(file.name);
            
            if (sanitization.changes_made) {
                results.filename_changes.push({
                    original: sanitization.original,
                    sanitized: sanitization.sanitized
                });
                this.addStatusMessage(
                    `File renamed: "${sanitization.original}" → "${sanitization.sanitized}"`, 
                    'info'
                );
            }
            
            this.updateProgress(file.name, 0, 'Getting upload URL...');

            try {
                const success = await this.uploadSingleFile(caseId, file, sanitization.sanitized);
                if (success) {
                    results.success.push({
                        original_name: file.name,
                        final_name: sanitization.sanitized,
                        size: file.size
                    });
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
     * Upload a single file with custom sanitized name
     */
    async uploadSingleFile(folderName, file, sanitizedFileName = null) {
        const finalFileName = sanitizedFileName || file.name;
        
        // Get upload URL from PHP API
        const urlResponse = await fetch('includes/direct_upload_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'get_upload_url',
                folderName: folderName,
                fileName: finalFileName
            })
        });

        const urlData = await urlResponse.json();
        if (!urlResponse.ok || !urlData.success) {
            throw new Error(urlData.error || 'Failed to get upload URL');
        }

        // Upload file directly to SharePoint
        return await this.uploadToSharePoint(file, urlData.upload_url);
    }

    // ... rest of the SharePoint upload methods remain the same ...
    
    /**
     * Create folder in SharePoint
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
     * Upload file to SharePoint using resumable upload
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

            if (i === totalChunks - 1) {
                return true;
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
 * Enhanced form submission handler
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
            buttonText.textContent = 'Create Folder & Upload Files';
        }
    });
}

/**
 * Enhanced upload results display
 */
function displayUploadResults(results, caseId) {
    let message = '';
    let messageType = '';
    
    if (results.success.length > 0 && results.failed.length === 0) {
        message = `All ${results.success.length} files uploaded successfully to folder '${caseId}'!<br>`;
        
        if (results.filename_changes.length > 0) {
            message += `<br><strong>Filename Changes:</strong><br>`;
            for (let change of results.filename_changes) {
                message += `• "${change.original}" → "${change.sanitized}"<br>`;
            }
        }
        
        messageType = 'success';
    } else if (results.success.length > 0) {
        message = `${results.success.length} files uploaded, ${results.failed.length} failed.<br>`;
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
