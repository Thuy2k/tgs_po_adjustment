<?php
/**
 * AJAX endpoints cho PO Điều chỉnh.
 *
 * - tgs_poa_scan          : quét tồn blog hiện tại + trả gợi ý PO.
 * - tgs_poa_export_excel  : trả về JSON dữ liệu Excel để JS tạo file (giữ kiểu giống stock-config).
 *
 * Quy ước bảo mật: yêu cầu manage_options + nonce 'tgs_poa_nonce'.
 */
if (!defined('ABSPATH')) exit;

class TGS_POA_Ajax
{
    public static function init()
    {
        add_action('wp_ajax_tgs_poa_scan',          [__CLASS__, 'ajax_scan']);
        add_action('wp_ajax_tgs_poa_supplier_stats',[__CLASS__, 'ajax_supplier_stats']);
        add_action('wp_ajax_tgs_poa_export_excel',  [__CLASS__, 'ajax_export']);
        add_action('wp_ajax_tgs_poa_create',        [__CLASS__, 'ajax_create']);
        add_action('wp_ajax_tgs_poa_list',          [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_tgs_poa_detail',        [__CLASS__, 'ajax_detail']);
        add_action('wp_ajax_tgs_poa_update_status', [__CLASS__, 'ajax_update_status']);
        add_action('wp_ajax_tgs_poa_update_items',  [__CLASS__, 'ajax_update_items']);
        add_action('wp_ajax_tgs_poa_group_by_sku',  [__CLASS__, 'ajax_group_by_sku']);
        add_action('wp_ajax_tgs_poa_get_blogs',     [__CLASS__, 'ajax_get_blogs']);
        add_action('wp_ajax_tgs_poa_search_sku',    [__CLASS__, 'ajax_search_sku']);
        add_action('wp_ajax_tgs_poa_create_manual', [__CLASS__, 'ajax_create_manual']);
    }

    private static function check()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Bạn không có quyền truy cập.'], 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'tgs_poa_nonce')) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
        }
    }

    public static function ajax_scan()
    {
        self::check();
        $bid = (int) get_current_blog_id();
        $result = TGS_POA_Helper::scan_blog($bid);
        wp_send_json_success($result);
    }

    /**
     * Supplier-centric view for smart stock scan.
     * Groups buy-needed scan rows by wp_global_supplier_product.product_sku.
     */
    public static function ajax_supplier_stats()
    {
        global $wpdb;
        self::check();

        $bid = (int) get_current_blog_id();
        $result = TGS_POA_Helper::scan_blog($bid);
        $suggestions = isset($result['suggestions']) && is_array($result['suggestions']) ? $result['suggestions'] : [];

        $buy_rows = array_values(array_filter($suggestions, function ($row) {
            $intent = isset($row['intent']) ? (string) $row['intent'] : '';
            $qty = isset($row['quantity']) ? (float) $row['quantity'] : 0;
            $max = isset($row['max_qty']) ? (float) $row['max_qty'] : 0;
            $cur = isset($row['current_stock']) ? (float) $row['current_stock'] : 0;
            return $qty > 0
                && $max > 0
                && $cur < $max
                && in_array($intent, ['warehouse_purchase_more', 'shop_request_from_warehouse'], true);
        }));

        $skus = [];
        foreach ($buy_rows as $row) {
            $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }

        $supplier_by_sku = [];
        $supplier_count_by_sku = [];
        if (!empty($skus)) {
            $link_table = $wpdb->base_prefix . 'global_supplier_product';
            $supplier_table = $wpdb->base_prefix . 'global_supplier';
            $link_exists = ((string) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $link_table)) === $link_table);
            $supplier_exists = ((string) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $supplier_table)) === $supplier_table);

            if ($link_exists && $supplier_exists) {
                $sku_values = array_keys($skus);
                foreach (array_chunk($sku_values, 400) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                    $sql = "SELECT gsp.product_sku,
                                   gs.supplier_id,
                                   gs.supplier_code,
                                   gs.supplier_name,
                                   gs.supplier_phone,
                                   gs.supplier_email
                            FROM {$link_table} gsp
                            INNER JOIN {$supplier_table} gs
                               ON gs.supplier_id = gsp.supplier_id
                              AND gs.is_deleted = 0
                            WHERE gsp.product_sku IN ({$placeholders})
                              AND gsp.product_sku IS NOT NULL
                              AND TRIM(gsp.product_sku) <> ''
                            ORDER BY gs.supplier_name ASC, gs.supplier_code ASC";
                    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$chunk), ARRAY_A);
                    foreach ((array) $rows as $r) {
                        $sku = trim((string) ($r['product_sku'] ?? ''));
                        $sid = (int) ($r['supplier_id'] ?? 0);
                        if ($sku === '' || $sid <= 0) {
                            continue;
                        }
                        if (!isset($supplier_by_sku[$sku])) {
                            $supplier_by_sku[$sku] = [];
                        }
                        $supplier_by_sku[$sku][$sid] = [
                            'supplier_id' => $sid,
                            'supplier_code' => (string) ($r['supplier_code'] ?? ''),
                            'supplier_name' => (string) ($r['supplier_name'] ?? ''),
                            'supplier_phone' => (string) ($r['supplier_phone'] ?? ''),
                            'supplier_email' => (string) ($r['supplier_email'] ?? ''),
                        ];
                    }
                }
            }
        }

        foreach ($supplier_by_sku as $sku => $suppliers) {
            $supplier_count_by_sku[$sku] = count($suppliers);
        }

        $groups = [];
        $no_supplier_key = 'none';
        $supplier_edit_base = admin_url('admin.php?page=tgs-shop-management&view=supplier-global-detail&id=');
        $supplier_list_url = admin_url('admin.php?page=tgs-shop-management&view=suppliers-global');
        $purchase_base_url = admin_url('admin.php?page=tgs-shop-management&view=purchase-add');

        $ensure_group = function ($key, $supplier) use (&$groups, $supplier_edit_base, $supplier_list_url, $purchase_base_url) {
            if (isset($groups[$key])) {
                return;
            }

            $sid = isset($supplier['supplier_id']) ? (int) $supplier['supplier_id'] : 0;
            $groups[$key] = [
                'key' => $key,
                'supplier_id' => $sid,
                'supplier_code' => $sid > 0 ? (string) ($supplier['supplier_code'] ?? '') : '',
                'supplier_name' => $sid > 0 ? (string) ($supplier['supplier_name'] ?? '') : 'Chua co NCC',
                'supplier_phone' => $sid > 0 ? (string) ($supplier['supplier_phone'] ?? '') : '',
                'supplier_email' => $sid > 0 ? (string) ($supplier['supplier_email'] ?? '') : '',
                'edit_url' => $sid > 0 ? ($supplier_edit_base . $sid) : $supplier_list_url,
                'purchase_url' => $sid > 0 ? add_query_arg(['supplier_id' => $sid], $purchase_base_url) : '',
                'items' => [],
                'count_total' => 0,
                'count_urgent' => 0,
                'count_normal' => 0,
                'sum_qty' => 0,
            ];
        };

        foreach ($buy_rows as $row) {
            $sku = isset($row['sku']) ? trim((string) $row['sku']) : '';
            if ($sku === '') {
                continue;
            }

            $suppliers = $supplier_by_sku[$sku] ?? [];
            if (empty($suppliers)) {
                $ensure_group($no_supplier_key, ['supplier_id' => 0]);
                $item = $row;
                $item['supplier_id'] = 0;
                $item['supplier_count'] = 0;
                $item['supplier_warning'] = 'SKU chua gan voi NCC nao.';
                $groups[$no_supplier_key]['items'][] = $item;
            } else {
                $supplier_count = $supplier_count_by_sku[$sku] ?? count($suppliers);
                foreach ($suppliers as $supplier) {
                    $sid = (int) ($supplier['supplier_id'] ?? 0);
                    if ($sid <= 0) {
                        continue;
                    }
                    $key = 's' . $sid;
                    $ensure_group($key, $supplier);
                    $item = $row;
                    $item['supplier_id'] = $sid;
                    $item['supplier_count'] = $supplier_count;
                    $item['supplier_warning'] = $supplier_count > 1 ? 'SKU nay dang gan voi nhieu NCC.' : '';
                    $groups[$key]['items'][] = $item;
                }
            }
        }

        foreach ($groups as $key => &$group) {
            $group['count_total'] = count($group['items']);
            $group['count_urgent'] = 0;
            $group['count_normal'] = 0;
            $group['sum_qty'] = 0;
            foreach ($group['items'] as $item) {
                if (($item['priority'] ?? '') === 'urgent') {
                    $group['count_urgent']++;
                } else {
                    $group['count_normal']++;
                }
                $group['sum_qty'] += (float) ($item['quantity'] ?? 0);
            }
            $group['sum_qty'] = round($group['sum_qty'], 3);
        }
        unset($group);

        $no_supplier_count = isset($groups[$no_supplier_key]) ? count($groups[$no_supplier_key]['items']) : 0;
        $groups = array_values($groups);
        usort($groups, function ($a, $b) use ($no_supplier_key) {
            if (($a['key'] ?? '') === $no_supplier_key) return 1;
            if (($b['key'] ?? '') === $no_supplier_key) return -1;
            if (($a['count_urgent'] ?? 0) !== ($b['count_urgent'] ?? 0)) {
                return ($b['count_urgent'] ?? 0) <=> ($a['count_urgent'] ?? 0);
            }
            if (($a['sum_qty'] ?? 0) !== ($b['sum_qty'] ?? 0)) {
                return ($b['sum_qty'] ?? 0) <=> ($a['sum_qty'] ?? 0);
            }
            return strcasecmp((string) ($a['supplier_name'] ?? ''), (string) ($b['supplier_name'] ?? ''));
        });

        wp_send_json_success([
            'blog_id' => $result['blog_id'] ?? $bid,
            'blog_name' => $result['blog_name'] ?? TGS_POA_Helper::get_blog_name($bid),
            'source_kind' => $result['source_kind'] ?? '',
            'parent_warehouse_id' => $result['parent_warehouse_id'] ?? 0,
            'parent_warehouse_name' => $result['parent_warehouse_name'] ?? '',
            'groups' => $groups,
            'summary' => [
                'supplier_count' => count(array_filter($groups, function ($g) { return (int) ($g['supplier_id'] ?? 0) > 0; })),
                'sku_count' => count($skus),
                'row_count' => count($buy_rows),
                'no_supplier_count' => $no_supplier_count,
            ],
        ]);
    }

    public static function ajax_export()
    {
        self::check();
        $bid = (int) get_current_blog_id();
        $result = TGS_POA_Helper::scan_blog($bid);

        $rows = [];
        foreach ($result['suggestions'] as $s) {
            $rows[] = [
                'sku'                  => $s['sku'],
                'name'                 => $s['name'],
                'current_stock'        => $s['current_stock'],
                'min_qty'              => $s['min_qty'],
                'max_qty'              => $s['max_qty'],
                'diff'                 => $s['diff'],
                'intent_label'         => $s['intent_label'],
                'quantity'             => $s['quantity'],
                'request_blog_id'      => $s['request_blog_id'],
                'request_blog_name'    => $s['request_blog_name'],
                'transfer_blog_id'     => $s['transfer_blog_id'],
                'transfer_blog_name'   => $s['transfer_blog_name'],
                'receive_blog_id'      => $s['receive_blog_id'],
                'receive_blog_name'    => $s['receive_blog_name'],
                'reason'               => $s['reason'],
            ];
        }

        wp_send_json_success([
            'blog_id'    => $result['blog_id'],
            'blog_name'  => $result['blog_name'],
            'source_kind'=> $result['source_kind'],
            'rows'       => $rows,
            'summary'    => $result['summary'],
        ]);
    }

    /**
     * Tạo PO từ các dòng đã chọn ở trang quét tồn.
     * Gom nhóm theo (intent, transfer_blog_id, receive_blog_id) → mỗi nhóm tạo 1 PO.
     * POST:
     *   items: array các object {sku,name,quantity,current_stock,min_qty,max_qty,intent,
     *           request_blog_id,request_blog_name,
     *           transfer_blog_id,transfer_blog_name,
     *           receive_blog_id,receive_blog_name,reason}
     *   note: ghi chú chung (optional)
     */
    public static function ajax_create()
    {
        global $wpdb;
        self::check();

        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $note      = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        $items     = is_string($items_raw) ? json_decode($items_raw, true) : (array) $items_raw;

        if (!is_array($items) || empty($items)) {
            wp_send_json_error(['message' => 'Không có dòng nào được chọn.']);
        }

        // Bỏ qua loại "warehouse_warning" — chỉ là cảnh báo, không tạo PO
        $items = array_values(array_filter($items, function ($it) {
            $intent = isset($it['intent']) ? (string) $it['intent'] : '';
            $qty    = isset($it['quantity']) ? (float) $it['quantity'] : 0;
            return $intent !== '' && $intent !== 'warehouse_warning' && $qty > 0;
        }));

        if (empty($items)) {
            wp_send_json_error(['message' => 'Không có dòng hợp lệ để tạo PO (đã loại "Kho thừa cảnh báo" và dòng SL = 0).']);
        }

        // Gom nhóm
        $groups = [];
        foreach ($items as $it) {
            $key = ($it['intent'] ?? '') . '|' . (int) ($it['transfer_blog_id'] ?? 0) . '|' . (int) ($it['receive_blog_id'] ?? 0);
            if (!isset($groups[$key])) $groups[$key] = [];
            $groups[$key][] = $it;
        }

        $tbl_h = TGS_POA_Database::table_header();
        $tbl_i = TGS_POA_Database::table_item();
        $now   = current_time('mysql');
        $uid   = (int) get_current_user_id();
        $created = [];

        foreach ($groups as $key => $rows) {
            $first = $rows[0];
            $intent = (string) ($first['intent'] ?? '');
            $source_kind = (in_array($intent, ['warehouse_purchase_more','warehouse_warning'], true)) ? 'warehouse' : 'shop';

            $total_items = count($rows);
            $total_qty   = 0;
            foreach ($rows as $r) $total_qty += (float) ($r['quantity'] ?? 0);

            $code = self::gen_po_code($intent);

            $ok = $wpdb->insert($tbl_h, [
                'po_code'            => $code,
                'source_kind'        => $source_kind,
                'intent'             => $intent,
                'request_blog_id'    => (int) ($first['request_blog_id'] ?? get_current_blog_id()),
                'request_blog_name'  => sanitize_text_field((string) ($first['request_blog_name'] ?? '')),
                'transfer_blog_id'   => ((int) ($first['transfer_blog_id'] ?? 0)) ?: null,
                'transfer_blog_name' => sanitize_text_field((string) ($first['transfer_blog_name'] ?? '')),
                'receive_blog_id'    => (int) ($first['receive_blog_id'] ?? 0),
                'receive_blog_name'  => sanitize_text_field((string) ($first['receive_blog_name'] ?? '')),
                'status'             => 'pending',
                'note'               => $note,
                'total_items'        => $total_items,
                'total_quantity'     => $total_qty,
                'created_by'         => $uid ?: null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            if ($ok === false) continue;
            $po_id = (int) $wpdb->insert_id;

            foreach ($rows as $r) {
                $wpdb->insert($tbl_i, [
                    'po_id'         => $po_id,
                    'product_sku'   => sanitize_text_field((string) ($r['sku'] ?? '')),
                    'product_name'  => sanitize_text_field((string) ($r['name'] ?? '')),
                    'quantity'      => (float) ($r['quantity'] ?? 0),
                    'current_stock' => (float) ($r['current_stock'] ?? 0),
                    'min_qty'       => (int) ($r['min_qty'] ?? 0),
                    'max_qty'       => (int) ($r['max_qty'] ?? 0),
                    'note'          => sanitize_text_field((string) ($r['reason'] ?? '')),
                    'created_at'    => $now,
                ]);
            }

            $created[] = ['po_id' => $po_id, 'code' => $code, 'intent' => $intent, 'items' => $total_items];
        }

        wp_send_json_success([
            'created' => $created,
            'message' => sprintf('Đã tạo %d phiếu PO điều chỉnh.', count($created)),
        ]);
    }

    private static function gen_po_code($intent)
    {
        $prefix = 'POA';
        switch ($intent) {
            case 'shop_request_from_warehouse': $prefix = 'PXIN'; break; // Phiếu xin
            case 'shop_return_to_warehouse':    $prefix = 'PTRA'; break; // Phiếu trả
            case 'shop_transfer_to_shop':       $prefix = 'PCSH'; break; // Phiếu chuyển shop↔shop
            case 'warehouse_purchase_more':     $prefix = 'PMUA'; break; // Phiếu mua thêm
        }
        return $prefix . '-' . date('ymd') . '-' . substr(strtoupper(wp_generate_password(5, false, false)), 0, 5);
    }

    /**
     * Liệt kê PO. Mặc định lọc theo blog hiện tại (request|transfer|receive).
     * Hỗ trợ:  status, intent, search (po_code), date_from, date_to, scope (current|all).
     */
    public static function ajax_list()
    {
        global $wpdb;
        self::check();

        $tbl_h = TGS_POA_Database::table_header();

        $status   = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        $intent   = isset($_POST['intent']) ? sanitize_text_field(wp_unslash($_POST['intent'])) : '';
        $search   = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $scope    = isset($_POST['scope']) ? sanitize_text_field(wp_unslash($_POST['scope'])) : 'current';
        $bid      = (int) get_current_blog_id();

        $where = ['1=1'];
        $args  = [];

        if ($scope === 'current') {
            $where[] = '(request_blog_id = %d OR transfer_blog_id = %d OR receive_blog_id = %d)';
            array_push($args, $bid, $bid, $bid);
        }
        if ($status !== '') {
            $where[] = 'status = %s';
            $args[]  = $status;
        }
        if ($intent !== '') {
            $where[] = 'intent = %s';
            $args[]  = $intent;
        }
        if ($search !== '') {
            $where[] = '(po_code LIKE %s OR note LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $like, $like);
        }

        $sql = "SELECT * FROM {$tbl_h} WHERE " . implode(' AND ', $where) . " ORDER BY po_id DESC LIMIT 500";
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        wp_send_json_success([
            'rows'    => $rows ?: [],
            'blog_id' => $bid,
        ]);
    }

    public static function ajax_detail()
    {
        global $wpdb;
        self::check();
        $po_id = isset($_POST['po_id']) ? (int) $_POST['po_id'] : 0;
        if (!$po_id) wp_send_json_error(['message' => 'Thiếu po_id.']);

        $tbl_h = TGS_POA_Database::table_header();
        $tbl_i = TGS_POA_Database::table_item();

        $header = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tbl_h} WHERE po_id = %d", $po_id), ARRAY_A);
        if (!$header) wp_send_json_error(['message' => 'Không tìm thấy phiếu.']);

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tbl_i} WHERE po_id = %d ORDER BY item_id ASC", $po_id
        ), ARRAY_A);

        wp_send_json_success(['header' => $header, 'items' => $items ?: []]);
    }

    public static function ajax_update_status()
    {
        global $wpdb;
        self::check();
        $po_id  = isset($_POST['po_id']) ? (int) $_POST['po_id'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        $allowed = ['pending','approved','rejected','cancelled','converted'];
        if (!$po_id || !in_array($status, $allowed, true)) {
            wp_send_json_error(['message' => 'Tham số không hợp lệ.']);
        }
        $tbl_h = TGS_POA_Database::table_header();
        $now   = current_time('mysql');
        $update = [
            'status'     => $status,
            'updated_at' => $now,
        ];
        if ($status === 'approved') {
            $update['approved_at'] = $now;
            $update['approved_by'] = (int) get_current_user_id();
        }
        $ok = $wpdb->update($tbl_h, $update, ['po_id' => $po_id]);
        if ($ok === false) wp_send_json_error(['message' => 'Cập nhật thất bại.']);
        wp_send_json_success(['message' => 'Đã cập nhật trạng thái.']);
    }

    /**
     * Sửa SL/ghi chú từng dòng items khi phiếu đang ở trạng thái 'pending'.
     * POST:
     *   po_id : int
     *   items : JSON [{item_id, quantity, note}]
     * Chỉ update các dòng thuộc đúng po_id. Sau đó tính lại total_items + total_quantity.
     */
    public static function ajax_update_items()
    {
        global $wpdb;
        self::check();

        $po_id    = isset($_POST['po_id']) ? (int) $_POST['po_id'] : 0;
        $items_raw = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items    = is_string($items_raw) ? json_decode($items_raw, true) : (array) $items_raw;
        if (!$po_id || !is_array($items)) wp_send_json_error(['message' => 'Tham số không hợp lệ.']);

        $tbl_h = TGS_POA_Database::table_header();
        $tbl_i = TGS_POA_Database::table_item();

        $h = $wpdb->get_row($wpdb->prepare("SELECT po_id, status FROM {$tbl_h} WHERE po_id = %d", $po_id), ARRAY_A);
        if (!$h) wp_send_json_error(['message' => 'Không tìm thấy phiếu.']);
        if ($h['status'] !== 'pending') {
            wp_send_json_error(['message' => 'Chỉ phiếu đang chờ duyệt mới được sửa số lượng.']);
        }

        $updated = 0;
        foreach ($items as $it) {
            $item_id = isset($it['item_id']) ? (int) $it['item_id'] : 0;
            if (!$item_id) continue;
            $qty  = isset($it['quantity']) ? (float) $it['quantity'] : 0;
            $note = isset($it['note']) ? sanitize_textarea_field((string) $it['note']) : '';
            if ($qty <= 0) continue;
            $r = $wpdb->update(
                $tbl_i,
                ['quantity' => $qty, 'note' => $note],
                ['item_id'  => $item_id, 'po_id' => $po_id]
            );
            if ($r !== false) $updated += $r;
        }

        // Tính lại tổng từ DB để chắc chắn đồng bộ
        $sum = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM(quantity),0) AS s FROM {$tbl_i} WHERE po_id = %d", $po_id
        ), ARRAY_A);

        $wpdb->update($tbl_h, [
            'total_items'    => (int) ($sum['c'] ?? 0),
            'total_quantity' => (float) ($sum['s'] ?? 0),
            'updated_at'     => current_time('mysql'),
        ], ['po_id' => $po_id]);

        wp_send_json_success([
            'message'        => 'Đã lưu thay đổi (' . $updated . ' dòng).',
            'total_items'    => (int) ($sum['c'] ?? 0),
            'total_quantity' => (float) ($sum['s'] ?? 0),
        ]);
    }

    /**
     * Tổng hợp theo SKU — góc nhìn của blog hiện tại:
     *  incoming  = Σ qty của items thuộc các PO active có receive_blog_id = blog
     *  outgoing  = Σ qty của items thuộc các PO active có transfer_blog_id = blog
     *  net       = incoming - outgoing
     *  current   = tồn hiện tại của blog
     *  max_qty   = từ wp_global_sku_stock_config
     *  expected  = current + net
     *  shortage  = max_qty - expected   (nếu > 0 → đề xuất mua thêm)
     *
     * "Active" = status IN ('pending','approved')
     */
    public static function ajax_group_by_sku()
    {
        global $wpdb;
        self::check();

        $bid    = (int) get_current_blog_id();
        $tbl_h  = TGS_POA_Database::table_header();
        $tbl_i  = TGS_POA_Database::table_item();

        $sql = "SELECT i.product_sku AS sku,
                       MAX(i.product_name) AS name,
                       SUM(CASE WHEN h.receive_blog_id = %d THEN i.quantity ELSE 0 END) AS incoming,
                       SUM(CASE WHEN h.transfer_blog_id = %d THEN i.quantity ELSE 0 END) AS outgoing,
                       SUM(CASE WHEN h.receive_blog_id = %d THEN 1 ELSE 0 END) AS in_count,
                       SUM(CASE WHEN h.transfer_blog_id = %d THEN 1 ELSE 0 END) AS out_count
                FROM {$tbl_i} i
                INNER JOIN {$tbl_h} h ON h.po_id = i.po_id
                WHERE h.status IN ('pending','approved')
                  AND (h.receive_blog_id = %d OR h.transfer_blog_id = %d)
                GROUP BY i.product_sku";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $bid, $bid, $bid, $bid, $bid, $bid), ARRAY_A);
        if (!$rows) $rows = [];

        $skus    = array_column($rows, 'sku');
        $configs = TGS_POA_Helper::get_stock_configs($bid);
        $stocks  = TGS_POA_Helper::get_current_stock_map($bid, $skus);

        $out = [];
        foreach ($rows as $r) {
            $sku       = (string) $r['sku'];
            $incoming  = (float) $r['incoming'];
            $outgoing  = (float) $r['outgoing'];
            $net       = $incoming - $outgoing;
            $current   = (float) ($stocks[$sku] ?? 0);
            $max_qty   = (int) ($configs[$sku]['max'] ?? 0);
            $min_qty   = (int) ($configs[$sku]['min'] ?? 0);
            $expected  = $current + $net;
            $shortage  = $max_qty > 0 ? ($max_qty - $expected) : 0;
            $name      = $r['name'] ?: ($configs[$sku]['name'] ?? '');

            $out[] = [
                'sku'        => $sku,
                'name'       => $name,
                'current'    => $current,
                'min_qty'    => $min_qty,
                'max_qty'    => $max_qty,
                'incoming'   => $incoming,
                'outgoing'   => $outgoing,
                'net'        => $net,
                'expected'   => $expected,
                'shortage'   => $shortage,
                'in_count'   => (int) $r['in_count'],
                'out_count'  => (int) $r['out_count'],
                'suggest_buy'=> $shortage > 0 ? round($shortage, 3) : 0,
            ];
        }

        usort($out, function ($a, $b) {
            return $b['shortage'] <=> $a['shortage']; // thiếu trước
        });

        wp_send_json_success(['rows' => $out, 'blog_id' => $bid]);
    }

    /**
     * Lấy danh sách blog (id, name, type, is_warehouse) — phục vụ form tạo PO chủ động.
     */
    public static function ajax_get_blogs()
    {
        self::check();
        $sites_info = class_exists('TGS_Hierarchy_Data') ? TGS_Hierarchy_Data::get_sites_info() : [];
        if (!is_array($sites_info)) $sites_info = [];
        $children = TGS_POA_Helper::get_children_count_map();

        $blogs = [];
        foreach ($sites_info as $bid => $info) {
            $bid = (int) $bid;
            if (!$bid) continue;
            $type = isset($info['type']) ? (string) $info['type'] : '';
            $name = isset($info['name']) ? (string) $info['name'] : '';
            if ($name === '') $name = TGS_POA_Helper::get_blog_name($bid);
            $is_kho = TGS_POA_Helper::is_warehouse($bid, $sites_info, $children);
            $blogs[] = [
                'id'           => $bid,
                'name'         => $name,
                'type'         => $type,
                'is_warehouse' => $is_kho ? 1 : 0,
            ];
        }
        usort($blogs, function ($a, $b) {
            if ($a['is_warehouse'] !== $b['is_warehouse']) return $b['is_warehouse'] - $a['is_warehouse'];
            return strcmp($a['name'], $b['name']);
        });
        wp_send_json_success(['blogs' => $blogs]);
    }

    /**
     * Tìm SKU trong 1 blog (mặc định blog hiện tại) để autocomplete khi nhập dòng PO.
     * POST: blog_id, q (search by sku/name), limit (default 30).
     *
     * Tìm kiếm thông minh: chuỗi truy vấn được tách thành các token (theo khoảng trắng),
     * mỗi token đều phải xuất hiện trong CONCAT(sku, ' ', name) (LIKE %token%).
     * Vd: "bimbosan g" → match cả "Bimbosan 400g".
     */
    public static function ajax_search_sku()
    {
        global $wpdb;
        self::check();
        $bid   = isset($_POST['blog_id']) ? (int) $_POST['blog_id'] : (int) get_current_blog_id();
        $q     = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $limit = isset($_POST['limit']) ? max(1, min(100, (int) $_POST['limit'])) : 30;

        if (!$bid) wp_send_json_error(['message' => 'Thiếu blog_id.']);

        $tbl = $wpdb->get_blog_prefix($bid) . 'local_product_name';
        $exists = ((string) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl)) === $tbl);
        if (!$exists) wp_send_json_success(['rows' => [], 'debug' => 'table_not_found:' . $tbl]);

        $base_select = "SELECT local_product_sku AS sku,
                               local_product_name AS name,
                               COALESCE(local_product_quantity_no_tracking, 0) AS qty
                        FROM {$tbl}
                        WHERE (is_deleted = 0 OR is_deleted IS NULL)";

        $q_trim = trim((string) $q);
        if ($q_trim === '') {
            $sql = $base_select . " ORDER BY local_product_name_id DESC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
            wp_send_json_success(['rows' => $rows ?: []]);
        }

        // Tách token (theo khoảng trắng), bỏ token rỗng, giới hạn 5 token
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $q_trim) ?: [],
            function ($t) { return trim($t) !== ''; }
        ));
        if (count($tokens) > 5) $tokens = array_slice($tokens, 0, 5);

        $where_parts = [];
        $args        = [];
        foreach ($tokens as $tok) {
            $like = '%' . $wpdb->esc_like($tok) . '%';
            // CONCAT_WS xử lý NULL an toàn
            $where_parts[] = "CONCAT_WS(' ', local_product_sku, local_product_name) LIKE %s";
            $args[]        = $like;
        }
        $where_sql = implode(' AND ', $where_parts);

        $sql = $base_select
             . " AND " . $where_sql
             . " ORDER BY (local_product_sku LIKE %s) DESC, local_product_name_id DESC LIMIT %d";
        $args_full = array_merge($args, [$tokens[0] . '%', $limit]);

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args_full), ARRAY_A);
        wp_send_json_success(['rows' => $rows ?: []]);
    }

    /**
     * Tạo 1 PO chủ động (manual). Cho phép shop ↔ shop, không nhất thiết đi qua kho.
     * POST:
     *   intent       : shop_request_from_warehouse | shop_return_to_warehouse | warehouse_purchase_more | manual
     *   transfer_blog_id : nguồn chuyển (có thể 0 nếu mua thêm)
     *   receive_blog_id  : nguồn nhận (bắt buộc)
     *   note            : ghi chú chung
     *   items           : JSON [{sku, name, quantity, note}]
     */
    public static function ajax_create_manual()
    {
        global $wpdb;
        self::check();

        $intent       = isset($_POST['intent']) ? sanitize_text_field(wp_unslash($_POST['intent'])) : 'manual';
        $transfer_bid = isset($_POST['transfer_blog_id']) ? (int) $_POST['transfer_blog_id'] : 0;
        $receive_bid  = isset($_POST['receive_blog_id']) ? (int) $_POST['receive_blog_id'] : 0;
        $note         = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        $items_raw    = isset($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items        = is_string($items_raw) ? json_decode($items_raw, true) : (array) $items_raw;

        if (!$receive_bid) wp_send_json_error(['message' => 'Vui lòng chọn nguồn nhận.']);
        if (!is_array($items) || empty($items)) wp_send_json_error(['message' => 'Phiếu chưa có dòng SKU nào.']);

        // Lọc dòng hợp lệ
        $items = array_values(array_filter($items, function ($it) {
            return !empty($it['sku']) && (float) ($it['quantity'] ?? 0) > 0;
        }));
        if (empty($items)) wp_send_json_error(['message' => 'Tất cả dòng đều có SL = 0 hoặc thiếu SKU.']);

        $request_bid = (int) get_current_blog_id();
        $sites_info  = class_exists('TGS_Hierarchy_Data') ? TGS_Hierarchy_Data::get_sites_info() : [];
        if (!is_array($sites_info)) $sites_info = [];

        $req_name      = TGS_POA_Helper::get_blog_name($request_bid);
        $transfer_name = $transfer_bid ? TGS_POA_Helper::get_blog_name($transfer_bid) : '';
        $receive_name  = TGS_POA_Helper::get_blog_name($receive_bid);

        $is_request_kho = TGS_POA_Helper::is_warehouse($request_bid);
        $source_kind    = $is_request_kho ? 'warehouse' : 'shop';

        // Suy intent nếu user đặt 'manual'
        if ($intent === 'manual' || $intent === '') {
            if ($transfer_bid && $receive_bid) {
                $is_transfer_kho = TGS_POA_Helper::is_warehouse($transfer_bid);
                $is_receive_kho  = TGS_POA_Helper::is_warehouse($receive_bid);
                if (!$is_transfer_kho && !$is_receive_kho) {
                    $intent = 'shop_transfer_to_shop';
                } elseif ($is_transfer_kho && !$is_receive_kho) {
                    $intent = 'shop_request_from_warehouse';
                } elseif (!$is_transfer_kho && $is_receive_kho) {
                    $intent = 'shop_return_to_warehouse';
                } else {
                    $intent = $is_request_kho ? 'shop_return_to_warehouse' : 'shop_request_from_warehouse';
                }
            } else {
                $intent = 'warehouse_purchase_more';
            }
        }

        $tbl_h = TGS_POA_Database::table_header();
        $tbl_i = TGS_POA_Database::table_item();
        $now   = current_time('mysql');
        $uid   = (int) get_current_user_id();

        $total_items = count($items);
        $total_qty   = 0;
        foreach ($items as $it) $total_qty += (float) ($it['quantity'] ?? 0);

        $code = self::gen_po_code($intent);

        $ok = $wpdb->insert($tbl_h, [
            'po_code'            => $code,
            'source_kind'        => $source_kind,
            'intent'             => $intent,
            'request_blog_id'    => $request_bid,
            'request_blog_name'  => $req_name,
            'transfer_blog_id'   => $transfer_bid ?: null,
            'transfer_blog_name' => $transfer_name,
            'receive_blog_id'    => $receive_bid,
            'receive_blog_name'  => $receive_name,
            'status'             => 'pending',
            'note'               => $note,
            'total_items'        => $total_items,
            'total_quantity'     => $total_qty,
            'created_by'         => $uid ?: null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        if ($ok === false) wp_send_json_error(['message' => 'Lưu phiếu thất bại.']);
        $po_id = (int) $wpdb->insert_id;

        // Lấy thêm tồn hiện tại + min/max của blog yêu cầu để lưu vào item (snapshot)
        $skus    = array_map(function ($r) { return (string) ($r['sku'] ?? ''); }, $items);
        $configs = TGS_POA_Helper::get_stock_configs($request_bid);
        $stocks  = TGS_POA_Helper::get_current_stock_map($request_bid, $skus);

        foreach ($items as $r) {
            $sku = (string) ($r['sku'] ?? '');
            $cfg = $configs[$sku] ?? ['min'=>0, 'max'=>0, 'name'=>''];
            $name = !empty($r['name']) ? (string) $r['name'] : (string) ($cfg['name'] ?? '');
            $wpdb->insert($tbl_i, [
                'po_id'         => $po_id,
                'product_sku'   => sanitize_text_field($sku),
                'product_name'  => sanitize_text_field($name),
                'quantity'      => (float) ($r['quantity'] ?? 0),
                'current_stock' => (float) ($stocks[$sku] ?? 0),
                'min_qty'       => (int) ($cfg['min'] ?? 0),
                'max_qty'       => (int) ($cfg['max'] ?? 0),
                'note'          => sanitize_text_field((string) ($r['note'] ?? '')),
                'created_at'    => $now,
            ]);
        }

        wp_send_json_success([
            'po_id'   => $po_id,
            'po_code' => $code,
            'intent'  => $intent,
            'message' => 'Đã tạo phiếu ' . $code . '.',
        ]);
    }
}
