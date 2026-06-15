<?php
/**
 * Helper: phân loại blog (shop/kho), tìm kho cha gần nhất, lấy tồn hiện tại,
 * lấy cấu hình min/max, và sinh gợi ý PO từ chênh lệch.
 *
 * Quy ước phân loại (đồng bộ với class-purchase-stock-config-ajax.php):
 *  - "Kho" nếu type === 'warehouse'/'kho' HOẶC site đó có ít nhất 1 con trong cây.
 *  - "Shop" nếu không thuộc nhóm trên.
 */
if (!defined('ABSPATH')) exit;

class TGS_POA_Helper
{
    /** @return array<int,int> [blog_id => số con trực tiếp] */
    public static function get_children_count_map()
    {
        if (!class_exists('TGS_Hierarchy_Data')) return [];
        $hierarchy = TGS_Hierarchy_Data::get_hierarchy();
        if (!is_array($hierarchy)) return [];
        $count = [];
        foreach ($hierarchy as $bid => $pid) {
            if ($pid === null || $pid === '' || $pid === 0) continue;
            $pid = (int) $pid;
            $count[$pid] = isset($count[$pid]) ? $count[$pid] + 1 : 1;
        }
        return $count;
    }

    public static function is_warehouse($bid, $sites_info = null, $children_count = null)
    {
        $bid = (int) $bid;
        if ($sites_info === null) {
            $sites_info = class_exists('TGS_Hierarchy_Data') ? TGS_Hierarchy_Data::get_sites_info() : [];
            if (!is_array($sites_info)) $sites_info = [];
        }
        if ($children_count === null) {
            $children_count = self::get_children_count_map();
        }
        $type = isset($sites_info[$bid]['type']) ? (string) $sites_info[$bid]['type'] : '';
        if ($type === 'warehouse' || $type === 'kho') return true;
        if (!empty($children_count[$bid])) return true;
        return false;
    }

    /**
     * Tìm kho cha gần nhất bằng cách đi lên cây phân cấp.
     * Trả về blog_id của tổ tiên đầu tiên thoả là "kho", hoặc 0 nếu không có.
     */
    public static function find_parent_warehouse($bid)
    {
        if (!class_exists('TGS_Hierarchy_Data')) return 0;
        $hierarchy = TGS_Hierarchy_Data::get_hierarchy();
        if (!is_array($hierarchy)) return 0;

        $sites_info     = TGS_Hierarchy_Data::get_sites_info();
        if (!is_array($sites_info)) $sites_info = [];
        $children_count = self::get_children_count_map();

        $current = (int) $bid;
        $guard = 0;
        while ($guard++ < 30) {
            $parent = isset($hierarchy[$current]) ? (int) $hierarchy[$current] : 0;
            if (!$parent) return 0;
            if (self::is_warehouse($parent, $sites_info, $children_count)) {
                return $parent;
            }
            $current = $parent;
        }
        return 0;
    }

    public static function get_blog_name($bid)
    {
        $bid = (int) $bid;
        if (!$bid) return '';
        $name = get_blog_option($bid, 'blogname');
        return $name ?: ('Blog #' . $bid);
    }

    public static function get_scan_targets($source_blog_id = null)
    {
        $source_blog_id = $source_blog_id ? (int) $source_blog_id : (int) get_current_blog_id();

        if (class_exists('TGS_Delivery_Schedule_Helper')) {
            return TGS_Delivery_Schedule_Helper::get_managed_sites($source_blog_id, true, false);
        }

        $targets = [[
            'blog_id' => $source_blog_id,
            'id' => $source_blog_id,
            'name' => self::get_blog_name($source_blog_id),
            'code' => 'SHOP-' . $source_blog_id,
            'type' => self::is_warehouse($source_blog_id) ? 'warehouse' : 'shop',
            'type_label' => self::is_warehouse($source_blog_id) ? 'Kho' : 'Shop',
        ]];

        if (class_exists('TGS_Hierarchy_Data') && self::is_warehouse($source_blog_id)) {
            foreach ((array) TGS_Hierarchy_Data::get_all_descendants($source_blog_id) as $bid) {
                $bid = (int) $bid;
                if (!$bid || $bid === $source_blog_id) {
                    continue;
                }
                $is_kho = self::is_warehouse($bid);
                $targets[] = [
                    'blog_id' => $bid,
                    'id' => $bid,
                    'name' => self::get_blog_name($bid),
                    'code' => 'SHOP-' . $bid,
                    'type' => $is_kho ? 'warehouse' : 'shop',
                    'type_label' => $is_kho ? 'Kho' : 'Shop',
                ];
            }
        }

        return $targets;
    }

    public static function can_scan_blog($target_blog_id, $source_blog_id = null)
    {
        $target_blog_id = (int) $target_blog_id;
        $source_blog_id = $source_blog_id ? (int) $source_blog_id : (int) get_current_blog_id();
        if (!$target_blog_id) {
            return false;
        }
        if ($target_blog_id === $source_blog_id) {
            return true;
        }

        foreach (self::get_scan_targets($source_blog_id) as $target) {
            if ((int) ($target['blog_id'] ?? $target['id'] ?? 0) === $target_blog_id) {
                return true;
            }
        }

        return false;
    }

    private static function ensure_global_product_source()
    {
        if (!class_exists('TGS_Global_Product_Source')) {
            $source_file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-global-product-source.php';
            if (is_readable($source_file)) {
                require_once $source_file;
            }
        }

        return class_exists('TGS_Global_Product_Source');
    }

    /**
     * Lấy tồn hiện tại của các SKU trên 1 blog.
     * Tồn lấy qua ledger/API global product, không đọc cột tồn ở bảng sản phẩm local.
     * @param int $bid
     * @param string[] $skus
     * @return array<string,float> [sku => qty]
     */
    public static function get_current_stock_map($bid, array $skus)
    {
        $bid = (int) $bid;
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
        if (!$bid || empty($skus)) return [];

        if (!self::ensure_global_product_source()) {
            return array_fill_keys($skus, 0.0);
        }

        $stocks = TGS_Global_Product_Source::get_stock_for_skus($skus, $bid);

        $map = [];
        foreach ($skus as $sku) {
            $stock = $stocks[$sku] ?? [];
            $map[$sku] = (float) ($stock['projected_stock'] ?? $stock['actual_stock'] ?? 0);
        }
        return $map;
    }

    /**
     * Tìm SKU từ catalog global, trả shape cũ cho UI manual PO.
     *
     * @return array<int,array{sku:string,name:string,qty:float}>
     */
    public static function search_global_products_for_blog($bid, $q = '', $limit = 30)
    {
        $bid = (int) $bid;
        $limit = max(1, min(100, (int) $limit));
        $q = trim((string) $q);

        if (!$bid || !self::ensure_global_product_source()) {
            return [];
        }

        $args = [
            'search' => $q,
            'blog_id' => $bid,
            'with_stock' => true,
            'with_local_aliases' => true,
            'parent_only' => false,
            'require_sku' => true,
            'tracking_filter' => 'all',
            'status_filter' => 'all',
            'order_by' => 'global_product_name_id',
            'order_dir' => 'DESC',
            'per_page' => $limit,
        ];

        if ($q !== '') {
            $args['order_by'] = 'global_product_name';
            $args['order_dir'] = 'ASC';
        }

        $result = TGS_Global_Product_Source::query_products($args);
        $rows = [];
        foreach ((array) ($result['items'] ?? []) as $item) {
            $sku = trim((string) ($item['global_product_sku'] ?? $item['local_product_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $rows[] = [
                'sku' => $sku,
                'name' => (string) ($item['global_product_name'] ?? $item['local_product_name'] ?? ''),
                'qty' => (float) ($item['projected_stock'] ?? $item['actual_stock'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Lấy cấu hình Min/Max của 1 blog từ bảng global stock config.
     * @return array<string,array{min:int,max:int,name:string}>
     */
    public static function get_stock_configs($bid)
    {
        global $wpdb;
        $bid = (int) $bid;
        if (!$bid) return [];
        $tbl = $wpdb->base_prefix . 'global_sku_stock_config';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_sku, product_name, min_qty, max_qty
             FROM {$tbl}
             WHERE blog_id = %d AND (min_qty > 0 OR max_qty > 0)",
            $bid
        ), ARRAY_A);

        $map = [];
        foreach ((array) $rows as $r) {
            $sku = (string) $r['product_sku'];
            $map[$sku] = [
                'min'  => (int) $r['min_qty'],
                'max'  => (int) $r['max_qty'],
                'name' => (string) $r['product_name'],
            ];
        }
        return $map;
    }

    /**
     * Quét tồn của blog hiện tại + sinh gợi ý PO điều chỉnh.
     * @return array{
     *   blog_id:int, blog_name:string, source_kind:string,
     *   parent_warehouse_id:int, parent_warehouse_name:string,
     *   suggestions: array,
     *   summary: array
     * }
     */
    public static function scan_blog($bid)
    {
        $bid = (int) $bid;
        $is_kho   = self::is_warehouse($bid);
        $kind     = $is_kho ? 'warehouse' : 'shop';
        $kho_pid  = $is_kho ? 0 : self::find_parent_warehouse($bid);
        $kho_name = $kho_pid ? self::get_blog_name($kho_pid) : '';
        $blog_name = self::get_blog_name($bid);

        $configs = self::get_stock_configs($bid);
        $skus    = array_keys($configs);
        $stocks  = self::get_current_stock_map($bid, $skus);

        $suggestions = [];
        $sum_deficit = 0;
        $sum_surplus = 0;
        $sum_urgent  = 0;
        $count_deficit = 0;
        $count_surplus = 0;
        $count_urgent  = 0;

        foreach ($configs as $sku => $cfg) {
            $current = (float) ($stocks[$sku] ?? 0);
            $max     = (int) $cfg['max'];
            $min     = (int) $cfg['min'];
            $name    = (string) $cfg['name'];

            // Mặc định: chỉ xét theo MAX (yêu cầu chính của user). Nếu MIN > 0 thì cảnh báo phụ.
            $diff_max = $max > 0 ? ($current - $max) : 0; // >0 = thừa, <0 = thiếu
            if ($max <= 0 && $min <= 0) continue;

            $intent     = '';
            $qty        = 0;
            $req_bid    = $bid;        $req_name = $blog_name;
            $tr_bid     = 0;           $tr_name  = '';
            $rcv_bid    = 0;           $rcv_name = '';
            $level      = 'info';
            $priority   = 'normal'; // 'urgent' = dưới MIN, 'normal' = thiếu so với MAX, 'info' = thừa
            $reason     = '';

            // Công thức gợi ý: max(0, Tồn max shop + Tồn max kho + doanh số tuần 1
            //                                − hàng đi đường − tồn kho − tồn shop)
            // Hiện tại các trường doanh số tuần 1 / hàng đi đường / tồn shop
            // chưa có nguồn dữ liệu → mặc định = 0.
            $max_kho_for_qty = ($is_kho && $max <= 0 && $min > 0) ? $min : $max;
            $max_shop    = $is_kho ? 0 : $max;
            $max_kho     = $max_kho_for_qty;
            $weekly_sales = 0;
            $in_transit  = 0;
            $shop_stock  = 0;

            if ($is_kho) {
                // KHO — ưu tiên kiểm tra MIN trước (mua gấp), rồi MAX (mua bổ sung)
                if ($min > 0 && $current < $min) {
                    // 🔴 DƯỚI MIN — cảnh báo cao nhất, mua gấp
                    $intent   = 'warehouse_purchase_more';
                    $qty      = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                    $rcv_bid  = $bid; $rcv_name = $blog_name;
                    $level    = 'danger';
                    $priority = 'urgent';
                    $reason   = sprintf('Tồn (%s) DƯỚI MIN (%s) — mua gấp, tránh đứt hàng.', self::n($current), self::n($min));
                    $count_urgent++; $sum_urgent += $qty;
                } elseif ($max > 0 && $current < $max) {
                    $intent   = 'warehouse_purchase_more';
                    $qty      = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                    $rcv_bid  = $bid; $rcv_name = $blog_name;
                    $level    = 'warning';
                    $priority = 'normal';
                    $reason   = 'Kho thiếu so với tồn max → nên mua bổ sung.';
                    $count_deficit++; $sum_deficit += $qty;
                } elseif ($max > 0 && $current > $max) {
                    $intent   = 'warehouse_warning';
                    $qty      = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                    $level    = 'info';
                    $priority = 'info';
                    $reason   = 'Kho thừa so với tồn max → chỉ cảnh báo (chờ shop xin hàng).';
                    $count_surplus++; $sum_surplus += ($current - $max);
                } else {
                    continue; // ok
                }
            } else {
                // SHOP — vẫn lấy MAX làm chính (chưa dùng MIN ở shop)
                if ($max > 0 && $current < $max) {
                    if (!$kho_pid) {
                        $reason = 'Shop thiếu hàng nhưng chưa cấu hình kho cha trong cây phân cấp.';
                        $intent = 'shop_request_from_warehouse';
                        $qty    = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                        $level  = 'danger';
                    } else {
                        $intent  = 'shop_request_from_warehouse';
                        $qty     = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                        $tr_bid  = $kho_pid; $tr_name = $kho_name;
                        $rcv_bid = $bid;     $rcv_name = $blog_name;
                        $level   = 'warning';
                        $reason  = 'Shop thiếu so với tồn max → đề xuất xin từ kho cha.';
                    }
                    $priority = 'normal';
                    $count_deficit++; $sum_deficit += $qty;
                } elseif ($max > 0 && $current > $max) {
                    if (!$kho_pid) {
                        $reason = 'Shop thừa hàng nhưng chưa cấu hình kho cha trong cây phân cấp.';
                        $intent = 'shop_return_to_warehouse';
                        $qty    = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                        $level  = 'danger';
                    } else {
                        $intent  = 'shop_return_to_warehouse';
                        $qty     = max(0.0, $max_shop + $max_kho + $weekly_sales - $in_transit - $current - $shop_stock);
                        $tr_bid  = $bid;     $tr_name = $blog_name;
                        $rcv_bid = $kho_pid; $rcv_name = $kho_name;
                        $level   = 'info';
                        $reason  = 'Shop thừa so với tồn max → đề xuất chuyển trả về kho cha.';
                    }
                    $priority = 'info';
                    $count_surplus++; $sum_surplus += ($current - $max);
                } else {
                    continue;
                }
            }

            $suggestions[] = [
                'sku'           => $sku,
                'name'          => $name,
                'current_stock' => $current,
                'min_qty'       => $min,
                'max_qty'       => $max,
                'diff'          => round($current - $max, 3),
                'diff_min'      => $min > 0 ? round($current - $min, 3) : null,
                'intent'        => $intent,
                'intent_label'  => self::intent_label($intent),
                'priority'      => $priority,
                'priority_label'=> self::priority_label($priority),
                'quantity'      => max(0, (float) $qty),
                'request_blog_id'   => $req_bid, 'request_blog_name'   => $req_name,
                'transfer_blog_id'  => $tr_bid,  'transfer_blog_name'  => $tr_name,
                'receive_blog_id'   => $rcv_bid, 'receive_blog_name'   => $rcv_name,
                'level'         => $level,
                'reason'        => $reason,
            ];
        }

        // Sắp xếp: urgent (dưới MIN) trước, rồi thiếu, rồi thừa; trong nhóm theo |diff| giảm dần
        usort($suggestions, function ($a, $b) {
            $rank = function ($r) {
                if (($r['priority'] ?? '') === 'urgent') return 0;
                $isDeficit = in_array($r['intent'] ?? '', ['shop_request_from_warehouse', 'warehouse_purchase_more'], true);
                return $isDeficit ? 1 : 2;
            };
            $ra = $rank($a); $rb = $rank($b);
            if ($ra !== $rb) return $ra <=> $rb;
            return abs($b['diff']) <=> abs($a['diff']);
        });

        return [
            'blog_id'              => $bid,
            'blog_name'            => $blog_name,
            'source_kind'          => $kind,
            'parent_warehouse_id'  => $kho_pid,
            'parent_warehouse_name'=> $kho_name,
            'suggestions'          => $suggestions,
            'summary' => [
                'total'         => count($suggestions),
                'count_urgent'  => $count_urgent,
                'count_deficit' => $count_deficit,
                'count_surplus' => $count_surplus,
                'sum_urgent'    => round($sum_urgent, 3),
                'sum_deficit'   => round($sum_deficit, 3),
                'sum_surplus'   => round($sum_surplus, 3),
                'configured'    => count($configs),
            ],
        ];
    }

    public static function intent_label($intent)
    {
        switch ($intent) {
            case 'shop_request_from_warehouse': return 'Shop xin hàng từ kho';
            case 'shop_return_to_warehouse':    return 'Shop trả hàng về kho';
            case 'shop_transfer_to_shop':       return 'Shop chuyển hàng sang shop';
            case 'warehouse_purchase_more':     return 'Kho mua thêm';
            case 'warehouse_warning':           return 'Kho thừa (cảnh báo)';
        }
        return $intent;
    }

    public static function priority_label($priority)
    {
        switch ($priority) {
            case 'urgent': return 'Mua gấp (dưới MIN)';
            case 'normal': return 'Nên mua';
            case 'info':   return 'Cảnh báo';
        }
        return $priority;
    }

    /** Format số gọn cho thông báo (bỏ .000 nếu là số nguyên). */
    private static function n($v)
    {
        $v = (float) $v;
        if (abs($v - round($v)) < 0.001) return number_format($v, 0, ',', '.');
        return number_format($v, 3, ',', '.');
    }
}
