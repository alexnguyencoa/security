<?php
// php_config_test.php - Test script to check if .htaccess is working
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Configuration Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .config-good { color: #28a745; font-weight: bold; }
        .config-bad { color: #dc3545; font-weight: bold; }
        .config-warning { color: #ffc107; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-cog"></i> PHP Configuration Test</h3>
                        <p class="mb-0">Check if your .htaccess file is working</p>
                    </div>
                    <div class="card-body">
                        <?php
                        // Function to parse size strings to bytes
                        function parseSize($size) {
                            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
                            $size = preg_replace('/[^0-9\.]/', '', $size);
                            if ($unit) {
                                return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
                            } else {
                                return round($size);
                            }
                        }

                        // Get current settings
                        $settings = [
                            'upload_max_filesize' => ini_get('upload_max_filesize'),
                            'post_max_size' => ini_get('post_max_size'),
                            'max_file_uploads' => ini_get('max_file_uploads'),
                            'memory_limit' => ini_get('memory_limit'),
                            'max_execution_time' => ini_get('max_execution_time'),
                            'file_uploads' => ini_get('file_uploads')
                        ];

                        // Required minimums
                        $requirements = [
                            'upload_max_filesize' => '50M',
                            'post_max_size' => '250M',
                            'max_file_uploads' => '5',
                            'memory_limit' => '256M',
                            'max_execution_time' => '300',
                            'file_uploads' => '1'
                        ];

                        echo "<table class='table table-striped'>";
                        echo "<thead><tr><th>Setting</th><th>Current Value</th><th>Required</th><th>Status</th></tr></thead>";
                        echo "<tbody>";

                        $allGood = true;

                        foreach ($settings as $setting => $current) {
                            $required = $requirements[$setting];
                            $status = '';
                            $statusClass = '';

                            if ($setting === 'file_uploads') {
                                $good = ($current == '1');
                                $status = $good ? 'OK' : 'DISABLED';
                                $statusClass = $good ? 'config-good' : 'config-bad';
                            } elseif (in_array($setting, ['upload_max_filesize', 'post_max_size', 'memory_limit'])) {
                                $currentBytes = parseSize($current);
                                $requiredBytes = parseSize($required);
                                $good = ($currentBytes >= $requiredBytes);
                                $status = $good ? 'OK' : 'TOO LOW';
                                $statusClass = $good ? 'config-good' : 'config-bad';
                            } else {
                                $good = (intval($current) >= intval($required));
                                $status = $good ? 'OK' : 'TOO LOW';
                                $statusClass = $good ? 'config-good' : 'config-bad';
                            }

                            if (!$good) $allGood = false;

                            echo "<tr>";
                            echo "<td><strong>{$setting}</strong></td>";
                            echo "<td>{$current}</td>";
                            echo "<td>{$required}</td>";
                            echo "<td class='{$statusClass}'>{$status}</td>";
                            echo "</tr>";
                        }

                        echo "</tbody></table>";

                        // Overall status
                        if ($allGood) {
                            echo "<div class='alert alert-success'>";
                            echo "<h5><i class='fas fa-check-circle'></i> Configuration OK!</h5>";
                            echo "Your PHP configuration meets the requirements for file uploads up to 50MB.";
                            echo "</div>";
                        } else {
                            echo "<div class='alert alert-danger'>";
                            echo "<h5><i class='fas fa-exclamation-triangle'></i> Configuration Issues Found</h5>";
                            echo "<p>Your .htaccess file is not working or doesn't exist. Here's what to do:</p>";
                            echo "<ol>";
                            echo "<li><strong>Create .htaccess file:</strong> Save the provided .htaccess content as a file named exactly <code>.htaccess</code> (with the dot)</li>";
                            echo "<li><strong>Upload to web directory:</strong> Place it in the same folder as your PHP script</li>";
                            echo "<li><strong>Check file permissions:</strong> Make sure the .htaccess file is readable (644 permissions)</li>";
                            echo "<li><strong>Contact hosting provider:</strong> If .htaccess doesn't work, ask them to increase upload limits</li>";
                            echo "</ol>";
                            echo "</div>";
                        }

                        // Additional info
                        echo "<div class='alert alert-info'>";
                        echo "<h6>Additional Information:</h6>";
                        echo "<strong>Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
                        echo "<strong>PHP Version:</strong> " . phpversion() . "<br>";
                        echo "<strong>Loaded Extensions:</strong> " . (extension_loaded('curl') ? 'cURL ✓' : 'cURL ✗') . "<br>";
                        echo "<strong>Current Directory:</strong> " . getcwd() . "<br>";
                        echo "<strong>.htaccess exists:</strong> " . (file_exists('.htaccess') ? 'Yes ✓' : 'No ✗') . "<br>";
                        if (file_exists('.htaccess')) {
                            echo "<strong>.htaccess readable:</strong> " . (is_readable('.htaccess') ? 'Yes ✓' : 'No ✗') . "<br>";
                            echo "<strong>.htaccess size:</strong> " . filesize('.htaccess') . " bytes<br>";
                        }
                        echo "</div>";
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
