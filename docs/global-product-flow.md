# Luồng sản phẩm global cho TGS PO Adjustment

Plugin này quét tồn Min/Max để gợi ý PO điều chỉnh. Từ bản chuyển global, plugin không đọc bảng sản phẩm local nữa.

## Nguyên tắc

- Catalog sản phẩm lấy qua `TGS_Global_Product_Source`.
- Tồn hiện tại theo SKU lấy từ `TGS_Global_Product_Source::get_stock_for_skus()`, tức tính từ ledger/API theo `blog_id`.
- Không đọc `local_product_quantity_no_tracking` trong bảng sản phẩm.
- Không join hoặc query bảng `local_product_name`.
- Các cột như `product_sku`, `product_name` trong bảng PO là dữ liệu snapshot nghiệp vụ của phiếu, không phải bảng catalog local.

## File chính

- `includes/class-tgs-poa-helper.php`
  - `get_current_stock_map()` lấy tồn từ ledger/API.
  - `search_global_products_for_blog()` tìm SKU/tên từ catalog global và trả shape cũ `{sku, name, qty}` cho UI.
- `includes/class-tgs-poa-ajax.php`
  - `ajax_search_sku()` gọi helper global, không đọc bảng `wp_<blog>_local_product_name`.

Khi phát triển thêm, hãy nối dữ liệu bằng SKU/global product API. Không fallback sang bảng sản phẩm local.
