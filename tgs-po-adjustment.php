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

require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-helper.php';
require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-menu.php';
require_once TGS_POA_PLUGIN_DIR . 'includes/class-tgs-poa-ajax.php';

/**
 * Stub giữ lại tên bảng — việc tạo bảng đã chuyển sang class-tgs-database.php (tgs_shop_management).
 */
if (!class_exists('TGS_POA_Database')) {
    class TGS_POA_Database
    {
        public static function table_header()
        {
            global $wpdb;
            return $wpdb->base_prefix . 'global_po_adjustment';
        }

        public static function table_item()
        {
            global $wpdb;
            return $wpdb->base_prefix . 'global_po_adjustment_item';
        }
    }
}

add_action('plugins_loaded', function () {
    TGS_POA_Menu::init();
    TGS_POA_Ajax::init();
});
