<?php
/**
 * RapidBase QueryBrowser API v1 - Entry Point
 * Minimalist router entry.
 */

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
