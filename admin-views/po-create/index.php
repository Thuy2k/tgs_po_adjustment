<?php
/**
 * View: Tạo PO điều chỉnh chủ động.
 *
 * Đặc điểm:
 *  - "Nguồn phát sinh phiếu" (request_blog) tự động = website hiện tại (không đổi).
 *  - Nguồn chuyển + nguồn nhận: do user chọn từ dropdown tất cả blog
 *    (cho phép Shop ↔ Shop, không nhất thiết phải qua kho).
 *  - Items: thêm dòng từ ô tìm SKU (autocomplete trong blog hiện tại) hoặc nhập tay.
 *  - Mỗi dòng có: SKU, tên hàng, số lượng, ghi chú dòng.
 *  - Có ghi chú chung của phiếu.
 */
if (!defined('ABSPATH')) exit;

$ajax_url     = admin_url('admin-ajax.php');
$nonce        = wp_create_nonce('tgs_poa_nonce');
$current_bid  = (int) get_current_blog_id();
$current_name = get_bloginfo('name');
$is_kho       = TGS_POA_Helper::is_warehouse($current_bid);
$kho_pid      = $is_kho ? 0 : TGS_POA_Helper::find_parent_warehouse($current_bid);
$kho_name     = $kho_pid ? TGS_POA_Helper::get_blog_name($kho_pid) : '';
$list_url     = admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_LIST);
$detail_base  = admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_DETAIL);
?>
<div class="container-xxl flex-grow-1 container-p-y" id="tgs-poa-create-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bx bx-plus-circle me-1"></i>Tạo PO điều chỉnh chủ động</h4>
            <div class="text-muted small">
                Tạo phiếu thủ công cho các tình huống <i>shop ↔ shop</i>, <i>shop xin trước</i>, <i>kho mua thêm</i>...
            </div>
        </div>
        <a href="<?php echo esc_url($list_url); ?>" class="btn btn-outline-secondary">
            <i class="bx bx-arrow-back me-1"></i> Danh sách PO
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2"><b>Thông tin phiếu</b></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Nguồn phát sinh phiếu</label>
                    <input type="text" class="form-control" readonly
                           value="<?php echo esc_attr($current_name . ' (#' . $current_bid . ')'); ?>">
                    <div class="form-text">Tự động = website đang truy cập <span class="badge <?php echo $is_kho ? 'bg-label-primary':'bg-label-success'; ?>"><?php echo $is_kho ? 'Kho' : 'Shop'; ?></span></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Loại đề xuất</label>
                    <select id="poa-c-intent" class="form-select">
                        <option value="manual">Tự động xác định theo nguồn chuyển/nhận</option>
                        <option value="shop_request_from_warehouse">Shop xin hàng từ kho</option>
                        <option value="shop_return_to_warehouse">Shop trả hàng về kho</option>
                        <option value="shop_transfer_to_shop">Shop chuyển hàng sang shop khác</option>
                        <option value="warehouse_purchase_more">Kho mua thêm (không có nguồn chuyển)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nguồn chuyển hàng</label>
                    <select id="poa-c-transfer" class="form-select">
                        <option value="">— Không chọn (mua thêm) —</option>
                    </select>
                    <div class="form-text">Để trống nếu là phiếu mua thêm.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nguồn nhận hàng <span class="text-danger">*</span></label>
                    <select id="poa-c-receive" class="form-select">
                        <option value="">— Chọn nơi nhận —</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Ghi chú chung</label>
                    <textarea id="poa-c-note" class="form-control" rows="2"
                              placeholder="VD: Shop A báo thiếu hàng đột xuất, xin từ shop B trước..."></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <b>Danh sách hàng hoá</b>
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <div class="position-relative" style="min-width:280px">
                    <input type="text" id="poa-c-search" class="form-control form-control-sm"
                           placeholder="Tìm SKU/tên trong website hiện tại..." autocomplete="off">
                    <div id="poa-c-search-results" class="dropdown-menu shadow-sm w-100" style="max-height:320px;overflow-y:auto"></div>
                </div>
                <button class="btn btn-sm btn-outline-secondary" id="btn-poa-c-add-blank">
                    <i class="bx bx-plus"></i> Thêm dòng trống
                </button>
                <span class="vr"></span>
                <button class="btn btn-sm btn-outline-success" id="btn-poa-c-import" type="button">
                    <i class="bx bx-upload"></i> Nhập Excel
                </button>
                <button class="btn btn-sm btn-link p-0" id="btn-poa-c-template" type="button" title="Tải file mẫu">
                    <i class="bx bx-download"></i> Tải mẫu
                </button>
                <input type="file" id="poa-c-import-file" accept=".xlsx,.xls,.csv" style="display:none">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px">#</th>
                        <th style="width:160px">SKU</th>
                        <th>Tên hàng</th>
                        <th class="text-end" style="width:140px">Số lượng</th>
                        <th>Ghi chú dòng</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody id="poa-c-items">
                    <tr id="poa-c-empty"><td colspan="6" class="text-center text-muted py-4">
                        Chưa có dòng nào — bấm tìm SKU ở trên hoặc "Thêm dòng trống".
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?php echo esc_url($list_url); ?>" class="btn btn-outline-secondary">Hủy</a>
        <button id="btn-poa-c-submit" class="btn btn-primary">
            <i class="bx bx-check me-1"></i> Tạo phiếu
        </button>
    </div>
</div>

<!-- SheetJS for xlsx import/export -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.20.3/dist/xlsx.full.min.js"></script>

<script>
(function ($) {
    'use strict';
    var POA = {
        ajax: <?php echo wp_json_encode($ajax_url); ?>,
        nonce: <?php echo wp_json_encode($nonce); ?>,
        bid: <?php echo (int) $current_bid; ?>,
        bidName: <?php echo wp_json_encode($current_name); ?>,
        isKho: <?php echo $is_kho ? 'true' : 'false'; ?>,
        khoPid: <?php echo (int) $kho_pid; ?>,
        khoName: <?php echo wp_json_encode($kho_name); ?>,
        detailBase: <?php echo wp_json_encode($detail_base); ?>,
        listUrl: <?php echo wp_json_encode($list_url); ?>
    };

    var blogs = [];
    var rowSeq = 0;

    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function fmt(n) { var v = parseFloat(n); return isNaN(v) ? '0' : v.toLocaleString('vi-VN', {maximumFractionDigits:3}); }

    function fillBlogSelects() {
        var $tr = $('#poa-c-transfer'), $rc = $('#poa-c-receive');
        $tr.find('option:not(:first)').remove();
        $rc.find('option:not(:first)').remove();

        var intent = $('#poa-c-intent').val();
        var shopOnly = (intent === 'shop_transfer_to_shop');

        function build($sel) {
            var $kho = $('<optgroup label="🏭 Kho"></optgroup>');
            var $shop = $('<optgroup label="🏬 Shop"></optgroup>');
            blogs.forEach(function (b) {
                var label = b.name + ' (#' + b.id + ')';
                var $o = $('<option>').val(b.id).text(label);
                if (b.is_warehouse) {
                    if (shopOnly) return; // ẩn kho khi shop ↔ shop
                    $kho.append($o);
                } else {
                    $shop.append($o);
                }
            });
            if ($kho.children().length) $sel.append($kho);
            $sel.append($shop);
        }
        build($tr); build($rc);

        // Auto-fill default theo intent
        applyIntentDefaults();
    }

    function applyIntentDefaults() {
        var intent = $('#poa-c-intent').val();
        if (intent === 'shop_request_from_warehouse' && POA.khoPid) {
            $('#poa-c-transfer').val(POA.khoPid);
            $('#poa-c-receive').val(POA.bid);
        } else if (intent === 'shop_return_to_warehouse' && POA.khoPid) {
            $('#poa-c-transfer').val(POA.bid);
            $('#poa-c-receive').val(POA.khoPid);
        } else if (intent === 'warehouse_purchase_more') {
            $('#poa-c-transfer').val('');
            $('#poa-c-receive').val(POA.bid);
        } else if (intent === 'shop_transfer_to_shop') {
            // 2 nguồn đều phải là shop. Nếu website hiện tại là shop → mặc định nó là nguồn chuyển.
            if (!POA.isKho) {
                $('#poa-c-transfer').val(POA.bid);
            } else {
                $('#poa-c-transfer').val('');
            }
            $('#poa-c-receive').val('');
        }
    }

    function loadBlogs() {
        $.post(POA.ajax, { action:'tgs_poa_get_blogs', nonce:POA.nonce })
        .done(function (resp) {
            if (resp && resp.success) {
                blogs = resp.data.blogs || [];
                fillBlogSelects();
            }
        });
    }

    // ============= ITEMS =============
    function addItemRow(data) {
        data = data || {};
        var id = ++rowSeq;
        $('#poa-c-empty').remove();
        var html = '<tr data-rid="' + id + '">'
          + '<td><span class="poa-c-no"></span></td>'
          + '<td><input type="text" class="form-control form-control-sm poa-c-sku" value="' + esc(data.sku || '') + '"></td>'
          + '<td><input type="text" class="form-control form-control-sm poa-c-name" value="' + esc(data.name || '') + '"></td>'
          + '<td><input type="number" min="0" step="0.001" class="form-control form-control-sm text-end poa-c-qty" value="' + (data.qty != null ? data.qty : '') + '" placeholder="0"></td>'
          + '<td><input type="text" class="form-control form-control-sm poa-c-note" placeholder="Ghi chú dòng..."></td>'
          + '<td class="text-center"><button class="btn btn-sm btn-outline-danger poa-c-del"><i class="bx bx-x"></i></button></td>'
          + '</tr>';
        $('#poa-c-items').append(html);
        renumberItems();
    }

    function renumberItems() {
        $('#poa-c-items tr[data-rid]').each(function (i) {
            $(this).find('.poa-c-no').text(i + 1);
        });
        if ($('#poa-c-items tr[data-rid]').length === 0) {
            $('#poa-c-items').html('<tr id="poa-c-empty"><td colspan="6" class="text-center text-muted py-4">Chưa có dòng nào — bấm tìm SKU ở trên hoặc "Thêm dòng trống".</td></tr>');
        }
    }

    function collectItems() {
        var arr = [];
        $('#poa-c-items tr[data-rid]').each(function () {
            var $tr = $(this);
            var sku = ($tr.find('.poa-c-sku').val() || '').trim();
            var qty = parseFloat($tr.find('.poa-c-qty').val()) || 0;
            if (!sku || qty <= 0) return;
            arr.push({
                sku: sku,
                name: ($tr.find('.poa-c-name').val() || '').trim(),
                quantity: qty,
                note: ($tr.find('.poa-c-note').val() || '').trim()
            });
        });
        return arr;
    }

    // ============= SEARCH SKU =============
    var searchTm;
    function doSearchSku() {
        var q = $('#poa-c-search').val();
        var $box = $('#poa-c-search-results');
        $.post(POA.ajax, { action:'tgs_poa_search_sku', nonce:POA.nonce, q:q, blog_id:POA.bid, limit:30 })
        .done(function (resp) {
            if (!resp || !resp.success) { $box.removeClass('show').empty(); return; }
            var rows = resp.data.rows || [];
            if (!rows.length) { $box.html('<span class="dropdown-item-text text-muted small">Không có kết quả.</span>').addClass('show'); return; }
            var html = '';
            rows.forEach(function (r) {
                html += '<a href="#" class="dropdown-item poa-c-pick small" data-sku="' + esc(r.sku) + '" data-name="' + esc(r.name) + '">'
                      + '<code>' + esc(r.sku) + '</code> — ' + esc(r.name)
                      + ' <span class="text-muted">(tồn ' + fmt(r.qty) + ')</span></a>';
            });
            $box.html(html).addClass('show');
        });
    }

    // ============= SUBMIT =============
    function submit() {
        var transfer = $('#poa-c-transfer').val();
        var receive  = $('#poa-c-receive').val();
        var intent   = $('#poa-c-intent').val();
        if (!receive) { alert('Vui lòng chọn nguồn nhận.'); return; }
        if (intent !== 'warehouse_purchase_more' && intent !== 'manual' && !transfer) {
            alert('Vui lòng chọn nguồn chuyển hàng.'); return;
        }
        if (transfer && transfer == receive) {
            alert('Nguồn chuyển và nguồn nhận không được trùng nhau.'); return;
        }
        if (intent === 'shop_transfer_to_shop') {
            // Cả 2 phải là shop (không phải kho)
            function isShopBid(bid) {
                bid = parseInt(bid, 10);
                for (var i = 0; i < blogs.length; i++) {
                    if (parseInt(blogs[i].id, 10) === bid) return !blogs[i].is_warehouse;
                }
                return false;
            }
            if (!transfer || !isShopBid(transfer) || !isShopBid(receive)) {
                alert('Phiếu Shop ↔ Shop yêu cầu cả nguồn chuyển và nguồn nhận đều là shop.'); return;
            }
        }
        var items = collectItems();
        if (!items.length) { alert('Phiếu chưa có dòng SKU nào hợp lệ (cần SKU + SL > 0).'); return; }

        var $btn = $('#btn-poa-c-submit');
        var oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Đang lưu...');

        $.post(POA.ajax, {
            action: 'tgs_poa_create_manual',
            nonce: POA.nonce,
            intent: intent,
            transfer_blog_id: transfer || 0,
            receive_blog_id: receive,
            note: $('#poa-c-note').val() || '',
            items: JSON.stringify(items)
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || 'Tạo phiếu thất bại.');
                return;
            }
            var d = resp.data || {};
            if (confirm((d.message || 'Đã tạo phiếu.') + '\n\nXem chi tiết phiếu?')) {
                window.location.href = POA.detailBase + '&po_id=' + d.po_id;
            } else {
                window.location.href = POA.listUrl;
            }
        }).fail(function () { alert('Lỗi mạng / máy chủ.'); })
        .always(function () { $btn.prop('disabled', false).html(oldHtml); });
    }

    // ============= EXCEL IMPORT / TEMPLATE =============
    // Chuẩn hoá tên header để map cột linh hoạt (không dấu, lower)
    function normHeader(s) {
        s = String(s == null ? '' : s).toLowerCase().trim();
        // bỏ dấu tiếng Việt
        s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');
        s = s.replace(/[^a-z0-9]+/g, ' ').trim();
        return s;
    }
    function pickColIdx(headerRow, candidates) {
        var keys = headerRow.map(normHeader);
        for (var i = 0; i < candidates.length; i++) {
            var want = normHeader(candidates[i]);
            for (var j = 0; j < keys.length; j++) {
                if (keys[j] === want) return j;
            }
        }
        // fallback: contains
        for (var i = 0; i < candidates.length; i++) {
            var want = normHeader(candidates[i]);
            for (var j = 0; j < keys.length; j++) {
                if (keys[j].indexOf(want) !== -1) return j;
            }
        }
        return -1;
    }

    function importExcelFile(file) {
        if (!window.XLSX) { alert('Thư viện Excel chưa tải xong, vui lòng thử lại.'); return; }
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                var data = new Uint8Array(e.target.result);
                var wb = XLSX.read(data, { type: 'array' });
                var ws = wb.Sheets[wb.SheetNames[0]];
                if (!ws) { alert('File không có sheet nào.'); return; }
                var aoa = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false });
                if (!aoa.length) { alert('File trống.'); return; }

                // Tìm dòng header: dòng đầu tiên có chữ "ma" hoặc "sku" hoặc "so luong"
                var headerIdx = -1;
                for (var i = 0; i < Math.min(aoa.length, 10); i++) {
                    var row = aoa[i].map(normHeader).join('|');
                    if (row.indexOf('ma hang') !== -1 || row.indexOf('sku') !== -1 ||
                        row.indexOf('so luong') !== -1 || row.indexOf('ma san pham') !== -1) {
                        headerIdx = i; break;
                    }
                }
                if (headerIdx === -1) headerIdx = 0; // giả định dòng 1 là header

                var header = aoa[headerIdx] || [];
                var iSku  = pickColIdx(header, ['ma hang', 'ma san pham', 'sku', 'ma']);
                var iName = pickColIdx(header, ['ten hang tham khao', 'ten hang', 'ten san pham', 'ten', 'name']);
                var iQty  = pickColIdx(header, ['so luong', 'sl', 'quantity', 'qty']);
                var iNote = pickColIdx(header, ['ghi chu', 'note', 'note dong']);

                if (iSku === -1 || iQty === -1) {
                    alert('Không tìm thấy cột "Mã hàng" hoặc "Số lượng" trong file.\nFile cần có header: Mã hàng | Tên hàng tham khảo | Số lượng | Ghi chú.');
                    return;
                }

                var added = 0, skipped = 0;
                for (var r = headerIdx + 1; r < aoa.length; r++) {
                    var row = aoa[r] || [];
                    var sku = String(row[iSku] || '').trim();
                    var qty = parseFloat(String(row[iQty] || '').replace(/[, ]+/g, '')) || 0;
                    if (!sku || qty <= 0) { if (sku || qty) skipped++; continue; }
                    var name = iName !== -1 ? String(row[iName] || '').trim() : '';
                    var note = iNote !== -1 ? String(row[iNote] || '').trim() : '';

                    // Nếu đã có dòng cùng SKU → cộng dồn số lượng
                    var $existing = $('#poa-c-items tr[data-rid] .poa-c-sku').filter(function () {
                        return $(this).val() === sku;
                    });
                    if ($existing.length) {
                        var $tr = $existing.first().closest('tr');
                        var oldQty = parseFloat($tr.find('.poa-c-qty').val()) || 0;
                        $tr.find('.poa-c-qty').val(oldQty + qty);
                        if (note) {
                            var oldNote = $tr.find('.poa-c-note').val() || '';
                            $tr.find('.poa-c-note').val(oldNote ? (oldNote + '; ' + note) : note);
                        }
                    } else {
                        addItemRow({ sku: sku, name: name, qty: qty });
                        var $last = $('#poa-c-items tr[data-rid]:last');
                        if (note) $last.find('.poa-c-note').val(note);
                    }
                    added++;
                }
                var msg = 'Đã nhập ' + added + ' dòng từ Excel.';
                if (skipped) msg += ' (' + skipped + ' dòng bị bỏ qua do thiếu mã hoặc SL ≤ 0)';
                alert(msg);
            } catch (err) {
                console.error(err);
                alert('Đọc file thất bại: ' + (err && err.message ? err.message : err));
            }
        };
        reader.onerror = function () { alert('Không đọc được file.'); };
        reader.readAsArrayBuffer(file);
    }

    function downloadTemplate() {
        if (!window.XLSX) { alert('Thư viện Excel chưa tải xong.'); return; }
        var aoa = [
            ['Mã hàng', 'Tên hàng tham khảo', 'Số lượng', 'Ghi chú'],
            ['SKU001', 'Tên sản phẩm ví dụ 1', 10, 'Ghi chú dòng (tuỳ chọn)'],
            ['SKU002', 'Tên sản phẩm ví dụ 2', 5, '']
        ];
        var ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!cols'] = [{ wch: 18 }, { wch: 36 }, { wch: 10 }, { wch: 28 }];
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'PO dieu chinh');
        XLSX.writeFile(wb, 'mau-PO-dieu-chinh.xlsx');
    }

    // ============= EVENTS =============
    $(document).on('click', '#btn-poa-c-add-blank', function () { addItemRow(); });
    $(document).on('click', '.poa-c-del', function () {
        $(this).closest('tr').remove();
        renumberItems();
    });
    $(document).on('change', '#poa-c-intent', fillBlogSelects);
    $(document).on('input focus', '#poa-c-search', function () {
        clearTimeout(searchTm);
        searchTm = setTimeout(doSearchSku, 250);
    });
    $(document).on('click', '.poa-c-pick', function (e) {
        e.preventDefault();
        var sku = $(this).data('sku'), name = $(this).data('name');
        // Nếu đã có dòng cùng SKU → focus vào nó, ngược lại thêm mới
        var $existing = $('#poa-c-items tr[data-rid] .poa-c-sku').filter(function () { return $(this).val() === String(sku); });
        if ($existing.length) {
            $existing.closest('tr').find('.poa-c-qty').focus().select();
        } else {
            addItemRow({ sku: sku, name: name, qty: '' });
            $('#poa-c-items tr[data-rid]:last .poa-c-qty').focus();
        }
        $('#poa-c-search-results').removeClass('show').empty();
        $('#poa-c-search').val('').focus();
    });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#poa-c-search, #poa-c-search-results').length) {
            $('#poa-c-search-results').removeClass('show');
        }
    });
    $(document).on('click', '#btn-poa-c-submit', submit);

    // Excel import / template
    $(document).on('click', '#btn-poa-c-import', function () {
        $('#poa-c-import-file').val('').trigger('click');
    });
    $(document).on('change', '#poa-c-import-file', function () {
        var f = this.files && this.files[0];
        if (f) importExcelFile(f);
    });
    $(document).on('click', '#btn-poa-c-template', function (e) {
        e.preventDefault();
        downloadTemplate();
    });

    $(function () { loadBlogs(); });
})(jQuery);
</script>
