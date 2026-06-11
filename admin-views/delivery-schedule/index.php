<?php
/**
 * View: Cấu hình lịch up hàng cho shop.
 */
if (!defined('ABSPATH')) {
    exit;
}

$current_bid = (int) get_current_blog_id();
$current_site = class_exists('TGS_Delivery_Schedule_Helper')
    ? TGS_Delivery_Schedule_Helper::get_site_info($current_bid)
    : ['name' => get_bloginfo('name'), 'blog_id' => $current_bid, 'type_label' => 'Website'];
$is_warehouse = class_exists('TGS_Delivery_Schedule_Helper')
    ? TGS_Delivery_Schedule_Helper::is_warehouse($current_bid)
    : false;
$ajax_url = admin_url('admin-ajax.php');
$nonce = wp_create_nonce('tgs_delivery_schedule_nonce');
?>

<div class="container-xxl flex-grow-1 container-p-y" id="tgs-delivery-schedule-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">
                <i class="bx bx-calendar-week me-1"></i>
                Lịch up hàng
            </h4>
            <div class="text-muted small">
                <?php echo esc_html($current_site['name'] ?? get_bloginfo('name')); ?>
                <span class="badge <?php echo $is_warehouse ? 'bg-label-primary' : 'bg-label-success'; ?> ms-1">
                    <?php echo esc_html($current_site['type_label'] ?? 'Website'); ?>
                </span>
                <span class="text-muted">#<?php echo (int) $current_bid; ?></span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" href="<?php echo esc_url(admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_SCAN)); ?>">
                <i class="bx bx-radar me-1"></i> Quét tồn
            </a>
            <button type="button" class="btn btn-primary" id="btn-delivery-reload">
                <i class="bx bx-refresh me-1"></i> Tải lại
            </button>
        </div>
    </div>

    <?php if (!$is_warehouse): ?>
        <div class="alert alert-info">
            Website hiện tại không phải kho trong sơ đồ phân cấp. Shop vẫn có thể xem cấu hình của chính nó, còn phần quản lý nhiều shop nên thao tác từ website kho.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3" id="delivery-summary-cards">
        <div class="col-md-3">
            <div class="card h-100 border-danger">
                <div class="card-body">
                    <div class="text-muted small">Shop đến lịch hôm nay</div>
                    <div class="h4 mb-0 text-danger" data-summary="today">—</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-warning">
                <div class="card-body">
                    <div class="text-muted small">Shop sắp đến lịch</div>
                    <div class="h4 mb-0 text-warning" data-summary="soon">—</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Đã cấu hình</div>
                    <div class="h4 mb-0" data-summary="configured_total">—</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Cảnh báo trong 3 ngày</div>
                    <div class="h4 mb-0" data-summary="total_in_horizon">—</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3" id="delivery-upcoming-card">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h5 class="mb-0">Shop cần chú ý</h5>
            <span class="small text-muted">Hôm nay và 3 ngày tới</span>
        </div>
        <div class="card-body py-3" id="delivery-upcoming-list">
            <div class="text-muted text-center py-3">Đang tải...</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h5 class="mb-0">Cấu hình theo shop</h5>
            <div class="input-group input-group-sm" style="max-width: 360px;">
                <span class="input-group-text"><i class="bx bx-search"></i></span>
                <input type="text" class="form-control" id="delivery-shop-search" placeholder="Tìm shop, mã shop...">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="min-width: 220px;">Shop</th>
                        <th style="min-width: 360px;">Ngày up hàng</th>
                        <th style="min-width: 220px;">Lịch kế tiếp</th>
                        <th style="min-width: 260px;">Ghi chú</th>
                        <th class="text-center" style="width: 90px;">Bật</th>
                        <th class="text-end" style="width: 120px;">Lưu</th>
                    </tr>
                </thead>
                <tbody id="delivery-schedule-rows">
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <span class="spinner-border spinner-border-sm me-1"></span> Đang tải cấu hình...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    #tgs-delivery-schedule-page .delivery-day-check {
        min-width: 86px;
    }
    #tgs-delivery-schedule-page .delivery-shop-name {
        font-weight: 700;
        color: #1f2937;
    }
    #tgs-delivery-schedule-page .delivery-upcoming-chip {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: .625rem .75rem;
        background: #fff;
        cursor: pointer;
        transition: border-color .12s ease, background .12s ease;
    }
    #tgs-delivery-schedule-page .delivery-upcoming-chip:hover {
        border-color: #696cff;
        background: #f8f9ff;
    }
</style>

<script>
(function($) {
    'use strict';

    var DS = {
        ajax: <?php echo wp_json_encode($ajax_url); ?>,
        nonce: <?php echo wp_json_encode($nonce); ?>,
        scanUrl: <?php echo wp_json_encode(admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_SCAN)); ?>
    };

    var state = {
        shops: [],
        schedules: {},
        dayLabels: {},
        search: ''
    };

    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

    function normalizeText(s) {
        s = (s == null ? '' : String(s)).toLowerCase();
        try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
        return s.replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function matchText(hay, q) {
        var h = normalizeText(hay);
        var k = normalizeText(q);
        if (!k) return true;
        return k.split(' ').every(function(token) { return h.indexOf(token) !== -1; });
    }

    function statusBadge(next) {
        if (!next || !next.has_schedule) {
            return '<span class="badge bg-label-secondary">Chưa cấu hình</span>';
        }
        if (next.status === 'today') {
            return '<span class="badge bg-danger">Hôm nay</span>';
        }
        if (next.status === 'soon') {
            return '<span class="badge bg-warning">Sắp đến</span>';
        }
        return '<span class="badge bg-label-secondary">' + esc(next.status_label || '') + '</span>';
    }

    function renderSummary(upcoming) {
        var s = (upcoming && upcoming.summary) || {};
        $('#delivery-summary-cards [data-summary]').each(function() {
            var key = $(this).data('summary');
            $(this).text(s[key] == null ? 0 : s[key]);
        });

        var items = (upcoming && upcoming.items) || [];
        var $wrap = $('#delivery-upcoming-list');
        if (!items.length) {
            $wrap.html('<div class="text-muted text-center py-3">Chưa có shop nào đến lịch trong 3 ngày tới.</div>');
            return;
        }

        var html = '<div class="d-flex flex-wrap gap-2">';
        items.forEach(function(item) {
            var next = item.next_delivery || {};
            var cls = next.status === 'today' ? 'text-danger' : (next.status === 'soon' ? 'text-warning' : 'text-muted');
            html += '<div class="delivery-upcoming-chip" data-blog-id="' + (item.target_blog_id || '') + '">'
                + '<div class="d-flex align-items-center justify-content-between gap-2">'
                + '<span class="fw-semibold">' + esc(item.target_blog_name || ('Shop #' + item.target_blog_id)) + '</span>'
                + statusBadge(next)
                + '</div>'
                + '<div class="small text-muted mt-1">' + esc(item.weekdays_label || '') + '</div>'
                + '<div class="small ' + cls + '">' + esc(next.next_label || '') + '</div>'
                + '</div>';
        });
        html += '</div>';
        $wrap.html(html);
    }

    function dayChecks(shopId, selected) {
        selected = selected || [];
        var html = '<div class="d-flex flex-wrap gap-2">';
        Object.keys(state.dayLabels).forEach(function(day) {
            var checked = selected.map(String).indexOf(String(day)) !== -1 ? ' checked' : '';
            html += '<label class="form-check form-check-inline delivery-day-check mb-0">'
                + '<input type="checkbox" class="form-check-input delivery-day" value="' + day + '"' + checked + '>'
                + '<span class="form-check-label">' + esc(state.dayLabels[day]) + '</span>'
                + '</label>';
        });
        html += '</div>';
        return html;
    }

    function renderRows() {
        var q = state.search || '';
        var shops = state.shops.filter(function(shop) {
            return matchText([shop.name || '', shop.code || '', shop.blog_id || ''].join(' '), q);
        });
        var $tbody = $('#delivery-schedule-rows');
        if (!shops.length) {
            $tbody.html('<tr><td colspan="6" class="text-center text-muted py-5">Không tìm thấy shop phù hợp.</td></tr>');
            return;
        }

        var html = '';
        shops.forEach(function(shop) {
            var schedule = state.schedules[shop.blog_id] || state.schedules[String(shop.blog_id)] || {};
            var next = schedule.next_delivery || {};
            var note = schedule.note || '';
            var active = schedule.schedule_id ? parseInt(schedule.is_active || 0, 10) === 1 : true;

            html += '<tr data-blog-id="' + shop.blog_id + '" data-schedule-id="' + (schedule.schedule_id || 0) + '">'
                + '<td>'
                + '<div class="delivery-shop-name">' + esc(shop.name || ('Shop #' + shop.blog_id)) + '</div>'
                + '<div class="small text-muted">' + esc(shop.code || ('SHOP-' + shop.blog_id)) + ' · #' + shop.blog_id + '</div>'
                + '</td>'
                + '<td>' + dayChecks(shop.blog_id, schedule.weekdays || []) + '</td>'
                + '<td>'
                + '<div>' + statusBadge(next) + '</div>'
                + '<div class="small text-muted mt-1">' + esc(next.has_schedule ? (next.next_label || '') : '—') + '</div>'
                + '</td>'
                + '<td><input type="text" class="form-control form-control-sm delivery-note" value="' + esc(note) + '" placeholder="Ghi chú lịch, tuyến xe, người phụ trách..."></td>'
                + '<td class="text-center">'
                + '<div class="form-check form-switch d-inline-flex">'
                + '<input class="form-check-input delivery-active" type="checkbox"' + (active ? ' checked' : '') + '>'
                + '</div>'
                + '</td>'
                + '<td class="text-end">'
                + '<button type="button" class="btn btn-sm btn-primary btn-delivery-save"><i class="bx bx-save me-1"></i>Lưu</button>'
                + '</td>'
                + '</tr>';
        });
        $tbody.html(html);
    }

    function load() {
        $('#delivery-schedule-rows').html('<tr><td colspan="6" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-1"></span>Đang tải cấu hình...</td></tr>');
        $.post(DS.ajax, {
            action: 'tgs_delivery_schedule_list',
            nonce: DS.nonce
        }).done(function(resp) {
            if (!resp || !resp.success) {
                var msg = (resp && resp.data && resp.data.message) || 'Không tải được lịch up hàng.';
                $('#delivery-schedule-rows').html('<tr><td colspan="6" class="text-center text-danger py-5">' + esc(msg) + '</td></tr>');
                return;
            }
            var d = resp.data || {};
            state.shops = d.shops || [];
            state.schedules = d.schedules || {};
            state.dayLabels = d.day_labels || {};
            renderSummary(d.upcoming || {});
            renderRows();
        }).fail(function() {
            $('#delivery-schedule-rows').html('<tr><td colspan="6" class="text-center text-danger py-5">Lỗi mạng / máy chủ.</td></tr>');
        });
    }

    function saveRow($tr) {
        var targetBlogId = parseInt($tr.data('blog-id'), 10) || 0;
        var weekdays = [];
        $tr.find('.delivery-day:checked').each(function() {
            weekdays.push(parseInt(this.value, 10));
        });

        var $btn = $tr.find('.btn-delivery-save');
        var oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Lưu...');

        $.post(DS.ajax, {
            action: 'tgs_delivery_schedule_save',
            nonce: DS.nonce,
            target_blog_id: targetBlogId,
            weekdays: JSON.stringify(weekdays),
            note: $tr.find('.delivery-note').val() || '',
            is_active: $tr.find('.delivery-active').is(':checked') ? 1 : 0
        }).done(function(resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || 'Không lưu được lịch up hàng.');
                return;
            }
            var schedule = (resp.data || {}).schedule || {};
            if (schedule.target_blog_id) {
                state.schedules[schedule.target_blog_id] = schedule;
            }
            renderSummary((resp.data || {}).upcoming || {});
            renderRows();
        }).fail(function() {
            alert('Lỗi mạng / máy chủ khi lưu lịch up hàng.');
        }).always(function() {
            $btn.prop('disabled', false).html(oldHtml);
        });
    }

    $(document).on('click', '.btn-delivery-save', function() {
        saveRow($(this).closest('tr'));
    });

    $(document).on('input', '#delivery-shop-search', function() {
        state.search = $(this).val();
        renderRows();
    });

    $(document).on('click', '#btn-delivery-reload', load);

    $(document).on('click', '.delivery-upcoming-chip', function() {
        var blogId = $(this).data('blog-id');
        if (!blogId) return;
        window.location.href = DS.scanUrl + '&scan_blog_id=' + encodeURIComponent(blogId);
    });

    $(function() { load(); });
})(jQuery);
</script>
