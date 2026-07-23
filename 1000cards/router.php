<?php
// If the requested file exists (CSS, JS, images, etc.), serve it normally.
$path = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($_SERVER['REQUEST_URI'] !== '/' && file_exists($path) && !is_dir($path)) {
    return false;
}

// Otherwise, load qwen_index.php
require __DIR__ . '/qwen_index.php';