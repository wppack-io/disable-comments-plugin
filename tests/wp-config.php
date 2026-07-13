<?php

/*
 * This file is part of the WPPack package.
 *
 * (c) Tsuyoshi Tsurushima
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('ABSPATH', dirname(__DIR__) . '/web/wp/');
define('WP_CONTENT_DIR', dirname(__DIR__) . '/web/wp-content');

define('DB_NAME', 'disable_comments_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'password');
define('DB_HOST', '127.0.0.1:' . ($_SERVER['DISABLE_COMMENTS_TEST_DB_PORT'] ?? '3311'));
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Disable Comments Tests');
define('WP_PHP_BINARY', 'php');
define('WPLANG', '');

define('WP_DEBUG', true);
define('WP_ENVIRONMENT_TYPE', 'local');

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');

// WP 6.8+: pre-populate $wp_theme_directories so wp_is_block_theme() does not
// trigger _doing_it_wrong during the separate-process install (see wppack).
$GLOBALS['wp_theme_directories'] = [dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit/data/themedir1'];
