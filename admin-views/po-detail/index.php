<?php
/**
 * View: Chi tiết PO điều chỉnh.
 * Yêu cầu query param ?po_id=
 *  - Hiển thị header + danh sách item.
 *  - Cho phép cập nhật trạng thái (Duyệt / Từ chối / Hủy / Đánh dấu đã chuyển phiếu).
 */
if (!defined('ABSPATH')) exit;

$ajax_url   = admin_url('admin-ajax.php');
$nonce      = wp_create_nonce('tgs_poa_nonce');
$po_id      = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$list_url   = admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_LIST);
?>
<div class="container-xxl flex-grow-1 container-p-y" id="tgs-poa-detail-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bx bx-receipt me-1"></i>Chi tiết PO điều chỉnh</h4>
            <a href="<?php echo esc_url($list_url); ?>" class="text-muted small">
                <i class="bx bx-arrow-back"></i> Quay lại danh sách
            </a>
        </div>
        <div id="poa-detail-actions" class="d-flex gap-2"></div>
    </div>

    <?php if (!$po_id): ?>
        <div class="alert alert-danger">Thiếu tham số <code>po_id</code>.</div>
    <?php else: ?>

    <!-- SheetJS for xlsx export -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.20.3/dist/xlsx.full.min.js"></script>

    <div class="card mb-3">
        <div class="card-body" id="poa-detail-header">
            <div class="text-center text-muted py-3">
                <span class="spinner-border spinner-border-sm me-1"></span> Đang tải...
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2"><b>Danh sách hàng hoá</b></div>
        <div class="table-responsive text-nowrap">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px">#</th>
                        <th>SKU</th>
                        <th>Tên hàng</th>
                        <th class="text-end">Tồn lúc tạo</th>
                        <th class="text-end">Min</th>
                        <th class="text-end">Max</th>
                        <th class="text-end">SL yêu cầu</th>
                        <th>Ghi chú</th>
                    </tr>
                </thead>
                <tbody id="poa-detail-items">
                    <tr><td colspan="8" class="text-center text-muted py-4">—</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    (function ($) {
        'use strict';
        var POA = {
            ajax: <?php echo wp_json_encode($ajax_url); ?>,
            nonce: <?php echo wp_json_encode($nonce); ?>,
            po_id: <?php echo (int) $po_id; ?>,
            adminUrl: <?php echo wp_json_encode(admin_url('admin.php')); ?>
        };

        var lastHeader = null, lastItems = [], editMode = false;

        function fmt(n) {
            if (n === null || n === undefined || n === '') return '0';
            var v = parseFloat(n);
            if (isNaN(v)) return '0';
            if (Math.abs(v - Math.round(v)) < 0.001) return Math.round(v).toLocaleString('vi-VN');
            return v.toLocaleString('vi-VN', {maximumFractionDigits: 3});
        }
        function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

        var INTENT = {
            'shop_request_from_warehouse': 'Shop xin hàng từ kho',
            'shop_return_to_warehouse':    'Shop trả hàng về kho',
            'shop_transfer_to_shop':       'Shop chuyển hàng sang shop',
            'warehouse_purchase_more':     'Kho mua thêm',
            'warehouse_warning':           'Kho thừa (cảnh báo)'
        };
        var STATUS = {
            'draft':'Nháp','pending':'Chờ duyệt','approved':'Đã duyệt',
            'rejected':'Từ chối','cancelled':'Đã hủy','converted':'Đã chuyển phiếu'
        };
        var STATUS_CLS = {
            'draft':'bg-label-secondary','pending':'bg-label-warning','approved':'bg-label-success',
            'rejected':'bg-label-danger','cancelled':'bg-label-secondary','converted':'bg-label-primary'
        };

        function placeBlock(label, id, name) {
            var v = id ? (esc(name || ('Blog #' + id)) + ' <span class="text-muted small">#' + id + '</span>')
                       : '<span class="text-muted">—</span>';
            return '<div class="col-md-4"><div class="small text-muted">' + label + '</div><div class="fw-semibold">' + v + '</div></div>';
        }

        function renderHeader(h) {
            var statusCls = STATUS_CLS[h.status] || 'bg-label-secondary';
            var html = ''
              + '<div class="d-flex flex-wrap justify-content-between align-items-start mb-2">'
              +   '<div>'
              +     '<h5 class="mb-1"><code>' + esc(h.po_code) + '</code></h5>'
              +     '<div class="text-muted small">' + esc(INTENT[h.intent] || h.intent) + '</div>'
              +   '</div>'
              +   '<span class="badge ' + statusCls + ' fs-6">' + esc(STATUS[h.status] || h.status) + '</span>'
              + '</div>'
              + '<hr class="my-2">'
              + '<div class="row g-3">'
              +   placeBlock('Yêu cầu', h.request_blog_id, h.request_blog_name)
              +   placeBlock('Chuyển hàng', h.transfer_blog_id, h.transfer_blog_name)
              +   placeBlock('Nhận hàng', h.receive_blog_id, h.receive_blog_name)
              + '</div>'
              + '<div class="row g-3 mt-1">'
              +   '<div class="col-md-3"><div class="small text-muted">Số dòng</div><div class="fw-semibold">' + fmt(h.total_items) + '</div></div>'
              +   '<div class="col-md-3"><div class="small text-muted">Tổng SL</div><div class="fw-semibold">' + fmt(h.total_quantity) + '</div></div>'
              +   '<div class="col-md-3"><div class="small text-muted">Ngày tạo</div><div class="fw-semibold">' + esc(h.created_at || '') + '</div></div>'
              +   '<div class="col-md-3"><div class="small text-muted">Cập nhật</div><div class="fw-semibold">' + esc(h.updated_at || '') + '</div></div>'
              + '</div>'
              + (h.note ? '<div class="mt-2"><div class="small text-muted">Ghi chú</div><div>' + esc(h.note) + '</div></div>' : '');

            $('#poa-detail-header').html(html);

            // Render action buttons
            var $act = $('#poa-detail-actions').empty();
            // Nút xuất Excel luôn hiển thị
            $act.append('<button class="btn btn-outline-success" id="btn-poa-d-export"><i class="bx bx-download"></i> Xuất Excel</button>');
            if (h.status === 'pending') {
                if (editMode) {
                    $act.append('<button class="btn btn-warning" id="btn-poa-d-save-items" style="display:none"><i class="bx bx-save"></i> Lưu thay đổi</button>');
                    $act.append('<button class="btn btn-outline-secondary" id="btn-poa-d-cancel-edit"><i class="bx bx-x"></i> Tắt sửa</button>');
                } else {
                    $act.append('<button class="btn btn-outline-warning" id="btn-poa-d-edit"><i class="bx bx-edit"></i> Sửa</button>');
                    $act.append('<button class="btn btn-success" data-status="approved"><i class="bx bx-check"></i> Duyệt</button>');
                    $act.append('<button class="btn btn-outline-danger" data-status="rejected"><i class="bx bx-x"></i> Từ chối</button>');
                    $act.append('<button class="btn btn-outline-secondary" data-status="cancelled"><i class="bx bx-trash"></i> Hủy</button>');
                }
            } else if (h.status === 'approved') {
                var copyDrop = '<div class="dropdown">'
                    + '<button class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">'
                    + '<i class="bx bx-copy-alt"></i> Tạo phiếu từ PO</button>'
                    + '<ul class="dropdown-menu">'
                    + '<li><a class="dropdown-item poa-copy-trigger" href="#" data-view="purchase-add"><i class="bx bx-cart me-1"></i>Phiếu mua hàng</a></li>'
                    + '<li><a class="dropdown-item poa-copy-trigger" href="#" data-view="transfer-export-add"><i class="bx bx-export me-1"></i>Phiếu bán hàng nội bộ</a></li>'
                    + '<li><a class="dropdown-item poa-copy-trigger" href="#" data-view="transfer-return-add"><i class="bx bx-undo me-1"></i>Phiếu trả hàng nội bộ</a></li>'
                    + '</ul></div>';
                $act.append(copyDrop);
                $act.append('<button class="btn btn-primary" data-status="converted"><i class="bx bx-transfer"></i> Đánh dấu đã chuyển phiếu</button>');
                $act.append('<button class="btn btn-outline-secondary" data-status="cancelled"><i class="bx bx-trash"></i> Hủy</button>');
            }
        }

        function renderItems(items) {
            var $tb = $('#poa-detail-items');
            if (!items.length) { $tb.html('<tr><td colspan="8" class="text-center text-muted py-4">Không có dòng nào.</td></tr>'); return; }
            var editable = editMode && lastHeader && lastHeader.status === 'pending';
            var html = '';
            items.forEach(function (it, i) {
                var qtyCell, noteCell;
                if (editable) {
                    qtyCell = '<input type="number" min="0.001" step="0.001" class="form-control form-control-sm text-end poa-d-qty"'
                            + ' data-item-id="' + (it.item_id || '') + '"'
                            + ' value="' + (parseFloat(it.quantity) || 0) + '">';
                    noteCell = '<input type="text" class="form-control form-control-sm poa-d-note"'
                            + ' data-item-id="' + (it.item_id || '') + '"'
                            + ' value="' + esc(it.note || '') + '" placeholder="Ghi chú dòng...">';
                } else {
                    qtyCell  = fmt(it.quantity);
                    noteCell = '<span class="small text-muted">' + esc(it.note || '') + '</span>';
                }
                html += '<tr data-item-id="' + (it.item_id || '') + '">'
                      + '<td>' + (i + 1) + '</td>'
                      + '<td><code>' + esc(it.product_sku) + '</code></td>'
                      + '<td>' + esc(it.product_name) + '</td>'
                      + '<td class="text-end">' + fmt(it.current_stock) + '</td>'
                      + '<td class="text-end">' + fmt(it.min_qty) + '</td>'
                      + '<td class="text-end">' + fmt(it.max_qty) + '</td>'
                      + '<td class="text-end fw-semibold">' + qtyCell + '</td>'
                      + '<td>' + noteCell + '</td>'
                      + '</tr>';
            });
            $tb.html(html);
        }

        // Theo dõi thay đổi để bật nút "Lưu thay đổi"
        function markDirty() {
            $('#btn-poa-d-save-items').show();
        }

        function saveItems() {
            if (!editMode || !lastHeader || lastHeader.status !== 'pending') return;
            var changed = [];
            $('#poa-detail-items tr[data-item-id]').each(function () {
                var $tr = $(this);
                var item_id = parseInt($tr.attr('data-item-id'), 10);
                if (!item_id) return;
                var qty = parseFloat($tr.find('.poa-d-qty').val());
                if (isNaN(qty) || qty <= 0) return;
                changed.push({
                    item_id:  item_id,
                    quantity: qty,
                    note:     ($tr.find('.poa-d-note').val() || '').trim()
                });
            });
            if (!changed.length) { alert('Không có dòng nào hợp lệ để lưu.'); return; }

            var $btn = $('#btn-poa-d-save-items');
            var oldHtml = $btn.html();
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Đang lưu...');

            $.post(POA.ajax, {
                action: 'tgs_poa_update_items',
                nonce:  POA.nonce,
                po_id:  POA.po_id,
                items:  JSON.stringify(changed)
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    alert((resp && resp.data && resp.data.message) || 'Cập nhật thất bại.');
                    return;
                }
                load();
            }).fail(function () { alert('Lỗi mạng / máy chủ.'); })
            .always(function () { $btn.prop('disabled', false).html(oldHtml); });
        }

        $(document).on('input change', '.poa-d-qty, .poa-d-note', markDirty);
        $(document).on('click', '#btn-poa-d-save-items', saveItems);

        // Bật chế độ sửa
        $(document).on('click', '#btn-poa-d-edit', function () {
            if (!lastHeader || lastHeader.status !== 'pending') return;
            editMode = true;
            renderHeader(lastHeader);
            renderItems(lastItems);
        });

        // Tắt chế độ sửa (huỷ thay đổi chưa lưu)
        $(document).on('click', '#btn-poa-d-cancel-edit', function () {
            // Nếu đã có thay đổi (nút Lưu đang hiện) → xác nhận
            if ($('#btn-poa-d-save-items').is(':visible')) {
                if (!confirm('Bạn có thay đổi chưa lưu. Tắt sửa sẽ huỷ các thay đổi này. Tiếp tục?')) return;
            }
            editMode = false;
            renderHeader(lastHeader);
            renderItems(lastItems);
        });

        function load() {
            $.post(POA.ajax, { action:'tgs_poa_detail', nonce:POA.nonce, po_id:POA.po_id })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    $('#poa-detail-header').html('<div class="text-danger">' + ((resp && resp.data && resp.data.message) || 'Lỗi tải dữ liệu.') + '</div>');
                    return;
                }
                lastHeader = resp.data.header;
                lastItems  = resp.data.items || [];
                editMode = false;
                renderHeader(lastHeader);
                renderItems(lastItems);
            }).fail(function () {
                $('#poa-detail-header').html('<div class="text-danger">Lỗi mạng / máy chủ.</div>');
            });
        }

        function calcDiff(it) {
            var cur = parseFloat(it.current_stock) || 0;
            var mn  = parseFloat(it.min_qty) || 0;
            var mx  = parseFloat(it.max_qty) || 0;
            // Ưu tiên so với max (đề xuất mua thêm/bù lên max). Nếu không có max thì so với min.
            if (mx > 0) return cur - mx;     // âm = thiếu so với max, dương = thừa so với max
            if (mn > 0) return cur - mn;     // âm = thiếu so với min
            return 0;
        }

        function safeFile(s) {
            return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'')
                .replace(/[^A-Za-z0-9_-]+/g,'_').replace(/_+/g,'_').replace(/^_|_$/g,'') || 'file';
        }

        function exportExcel() {
            if (!window.XLSX) { alert('Thư viện Excel chưa tải xong, thử lại sau.'); return; }
            if (!lastHeader) { alert('Chưa có dữ liệu phiếu.'); return; }
            if (!lastItems.length) { alert('Phiếu chưa có dòng nào để xuất.'); return; }

            var h = lastHeader;
            var srcKind = (h.source_kind === 'warehouse') ? 'Kho' : 'Shop';

            // Phát hiện kiểu shop/kho cho 3 vai trò dựa trên id và source_kind
            // (header có source_kind = vai trò của blog tạo phiếu)
            function placeStr(id, name) {
                if (!id) return '';
                return (name || ('Blog #' + id)) + ' (#' + id + ')';
            }

            // Khối thông tin phiếu (block trên cùng)
            var headerInfo = [
                ['PHIẾU PO ĐIỀU CHỈNH — ' + (h.po_code || '')],
                ['Loại đề xuất: ' + (INTENT[h.intent] || h.intent || '') + '   |   Trạng thái: ' + (STATUS[h.status] || h.status || '')],
                ['Nguồn phát sinh phiếu: ' + placeStr(h.request_blog_id, h.request_blog_name) + '   (' + srcKind + ')'],
                ['Nguồn chuyển hàng:    ' + (placeStr(h.transfer_blog_id, h.transfer_blog_name) || '— (mua thêm/không có)')],
                ['Nguồn nhận hàng:      ' + placeStr(h.receive_blog_id,  h.receive_blog_name)],
                ['Ngày tạo: ' + (h.created_at || '') + '   |   Cập nhật: ' + (h.updated_at || '') + '   |   Người tạo (UID): ' + (h.created_by || '')],
                ['Ghi chú phiếu: ' + (h.note || '—')],
                ['Thời điểm xuất: ' + new Date().toLocaleString('vi-VN')],
                []
            ];

            var headerCols = [
                'STT', 'SKU', 'Tên hàng',
                'Tồn hiện tại (lúc tạo)', 'Tồn min', 'Tồn max', 'Chênh lệch',
                'SL yêu cầu',
                'Blog ID yêu cầu', 'Tên blog yêu cầu',
                'Blog ID chuyển', 'Tên blog chuyển',
                'Blog ID nhận', 'Tên blog nhận',
                'Ghi chú dòng'
            ];
            var aoa = headerInfo.concat([headerCols]);

            var sumQty = 0;
            lastItems.forEach(function (it, i) {
                var qty = parseFloat(it.quantity) || 0;
                sumQty += qty;
                aoa.push([
                    i + 1,
                    it.product_sku || '',
                    it.product_name || '',
                    Number(it.current_stock) || 0,
                    Number(it.min_qty) || 0,
                    Number(it.max_qty) || 0,
                    calcDiff(it),
                    qty,
                    h.request_blog_id  || '', h.request_blog_name  || '',
                    h.transfer_blog_id || '', h.transfer_blog_name || '',
                    h.receive_blog_id  || '', h.receive_blog_name  || '',
                    it.note || ''
                ]);
            });
            // Dòng tổng
            aoa.push([]);
            aoa.push(['', '', 'TỔNG', '', '', '', '', sumQty, '', '', '', '', '', '', '']);

            var ws = XLSX.utils.aoa_to_sheet(aoa);
            // Merge các dòng thông tin đầu (cột 0..14)
            ws['!merges'] = [
                {s:{r:0,c:0},e:{r:0,c:14}},
                {s:{r:1,c:0},e:{r:1,c:14}},
                {s:{r:2,c:0},e:{r:2,c:14}},
                {s:{r:3,c:0},e:{r:3,c:14}},
                {s:{r:4,c:0},e:{r:4,c:14}},
                {s:{r:5,c:0},e:{r:5,c:14}},
                {s:{r:6,c:0},e:{r:6,c:14}},
                {s:{r:7,c:0},e:{r:7,c:14}}
            ];
            ws['!cols'] = [
                {wch:5},{wch:18},{wch:36},
                {wch:14},{wch:8},{wch:8},{wch:12},
                {wch:12},
                {wch:12},{wch:24},{wch:12},{wch:24},{wch:12},{wch:24},
                {wch:36}
            ];

            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'PO ' + (h.po_code || POA.po_id));

            var dateStr = new Date().toISOString().slice(0,10);
            var fname = 'PO-dieu-chinh_' + safeFile(h.po_code || ('id-' + POA.po_id)) + '_' + dateStr + '.xlsx';
            XLSX.writeFile(wb, fname);
        }

        $(document).on('click', '#btn-poa-d-export', exportExcel);

        $(document).on('click', '#poa-detail-actions [data-status]', function () {
            var $btn = $(this);
            var status = $btn.data('status');
            var labels = { approved:'Duyệt phiếu', rejected:'Từ chối phiếu', cancelled:'Hủy phiếu', converted:'Đánh dấu đã chuyển phiếu' };
            if (!confirm(labels[status] + ' này?')) return;
            $btn.prop('disabled', true);
            $.post(POA.ajax, { action:'tgs_poa_update_status', nonce:POA.nonce, po_id:POA.po_id, status:status })
            .done(function (resp) {
                if (!resp || !resp.success) {
                    alert((resp && resp.data && resp.data.message) || 'Cập nhật thất bại.');
                    $btn.prop('disabled', false);
                    return;
                }
                load();
            }).fail(function () { alert('Lỗi mạng.'); $btn.prop('disabled', false); });
        });

        // ========== COPY TO TICKET ==========

        var _copyView = null;
        var COPY_LABEL = {
            'purchase-add':       'Phiếu mua hàng',
            'transfer-export-add':'Phiếu bán hàng nội bộ',
            'transfer-return-add':'Phiếu trả hàng nội bộ'
        };

        function openCopyModal(view) {
            if (!lastItems.length) { alert('Phiếu chưa có dòng nào.'); return; }
            _copyView = view;
            $('#poa-copy-modal-title').text('Tạo ' + (COPY_LABEL[view] || view) + ' từ PO');
            $('#poa-copy-ticket-note').val('Tạo từ PO ' + (lastHeader ? lastHeader.po_code : ''));
            $('#poa-copy-chk-all').prop('checked', true);

            var $tb = $('#poa-copy-items-tbody').empty();
            lastItems.forEach(function (it, i) {
                var $tr = $('<tr>');
                var $chkTd = $('<td class="text-center align-middle">');
                var $chk = $('<input type="checkbox" class="form-check-input poa-copy-item-chk">')
                    .prop('checked', true)
                    .attr('data-idx', i)
                    .css({width:'1.2em', height:'1.2em', cursor:'pointer'});
                $chkTd.append($chk);

                var $qtyInput = $('<input type="number" class="form-control form-control-sm text-end poa-copy-qty">')
                    .attr({min:'0.001', step:'0.001', 'data-idx': i})
                    .val(parseFloat(it.quantity) || 1);

                var $noteInput = $('<input type="text" class="form-control form-control-sm poa-copy-note">')
                    .attr('data-idx', i)
                    .val(it.note || '');

                $tr.append($chkTd)
                   .append($('<td>').append($('<code>').text(it.product_sku)))
                   .append($('<td class="small">').text(it.product_name))
                   .append($('<td>').append($qtyInput))
                   .append($('<td>').append($noteInput));
                $tb.append($tr);
            });
            updateCopyCount();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-poa-copy-ticket')).show();
        }

        function updateCopyCount() {
            var n = $('#poa-copy-items-tbody .poa-copy-item-chk:checked').length;
            $('#poa-copy-selected-count').text(n + ' / ' + lastItems.length + ' dòng được chọn');
        }

        $(document).on('click', '.poa-copy-trigger', function (e) {
            e.preventDefault();
            openCopyModal($(this).data('view'));
        });

        $(document).on('change', '#poa-copy-chk-all', function () {
            var v = $(this).prop('checked');
            $('#poa-copy-items-tbody .poa-copy-item-chk').prop('checked', v);
            updateCopyCount();
        });

        $(document).on('change', '.poa-copy-item-chk', function () {
            // Nếu bỏ chọn bất kỳ dòng nào → bỏ check-all; nếu tất cả checked → check-all
            var total = $('#poa-copy-items-tbody .poa-copy-item-chk').length;
            var checked = $('#poa-copy-items-tbody .poa-copy-item-chk:checked').length;
            $('#poa-copy-chk-all').prop('checked', total > 0 && checked === total)
                                  .prop('indeterminate', checked > 0 && checked < total);
            updateCopyCount();
        });

        $(document).on('click', '#btn-poa-copy-confirm', function () {
            var selected = [];
            $('#poa-copy-items-tbody tr').each(function () {
                var $chk = $(this).find('.poa-copy-item-chk');
                if (!$chk.prop('checked')) return;
                var idx = parseInt($chk.data('idx'), 10);
                var it = lastItems[idx];
                if (!it) return;
                var qty = parseFloat($(this).find('.poa-copy-qty').val()) || 1;
                var note = ($(this).find('.poa-copy-note').val() || '').trim();
                selected.push({ sku: it.product_sku, quantity: qty, note: note });
            });
            if (!selected.length) { alert('Chưa chọn dòng nào.'); return; }

            var payload = {
                items:   selected,
                po_code: lastHeader ? lastHeader.po_code : '',
                note:    $('#poa-copy-ticket-note').val().trim()
            };
            try {
                sessionStorage.setItem('tgs_poa_copy_items', JSON.stringify(payload));
            } catch (e) {
                alert('Không thể lưu dữ liệu vào sessionStorage. Hãy bật cookie cho trang này.');
                return;
            }

            var url = POA.adminUrl + '?page=tgs-shop-management&view=' + encodeURIComponent(_copyView);
            window.open(url, '_blank');
            bootstrap.Modal.getInstance(document.getElementById('modal-poa-copy-ticket')).hide();
        });

        $(function () { load(); });
    })(jQuery);
    </script>

    <!-- Modal copy sang phiếu -->
    <div class="modal fade" id="modal-poa-copy-ticket" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="poa-copy-modal-title">Tạo phiếu từ PO</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold mb-1">Ghi chú tự động điền vào phiếu mới</label>
                        <input type="text" class="form-control" id="poa-copy-ticket-note" placeholder="Ví dụ: Tạo từ PO-001">
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:38px" class="text-center">
                                        <input type="checkbox" class="form-check-input" id="poa-copy-chk-all" checked title="Chọn/bỏ tất cả" style="width:1.2em;height:1.2em;cursor:pointer">
                                    </th>
                                    <th>SKU</th>
                                    <th>Tên hàng</th>
                                    <th style="width:110px">SL chuyển</th>
                                    <th>Ghi chú dòng</th>
                                </tr>
                            </thead>
                            <tbody id="poa-copy-items-tbody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="text-muted small me-auto" id="poa-copy-selected-count"></span>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-primary" id="btn-poa-copy-confirm">
                        <i class="bx bx-window-open me-1"></i>Mở phiếu mới (tab mới)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
