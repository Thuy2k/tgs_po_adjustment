<?php
/**
 * Tạo bảng global cho PO Điều chỉnh.
 * Cố tình đặt 2 bảng global (multisite-wide) — KHÔNG đặt theo từng blog.
 *
 * - wp_global_po_adjustment       : phiếu yêu cầu điều chỉnh (header).
 * - wp_global_po_adjustment_item  : các dòng SKU trong phiếu.
 *
 * Cột chính:
 *  source_kind         : 'shop' | 'warehouse' — phiếu xuất phát từ shop hay kho.
 *  request_blog_id     : blog yêu cầu (luôn có).
 *  transfer_blog_id    : blog chuyển hàng (có thể NULL nếu là phiếu mua thêm của kho).
 *  receive_blog_id     : blog nhận hàng.
 *  status              : draft | pending | approved | rejected | cancelled | converted.
 *  intent              : 'shop_request_from_warehouse' | 'shop_return_to_warehouse'
 *                       | 'warehouse_purchase_more'    | 'warehouse_warning'.
 */
if (!defined('ABSPATH')) exit;

class TGS_POA_Database
{
    const VERSION_OPTION = 'tgs_poa_db_version';
    const CURRENT_VERSION = '1.0.0';

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

    public static function activate()
    {
        self::create_tables();
        update_site_option(self::VERSION_OPTION, self::CURRENT_VERSION);
    }

    public static function maybe_create_tables()
    {
        $current = get_site_option(self::VERSION_OPTION, '');
        if ($current !== self::CURRENT_VERSION) {
            self::create_tables();
            update_site_option(self::VERSION_OPTION, self::CURRENT_VERSION);
        }
    }

    public static function create_tables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $tbl_h = self::table_header();
        $tbl_i = self::table_item();

        $sql_h = "CREATE TABLE {$tbl_h} (
            po_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_code VARCHAR(50) NOT NULL,
            source_kind VARCHAR(20) NOT NULL DEFAULT 'shop' COMMENT 'shop|warehouse',
            intent VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'shop_request_from_warehouse|shop_return_to_warehouse|warehouse_purchase_more',
            request_blog_id BIGINT UNSIGNED NOT NULL,
            request_blog_name VARCHAR(255) NOT NULL DEFAULT '',
            transfer_blog_id BIGINT UNSIGNED NULL,
            transfer_blog_name VARCHAR(255) NOT NULL DEFAULT '',
            receive_blog_id BIGINT UNSIGNED NOT NULL,
            receive_blog_name VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|pending|approved|rejected|cancelled|converted',
            note TEXT NULL,
            total_items INT UNSIGNED NOT NULL DEFAULT 0,
            total_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            PRIMARY KEY (po_id),
            UNIQUE KEY uk_po_code (po_code),
            KEY idx_request_blog (request_blog_id),
            KEY idx_status (status),
            KEY idx_source_kind (source_kind),
            KEY idx_created_at (created_at)
        ) {$charset};";

        $sql_i = "CREATE TABLE {$tbl_i} (
            item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            po_id BIGINT UNSIGNED NOT NULL,
            product_sku VARCHAR(100) NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
            current_stock DECIMAL(15,3) NOT NULL DEFAULT 0 COMMENT 'tồn lúc tạo phiếu',
            min_qty INT UNSIGNED NOT NULL DEFAULT 0,
            max_qty INT UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NULL,
            PRIMARY KEY (item_id),
            KEY idx_po_id (po_id),
            KEY idx_sku (product_sku)
        ) {$charset};";

        dbDelta($sql_h);
        dbDelta($sql_i);
    }
}
