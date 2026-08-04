<?php
$dir = dirname(__DIR__);
$memberDir = 'member';
if (file_exists($dir . '/.env') && $lines = @file($dir . '/.env')) {
    foreach ($lines as $line) {
        if (preg_match('/^\s*MEMBER_DIR\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
            $memberDir = trim($m[1]);
            break;
        }
        if (preg_match('/^\s*BASE_URL\s*=\s*["\']?(.*?)["\']?\s*$/', $line, $m)) {
            $path = parse_url(trim($m[1]), PHP_URL_PATH);
            $memberDir = trim($path, '/');
        }
    }
}
header("Location: /" . ($memberDir ? $memberDir . "/" : ""));
exit;
