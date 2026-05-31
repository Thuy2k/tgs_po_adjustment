<?php
/**
 * View: Danh sách PO điều chỉnh.
 * 2 tab:
 *  - Theo phiếu (mặc định): bảng danh sách header, lọc theo blog hiện tại.
 *  - Theo SKU: gộp incoming/outgoing/expected/shortage để gợi ý mua thêm.
 */
if (!defined('ABSPATH')) exit;

$ajax_url = admin_url('admin-ajax.php');
$nonce    = wp_create_nonce('tgs_poa_nonce');
$detail_base = admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_DETAIL);
$scan_url = admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_SCAN);
$create_url = admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_CREATE);
$current_bid  = (int) get_current_blog_id();
$current_name = get_bloginfo('name');
?>
<div class="container-xxl flex-grow-1 container-p-y" id="tgs-poa-list-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bx bx-list-check me-1"></i>PO điều chỉnh</h4>
            <div class="text-muted small">
                Website: <b><?php echo esc_html($current_name); ?></b>
                <span class="text-muted">#<?php echo (int) $current_bid; ?></span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?php echo esc_url($scan_url); ?>" class="btn btn-outline-primary">
                <i class="bx bx-radar me-1"></i> Quét tồn để tạo PO
            </a>
            <a href="<?php echo esc_url($create_url); ?>" class="btn btn-primary">
                <i class="bx bx-plus-circle me-1"></i> Tạo PO chủ động
            </a>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a href="#tab-by-order" class="nav-link active" data-bs-toggle="tab" role="tab">
                <i class="bx bx-receipt me-1"></i> Theo phiếu
            </a>
        </li>
        <li class="nav-item">
            <a href="#tab-by-sku" class="nav-link" data-bs-toggle="tab" role="tab">
                <i class="bx bx-package me-1"></i> Theo SKU (tổng hợp)
            </a>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ===== TAB: THEO PHIẾU ===== -->
        <div class="tab-pane fade show active" id="tab-by-order" role="tabpanel">
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <input type="text" id="poa-list-search" class="form-control" placeholder="Tìm mã phiếu...">
                        </div>
                        <div class="col-md-2">
                            <select id="poa-list-status" class="form-select">
                                <option value="">Tất cả trạng thái</option>
                                <option value="pending">Chờ duyệt</option>
                                <option value="approved">Đã duyệt</option>
                                <option value="rejected">Từ chối</option>
                                <option value="cancelled">Đã hủy</option>
                                <option value="converted">Đã chuyển phiếu</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select id="poa-list-intent" class="form-select">
                                <option value="">Tất cả loại</option>
                                <option value="shop_request_from_warehouse">Shop xin hàng</option>
                                <option value="shop_return_to_warehouse">Shop trả hàng</option>
                                <option value="shop_transfer_to_shop">Shop ↔ Shop</option>
                                <option value="warehouse_purchase_more">Kho mua thêm</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select id="poa-list-scope" class="form-select">
                                <option value="current">Liên quan website này</option>
                                <option value="all">Toàn hệ thống</option>
                            </select>
                        </div>
                        <div class="col-md-2 text-end">
                            <button id="btn-poa-list-refresh" class="btn btn-outline-secondary w-100">
                                <i class="bx bx-refresh"></i> Tải lại
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive text-nowrap">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Mã phiếu</th>
                                <th>Loại</th>
                                <th>Yêu cầu</th>
                                <th>Chuyển</th>
                                <th>Nhận</th>
                                <th class="text-end">Số dòng</th>
                                <th class="text-end">Tổng SL</th>
                                <th>Trạng thái</th>
                                <th>Ngày tạo</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="poa-list-rows">
                            <tr><td colspan="10" class="text-center text-muted py-4">
                                <span class="spinner-border spinner-border-sm me-1"></span> Đang tải...
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== TAB: THEO SKU ===== -->
        <div class="tab-pane fade" id="tab-by-sku" role="tabpanel">
            <div class="alert alert-info small">
                <b>Cách hiểu:</b> Bảng này tổng hợp tất cả các PO đang <i>Chờ duyệt</i> hoặc <i>Đã duyệt</i> liên quan đến website hiện tại.
                <ul class="mb-0">
                    <li><b>Sẽ nhận</b> = tổng số lượng từ các PO mà website này là nơi nhận.</li>
                    <li><b>Sẽ chuyển</b> = tổng số lượng từ các PO mà website này là nơi chuyển đi.</li>
                    <li><b>Tồn dự kiến</b> = Tồn hiện tại + (Sẽ nhận − Sẽ chuyển).</li>
                    <li><b>Còn thiếu so với Max</b> &gt; 0 → vẫn cần tạo thêm phiếu mua / xin.</li>
                </ul>
            </div>
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" id="poa-sku-search" class="form-control" placeholder="Tìm SKU hoặc tên...">
                        </div>
                        <div class="col-md-4">
                            <select id="poa-sku-filter" class="form-select">
                                <option value="">Tất cả</option>
                                <option value="shortage">Còn thiếu (cần mua thêm)</option>
                                <option value="enough">Đã đủ / dư</option>
                            </select>
                        </div>
                        <div class="col-md-4 text-end">
                            <button id="btn-poa-sku-refresh" class="btn btn-outline-secondary">
                                <i class="bx bx-refresh"></i> Tải lại
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive text-nowrap">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>SKU</th>
                                <th>Tên hàng</th>
                                <th class="text-end">Tồn hiện</th>
                                <th class="text-end">Min</th>
                                <th class="text-end">Max</th>
                                <th class="text-end">Sẽ nhận</th>
                                <th class="text-end">Sẽ chuyển</th>
                                <th class="text-end">Tồn dự kiến</th>
                                <th class="text-end">Còn thiếu so Max</th>
                                <th class="text-end">Gợi ý mua</th>
                            </tr>
                        </thead>
                        <tbody id="poa-sku-rows">
                            <tr><td colspan="10" class="text-center text-muted py-4">
                                Bấm vào tab này để tải tổng hợp...
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
(function ($) {
    'use strict';
    var POA = {
        ajax: <?php echo wp_json_encode($ajax_url); ?>,
        nonce: <?php echo wp_json_encode($nonce); ?>,
        detailBase: <?php echo wp_json_encode($detail_base); ?>,
        bid: <?php echo (int) $current_bid; ?>
    };

    var orderState = { rows: [] };
    var skuState   = { rows: [], loaded: false };

    function fmt(n) {
        if (n === null || n === undefined || n === '') return '0';
        var v = parseFloat(n);
        if (isNaN(v)) return '0';
        if (Math.abs(v - Math.round(v)) < 0.001) return Math.round(v).toLocaleString('vi-VN');
        return v.toLocaleString('vi-VN', {maximumFractionDigits: 3});
    }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

    var INTENT_LBL = {
        'shop_request_from_warehouse': ['Shop xin hàng', 'bg-label-warning'],
        'shop_transfer_to_shop':       ['Shop ↔ Shop',   'bg-label-info'],
        'shop_return_to_warehouse':    ['Shop trả hàng', 'bg-label-info'],
        'warehouse_purchase_more':     ['Kho mua thêm',  'bg-label-warning'],
        'warehouse_warning':           ['Kho thừa CB',   'bg-label-secondary']
    };
    var STATUS_LBL = {
        'draft':     ['Nháp', 'bg-label-secondary'],
        'pending':   ['Chờ duyệt', 'bg-label-warning'],
        'approved':  ['Đã duyệt', 'bg-label-success'],
        'rejected':  ['Từ chối', 'bg-label-danger'],
        'cancelled': ['Đã hủy', 'bg-label-secondary'],
        'converted': ['Đã chuyển phiếu', 'bg-label-primary']
    };

    function badge(map, key) {
        var info = map[key] || [key, 'bg-label-secondary'];
        return '<span class="badge ' + info[1] + '">' + esc(info[0]) + '</span>';
    }

    function placeCell(id, name) {
        if (!id || id == 0) return '<span class="text-muted">—</span>';
        return esc(name || ('Blog #' + id)) + ' <span class="text-muted small">#' + id + '</span>';
    }

    function loadOrders() {
        var $tb = $('#poa-list-rows');
        $tb.html('<tr><td colspan="10" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Đang tải...</td></tr>');
        $.post(POA.ajax, {
            action: 'tgs_poa_list',
            nonce: POA.nonce,
            search: $('#poa-list-search').val(),
            status: $('#poa-list-status').val(),
            intent: $('#poa-list-intent').val(),
            scope:  $('#poa-list-scope').val()
        }).done(function (resp) {
            if (!resp || !resp.success) {
                $tb.html('<tr><td colspan="10" class="text-center text-danger py-4">Lỗi tải dữ liệu.</td></tr>');
                return;
            }
            orderState.rows = resp.data.rows || [];
            renderOrders();
        }).fail(function () {
            $tb.html('<tr><td colspan="10" class="text-center text-danger py-4">Lỗi mạng.</td></tr>');
        });
    }

    function renderOrders() {
        var $tb = $('#poa-list-rows');
        if (!orderState.rows.length) {
            $tb.html('<tr><td colspan="10" class="text-center text-muted py-4">Chưa có phiếu nào.</td></tr>');
            return;
        }
        var html = '';
        orderState.rows.forEach(function (r) {
            var detailUrl = POA.detailBase + '&po_id=' + r.po_id;
            html += '<tr>'
                  +   '<td><a href="' + detailUrl + '"><code>' + esc(r.po_code) + '</code></a></td>'
                  +   '<td>' + badge(INTENT_LBL, r.intent) + '</td>'
                  +   '<td>' + placeCell(r.request_blog_id, r.request_blog_name) + '</td>'
                  +   '<td>' + placeCell(r.transfer_blog_id, r.transfer_blog_name) + '</td>'
                  +   '<td>' + placeCell(r.receive_blog_id,  r.receive_blog_name)  + '</td>'
                  +   '<td class="text-end">' + fmt(r.total_items) + '</td>'
                  +   '<td class="text-end fw-semibold">' + fmt(r.total_quantity) + '</td>'
                  +   '<td>' + badge(STATUS_LBL, r.status) + '</td>'
                  +   '<td class="small text-muted">' + esc(r.created_at || '') + '</td>'
                  +   '<td><a href="' + detailUrl + '" class="btn btn-sm btn-outline-primary"><i class="bx bx-show"></i></a></td>'
                  + '</tr>';
        });
        $tb.html(html);
    }

    function loadSku() {
        var $tb = $('#poa-sku-rows');
        $tb.html('<tr><td colspan="10" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Đang tổng hợp...</td></tr>');
        $.post(POA.ajax, {
            action: 'tgs_poa_group_by_sku',
            nonce: POA.nonce
        }).done(function (resp) {
            if (!resp || !resp.success) {
                $tb.html('<tr><td colspan="10" class="text-center text-danger py-4">Lỗi tải dữ liệu.</td></tr>');
                return;
            }
            skuState.rows = resp.data.rows || [];
            skuState.loaded = true;
            renderSku();
        }).fail(function () {
            $tb.html('<tr><td colspan="10" class="text-center text-danger py-4">Lỗi mạng.</td></tr>');
        });
    }

    function renderSku() {
        var $tb = $('#poa-sku-rows');
        var s   = ($('#poa-sku-search').val() || '').toLowerCase().trim();
        var f   = $('#poa-sku-filter').val();
        var data = skuState.rows.filter(function (r) {
            if (f === 'shortage' && !(r.shortage > 0)) return false;
            if (f === 'enough'   &&  (r.shortage > 0)) return false;
            if (s) {
                var hay = (r.sku + ' ' + (r.name || '')).toLowerCase();
                if (hay.indexOf(s) === -1) return false;
            }
            return true;
        });
        if (!data.length) {
            $tb.html('<tr><td colspan="10" class="text-center text-muted py-4">Không có dữ liệu.</td></tr>');
            return;
        }
        var html = '';
        data.forEach(function (r) {
            var rowCls = r.shortage > 0 ? 'table-warning' : '';
            html += '<tr class="' + rowCls + '">'
                  +   '<td><code>' + esc(r.sku) + '</code></td>'
                  +   '<td>' + esc(r.name) + '</td>'
                  +   '<td class="text-end">' + fmt(r.current) + '</td>'
                  +   '<td class="text-end">' + fmt(r.min_qty) + '</td>'
                  +   '<td class="text-end">' + fmt(r.max_qty) + '</td>'
                  +   '<td class="text-end text-success">+' + fmt(r.incoming) + ' <span class="text-muted small">(' + r.in_count + ')</span></td>'
                  +   '<td class="text-end text-danger">-'  + fmt(r.outgoing) + ' <span class="text-muted small">(' + r.out_count + ')</span></td>'
                  +   '<td class="text-end fw-semibold">' + fmt(r.expected) + '</td>'
                  +   '<td class="text-end ' + (r.shortage > 0 ? 'text-warning fw-semibold' : 'text-muted') + '">' + fmt(r.shortage) + '</td>'
                  +   '<td class="text-end ' + (r.suggest_buy > 0 ? 'fw-semibold' : 'text-muted') + '">' + fmt(r.suggest_buy) + '</td>'
                  + '</tr>';
        });
        $tb.html(html);
    }

    // Event bindings
    $(document).on('click', '#btn-poa-list-refresh', loadOrders);
    $(document).on('change', '#poa-list-status, #poa-list-intent, #poa-list-scope', loadOrders);
    $(document).on('input', '#poa-list-search', function () {
        clearTimeout(window.__poaListTm);
        window.__poaListTm = setTimeout(loadOrders, 350);
    });
    $(document).on('click', '#btn-poa-sku-refresh', loadSku);
    $(document).on('change input', '#poa-sku-search, #poa-sku-filter', renderSku);
    $(document).on('shown.bs.tab', 'a[href="#tab-by-sku"]', function () {
        if (!skuState.loaded) loadSku();
    });

    $(function () { loadOrders(); });
})(jQuery);
</script>
