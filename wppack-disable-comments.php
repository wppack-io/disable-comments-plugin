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

/**
 * Plugin Name: WPPack Disable Comments
 * Description: Completely disable comments — closes discussion everywhere, hides existing comments, and removes every comment surface from wp-admin, feeds, REST and XML-RPC. No settings; deactivate to restore everything.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires at least: 6.7
 * Author: WPPack
 * License: MIT
 * Text Domain: wppack-disable-comments
 */

namespace WPPack\Plugin\DisableCommentsPlugin;

if (!defined('ABSPATH')) {
    exit;
}

// Composer installs resolve the class through the site autoloader (see the
// PSR-4 mapping in composer.json); plain plugin installs load it here.
if (!class_exists(DisableCommentsPlugin::class)) {
    require __DIR__ . '/src/DisableCommentsPlugin.php';
}

DisableCommentsPlugin::boot();
