<?php

$TOKEN = 'add-token-here';
$BATCH_SIZE = 80;
$REFRESH_SECONDS = 3;

if (!isset($_GET['token']) || $_GET['token'] !== $TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

set_time_limit(20);
ini_set('memory_limit', '256M');

$root = __DIR__;
$backupDir = $root . '/_backup_tmp';
$stateFile = $backupDir . '/state.json';
$fileListFile = $backupDir . '/files.json';

//remove these if you want to back up everything
$excludeDirs = [
    '_backup_tmp',
    'cache',
    'tmp',
    'logs',
    'administrator/cache'
];

$excludeFiles = [
    'backup.php'
];

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

function clean_path($path) {
    return str_replace('\\', '/', $path);
}

function is_excluded($relativePath, $excludeDirs, $excludeFiles) {
    $relativePath = clean_path($relativePath);

    foreach ($excludeFiles as $file) {
        if ($relativePath === $file) return true;
    }

    foreach ($excludeDirs as $dir) {
        $dir = trim(clean_path($dir), '/');
        if ($relativePath === $dir || strpos($relativePath, $dir . '/') === 0) {
            return true;
        }
    }

    if (preg_match('/\.(zip|tar|gz|sql)$/i', $relativePath)) return true;

    return false;
}

function scan_files($root, $excludeDirs, $excludeFiles) {
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;

        $fullPath = $file->getRealPath();
        $relativePath = substr($fullPath, strlen($root) + 1);
        $relativePath = clean_path($relativePath);

        if (is_excluded($relativePath, $excludeDirs, $excludeFiles)) continue;

        $files[] = $relativePath;
    }

    return $files;
}

function load_json($file) {
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function save_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

if (isset($_GET['reset'])) {
    @unlink($stateFile);
    @unlink($fileListFile);

    foreach (glob($backupDir . '/*.zip') as $oldZip) {
        @unlink($oldZip);
    }

    header('Location: backup.php?token=' . urlencode($TOKEN));
    exit;
}

$state = load_json($stateFile);

if (!$state) {
    $files = scan_files($root, $excludeDirs, $excludeFiles);

    $zipName = 'joomla-files-backup-' . date('Y-m-d-His') . '.zip';

    $state = [
        'status' => 'running',
        'zip' => $zipName,
        'total' => count($files),
        'done' => 0,
        'current' => '',
        'started_at' => date('Y-m-d H:i:s'),
        'finished_at' => '',
        'error' => ''
    ];

    save_json($fileListFile, $files);
    save_json($stateFile, $state);
}

$files = load_json($fileListFile);
$zipPath = $backupDir . '/' . $state['zip'];

if ($state['status'] === 'running') {
    try {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new Exception('Cannot open ZIP file.');
        }

        $start = (int) $state['done'];
        $end = min($start + $BATCH_SIZE, $state['total']);

        for ($i = $start; $i < $end; $i++) {
            $relativePath = $files[$i];
            $fullPath = $root . '/' . $relativePath;

            if (file_exists($fullPath) && is_readable($fullPath)) {
                $zip->addFile($fullPath, $relativePath);
            }

            $state['current'] = $relativePath;
            $state['done'] = $i + 1;
        }

        $zip->close();

        if ($state['done'] >= $state['total']) {
            $state['status'] = 'finished';
            $state['current'] = '';
            $state['finished_at'] = date('Y-m-d H:i:s');
            $state['zip_size_mb'] = file_exists($zipPath) ? round(filesize($zipPath) / 1024 / 1024, 2) : 0;
        }

        save_json($stateFile, $state);

    } catch (Throwable $e) {
        $state['status'] = 'error';
        $state['error'] = $e->getMessage();
        save_json($stateFile, $state);
    }
}

$percent = $state['total'] > 0 ? round(($state['done'] / $state['total']) * 100, 2) : 0;
$downloadPath = '_backup_tmp/' . $state['zip'];

if ($state['status'] === 'running') {
    header("Refresh: {$REFRESH_SECONDS}; url=backup.php?token=" . urlencode($TOKEN));
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Joomla Backup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #fff;
            padding: 30px;
        }
        .box {
            max-width: 700px;
            background: #1d1d1d;
            padding: 25px;
            border-radius: 12px;
        }
        .bar {
            width: 100%;
            height: 24px;
            background: #333;
            border-radius: 20px;
            overflow: hidden;
            margin: 20px 0;
        }
        .fill {
            height: 100%;
            width: <?php echo $percent; ?>%;
            background: #e9000d;
        }
        code {
            color: #ddd;
            word-break: break-all;
        }
        a {
            color: #fff;
            background: #e9000d;
            padding: 10px 15px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            margin-top: 15px;
        }
        .muted {
            color: #aaa;
        }
    </style>
</head>
<body>
<div class="box">
    <h2>Joomla Backup</h2>

    <p>Status: <strong><?php echo htmlspecialchars($state['status']); ?></strong></p>

    <div class="bar">
        <div class="fill"></div>
    </div>

    <p><?php echo $percent; ?>% complete</p>
    <p>Files: <?php echo (int)$state['done']; ?> / <?php echo (int)$state['total']; ?></p>

    <?php if ($state['status'] === 'running'): ?>
        <p class="muted">Auto-refresh every <?php echo (int)$REFRESH_SECONDS; ?> seconds.</p>
        <p>Current file:</p>
        <code><?php echo htmlspecialchars($state['current']); ?></code>
    <?php endif; ?>

    <?php if ($state['status'] === 'finished'): ?>
        <p>Backup finished successfully.</p>
        <p>ZIP size: <?php echo htmlspecialchars($state['zip_size_mb'] ?? '0'); ?> MB</p>
        <a href="<?php echo htmlspecialchars($downloadPath); ?>" download>Download Backup ZIP</a>
    <?php endif; ?>

    <?php if ($state['status'] === 'error'): ?>
        <p style="color:#ff6666;">Error: <?php echo htmlspecialchars($state['error']); ?></p>
    <?php endif; ?>

    <br><br>
    <a href="backup.php?token=<?php echo urlencode($TOKEN); ?>&reset=1" onclick="return confirm('Reset and delete current backup?')">Reset Backup</a>
</div>
</body>
</html>