<?php
/**
 * RapidBase QueryBrowser API v1 - Entry Point
 * Minimalist router entry.
 */
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);
// Load RapidBase Bundle
require_once __DIR__ . '/lib/RapidBase.php';

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Delegate to Router
require_once __DIR__ . '/Router.php';
$router = new RapidBase\Api\v1\Router();
$router->handle();
