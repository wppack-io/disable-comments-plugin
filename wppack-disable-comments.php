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

// Self-contained autoloader: works without Composer (plain plugin install).
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, __NAMESPACE__ . '\\')) {
        return;
    }
    $relative = substr($class, strlen(__NAMESPACE__) + 1);
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

DisableCommentsPlugin::boot();
