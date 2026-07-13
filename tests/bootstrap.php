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

require_once __DIR__ . '/../vendor/autoload.php';

putenv('WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-config.php');

$_tests_dir = dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit';

require_once $_tests_dir . '/includes/functions.php';

// Load the plugin under test into the test WordPress, before init fires, the
// same way WordPress loads an active plugin.
tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/wppack-disable-comments.php';
});

require_once $_tests_dir . '/includes/bootstrap.php';

// The toolbar test builds a WP_Admin_Bar by hand.
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
