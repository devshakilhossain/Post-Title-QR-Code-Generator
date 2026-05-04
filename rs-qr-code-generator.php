<?php

/**
 * Plugin Name: RS QR Code Generator
 * Description: Generate QR codes from post titles with a centered logo directly from the post editor sidebar.
 * Version: 1.0.0
 * Author: MD Shakil Hossain
 * Author URI: https://devshakilhossain.github.io/portfolio/
 * Text Domain: rs-qr-code-generator
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RS_QR_CODE_GENERATOR_FILE', __FILE__);
define('RS_QR_CODE_GENERATOR_DIR', plugin_dir_path(__FILE__));
define('RS_QR_CODE_GENERATOR_URL', plugin_dir_url(__FILE__));
define('RS_QR_CODE_GENERATOR_VERSION', '1.0.0');

$rs_qr_autoloader = RS_QR_CODE_GENERATOR_DIR . 'vendor/autoload.php';

if (file_exists($rs_qr_autoloader)) {
    require_once $rs_qr_autoloader;
}

require_once RS_QR_CODE_GENERATOR_DIR . 'src/Plugin.php';

add_action('plugins_loaded', static function (): void {
    RSQRCodeGenerator\Plugin::instance()->boot();
});
