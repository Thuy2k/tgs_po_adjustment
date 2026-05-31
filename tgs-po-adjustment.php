<?php
/**
 * Plugin Name: TGS PO Điều chỉnh (Quét tồn thông minh)
 * Plugin URI: https://bizgpt.vn/
 * Description: Quét tồn hiện tại vs tồn Min/Max → gợi ý đơn PO điều chỉnh (xin kho, trả về kho, mua thêm). Hook vào menu "Kho hàng" của plugin TGS Shop Management.
 * Version: 1.0.0
 * Author: BIZGPT_AI
 * License: GPL v2 or later
 * Text Domain: tgs-po-adjustment
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_POA_VERSION', '1.0.0');
define('TGS_POA_PLUGIN_FILE', __FILE__);
define('TGS_POA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TGS_POA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-database.php';
require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-helper.php';
require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-menu.php';
require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-ajax.php';

register_activation_hook(__FILE__, ['TGS_POA_Database', 'activate']);

add_action('plugins_loaded', function () {
    TGS_POA_Menu::init();
    TGS_POA_Ajax::init();
    // Tạo bảng nếu chưa có (an toàn khi update plugin)
    add_action('admin_init', ['TGS_POA_Database', 'maybe_create_tables']);
});
