<?php
/**
 * View: Quét tồn thông minh — PO điều chỉnh.
 * Render bên trong main-layout.php của plugin tgs_shop_management.
 *
 * Tính năng:
 *  - Tự động quét blog hiện tại khi mở trang (không có shop selector).
 *  - Hiển thị 4 ô tổng quan: cấu hình, thiếu, thừa, tổng dòng đề xuất.
 *  - Bảng đề xuất với màu sắc theo level (warning/danger/info).
 *  - Lọc theo loại đề xuất + tìm kiếm SKU/tên.
 *  - Xuất Excel danh sách đề xuất.
 */
if (!defined('ABSPATH')) exit;

$current_bid   = (int) get_current_blog_id();
$current_name  = get_bloginfo('name');
$is_kho_now    = TGS_POA_Helper::is_warehouse($current_bid);
$kind_label    = $is_kho_now ? 'Kho' : 'Shop';
$kind_class    = $is_kho_now ? 'bg-label-primary' : 'bg-label-success';
$kho_pid       = $is_kho_now ? 0 : TGS_POA_Helper::find_parent_warehouse($current_bid);
$kho_name      = $kho_pid ? TGS_POA_Helper::get_blog_name($kho_pid) : '';
$scan_targets  = TGS_POA_Helper::get_scan_targets($current_bid);
$scan_target_map = [];
foreach ($scan_targets as $target) {
    $scan_target_map[(int) ($target['blog_id'] ?? $target['id'] ?? 0)] = $target;
}
$requested_scan_bid = isset($_GET['scan_blog_id']) ? (int) $_GET['scan_blog_id'] : $current_bid;
$selected_scan_bid = isset($scan_target_map[$requested_scan_bid]) ? $requested_scan_bid : $current_bid;
$selected_scan_target = $scan_target_map[$selected_scan_bid] ?? [
    'blog_id' => $current_bid,
    'id' => $current_bid,
    'name' => $current_name,
    'type' => $is_kho_now ? 'warehouse' : 'shop',
    'type_label' => $kind_label,
];
$delivery_upcoming = class_exists('TGS_Delivery_Schedule_Helper')
    ? TGS_Delivery_Schedule_Helper::get_upcoming_summaries($current_bid, 3, 12)
    : ['items' => [], 'summary' => []];

$ajax_url = admin_url('admin-ajax.php');
$nonce    = wp_create_nonce('tgs_poa_nonce');
$delivery_nonce = wp_create_nonce('tgs_delivery_schedule_nonce');
?>
<div class="container-xxl flex-grow-1 container-p-y" id="tgs-poa-page">

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">
                <i class="bx bx-radar me-1"></i>
                Quét tồn thông minh
            </h4>
            <div class="text-muted small">
                Đối chiếu tồn hiện tại với cấu hình Min/Max → đề xuất PO điều chỉnh phù hợp.
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($is_kho_now): ?>
            <button id="btn-poa-select-scan-target" class="btn btn-outline-secondary">
                <i class="bx bx-store-alt me-1"></i> Chọn shop quét
            </button>
            <?php endif; ?>
            <button id="btn-poa-rescan" class="btn btn-outline-primary">
                <i class="bx bx-refresh me-1"></i> Quét lại
            </button>
            <?php if ($is_kho_now): ?>
            <button id="btn-poa-supplier-stats" class="btn btn-outline-dark">
                <i class="bx bx-network-chart me-1"></i> Thống kê thông minh cần mua từ nhà cung cấp
            </button>
            <?php endif; ?>
            <button id="btn-poa-export" class="btn btn-success">
                <i class="bx bxs-file-export me-1"></i> Xuất Excel đề xuất
            </button>
            <button id="btn-poa-create" class="btn btn-primary" disabled>
                <i class="bx bx-check-double me-1"></i> Tạo PO từ dòng đã chọn
                <span class="badge bg-white text-primary ms-1" id="poa-selected-count">0</span>
            </button>
            <button id="btn-poa-create-purchase-ticket" class="btn btn-outline-primary" disabled>
                <i class="bx bx-cart-download me-1"></i> Tạo phiếu mua
            </button>
            <button id="btn-poa-create-internal-ticket" class="btn btn-outline-info" disabled>
                <i class="bx bx-transfer-alt me-1"></i> Tạo bán nội bộ
            </button>
        </div>
    </div>

    <!-- Site info -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="small text-muted">Đang quét website</div>
                    <div class="fw-semibold" id="poa-scan-target-label">
                        <?php echo esc_html($selected_scan_target['name'] ?? $current_name); ?>
                        <span class="badge <?php echo esc_attr(($selected_scan_target['type'] ?? '') === 'warehouse' ? 'bg-label-primary' : 'bg-label-success'); ?> ms-1" id="poa-scan-target-kind">
                            <?php echo esc_html($selected_scan_target['type_label'] ?? $kind_label); ?>
                        </span>
                        <span class="text-muted small" id="poa-scan-target-id">#<?php echo (int) $selected_scan_bid; ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Nguồn thao tác</div>
                    <div class="fw-semibold">
                        <?php echo esc_html($current_name); ?>
                        <span class="badge <?php echo esc_attr($kind_class); ?> ms-1">
                            <?php echo esc_html($kind_label); ?>
                        </span>
                        <span class="text-muted small">#<?php echo (int) $current_bid; ?></span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Thời điểm quét</div>
                    <div class="fw-semibold" id="poa-scan-time">—</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($delivery_upcoming['items'])): ?>
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="fw-semibold me-2">
                    <i class="bx bx-calendar-event me-1"></i> Lịch up hàng gần nhất
                </div>
                <?php foreach ($delivery_upcoming['items'] as $item): ?>
                    <?php
                    $next = $item['next_delivery'] ?? [];
                    $status = $next['status'] ?? '';
                    $badge = $status === 'today' ? 'bg-danger' : ($status === 'soon' ? 'bg-warning' : 'bg-label-secondary');
                    ?>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary poa-delivery-chip"
                            data-blog-id="<?php echo (int) ($item['target_blog_id'] ?? 0); ?>">
                        <span class="fw-semibold"><?php echo esc_html($item['target_blog_name'] ?? ('Shop #' . ($item['target_blog_id'] ?? ''))); ?></span>
                        <span class="badge <?php echo esc_attr($badge); ?> ms-1"><?php echo esc_html($next['status_label'] ?? ''); ?></span>
                        <span class="text-muted ms-1"><?php echo esc_html($next['next_label'] ?? ''); ?></span>
                    </button>
                <?php endforeach; ?>
                <a class="btn btn-sm btn-link ms-auto"
                   href="<?php echo esc_url(admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_SCHEDULE)); ?>">
                    Cấu hình lịch
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-3" id="poa-summary">
        <div class="col-md-3">
            <div class="card h-100 border-danger bg-label-danger poa-card-urgent" id="poa-card-urgent" role="button"
                 title="Bấm để lọc nhanh các dòng dưới MIN">
                <div class="card-body">
                    <div class="text-danger small fw-semibold">
                        <i class="bx bxs-error-circle me-1"></i>DƯỚI MIN — MUA GẤP
                    </div>
                    <div class="h3 mb-0 text-danger" data-key="count_urgent">—</div>
                    <div class="small text-muted">Tổng SL cần mua: <span data-key="sum_urgent">—</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-warning"><div class="card-body">
                <div class="text-muted small">Nên mua / xin thêm (chưa đến MIN)</div>
                <div class="h4 mb-0 text-warning" data-key="count_deficit">—</div>
                <div class="small text-muted">Tổng SL: <span data-key="sum_deficit">—</span></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100 border-info"><div class="card-body">
                <div class="text-muted small">Thừa (trả / cảnh báo)</div>
                <div class="h4 mb-0 text-info" data-key="count_surplus">—</div>
                <div class="small text-muted">Tổng SL: <span data-key="sum_surplus">—</span></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">SKU đã cấu hình</div>
                <div class="h4 mb-0" data-key="configured">—</div>
                <div class="small text-muted">Tổng dòng đề xuất: <span data-key="total">—</span></div>
            </div></div>
        </div>
    </div>

    <style>
        .poa-card-urgent { cursor: pointer; transition: transform .12s ease, box-shadow .12s ease; }
        .poa-card-urgent:hover { transform: translateY(-1px); box-shadow: 0 .25rem .75rem rgba(220,53,69,.15); }
        .poa-card-urgent.active { box-shadow: 0 0 0 2px #dc3545 inset; }
        tr.poa-row-urgent td { background-color: #fdecea !important; }
        tr.poa-row-urgent { border-left: 3px solid #dc3545; }
        .poa-badge-urgent { background:#dc3545 !important; color:#fff !important; animation: poaPulse 1.6s infinite; }
        @keyframes poaPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(220,53,69,.55); } 50% { box-shadow: 0 0 0 6px rgba(220,53,69,0); } }
        .poa-supplier-modal .modal-dialog { width: min(1560px, 96vw); max-width: 1560px; }
        .poa-supplier-modal .modal-content { min-height: 88vh; }
        .poa-supplier-modal .modal-body { min-height: 0; }
        .poa-supplier-layout { display: grid; grid-template-columns: 330px minmax(0, 1fr); min-height: 68vh; }
        .poa-supplier-sidebar { border-right: 1px solid #e5e7eb; background: #f8fafc; min-height: 0; }
        .poa-supplier-tabs { max-height: 62vh; overflow: auto; }
        .poa-supplier-tab { width: 100%; border: 0; background: transparent; text-align: left; padding: .75rem .875rem; border-bottom: 1px solid #e5e7eb; }
        .poa-supplier-tab:hover { background: #eef2ff; }
        .poa-supplier-tab.active { background: #fff; box-shadow: inset 3px 0 0 #0d6efd; }
        .poa-supplier-tab-title { font-weight: 700; color: #111827; }
        .poa-supplier-tab-sub { color: #64748b; font-size: .78rem; line-height: 1.35; }
        .poa-supplier-main { min-width: 0; display: flex; flex-direction: column; }
        .poa-supplier-table-wrap { overflow: auto; max-height: 48vh; border-top: 1px solid #e5e7eb; }
        .poa-supplier-empty { min-height: 240px; display:flex; align-items:center; justify-content:center; color:#64748b; }
        .poa-supplier-filterbar { border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; background:#fff; }
        .poa-supplier-actions { border-top: 1px solid #e5e7eb; background:#f8fafc; }
        .poa-target-list { max-height: 62vh; overflow: auto; }
        .poa-target-item { border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem; background: #fff; cursor: pointer; transition: border-color .12s ease, background .12s ease; }
        .poa-target-item:hover { border-color: #696cff; background: #f8f9ff; }
        .poa-target-item.active { border-color: #696cff; box-shadow: 0 0 0 2px rgba(105,108,255,.14); }
        @media (max-width: 991.98px) {
            .poa-supplier-layout { grid-template-columns: 1fr; }
            .poa-supplier-sidebar { border-right: 0; border-bottom: 1px solid #e5e7eb; }
            .poa-supplier-tabs { max-height: 260px; }
        }
    </style>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" id="poa-search" class="form-control"
                           placeholder="Tìm SKU hoặc tên hàng...">
                </div>
                <div class="col-md-3">
                    <select id="poa-filter-intent" class="form-select">
                        <option value="">Tất cả loại đề xuất</option>
                        <option value="shop_request_from_warehouse">Shop xin hàng từ kho</option>
                        <option value="shop_return_to_warehouse">Shop trả hàng về kho</option>
                        <option value="warehouse_purchase_more">Kho mua thêm</option>
                        <option value="warehouse_warning">Kho thừa (cảnh báo)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="poa-filter-priority" class="form-select">
                        <option value="">Tất cả mức ưu tiên</option>
                        <option value="urgent">🔴 Chỉ dưới MIN (mua gấp)</option>
                        <option value="normal">🟡 Nên mua / nên xin</option>
                        <option value="info">⚪ Thừa / cảnh báo</option>
                    </select>
                </div>
                <div class="col-md-3 text-end">
                    <span class="text-muted small">
                        Hiển thị <b id="poa-shown">0</b> / <b id="poa-total">0</b> dòng
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Result table -->
    <div class="card">
        <div class="table-responsive text-nowrap">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px">
                            <input type="checkbox" id="poa-check-all" class="form-check-input">
                        </th>
                        <th>SKU</th>
                        <th>Tên hàng</th>
                        <th class="text-end">Tồn hiện</th>
                        <th class="text-end">Min</th>
                        <th class="text-end">Max</th>
                        <th class="text-end">Chênh lệch</th>
                        <th>Loại đề xuất</th>
                        <th class="text-end">SL đề xuất</th>
                        <th>Nơi chuyển</th>
                        <th>Nơi nhận</th>
                        <th>Lý do</th>
                    </tr>
                </thead>
                <tbody id="poa-rows">
                    <tr><td colspan="12" class="text-center text-muted py-4">
                        <span class="spinner-border spinner-border-sm me-1"></span> Đang quét tồn...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small">
        <b>Cách hiểu mức ưu tiên:</b>
        <ul class="mb-0">
            <li><span class="badge poa-badge-urgent">MUA GẤP</span> &mdash; Tồn kho hiện tại <b>dưới ngưỡng MIN</b> (an toàn) → nguy cơ đứt hàng, ưu tiên xử lý đầu tiên. SL đề xuất = bù lên MAX.</li>
            <li><span class="badge bg-label-warning">Nên mua / xin</span> &mdash; Tồn vẫn trên MIN nhưng dưới MAX → mua bổ sung khi có dịp, không gấp.</li>
            <li><span class="badge bg-label-info">Thừa</span> &mdash; Tồn vượt MAX → cảnh báo dư hàng. Kho thì chỉ cảnh báo, shop thì đề xuất trả về kho cha.</li>
        </ul>
    </div>
</div>

<!-- =================== MODAL: SELECT SCAN TARGET =================== -->
<div class="modal fade" id="poaScanTargetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1">
            <i class="bx bx-store-alt me-1"></i> Chọn shop/kho để quét tồn
          </h5>
          <div class="small text-muted">Chỉ hiển thị các website nằm trong sơ đồ phân cấp của nguồn hiện tại.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="input-group mb-3">
          <span class="input-group-text"><i class="bx bx-search"></i></span>
          <input type="text" class="form-control" id="poa-target-search" placeholder="Tìm tên shop, mã shop hoặc blog ID...">
        </div>
        <div id="poa-target-list" class="poa-target-list d-grid gap-2">
          <div class="text-center text-muted py-4">Đang tải danh sách...</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- =================== MODAL: SUPPLIER STATS =================== -->
<div class="modal fade poa-supplier-modal" id="poaSupplierStatsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1">
            <i class="bx bx-network-chart me-1"></i> Thống kê thông minh cần mua từ nhà cung cấp
          </h5>
          <div class="small text-muted" id="poa-supplier-stats-subtitle">
            Nhóm SKU dưới Max/dưới Min theo nhà cung cấp đang gắn trong global_supplier_product.
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="poa-supplier-layout">
          <aside class="poa-supplier-sidebar d-flex flex-column">
            <div class="p-3 border-bottom bg-white">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bx bx-search"></i></span>
                <input type="text" class="form-control" id="poa-supplier-search" placeholder="Tìm NCC, mã, SĐT...">
              </div>
              <div class="small text-muted mt-2" id="poa-supplier-count-line">Chưa tải dữ liệu.</div>
            </div>
            <div class="poa-supplier-tabs" id="poa-supplier-tabs">
              <div class="p-4 text-center text-muted">Đang chờ tải dữ liệu NCC.</div>
            </div>
          </aside>
          <section class="poa-supplier-main">
            <div class="p-3" id="poa-supplier-active-head">
              <div class="text-muted">Chọn NCC bên trái để xem SKU cần mua.</div>
            </div>
            <div class="poa-supplier-filterbar p-3">
              <div class="row g-2 align-items-center">
                <div class="col-lg-5">
                  <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bx bx-search"></i></span>
                    <input type="text" class="form-control" id="poa-supplier-product-search" placeholder="Tìm SKU hoặc tên hàng...">
                  </div>
                </div>
                <div class="col-lg-3">
                  <select class="form-select form-select-sm" id="poa-supplier-priority-filter">
                    <option value="">Tất cả các mức ưu tiên</option>
                    <option value="urgent">Chỉ dưới MIN</option>
                    <option value="normal">Dưới MAX</option>
                  </select>
                </div>
                <div class="col-lg-3">
                  <select class="form-select form-select-sm" id="poa-supplier-multi-filter">
                    <option value="">Tất cả SKU</option>
                    <option value="multi">SKU có nhiều NCC</option>
                    <option value="single">SKU chỉ 1 NCC</option>
                  </select>
                </div>
                <div class="col-lg-1 text-end">
                  <button type="button" class="btn btn-sm btn-outline-primary" id="btn-poa-supplier-reload" title="Tải lại AJAX">
                    <i class="bx bx-refresh"></i>
                  </button>
                </div>
              </div>
            </div>
            <div class="poa-supplier-table-wrap">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th style="width:40px"><input type="checkbox" class="form-check-input" id="poa-supplier-check-all"></th>
                    <th>SKU</th>
                    <th>Tên hàng</th>
                    <th class="text-end">Tồn</th>
                    <th class="text-end">Min</th>
                    <th class="text-end">Max</th>
                    <th class="text-end">SL đề xuất</th>
                    <th>Ưu tiên</th>
                    <th>Ghi chú NCC</th>
                  </tr>
                </thead>
                <tbody id="poa-supplier-products">
                  <tr><td colspan="9" class="text-center text-muted py-5">Chưa tải dữ liệu.</td></tr>
                </tbody>
              </table>
            </div>
            <div class="poa-supplier-actions p-3 d-flex flex-wrap gap-2 align-items-center">
              <div class="me-auto small text-muted" id="poa-supplier-selected-line">Chưa chọn SKU nào.</div>
              <a class="btn btn-outline-primary disabled" href="#" target="_blank" rel="noopener" id="btn-poa-open-purchase">
                <i class="bx bx-cart me-1"></i> Mở phiếu mua hàng
              </a>
              <a class="btn btn-outline-secondary disabled" href="#" target="_blank" rel="noopener" id="btn-poa-open-supplier">
                <i class="bx bx-link-external me-1"></i> Sửa/Gắn NCC
              </a>
              <button type="button" class="btn btn-primary" id="btn-poa-supplier-create-po" disabled>
                <i class="bx bx-check-double me-1"></i> Tạo PO từ SKU đã chọn
              </button>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- =================== MODAL: REVIEW + TẠO PO =================== -->
<div class="modal fade" id="poaReviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bx bx-edit me-1"></i> Xem lại & chỉnh số lượng trước khi tạo PO
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info small mb-3">
          Hệ thống sẽ <b>tự gom nhóm</b> các dòng theo loại đề xuất + nơi chuyển + nơi nhận → mỗi nhóm là 1 phiếu PO riêng.
          <br>Bạn có thể chỉnh lại <b>SL ghi vào PO</b> (mặc định = SL đề xuất) và thêm <b>ghi chú từng dòng</b>.
          Đặt SL = 0 hoặc bỏ tick để loại dòng đó khỏi PO.
        </div>

        <div id="poa-review-groups"></div>

        <div class="mt-3">
          <label class="form-label fw-semibold">Ghi chú chung (áp dụng cho tất cả phiếu được tạo)</label>
          <textarea id="poa-review-note" class="form-control" rows="2"
                    placeholder="VD: Tổng hợp đề xuất ngày .../..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <div class="me-auto small text-muted">
          <span id="poa-review-summary">—</span>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
        <button type="button" class="btn btn-primary" id="btn-poa-confirm-create">
          <i class="bx bx-check-double me-1"></i> Xác nhận tạo PO
        </button>
      </div>
    </div>
  </div>
</div>

<!-- SheetJS for xlsx export -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.20.3/dist/xlsx.full.min.js"></script>

<script>
(function ($) {
    'use strict';

    var POA = {
        ajax: <?php echo wp_json_encode($ajax_url); ?>,
        nonce: <?php echo wp_json_encode($nonce); ?>,
        bid: <?php echo (int) $current_bid; ?>,
        blogName: <?php echo wp_json_encode($current_name); ?>,
        kindLabel: <?php echo wp_json_encode($kind_label); ?>,
        khoName: <?php echo wp_json_encode($kho_name); ?>,
        khoId: <?php echo (int) $kho_pid; ?>,
        isCurrentWarehouse: <?php echo $is_kho_now ? 'true' : 'false'; ?>,
        selectedBlogId: <?php echo (int) $selected_scan_bid; ?>,
        scanTargets: <?php echo wp_json_encode(array_values($scan_targets)); ?>,
        deliveryNonce: <?php echo wp_json_encode($delivery_nonce); ?>,
        ticketUrls: {
            purchase: <?php echo wp_json_encode(admin_url('admin.php?page=tgs-shop-management&view=purchase-add')); ?>,
            internalExport: <?php echo wp_json_encode(admin_url('admin.php?page=tgs-shop-management&view=transfer-export-add')); ?>
        },
        poDetailUrlBase: <?php echo wp_json_encode(admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_DETAIL . '&po_id=')); ?>,
        poListUrl: <?php echo wp_json_encode(admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_LIST)); ?>
    };

    var state = {
        rows: [],
        filterIntent: '',
        filterPriority: '',
        search: '',
        selected: {}, // {idx: true}
        reviewItems: [],
        reviewLaunchMode: 'main',
        reviewSubmitMode: 'po',
        selectedBlogId: POA.selectedBlogId || POA.bid,
        targetSearch: '',
        supplierStats: {
            loaded: false,
            loading: false,
            groups: [],
            activeKey: '',
            supplierSearch: '',
            productSearch: '',
            priority: '',
            multi: '',
            selected: {}
        }
    };

    var $rows  = $('#poa-rows');
    var $shown = $('#poa-shown');
    var $total = $('#poa-total');

    function fmt(n) {
        if (n === null || n === undefined || n === '') return '0';
        var v = parseFloat(n);
        if (isNaN(v)) return '0';
        if (Math.abs(v - Math.round(v)) < 0.001) return Math.round(v).toLocaleString('vi-VN');
        return v.toLocaleString('vi-VN', {minimumFractionDigits: 0, maximumFractionDigits: 3});
    }

    function intentBadge(intent, label) {
        var cls = 'bg-label-secondary';
        if (intent === 'shop_request_from_warehouse' || intent === 'warehouse_purchase_more') cls = 'bg-label-warning';
        else if (intent === 'shop_return_to_warehouse') cls = 'bg-label-info';
        else if (intent === 'warehouse_warning') cls = 'bg-label-secondary';
        return '<span class="badge ' + cls + '">' + $('<i>').text(label).html() + '</span>';
    }

    function priorityBadge(priority) {
        if (priority === 'urgent') return '<span class="badge poa-badge-urgent" title="Tồn dưới ngưỡng MIN">MUA GẤP</span>';
        if (priority === 'normal') return '<span class="badge bg-label-warning">Nên mua</span>';
        if (priority === 'info')   return '<span class="badge bg-label-info">Cảnh báo</span>';
        return '';
    }

    function diffCell(diff) {
        var v = parseFloat(diff) || 0;
        var cls = v < 0 ? 'text-warning fw-semibold' : (v > 0 ? 'text-info fw-semibold' : 'text-muted');
        var sign = v > 0 ? '+' : '';
        return '<span class="' + cls + '">' + sign + fmt(v) + '</span>';
    }

    function placeCell(id, name) {
        if (!id) return '<span class="text-muted">—</span>';
        return $('<i>').text(name || ('Blog #' + id)).html()
             + ' <span class="text-muted small">#' + id + '</span>';
    }

    function applyFilters() {
        var s = (state.search || '').toLowerCase().trim();
        var intent = state.filterIntent || '';
        var prio = state.filterPriority || '';
        return state.rows.filter(function (r) {
            if (intent && r.intent !== intent) return false;
            if (prio && (r.priority || '') !== prio) return false;
            if (s) {
                var hay = (r.sku + ' ' + r.name).toLowerCase();
                if (hay.indexOf(s) === -1) return false;
            }
            return true;
        });
    }

    function renderRows() {
        var data = applyFilters();
        $shown.text(data.length);
        $total.text(state.rows.length);

        if (!data.length) {
            $rows.html('<tr><td colspan="12" class="text-center text-muted py-4">Không có dòng đề xuất nào.</td></tr>');
            updateSelectedCount();
            return;
        }

        var html = '';
        data.forEach(function (r, idx) {
            var rowCls = '';
            if (r.priority === 'urgent')   rowCls = 'poa-row-urgent';
            else if (r.level === 'danger') rowCls = 'table-danger';
            else if (r.level === 'warning')rowCls = 'table-warning';
            else if (r.level === 'info')   rowCls = 'table-info';

            // Không cho chọn dòng kho thừa cảnh báo
            var canSelect = (r.intent !== 'warehouse_warning') && (parseFloat(r.quantity) > 0);
            var checkboxHtml = canSelect
                ? '<input type="checkbox" class="form-check-input poa-row-check" data-idx="' + r._idx + '">'
                : '<span class="text-muted" title="Không tạo PO cho dòng này">—</span>';

            // Cột loại đề xuất: gộp priority badge + intent badge
            var typeCellHtml = (r.priority === 'urgent' ? priorityBadge('urgent') + ' ' : '')
                             + intentBadge(r.intent, r.intent_label);

            // Highlight MIN khi tồn dưới MIN
            var minCellHtml = r.priority === 'urgent'
                ? '<span class="text-danger fw-bold" title="Tồn hiện tại dưới MIN!">' + fmt(r.min_qty) + ' ⚠</span>'
                : fmt(r.min_qty);

            html += '<tr class="' + rowCls + '">'
                  +   '<td>' + checkboxHtml + '</td>'
                  +   '<td><code>' + $('<i>').text(r.sku).html() + '</code></td>'
                  +   '<td>' + $('<i>').text(r.name || '').html() + '</td>'
                  +   '<td class="text-end">' + fmt(r.current_stock) + '</td>'
                  +   '<td class="text-end">' + minCellHtml + '</td>'
                  +   '<td class="text-end">' + fmt(r.max_qty) + '</td>'
                  +   '<td class="text-end">' + diffCell(r.diff) + '</td>'
                  +   '<td>' + typeCellHtml + '</td>'
                  +   '<td class="text-end fw-semibold">' + fmt(r.quantity) + '</td>'
                  +   '<td>' + placeCell(r.transfer_blog_id, r.transfer_blog_name) + '</td>'
                  +   '<td>' + placeCell(r.receive_blog_id,  r.receive_blog_name)  + '</td>'
                  +   '<td class="small text-muted">' + $('<i>').text(r.reason || '').html() + '</td>'
                  + '</tr>';
        });
        $rows.html(html);
    }

    function setSummary(s) {
        $('#poa-summary [data-key]').each(function () {
            var k = $(this).data('key');
            var v = (s && s[k] !== undefined) ? s[k] : 0;
            $(this).text(typeof v === 'number' ? fmt(v) : v);
        });
    }

    function scan() {
        $rows.html('<tr><td colspan="12" class="text-center text-muted py-4">'
            + '<span class="spinner-border spinner-border-sm me-1"></span> Đang quét tồn...</td></tr>');
        $.post(POA.ajax, {
            action: 'tgs_poa_scan',
            nonce: POA.nonce,
            blog_id: state.selectedBlogId
        }).done(function (resp) {
            if (!resp || !resp.success) {
                $rows.html('<tr><td colspan="12" class="text-center text-danger py-4">'
                    + ((resp && resp.data && resp.data.message) || 'Lỗi không xác định.')
                    + '</td></tr>');
                return;
            }
            var d = resp.data || {};
            state.selectedBlogId = parseInt(d.blog_id || state.selectedBlogId || POA.bid, 10);
            state.rows = (d.suggestions || []).map(function (r, i) { r._idx = i; return r; });
            state.selected = {};
            state.supplierStats.loaded = false;
            state.supplierStats.groups = [];
            state.supplierStats.selected = {};
            setSummary(d.summary || {});
            $('#poa-scan-time').text(new Date().toLocaleString('vi-VN'));
            updateScanTargetHeader(d);
            renderRows();
            updateSelectedCount();
        }).fail(function () {
            $rows.html('<tr><td colspan="12" class="text-center text-danger py-4">Lỗi mạng / máy chủ.</td></tr>');
        });
    }

    function updateSelectedCount() {
        var n = Object.keys(state.selected || {}).length;
        $('#poa-selected-count').text(n);
        $('#btn-poa-create').prop('disabled', n === 0);
        $('#btn-poa-create-purchase-ticket').prop('disabled', n === 0);
        var internalRows = getSelectedRows().filter(function (r) {
            return r && r.intent === 'shop_request_from_warehouse' && parseInt(r.receive_blog_id || 0, 10) > 0;
        });
        $('#btn-poa-create-internal-ticket').prop('disabled', internalRows.length === 0);
    }

    function getSelectedRows() {
        return Object.keys(state.selected || {}).map(function (i) {
            return state.rows[parseInt(i, 10)];
        }).filter(Boolean);
    }

    function createPOs() {
        var items = getSelectedRows();
        if (!items.length) return;
        state.reviewLaunchMode = 'main';
        state.reviewSubmitMode = 'po';
        $('#poa-review-note').val('');
        if (!items.length) { alert('Không có dòng nào.'); return; }

        // Mở modal review thay vì tạo ngay
        openReviewModal(items);
    }

    function createTicketFromSelected(ticketType) {
        var items = getSelectedRows();
        if (!items.length) return;

        if (ticketType === 'internal_export') {
            items = items.filter(function (r) {
                return r.intent === 'shop_request_from_warehouse' && parseInt(r.receive_blog_id || 0, 10) > 0;
            });
            if (!items.length) {
                alert('Phiếu bán nội bộ chỉ tạo từ các dòng "Shop xin hàng từ kho".');
                return;
            }
        }

        state.reviewLaunchMode = ticketType === 'purchase' ? 'ticket_purchase' : 'ticket_internal_export';
        state.reviewSubmitMode = state.reviewLaunchMode;
        $('#poa-review-note').val(ticketType === 'purchase'
            ? 'Tạo phiếu mua từ quét tồn thông minh'
            : 'Tạo phiếu bán nội bộ từ quét tồn thông minh');
        openReviewModal(items);
    }

    function openTicketCreateFromReview(ticketType, items) {
        if (!items.length) {
            alert('Không còn dòng nào hợp lệ để tạo phiếu.');
            return;
        }

        var first = items[0] || {};
        var person = null;
        var url = '';
        if (ticketType === 'internal_export') {
            var receiveId = parseInt(first.receive_blog_id || 0, 10);
            if (!receiveId) {
                alert('Không xác định được shop nhận hàng.');
                return;
            }
            person = {
                id: receiveId,
                code: 'SHOP-' + receiveId,
                name: first.receive_blog_name || ('Shop #' + receiveId),
                phone: '',
                email: '',
                address: ''
            };
            url = POA.ticketUrls.internalExport;
        } else {
            url = POA.ticketUrls.purchase;
        }

        var payload = {
            source: 'poa_scan',
            ticket_type: ticketType,
            source_blog_id: POA.bid,
            scan_blog_id: state.selectedBlogId,
            person: person,
            items: items.map(function (item) {
                return {
                    sku: item.sku || item.product_sku || '',
                    product_sku: item.sku || item.product_sku || '',
                    name: item.name || item.product_name || '',
                    quantity: parseFloat(item.quantity || 0) || 1,
                    note: item.reason || item.note || '',
                    reason: item.reason || item.note || '',
                    request_blog_id: item.request_blog_id || 0,
                    request_blog_name: item.request_blog_name || '',
                    transfer_blog_id: item.transfer_blog_id || 0,
                    transfer_blog_name: item.transfer_blog_name || '',
                    receive_blog_id: item.receive_blog_id || 0,
                    receive_blog_name: item.receive_blog_name || ''
                };
            }),
            note: $('#poa-review-note').val() || ''
        };

        try {
            sessionStorage.setItem('tgs_poa_ticket_prefill', JSON.stringify(payload));
        } catch (e) {
            alert('Không thể lưu dữ liệu tạm để mở phiếu. Hãy kiểm tra cài đặt trình duyệt.');
            return;
        }

        window.open(url, '_blank');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('poaReviewModal')).hide();
    }

    var INTENT_LBL_MAP = {
        'shop_request_from_warehouse': ['Shop xin hàng', 'bg-label-warning'],
        'shop_return_to_warehouse':    ['Shop trả hàng', 'bg-label-info'],
        'warehouse_purchase_more':     ['Kho mua thêm',  'bg-label-warning']
    };

    function escHtml(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

    function normalizeText(s) {
        s = (s == null ? '' : String(s)).toLowerCase();
        try {
            s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        } catch (e) {}
        return s.replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function smartMatchText(haystack, keyword) {
        var h = normalizeText(haystack);
        var k = normalizeText(keyword);
        if (!k) return true;
        return k.split(' ').filter(Boolean).every(function (token) {
            return h.indexOf(token) !== -1;
        });
    }

    function scanTargetById(blogId) {
        blogId = parseInt(blogId || 0, 10);
        var targets = POA.scanTargets || [];
        for (var i = 0; i < targets.length; i++) {
            var id = parseInt(targets[i].blog_id || targets[i].id || 0, 10);
            if (id === blogId) return targets[i];
        }
        return null;
    }

    function updateScanTargetHeader(scanResult) {
        var target = scanTargetById(state.selectedBlogId) || {};
        var name = scanResult && scanResult.blog_name ? scanResult.blog_name : (target.name || POA.blogName);
        var kind = scanResult && scanResult.source_kind ? scanResult.source_kind : (target.type || '');
        var isWarehouse = kind === 'warehouse';
        $('#poa-scan-target-label').html(
            escHtml(name) + ' '
            + '<span class="badge ' + (isWarehouse ? 'bg-label-primary' : 'bg-label-success') + ' ms-1" id="poa-scan-target-kind">'
            + (isWarehouse ? 'Kho' : 'Shop') + '</span> '
            + '<span class="text-muted small" id="poa-scan-target-id">#' + state.selectedBlogId + '</span>'
        );
        $('#btn-poa-supplier-stats').toggle(isWarehouse);
    }

    function renderTargetModal() {
        var q = state.targetSearch || '';
        var targets = (POA.scanTargets || []).filter(function (target) {
            var hay = [target.name || '', target.code || '', target.blog_id || target.id || '', target.type_label || ''].join(' ');
            return smartMatchText(hay, q);
        });
        var $list = $('#poa-target-list');
        if (!targets.length) {
            $list.html('<div class="text-center text-muted py-4">Không tìm thấy shop/kho phù hợp.</div>');
            return;
        }
        var html = '';
        targets.forEach(function (target) {
            var id = parseInt(target.blog_id || target.id || 0, 10);
            var type = target.type || '';
            var badge = type === 'warehouse' ? 'bg-label-primary' : 'bg-label-success';
            var active = id === parseInt(state.selectedBlogId || 0, 10) ? ' active' : '';
            html += '<div class="poa-target-item' + active + '" data-blog-id="' + id + '">'
                + '<div class="d-flex align-items-center justify-content-between gap-2">'
                + '<div>'
                + '<div class="fw-semibold">' + escHtml(target.name || ('Blog #' + id)) + '</div>'
                + '<div class="small text-muted">' + escHtml(target.code || ('SHOP-' + id)) + ' · #' + id + '</div>'
                + '</div>'
                + '<span class="badge ' + badge + '">' + escHtml(target.type_label || (type === 'warehouse' ? 'Kho' : 'Shop')) + '</span>'
                + '</div>'
                + '</div>';
        });
        $list.html(html);
    }

    function selectScanTarget(blogId, shouldScan) {
        blogId = parseInt(blogId || 0, 10);
        if (!blogId) return;
        state.selectedBlogId = blogId;
        state.selected = {};
        updateSelectedCount();
        updateScanTargetHeader({});
        renderTargetModal();
        if (shouldScan) {
            scan();
        }
    }

    function placeText(id, name) {
        if (!id) return '<span class="text-muted">—</span>';
        return escHtml(name || ('Blog #' + id)) + ' <span class="text-muted small">#' + id + '</span>';
    }

    function supplierGroupByKey(key) {
        var groups = state.supplierStats.groups || [];
        for (var i = 0; i < groups.length; i++) {
            if (String(groups[i].key) === String(key)) return groups[i];
        }
        return null;
    }

    function supplierItemKey(groupKey, sku) {
        return String(groupKey || '') + '|' + String(sku || '').toUpperCase();
    }

    function currentSupplierGroup() {
        return supplierGroupByKey(state.supplierStats.activeKey);
    }

    function filteredSupplierGroups() {
        var q = state.supplierStats.supplierSearch || '';
        return (state.supplierStats.groups || []).filter(function (g) {
            var hay = [g.supplier_code || '', g.supplier_name || '', g.supplier_phone || '', g.supplier_email || ''].join(' ');
            return smartMatchText(hay, q);
        });
    }

    function filteredSupplierItems(group) {
        if (!group) return [];
        var q = state.supplierStats.productSearch || '';
        var prio = state.supplierStats.priority || '';
        var multi = state.supplierStats.multi || '';
        return (group.items || []).filter(function (item) {
            if (prio && (item.priority || '') !== prio) return false;
            var supplierCount = parseInt(item.supplier_count || 0, 10);
            if (multi === 'multi' && supplierCount <= 1) return false;
            if (multi === 'single' && supplierCount > 1) return false;
            var hay = [item.sku || '', item.name || '', item.reason || '', item.supplier_warning || ''].join(' ');
            return smartMatchText(hay, q);
        });
    }

    function renderSupplierStats() {
        renderSupplierTabs();
        renderSupplierProducts();
    }

    function renderSupplierTabs() {
        var groups = filteredSupplierGroups();
        var $tabs = $('#poa-supplier-tabs');
        var total = state.supplierStats.groups.length;
        var summary = state.supplierStats.summary || {};
        $('#poa-supplier-count-line').text(total
            ? (groups.length + ' / ' + total + ' NCC, ' + (summary.row_count || 0) + ' SKU cần mua')
            : 'Chưa có NCC nào có SKU cần mua.');

        if (!groups.length) {
            $tabs.html('<div class="p-4 text-center text-muted">Không tìm thấy NCC phù hợp.</div>');
            return;
        }

        var activeInFiltered = groups.some(function (g) { return String(g.key) === String(state.supplierStats.activeKey); });
        if (!activeInFiltered) {
            state.supplierStats.activeKey = groups[0].key;
        }

        var html = '';
        groups.forEach(function (g) {
            var active = String(g.key) === String(state.supplierStats.activeKey) ? ' active' : '';
            var code = g.supplier_code ? escHtml(g.supplier_code) + ' - ' : '';
            var phone = g.supplier_phone ? escHtml(g.supplier_phone) : 'Chưa có SĐT';
            var urgent = parseInt(g.count_urgent || 0, 10);
            html += '<button type="button" class="poa-supplier-tab' + active + '" data-key="' + escHtml(g.key) + '">'
                + '<div class="d-flex justify-content-between gap-2">'
                + '<div class="poa-supplier-tab-title">' + code + escHtml(g.supplier_name || 'NCC') + '</div>'
                + '<span class="badge bg-label-primary">' + fmt(g.count_total || 0) + '</span>'
                + '</div>'
                + '<div class="poa-supplier-tab-sub">' + phone + (urgent > 0 ? ' - ' + urgent + ' dưới MIN' : '') + '</div>'
                + '<div class="poa-supplier-tab-sub">Tổng SL đề xuất: ' + fmt(g.sum_qty || 0) + '</div>'
                + '</button>';
        });
        $tabs.html(html);
    }

    function renderSupplierProducts() {
        var group = currentSupplierGroup();
        var $body = $('#poa-supplier-products');
        var $purchase = $('#btn-poa-open-purchase');
        var $supplier = $('#btn-poa-open-supplier');
        var $create = $('#btn-poa-supplier-create-po');
        var $checkAll = $('#poa-supplier-check-all');

        if (!group) {
            $('#poa-supplier-active-head').html('<div class="text-muted">Chọn NCC bên trái để xem SKU cần mua.</div>');
            $body.html('<tr><td colspan="9" class="text-center text-muted py-5">Chưa chọn NCC.</td></tr>');
            $purchase.addClass('disabled').attr('href', '#');
            $supplier.addClass('disabled').attr('href', '#');
            $create.prop('disabled', true);
            $('#poa-supplier-selected-line').text('Chưa chọn SKU nào.');
            $checkAll.prop('checked', false).prop('indeterminate', false);
            return;
        }

        var supplierTitle = (group.supplier_code ? escHtml(group.supplier_code) + ' - ' : '') + escHtml(group.supplier_name || 'NCC');
        var supplierMeta = [
            group.supplier_phone ? 'SĐT: ' + escHtml(group.supplier_phone) : '',
            group.supplier_email ? 'Email: ' + escHtml(group.supplier_email) : ''
        ].filter(Boolean).join(' | ');
        var noSupplier = parseInt(group.supplier_id || 0, 10) <= 0;
        $('#poa-supplier-active-head').html(
            '<div class="d-flex flex-wrap justify-content-between gap-2">'
            + '<div>'
            + '<div class="h5 mb-1">' + supplierTitle + '</div>'
            + '<div class="small text-muted">' + (supplierMeta || (noSupplier ? 'SKU chưa gắn với NCC nào.' : 'NCC đang được chọn.')) + '</div>'
            + '<div class="small text-muted mt-1">Chỉ hiện SKU dưới Max/dưới Min của đợt quét hiện tại.</div>'
            + '</div>'
            + '<div class="text-end small">'
            + '<span class="badge bg-label-danger me-1">' + fmt(group.count_urgent || 0) + ' dưới MIN</span>'
            + '<span class="badge bg-label-warning">' + fmt(group.count_normal || 0) + ' dưới MAX</span>'
            + '</div>'
            + '</div>'
        );

        if (group.purchase_url) $purchase.removeClass('disabled').attr('href', group.purchase_url);
        else $purchase.addClass('disabled').attr('href', '#');
        if (group.edit_url) $supplier.removeClass('disabled').attr('href', group.edit_url);
        else $supplier.addClass('disabled').attr('href', '#');

        var items = filteredSupplierItems(group);
        if (!items.length) {
            $body.html('<tr><td colspan="9"><div class="poa-supplier-empty">Không có SKU phù hợp bộ lọc.</div></td></tr>');
            updateSupplierSelectedCount();
            return;
        }

        var html = '';
        items.forEach(function (item) {
            var key = supplierItemKey(group.key, item.sku);
            var checked = state.supplierStats.selected[key] ? ' checked' : '';
            var warning = item.supplier_warning
                ? '<span class="badge bg-label-warning">' + escHtml(item.supplier_warning) + '</span>'
                : '<span class="text-muted">-</span>';
            var prio = (item.priority || '') === 'urgent'
                ? '<span class="badge poa-badge-urgent">DƯỚI MIN</span>'
                : '<span class="badge bg-label-warning">DƯỚI MAX</span>';
            html += '<tr data-key="' + escHtml(key) + '">'
                + '<td><input type="checkbox" class="form-check-input poa-supplier-row-check" data-key="' + escHtml(key) + '"' + checked + '></td>'
                + '<td><code>' + escHtml(item.sku || '') + '</code></td>'
                + '<td>' + escHtml(item.name || '') + '</td>'
                + '<td class="text-end">' + fmt(item.current_stock || 0) + '</td>'
                + '<td class="text-end">' + fmt(item.min_qty || 0) + '</td>'
                + '<td class="text-end">' + fmt(item.max_qty || 0) + '</td>'
                + '<td class="text-end fw-semibold">' + fmt(item.quantity || 0) + '</td>'
                + '<td>' + prio + '</td>'
                + '<td>' + warning + '</td>'
                + '</tr>';
        });
        $body.html(html);
        updateSupplierSelectedCount();
    }

    function updateSupplierSelectedCount() {
        var group = currentSupplierGroup();
        var selected = 0;
        var visible = 0;
        if (group) {
            filteredSupplierItems(group).forEach(function (item) {
                visible++;
                if (state.supplierStats.selected[supplierItemKey(group.key, item.sku)]) selected++;
            });
        }

        $('#poa-supplier-selected-line').text(selected
            ? ('Đã chọn ' + selected + ' SKU trong NCC này.')
            : 'Chưa chọn SKU nào.');
        $('#btn-poa-supplier-create-po').prop('disabled', selected === 0);
        $('#poa-supplier-check-all')
            .prop('checked', visible > 0 && selected === visible)
            .prop('indeterminate', selected > 0 && selected < visible);
    }

    function loadSupplierStats(force) {
        if (state.supplierStats.loading) return;
        if (state.supplierStats.loaded && !force) {
            renderSupplierStats();
            return;
        }

        state.supplierStats.loading = true;
        $('#poa-supplier-tabs').html('<div class="p-4 text-center text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Đang tải NCC...</div>');
        $('#poa-supplier-products').html('<tr><td colspan="9" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-1"></span>Đang tải dữ liệu...</td></tr>');
        $('#poa-supplier-count-line').text('Đang tải dữ liệu...');

        $.post(POA.ajax, {
            action: 'tgs_poa_supplier_stats',
            nonce: POA.nonce,
            blog_id: state.selectedBlogId
        }).done(function (resp) {
            if (!resp || !resp.success) {
                var msg = (resp && resp.data && resp.data.message) || 'Không tải được thống kê thông minh cần mua từ nhà cung cấp.';
                $('#poa-supplier-tabs').html('<div class="p-4 text-center text-danger">' + escHtml(msg) + '</div>');
                $('#poa-supplier-products').html('<tr><td colspan="9" class="text-center text-danger py-5">' + escHtml(msg) + '</td></tr>');
                return;
            }
            var d = resp.data || {};
            state.supplierStats.groups = d.groups || [];
            state.supplierStats.summary = d.summary || {};
            state.supplierStats.loaded = true;
            state.supplierStats.selected = {};
            state.supplierStats.activeKey = state.supplierStats.groups.length ? state.supplierStats.groups[0].key : '';
            $('#poa-supplier-stats-subtitle').text('Website: ' + (d.blog_name || POA.blogName) + ' #' + (d.blog_id || POA.bid) + ' - ' + ((d.summary && d.summary.row_count) || 0) + ' SKU cần mua.');
            renderSupplierStats();
        }).fail(function () {
            $('#poa-supplier-tabs').html('<div class="p-4 text-center text-danger">Lỗi mạng / máy chủ.</div>');
            $('#poa-supplier-products').html('<tr><td colspan="9" class="text-center text-danger py-5">Lỗi mạng / máy chủ.</td></tr>');
        }).always(function () {
            state.supplierStats.loading = false;
        });
    }

    function openSupplierStatsModal() {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('poaSupplierStatsModal')).show();
        loadSupplierStats(false);
    }

    function openSupplierPOReview() {
        var group = currentSupplierGroup();
        if (!group) return;
        var items = [];
        (group.items || []).forEach(function (item) {
            if (state.supplierStats.selected[supplierItemKey(group.key, item.sku)]) {
                items.push($.extend({}, item));
            }
        });
        if (!items.length) {
            alert('Chưa chọn SKU nào.');
            return;
        }
        state.reviewLaunchMode = 'supplier_stats';
        state.reviewSubmitMode = 'po';
        $('#poa-review-note').val('Tạo PO từ thống kê thông minh cần mua từ nhà cung cấp: ' + (group.supplier_code ? group.supplier_code + ' - ' : '') + (group.supplier_name || 'NCC'));
        openReviewModal(items);
    }

    /**
     * Nhóm theo (intent, transfer_blog_id, receive_blog_id) — phải khớp với backend.
     */
    function groupItems(items) {
        var groups = {};
        items.forEach(function (it) {
            var key = (it.intent || '') + '|' + (it.transfer_blog_id || 0) + '|' + (it.receive_blog_id || 0);
            if (!groups[key]) groups[key] = { meta: it, items: [] };
            groups[key].items.push(it);
        });
        return groups;
    }

    function openReviewModal(items) {
        var $wrap = $('#poa-review-groups').empty();
        var isTicketMode = state.reviewSubmitMode === 'ticket_purchase' || state.reviewSubmitMode === 'ticket_internal_export';
        var modalTitle = 'Xem lại & chỉnh số lượng trước khi tạo PO';
        var confirmHtml = '<i class="bx bx-check-double me-1"></i> Xác nhận tạo PO';
        if (state.reviewSubmitMode === 'ticket_purchase') {
            modalTitle = 'Xem lại & chỉnh số lượng trước khi mở phiếu mua';
            confirmHtml = '<i class="bx bx-window-open me-1"></i> Mở phiếu mua';
        } else if (state.reviewSubmitMode === 'ticket_internal_export') {
            modalTitle = 'Xem lại & chỉnh số lượng trước khi mở phiếu bán nội bộ';
            confirmHtml = '<i class="bx bx-window-open me-1"></i> Mở phiếu bán nội bộ';
        }
        $('#poaReviewModal .modal-title').html('<i class="bx bx-edit me-1"></i> ' + escHtml(modalTitle));
        $('#btn-poa-confirm-create').html(confirmHtml);
        $('#poaReviewModal .alert-info').html(isTicketMode
            ? 'Hệ thống sẽ mở trang tạo phiếu với các dòng đã chọn. Bạn vẫn có thể kiểm tra, chỉnh tiếp và chủ động bấm lưu phiếu trên trang mới.'
            : 'Hệ thống sẽ <b>tự gom nhóm</b> các dòng theo loại đề xuất + nơi chuyển + nơi nhận → mỗi nhóm là 1 phiếu PO riêng.<br>Bạn có thể chỉnh lại <b>SL ghi vào PO</b> và thêm <b>ghi chú từng dòng</b>. Đặt SL = 0 hoặc bỏ tick để loại dòng đó khỏi PO.');
        state.reviewItems = (items || []).map(function (item, i) {
            var copy = $.extend({}, item);
            copy._review_idx = i;
            return copy;
        });
        var groups = groupItems(state.reviewItems);
        var gIdx = 0;

        Object.keys(groups).forEach(function (key) {
            var g = groups[key];
            var meta = g.meta;
            var lbl = INTENT_LBL_MAP[meta.intent] || [meta.intent, 'bg-label-secondary'];

            var rowsHtml = '';
            g.items.forEach(function (r) {
                rowsHtml += '<tr data-idx="' + (r._idx == null ? '' : r._idx) + '" data-review-idx="' + r._review_idx + '">'
                  + '<td><input type="checkbox" class="form-check-input poa-rv-include" checked></td>'
                  + '<td><code>' + escHtml(r.sku) + '</code></td>'
                  + '<td>' + escHtml(r.name || '') + '</td>'
                  + '<td class="text-end">' + fmt(r.current_stock) + '</td>'
                  + '<td class="text-end">' + fmt(r.max_qty) + '</td>'
                  + '<td class="text-end text-muted">' + fmt(r.quantity) + '</td>'
                  + '<td><input type="number" min="0" step="0.001" class="form-control form-control-sm poa-rv-qty text-end"'
                  +     ' value="' + (parseFloat(r.quantity) || 0) + '" style="max-width:120px"></td>'
                  + '<td><input type="text" class="form-control form-control-sm poa-rv-note"'
                  +     ' value="' + escHtml(r.reason || '') + '" placeholder="Ghi chú dòng..."></td>'
                  + '</tr>';
            });

            var card = ''
              + '<div class="card border mb-3" data-group-key="' + escHtml(key) + '">'
              +   '<div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">'
              +     '<div>'
              +       '<span class="fw-semibold">Phiếu #' + (++gIdx) + '</span> '
              +       '<span class="badge ' + lbl[1] + ' ms-1">' + escHtml(lbl[0]) + '</span>'
              +     '</div>'
              +     '<div class="small">'
              +       '<span class="text-muted">Yêu cầu:</span> ' + placeText(meta.request_blog_id, meta.request_blog_name)
              +       ' &nbsp;<span class="text-muted">→ Chuyển:</span> ' + placeText(meta.transfer_blog_id, meta.transfer_blog_name)
              +       ' &nbsp;<span class="text-muted">→ Nhận:</span> ' + placeText(meta.receive_blog_id, meta.receive_blog_name)
              +     '</div>'
              +   '</div>'
              +   '<div class="table-responsive">'
              +     '<table class="table table-sm align-middle mb-0">'
              +       '<thead class="table-light">'
              +         '<tr>'
              +           '<th style="width:36px"><input type="checkbox" class="form-check-input poa-rv-grp-all" checked></th>'
              +           '<th>SKU</th><th>Tên hàng</th>'
              +           '<th class="text-end">Tồn hiện</th>'
              +           '<th class="text-end">Max</th>'
              +           '<th class="text-end">SL đề xuất</th>'
              +           '<th class="text-end" style="width:140px">SL ghi PO</th>'
              +           '<th style="min-width:200px">Ghi chú dòng</th>'
              +         '</tr>'
              +       '</thead>'
              +       '<tbody>' + rowsHtml + '</tbody>'
              +     '</table>'
              +   '</div>'
              + '</div>';
            $wrap.append(card);
        });

        updateReviewSummary();
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('poaReviewModal'));
        modal.show();
    }

    function updateReviewSummary() {
        var grpCount = $('#poa-review-groups .card[data-group-key]').length;
        var lineCount = 0;
        var qtySum = 0;
        $('#poa-review-groups tbody tr').each(function () {
            var $tr = $(this);
            if (!$tr.find('.poa-rv-include').is(':checked')) return;
            var q = parseFloat($tr.find('.poa-rv-qty').val()) || 0;
            if (q <= 0) return;
            lineCount++;
            qtySum += q;
        });
        $('#poa-review-summary').text('Sẽ tạo ' + grpCount + ' phiếu · ' + lineCount + ' dòng · tổng SL ' + fmt(qtySum));
    }

    function collectReviewItems() {
        var collected = [];
        $('#poa-review-groups tbody tr').each(function () {
            var $tr = $(this);
            if (!$tr.find('.poa-rv-include').is(':checked')) return;
            var qty = parseFloat($tr.find('.poa-rv-qty').val()) || 0;
            if (qty <= 0) return;
            var reviewIdx = parseInt($tr.data('review-idx'), 10);
            var idx = parseInt($tr.data('idx'), 10);
            var src = !isNaN(reviewIdx) ? state.reviewItems[reviewIdx] : state.rows[idx];
            if (!src) return;
            collected.push($.extend({}, src, {
                quantity: qty,
                reason: $tr.find('.poa-rv-note').val() || ''
            }));
        });
        return collected;
    }

    function confirmCreate() {
        var items = collectReviewItems();
        if (!items.length) { alert('Không còn dòng nào hợp lệ (đã bỏ tick / SL = 0).'); return; }
        var note = $('#poa-review-note').val() || '';

        if (state.reviewSubmitMode === 'ticket_purchase') {
            openTicketCreateFromReview('purchase', items);
            return;
        }
        if (state.reviewSubmitMode === 'ticket_internal_export') {
            openTicketCreateFromReview('internal_export', items);
            return;
        }

        var $btn = $('#btn-poa-confirm-create');
        var oldHtml = $btn.html();
        var openAfterCreate = (state.reviewLaunchMode === 'supplier_stats');
        var targetWindow = openAfterCreate ? window.open('about:blank', '_blank') : null;
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Đang tạo...');

        $.post(POA.ajax, {
            action: 'tgs_poa_create',
            nonce: POA.nonce,
            items: JSON.stringify(items),
            note: note
        }).done(function (resp) {
            if (!resp || !resp.success) {
                if (targetWindow && !targetWindow.closed) targetWindow.close();
                alert((resp && resp.data && resp.data.message) || 'Tạo PO thất bại.');
                return;
            }
            var d = resp.data || {};
            var msg = d.message || ('Đã tạo ' + (d.created || []).length + ' phiếu PO.');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('poaReviewModal')).hide();
            if (openAfterCreate) {
                var created = d.created || [];
                var url = (created[0] && created[0].po_id) ? (POA.poDetailUrlBase + encodeURIComponent(created[0].po_id)) : POA.poListUrl;
                if (targetWindow && !targetWindow.closed) targetWindow.location.href = url;
                else window.open(url, '_blank');
                state.supplierStats.selected = {};
                renderSupplierProducts();
                state.reviewLaunchMode = 'main';
                alert(msg);
                return;
            }
            if (confirm(msg + '\n\nĐi đến danh sách PO ngay?')) {
                window.location.href = <?php echo wp_json_encode(admin_url('admin.php?page=tgs-shop-management&view=' . TGS_POA_Menu::VIEW_LIST)); ?>;
            } else {
                state.selected = {};
                renderRows();
                updateSelectedCount();
            }
        }).fail(function () {
            if (targetWindow && !targetWindow.closed) targetWindow.close();
            alert('Lỗi mạng / máy chủ khi tạo PO.');
        }).always(function () {
            $btn.prop('disabled', false).html(oldHtml);
        });
    }

    function exportExcel() {
        var $btn = $('#btn-poa-export');
        var oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Đang tạo...');

        $.post(POA.ajax, {
            action: 'tgs_poa_export_excel',
            nonce: POA.nonce,
            blog_id: state.selectedBlogId
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || 'Không tạo được Excel.');
                return;
            }
            var d = resp.data || {};
            var rows = d.rows || [];
            if (!rows.length) {
                alert('Không có dòng đề xuất nào để xuất.');
                return;
            }

            var headerInfo = [
                ['BÁO CÁO QUÉT TỒN THÔNG MINH — PO ĐIỀU CHỈNH'],
                ['Website: ' + (d.blog_name || POA.blogName) + '  (#' + (d.blog_id || POA.bid) + ')   Loại: ' + (d.source_kind === 'warehouse' ? 'Kho' : 'Shop')],
                ['Thời điểm: ' + new Date().toLocaleString('vi-VN')],
                []
            ];
            var headerCols = [
                'SKU', 'Tên hàng', 'Tồn hiện tại', 'Tồn min', 'Tồn max', 'Chênh lệch',
                'Mức ưu tiên', 'Loại đề xuất', 'SL đề xuất',
                'Blog ID yêu cầu', 'Tên shop yêu cầu',
                'Blog ID chuyển hàng', 'Tên shop chuyển hàng',
                'Blog ID nhận hàng', 'Tên shop nhận hàng',
                'Lý do / ghi chú'
            ];
            var aoa = headerInfo.concat([headerCols]);
            rows.forEach(function (r) {
                aoa.push([
                    r.sku, r.name,
                    Number(r.current_stock) || 0, Number(r.min_qty) || 0, Number(r.max_qty) || 0,
                    Number(r.diff) || 0,
                    r.priority_label || '', r.intent_label, Number(r.quantity) || 0,
                    r.request_blog_id || '', r.request_blog_name || '',
                    r.transfer_blog_id || '', r.transfer_blog_name || '',
                    r.receive_blog_id || '',  r.receive_blog_name  || '',
                    r.reason || ''
                ]);
            });

            var ws = XLSX.utils.aoa_to_sheet(aoa);
            ws['!merges'] = [
                {s:{r:0,c:0}, e:{r:0,c:15}},
                {s:{r:1,c:0}, e:{r:1,c:15}},
                {s:{r:2,c:0}, e:{r:2,c:15}}
            ];
            ws['!cols'] = [
                {wch:18},{wch:36},{wch:12},{wch:8},{wch:8},{wch:12},
                {wch:18},{wch:24},{wch:12},
                {wch:14},{wch:24},{wch:16},{wch:24},{wch:14},{wch:24},{wch:40}
            ];

            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Goi y PO dieu chinh');

            var dateStr = new Date().toISOString().slice(0,10);
            var safeName = (d.blog_name || POA.blogName || 'website').toString()
                .normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^A-Za-z0-9_-]+/g,'_').replace(/_+/g,'_').replace(/^_|_$/g,'');
            XLSX.writeFile(wb, 'goi-y-PO-dieu-chinh_' + safeName + '_' + dateStr + '.xlsx');
        }).fail(function () {
            alert('Lỗi mạng / máy chủ khi xuất Excel.');
        }).always(function () {
            $btn.prop('disabled', false).html(oldHtml);
        });
    }

    $(document).on('click', '#btn-poa-supplier-stats', openSupplierStatsModal);
    $(document).on('click', '#btn-poa-supplier-reload', function () {
        loadSupplierStats(true);
    });
    $(document).on('input', '#poa-supplier-search', function () {
        state.supplierStats.supplierSearch = $(this).val();
        renderSupplierStats();
    });
    $(document).on('input', '#poa-supplier-product-search', function () {
        state.supplierStats.productSearch = $(this).val();
        renderSupplierProducts();
    });
    $(document).on('change', '#poa-supplier-priority-filter', function () {
        state.supplierStats.priority = $(this).val();
        renderSupplierProducts();
    });
    $(document).on('change', '#poa-supplier-multi-filter', function () {
        state.supplierStats.multi = $(this).val();
        renderSupplierProducts();
    });
    $(document).on('click', '.poa-supplier-tab', function () {
        state.supplierStats.activeKey = $(this).data('key');
        renderSupplierStats();
    });
    $(document).on('change', '.poa-supplier-row-check', function () {
        var key = $(this).data('key');
        if (this.checked) state.supplierStats.selected[key] = true;
        else delete state.supplierStats.selected[key];
        updateSupplierSelectedCount();
    });
    $(document).on('change', '#poa-supplier-check-all', function () {
        var group = currentSupplierGroup();
        var checked = this.checked;
        if (group) {
            filteredSupplierItems(group).forEach(function (item) {
                var key = supplierItemKey(group.key, item.sku);
                if (checked) state.supplierStats.selected[key] = true;
                else delete state.supplierStats.selected[key];
            });
        }
        renderSupplierProducts();
    });
    $(document).on('click', '#btn-poa-supplier-create-po', openSupplierPOReview);
    $(document).on('hidden.bs.modal', '#poaReviewModal', function () {
        if ($('#poaSupplierStatsModal').hasClass('show')) {
            $('body').addClass('modal-open');
        }
    });

    $(document).on('click', '#btn-poa-select-scan-target', function () {
        renderTargetModal();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('poaScanTargetModal')).show();
        setTimeout(function () { $('#poa-target-search').trigger('focus'); }, 250);
    });
    $(document).on('input', '#poa-target-search', function () {
        state.targetSearch = $(this).val();
        renderTargetModal();
    });
    $(document).on('click', '.poa-target-item', function () {
        selectScanTarget($(this).data('blog-id'), true);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('poaScanTargetModal')).hide();
    });
    $(document).on('click', '.poa-delivery-chip', function () {
        selectScanTarget($(this).data('blog-id'), true);
    });

    $(document).on('click', '#btn-poa-rescan', scan);
    $(document).on('click', '#btn-poa-export', exportExcel);
    $(document).on('click', '#btn-poa-create', createPOs);
    $(document).on('click', '#btn-poa-create-purchase-ticket', function () {
        createTicketFromSelected('purchase');
    });
    $(document).on('click', '#btn-poa-create-internal-ticket', function () {
        createTicketFromSelected('internal_export');
    });
    $(document).on('click', '#btn-poa-confirm-create', confirmCreate);
    $(document).on('input change', '#poa-review-groups .poa-rv-qty, #poa-review-groups .poa-rv-include', updateReviewSummary);
    $(document).on('change', '#poa-review-groups .poa-rv-grp-all', function () {
        var checked = this.checked;
        $(this).closest('.card').find('.poa-rv-include').each(function () {
            this.checked = checked;
        });
        updateReviewSummary();
    });
    $(document).on('change', '.poa-row-check', function () {
        var idx = $(this).data('idx');
        if (this.checked) state.selected[idx] = true;
        else delete state.selected[idx];
        updateSelectedCount();
    });
    $(document).on('change', '#poa-check-all', function () {
        var checked = this.checked;
        $('.poa-row-check').each(function () {
            this.checked = checked;
            var idx = $(this).data('idx');
            if (checked) state.selected[idx] = true;
            else delete state.selected[idx];
        });
        updateSelectedCount();
    });
    $(document).on('input', '#poa-search', function () {
        state.search = $(this).val();
        renderRows();
    });
    $(document).on('change', '#poa-filter-intent', function () {
        state.filterIntent = $(this).val();
        renderRows();
    });
    $(document).on('change', '#poa-filter-priority', function () {
        state.filterPriority = $(this).val();
        $('#poa-card-urgent').toggleClass('active', state.filterPriority === 'urgent');
        renderRows();
    });
    // Click vào card "DƯỚI MIN" → bật/tắt filter nhanh
    $(document).on('click', '#poa-card-urgent', function () {
        var $f = $('#poa-filter-priority');
        var newVal = ($f.val() === 'urgent') ? '' : 'urgent';
        $f.val(newVal).trigger('change');
    });

    $(function () {
        updateScanTargetHeader({});
        renderTargetModal();
        scan();
    });
})(jQuery);
</script>
