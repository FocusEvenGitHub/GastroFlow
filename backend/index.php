<?php
// Redirect to appropriate section based on path
$path = $_SERVER['REQUEST_URI'];

if (strpos($path, '/cashier') === 0) {
    header('Location: /cashier/');
    exit;
} elseif (strpos($path, '/kitchen') === 0) {
    header('Location: /kitchen/');
    exit;
} elseif (strpos($path, '/api') === 0) {
    // API is handled by api/index.php via .htaccess
    exit;
} else {
    // Default redirect
    header('Location: /cashier/');
    exit;
}