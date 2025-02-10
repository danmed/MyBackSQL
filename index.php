<?php
// Load MySQL configuration
$configFile = __DIR__ . '/config/db_config.php';
if (!file_exists($configFile)) {
    // Create a default configuration file if it doesn't exist
    file_put_contents($configFile, "<?php\nreturn " . var_export([
        'host' => 'localhost',
        'username' => 'root',
        'password' => '',
    ], true) . ";\n?>");
}
$config = include($configFile);

// Use the dynamic configuration for backup and restore logic
$host = $config['host'];
$username = $config['username'];
$password = $config['password'];

$backupDir = __DIR__ . '/backup/';
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

// Helper functions
function getDatabases($host, $username, $password) {
    $conn = new mysqli($host, $username, $password);
    if ($conn->connect_error) return [];
    
    $result = $conn->query("SHOW DATABASES");
    $databases = [];
    
    while ($row = $result->fetch_assoc()) {
        $databases[] = $row['Database'];
    }
    
    $conn->close();
    
    return $databases;
}

function getBackupFiles() {
    global $backupDir;
    return array_map('basename', glob($backupDir . '*.sql'));
}

function extractDatabaseNameFromBackup($filename) {
    return explode('_', basename($filename))[0];
}

// Save new configuration if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $newConfig = [
        'host' => $_POST['host'],
        'username' => $_POST['username'],
        'password' => $_POST['password'],
    ];
    
    file_put_contents($configFile, "<?php\nreturn " . var_export($newConfig, true) . ";\n?>");
    
    echo json_encode(['status' => 'success', 'message' => 'Configuration saved successfully!']);
    exit;
}

// Handle Backup Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup') {
    $database = $_POST['database'];
    
    if (empty($database)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a database to backup.']);
        exit;
    }
    
    $filename = $backupDir . "{$database}_" . date('Y-m-d_H-i-s') . '.sql';
    
    exec("mysqldump -h {$host} -u {$username} -p{$password} {$database} > {$filename}", $output, $returnVar);
    
    if ($returnVar === 0) {
        echo json_encode(['status' => 'success', 'message' => "Backup created successfully! File: {$filename}"]);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => "Backup failed. Check your credentials or database name."]);
        exit;
    }
}

// Handle Restore Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore') {
    $databaseOption = $_POST['database_option'];
    $backupFile = $_POST['backup_file'];
    
    if ($databaseOption === 'new') {
        $targetDatabase = $_POST['new_db_name'];
        if (empty($targetDatabase)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a name for the new database.']);
            exit();
        }
    } else {
        $targetDatabase = $databaseOption;
    }
    
    if (empty($targetDatabase) || empty($backupFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Please select a target database and backup file to restore.']);
        exit();
    }
    
    // Create the target database if it doesn't exist
    if (!in_array($targetDatabase, getDatabases($host, $username, $password))) {
        exec("mysql -h {$host} -u {$username} -p{$password} -e \"CREATE DATABASE {$targetDatabase}\"", $output, $returnVar);
        
        if ($returnVar !== 0) {
            echo json_encode(['status' => 'error', 'message' => "Failed to create target database '$targetDatabase'."]);
            exit();
        }
    }
    
    $filePath = $backupDir . basename($backupFile);
    if (file_exists($filePath)) {
        exec("mysql -h {$host} -u {$username} -p{$password} {$targetDatabase} < {$filePath}", $output, $returnVar);
        if ($returnVar === 0) {
            echo json_encode(['status' => 'success', 'message' => "Database restored successfully to '$targetDatabase'."]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Restore failed. Check your credentials or backup file.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Selected backup file does not exist.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL Backup & Restore</title>
    <link rel="stylesheet" href="./assets/styles.css"/>
</head>
<body>
    <h1>MySQL Backup & Restore</h1>
    
    <!-- Configuration Form -->
    <form id="configForm" method="post">
        <h2>Configure MySQL Connection</h2>
        <input type="hidden" name="action" value="save_config">
        <label for="host">Host:</label>
        <input type="text" name="host" id="host" value="<?= htmlspecialchars($config['host']) ?>" required><br>
        <label for="username">Username:</label>
        <input type="text" name="username" id="username" value="<?= htmlspecialchars($config['username']) ?>" required><br>
        <label for="password">Password:</label>
        <input type="password" name="password" id="password" value="<?= htmlspecialchars($config['password']) ?>"><br>
        <button type="submit">Save Configuration</button>
    </form>

    <!-- Backup Form -->
    <form id="backupForm" method="post">
        <h2>Backup Database</h2>
        <input type="hidden" name="action" value="backup">
        <label for="backup_database">Select Database:</label>
        <select name="database" id="backup_database" required>
            <option value="">Select a database</option>
            <?php foreach (getDatabases($host, $username, $password) as $db): ?>
                <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
            <?php endforeach; ?>
        </select><br>
        <button type="submit">Backup</button>
    </form>

    <!-- Restore Form -->
    <form id="restoreForm" method="post">
        <h2>Restore Database</h2>
        <input type="hidden" name="action" value="restore">
        <label for="restore_database">Select Target Database:</label>
        <select name="database_option" id="restore_database" required>
            <option value="">Select a database</option>
            <?php foreach (getDatabases($host, $username, $password) as $db): ?>
                <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
            <?php endforeach; ?>
            <option value="new">Create New Database</option>
        </select><br>
        <div id="new_database_name" style="display:none;">
            <label for="new_db_name">New Database Name:</label>
            <input type="text" name="new_db_name" id="new_db_name">
        </div>
        <label for="backup_file">Select Backup File:</label>
        <select name="backup_file" id="backup_file" required>
            <option value="">Select a backup file</option>
            <?php foreach (getBackupFiles() as $file): ?>
                <option value="<?= htmlspecialchars($file) ?>"><?= htmlspecialchars($file) ?></option>
            <?php endforeach; ?>
        </select><br>
        <button type="submit">Restore</button>
    </form>

    <div id="message"></div>

    <script>
        document.getElementById('restore_database').addEventListener('change', function() {
            var newDbInput = document.getElementById('new_database_name');
            if (this.value === 'new') {
                newDbInput.style.display = 'block';
            } else {
                newDbInput.style.display = 'none';
            }
        });

        // Handle form submissions with AJAX
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                fetch('', {
                    method: 'POST',
                    body: new FormData(this)
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('message').textContent = data.message;
                    if (data.status === 'success') {
                        // Optionally reload the page or update form elements
                        // location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            });
        });
    </script>
</body>
</html>
