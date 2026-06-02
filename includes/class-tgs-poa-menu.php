<?php
/**
 * Hook menu vào plugin TGS Shop Management:
 *  - Thêm mục "Quét tồn thông minh" vào nhóm "Kho hàng" (action tgs_shop_sidebar_menu).
 *  - Đăng ký route view=poa-scan để main-layout.php của shop management render trang.
 */
if (!defined('ABSPATH')) exit;

class TGS_POA_Menu
{
    const VIEW_SCAN   = 'poa-scan';
    const VIEW_LIST   = 'poa-list';
    const VIEW_DETAIL = 'poa-detail';
    const VIEW_CREATE = 'poa-create';

    public static function init()
    {
        add_filter('tgs_shop_dashboard_routes', [__CLASS__, 'register_routes']);
        add_action('tgs_shop_sidebar_menu',     [__CLASS__, 'render_menu_item']);
    }

    public static function register_routes($routes)
    {
        $routes[self::VIEW_SCAN] = [
            'Quét tồn thông minh — PO điều chỉnh',
            TGS_POA_PLUGIN_DIR . 'admin-views/scan-stock/index.php',
        ];
        $routes[self::VIEW_LIST] = [
            'Danh sách PO điều chỉnh',
            TGS_POA_PLUGIN_DIR . 'admin-views/po-list/index.php',
        ];
        $routes[self::VIEW_DETAIL] = [
            'Chi tiết PO điều chỉnh',
            TGS_POA_PLUGIN_DIR . 'admin-views/po-detail/index.php',
        ];
        $routes[self::VIEW_CREATE] = [
            'Tạo PO điều chỉnh chủ động',
            TGS_POA_PLUGIN_DIR . 'admin-views/po-create/index.php',
        ];
        return $routes;
    }

    public static function render_menu_item($current_view)
    {
        $url_scan   = admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW_SCAN);
        $url_list   = admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW_LIST);
        $url_create = admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW_CREATE);

        $active_scan = ($current_view === self::VIEW_SCAN) ? 'active' : '';

        $po_views      = [self::VIEW_LIST, self::VIEW_DETAIL, self::VIEW_CREATE];
        $active_po     = in_array($current_view, $po_views, true) ? 'active open' : '';
        $active_list   = in_array($current_view, [self::VIEW_LIST, self::VIEW_DETAIL], true) ? 'active' : '';
        $active_create = ($current_view === self::VIEW_CREATE) ? 'active' : '';

        echo '<li><a href="' . esc_url($url_scan) . '" class="' . esc_attr($active_scan) . '">'
            . '<i class="bx bx-radar"></i>Quét tồn thông minh</a></li>';

        echo '<li class="menu-item ' . esc_attr($active_po) . '">'
            . '<a href="javascript:void(0);" class="menu-link menu-toggle">'
            . '<i class="menu-icon tf-icons bx bx-list-check"></i>'
            . '<div>Danh sách PO</div>'
            . '</a>'
            . '<ul class="menu-sub">'
            . '<li class="menu-item ' . esc_attr($active_list) . '">'
            . '<a href="' . esc_url($url_list) . '" class="menu-link">'
            . '<i class="bx bx-list-ul me-1"></i><div>Danh sách</div>'
            . '</a></li>'
            . '<li class="menu-item ' . esc_attr($active_create) . '">'
            . '<a href="' . esc_url($url_create) . '" class="menu-link">'
            . '<i class="bx bx-plus-circle me-1"></i><div>Tạo PO chủ động</div>'
            . '</a></li>'
            . '</ul>'
            . '</li>';
    }
}
