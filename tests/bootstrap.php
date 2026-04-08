<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * Integration tests talk to a real MongoDB instance. Override via env:
 *
 *   MONGODB_URI       (default: mongodb://127.0.0.1:27017)
 *   MONGODB_DATABASE  (default: cakephp5_mongodb_driver_test)
 *
 * Start one locally with `docker compose -f docker-compose.test.yml up -d`.
 */
if (!defined('MONGODB_TEST_URI')) {
    define('MONGODB_TEST_URI', getenv('MONGODB_URI') ?: 'mongodb://127.0.0.1:27017');
}
if (!defined('MONGODB_TEST_DATABASE')) {
    define('MONGODB_TEST_DATABASE', getenv('MONGODB_DATABASE') ?: 'cakephp5_mongodb_driver_test');
}
