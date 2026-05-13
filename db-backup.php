<?php

$TOKEN = 'add-token-here';
$REFRESH_SECONDS = 2;
$ROW_BATCH_SIZE = 500;

$dbHost = 'add-dbHost';
$dbUser = 'add-dbUser';
$dbPass = 'add-dbPass';
$dbName = 'add-dbName';

if (!isset($_GET['token']) || $_GET['token'] !== $TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

set_time_limit(20);
ini_set('memory_limit', '256M');

$backupDir = __DIR__ . '/_db_backup_tmp';
$stateFile = $backupDir . '/db-state.json';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

function save_json($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function load_json($file) {
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

function sql_value($mysqli, $value) {
    if ($value === null) return 'NULL';
    return "'" . $mysqli->real_escape_string($value) . "'";
}

function sql_identifier($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

if (isset($_GET['reset'])) {
    @unlink($stateFile);
    foreach (glob($backupDir . '/*.sql') as $f) @unlink($f);
    foreach (glob($backupDir . '/*.gz') as $f) @unlink($f);

    header('Location: db-backup.php?token=' . urlencode($TOKEN));
    exit;
}

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($mysqli->connect_error) {
    die('Database connection failed: ' . htmlspecialchars($mysqli->connect_error));
}

$mysqli->set_charset('utf8mb4');

$state = load_json($stateFile);

if (!$state) {
    $tables = [];

    $res = $mysqli->query("SHOW TABLES");
    while ($row = $res->fetch_array()) {
        $tables[] = $row[0];
    }

    $sqlName = 'joomla-db-backup-' . date('Y-m-d-His') . '.sql';
    $sqlPath = $backupDir . '/' . $sqlName;

    $header = "-- Joomla Database Backup\n";
    $header .= "-- Database: {$dbName}\n";
    $header .= "-- Created: " . date('Y-m-d H:i:s') . "\n\n";
    $header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $header .= "SET time_zone = \"+00:00\";\n";
    $header .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    $header .= "CREATE DATABASE IF NOT EXISTS " . sql_identifier($dbName) . " DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    $header .= "USE " . sql_identifier($dbName) . ";\n\n";

    file_put_contents($sqlPath, $header);

    $state = [
        'status' => 'running',
        'tables' => $tables,
        'table_index' => 0,
        'current_table' => '',
        'phase' => 'structure',
        'offset' => 0,
        'done_rows' => 0,
        'total_rows' => 0,
        'sql' => $sqlName,
        'started_at' => date('Y-m-d H:i:s'),
        'finished_at' => '',
        'error' => ''
    ];

    foreach ($tables as $table) {
        $countRes = $mysqli->query("SELECT COUNT(*) AS c FROM " . sql_identifier($table));
        $countRow = $countRes->fetch_assoc();
        $state['total_rows'] += (int)$countRow['c'];
    }

    save_json($stateFile, $state);
}

try {
    $sqlPath = $backupDir . '/' . $state['sql'];

    if ($state['status'] === 'running') {
        if ($state['table_index'] >= count($state['tables'])) {
            file_put_contents($sqlPath, "\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND);

            $gzPath = $sqlPath . '.gz';
            $fpIn = fopen($sqlPath, 'rb');
            $fpOut = gzopen($gzPath, 'wb9');

            while (!feof($fpIn)) {
                gzwrite($fpOut, fread($fpIn, 1024 * 512));
            }

            fclose($fpIn);
            gzclose($fpOut);

            $state['status'] = 'finished';
            $state['finished_at'] = date('Y-m-d H:i:s');
            $state['sql_size_mb'] = round(filesize($sqlPath) / 1024 / 1024, 2);
            $state['gz'] = basename($gzPath);
            $state['gz_size_mb'] = round(filesize($gzPath) / 1024 / 1024, 2);

            save_json($stateFile, $state);
        } else {
			$table = $state['tables'][$state['table_index']];
            $state['current_table'] = $table;

            if ($state['phase'] === 'structure') {
                file_put_contents($sqlPath, "\n\n-- --------------------------------------------------------\n", FILE_APPEND);
                file_put_contents($sqlPath, "-- Table structure for table " . sql_identifier($table) . "\n\n", FILE_APPEND);
                file_put_contents($sqlPath, "DROP TABLE IF EXISTS " . sql_identifier($table) . ";\n", FILE_APPEND);

                $createRes = $mysqli->query("SHOW CREATE TABLE " . sql_identifier($table));
                $createRow = $createRes->fetch_assoc();

                file_put_contents($sqlPath, $createRow['Create Table'] . ";\n\n", FILE_APPEND);

                $state['phase'] = 'data';
                $state['offset'] = 0;

                save_json($stateFile, $state);
            } else {
                $res = $mysqli->query(
                    "SELECT * FROM " . sql_identifier($table) .
                    " LIMIT " . (int)$ROW_BATCH_SIZE .
                    " OFFSET " . (int)$state['offset']
                );

                if ($res && $res->num_rows > 0) {
                    $columns = [];

                    while ($field = $res->fetch_field()) {
                        $columns[] = sql_identifier($field->name);
                    }

                    $insertPrefix = "INSERT INTO " . sql_identifier($table) .
                        " (" . implode(', ', $columns) . ") VALUES\n";

                    $rowsSql = [];

                    while ($row = $res->fetch_assoc()) {
                        $values = [];

                        foreach ($row as $value) {
                            $values[] = sql_value($mysqli, $value);
                        }

                        $rowsSql[] = "(" . implode(', ', $values) . ")";
                    }

                    file_put_contents($sqlPath, $insertPrefix . implode(",\n", $rowsSql) . ";\n\n", FILE_APPEND);

                    $added = count($rowsSql);
                    $state['offset'] += $added;
                    $state['done_rows'] += $added;

                    save_json($stateFile, $state);
                } else {
                    $state['table_index']++;
                    $state['phase'] = 'structure';
                    $state['offset'] = 0;

                    save_json($stateFile, $state);
                }
            }
        }
    }

} catch (Throwable $e) {
    $state['status'] = 'error';
    $state['error'] = $e->getMessage();
    save_json($stateFile, $state);
}

$percent = $state['total_rows'] > 0 ? round(($state['done_rows'] / $state['total_rows']) * 100, 2) : 0;

if ($state['status'] === 'running') {
    header("Refresh: {$REFRESH_SECONDS}; url=db-backup.php?token=" . urlencode($TOKEN));
}

$downloadSql = '_db_backup_tmp/' . $state['sql'];
$downloadGz = isset($state['gz']) ? '_db_backup_tmp/' . $state['gz'] : '';

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Joomla DB Backup</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #111;
            color: #fff;
            padding: 30px;
        }
        .box {
            max-width: 760px;
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
            margin-right: 8px;
        }
        .muted {
            color: #aaa;
        }
    </style>
</head>
<body>
<div class="box">
    <h2>Joomla Database Backup</h2>

    <p>Status: <strong><?php echo htmlspecialchars($state['status']); ?></strong></p>

    <div class="bar">
        <div class="fill"></div>
    </div>

    <p><?php echo $percent; ?>% complete</p>
    <p>Rows exported: <?php echo (int)$state['done_rows']; ?> / <?php echo (int)$state['total_rows']; ?></p>
    <p>Table: <code><?php echo htmlspecialchars($state['current_table']); ?></code></p>
    <p>Phase: <strong><?php echo htmlspecialchars($state['phase']); ?></strong></p>

    <?php if ($state['status'] === 'running'): ?>
        <p class="muted">Auto-refresh every <?php echo (int)$REFRESH_SECONDS; ?> seconds.</p>
    <?php endif; ?>

    <?php if ($state['status'] === 'finished'): ?>
        <p>Database backup finished successfully.</p>
        <p>SQL size: <?php echo htmlspecialchars($state['sql_size_mb'] ?? '0'); ?> MB</p>
        <p>GZIP size: <?php echo htmlspecialchars($state['gz_size_mb'] ?? '0'); ?> MB</p>

        <a href="<?php echo htmlspecialchars($downloadGz); ?>" download>Download GZIP SQL</a>
        <a href="<?php echo htmlspecialchars($downloadSql); ?>" download>Download Raw SQL</a>
    <?php endif; ?>

    <?php if ($state['status'] === 'error'): ?>
        <p style="color:#ff6666;">Error: <?php echo htmlspecialchars($state['error']); ?></p>
    <?php endif; ?>

    <br><br>
    <a href="db-backup.php?token=<?php echo urlencode($TOKEN); ?>&reset=1" onclick="return confirm('Reset and delete current DB backup?')">Reset Backup</a>
</div>
</body>
</html>