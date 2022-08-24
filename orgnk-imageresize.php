<?php
/*
Plugin Name:    Organik Image Resize
Description:    Dynamic image resizing and manipulation in WordPress templates.
Version:        1.0.0
Author:         Organik Web
Author URI:     https://www.organikweb.com.au/
License:        MIT License
*/

if (!defined('ABSPATH')) {
    exit;
}

// Current plugin version
define('ORGNK_IMAGERESIZE_VERSION', '1.0.0');

// Useful constants
define('ORGNK_IMAGERESIZE_PATH', plugin_dir_path(__FILE__));

// Activation / deactivation
require_once ORGNK_IMAGERESIZE_PATH . 'classes/Activator.php';
register_activation_hook(__FILE__, ['Organik\ImageResizer\Classes\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Organik\ImageResizer\Classes\Activator', 'deactivate']);

// Load files
require_once ORGNK_IMAGERESIZE_PATH . 'classes/Resizer.php';
require_once ORGNK_IMAGERESIZE_PATH . 'classes/ImageHandler.php';
require_once ORGNK_IMAGERESIZE_PATH . 'classes/Router.php';
require_once ORGNK_IMAGERESIZE_PATH . 'classes/WordpressHandler.php';
require_once ORGNK_IMAGERESIZE_PATH . 'inc/helpers.php';

// Load router
Organik\ImageResizer\Classes\Router::instance();

// Load Wordpress handler
Organik\ImageResizer\Classes\WordpressHandler::instance();
