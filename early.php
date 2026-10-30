<?php
/**
 * Loaded by the optional early must-use plugin, before other plugins: starts
 * the PHP error collector on smoke test requests, so warnings raised while
 * other plugins load are caught too.
 *
 * @package AryanX\Axle\SmokeTest
 */

namespace AryanX\Axle\SmokeTest;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/ErrorCollector.php';

ErrorCollector::maybe_start();
