<?php

namespace App\Controllers;

use App\Models\Item_quantity;
use Config\Database;

/**
 * Controller for the TileVista ↔ OSPOS integration API.
 * Exposes live inventory metrics for the Weerawila Showroom outlet location (location_id = 1).
 * This controller is read-only. Stock mutations must be performed through the POS Cashier UI.
 */
class TilevistaApi extends BaseController
{
    /**
     * Helper to authenticate incoming requests via a Bearer token.
     * The token must match OSPOS_TILEVISTA_TOKEN defined in OSPOS's .env file.
     */
    private function checkAuth()
    {
        $expectedToken = env('OSPOS_TILEVISTA_TOKEN');
        $authHeader = $this->request->getHeaderLine('Authorization');

        if (empty($authHeader) || $authHeader !== 'Bearer ' . $expectedToken) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['error' => 'Unauthorized']);
        }

        return null; // Auth passed
    }

    /**
     * GET /api/tilevista/items
     * Returns all active, non-deleted items joined with their live quantity at location 1 (Weerawila Showroom).
     */
    public function getItems()
    {
        if ($authError = $this->checkAuth()) {
            return $authError;
        }

        $db = Database::connect();

        // 1. Fetch attributes for all products
        $attrQuery = $db->query('
            SELECT 
                al.item_id,
                ad.definition_name,
                av.attribute_value
            FROM ospos_attribute_links al
            JOIN ospos_attribute_definitions ad ON al.definition_id = ad.definition_id
            JOIN ospos_attribute_values av ON al.attribute_id = av.attribute_id
            WHERE al.item_id IS NOT NULL AND ad.deleted = 0
        ');
        $attrRows = $attrQuery->getResultArray();

        // Group attributes by item_id
        $itemAttributes = [];
        foreach ($attrRows as $attrRow) {
            $itemId = (int) $attrRow['item_id'];
            $defName = $attrRow['definition_name'];
            $val = $attrRow['attribute_value'];
            if (!isset($itemAttributes[$itemId])) {
                $itemAttributes[$itemId] = [];
            }
            $itemAttributes[$itemId][$defName] = $val;
        }

        // 2. Fetch main product list
        $query = $db->query('
            SELECT
                i.item_id,
                i.name,
                i.category,
                i.category_id,
                i.subcategory_id,
                i.item_number,
                i.description,
                i.unit_price,
                i.reorder_level,
                COALESCE(iq.quantity, 0) AS quantity
            FROM ospos_items i
            LEFT JOIN ospos_item_quantities iq
                ON i.item_id = iq.item_id AND iq.location_id = 1
            WHERE i.deleted = 0
            ORDER BY i.item_id ASC
        ');
        $rows = $query->getResultArray();

        // 3. Map attributes into item payload
        $items = array_map(static fn ($row) => [
            'item_id'     => (int) $row['item_id'],
            'name'        => $row['name'],
            'category'    => $row['category'],
            'category_id' => $row['category_id'] ? (int) $row['category_id'] : null,
            'subcategory_id' => $row['subcategory_id'] ? (int) $row['subcategory_id'] : null,
            'sku'         => $row['item_number'] ?? '',
            'description' => $row['description'],
            'price'       => (float) $row['unit_price'],
            'quantity'       => (float) $row['quantity'],
            'reorder_level'  => (float) $row['reorder_level'],
            'attributes'     => $itemAttributes[(int) $row['item_id']] ?? (object) [],
        ], $rows);

        return $this->response->setJSON($items);
    }

    /**
     * GET /api/tilevista/categories
     * Returns the hierarchical categories.
     */
    public function getCategories()
    {
        if ($authError = $this->checkAuth()) {
            return $authError;
        }

        $db = Database::connect();

        // Fetch categories
        $queryCat = $db->query('SELECT id, name FROM ospos_categories WHERE deleted = 0 ORDER BY id');
        $cats = $queryCat->getResultArray();

        // Fetch subcategories
        $querySub = $db->query('SELECT id, category_id, name FROM ospos_subcategories WHERE deleted = 0 ORDER BY category_id, id');
        $subs = $querySub->getResultArray();

        $categories = [];

        foreach ($cats as $cat) {
            $categories[$cat['id']] = [
                'id' => (int) $cat['id'],
                'name' => $cat['name'],
                'subcategories' => []
            ];
        }

        foreach ($subs as $sub) {
            if (isset($categories[$sub['category_id']])) {
                $categories[$sub['category_id']]['subcategories'][] = [
                    'id' => (int) $sub['id'],
                    'category_id' => (int) $sub['category_id'],
                    'name' => $sub['name']
                ];
            }
        }

        return $this->response->setJSON(array_values($categories));
    }

    /**
     * GET /api/tilevista/stock/{item_id}
     * Returns the current stock level for a single item at location 1 (Weerawila Showroom).
     */
    public function getStock($itemId = null)
    {
        if ($authError = $this->checkAuth()) {
            return $authError;
        }

        if ($itemId === null || $itemId === '' || !is_numeric($itemId)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['error' => 'Missing or invalid item ID parameter']);
        }

        $itemId = (int) $itemId;

        // Load the CodeIgniter 4 model natively
        $itemQuantityModel = model(Item_quantity::class);

        // Look up stock for the Weerawila Showroom (location_id = 1)
        $result = $itemQuantityModel->get_item_quantity($itemId, 1);

        if (
            empty($result)
            || !isset($result->item_id)
            || $result->item_id === ''
            || $result->item_id === null
        ) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['error' => 'Product identifier not found in inventory record']);
        }

        return $this->response->setJSON([
            'item_id'            => (int) $result->item_id,
            'quantity_available' => (float) $result->quantity,
        ]);
    }

    /**
     * GET /api/tilevista/sales
     * Returns paginated sales line-item data with computed revenue, tax, and EAV attributes.
     *
     * Query Parameters:
     *   start_date  (YYYY-MM-DD)  Default: 30 days ago
     *   end_date    (YYYY-MM-DD)  Default: today
     *   page        (int)         Default: 1
     *   limit       (int)         Default: 50, Max: 200
     *   location_id (int)         Default: 1 (Weerawila Showroom)
     *   sale_type   (int|null)    Default: all (0=POS, 1=Invoice, 2=Work Order, 3=Quote, 4=Return)
     */
    public function getSales()
    {
        if ($authError = $this->checkAuth()) {
            return $authError;
        }

        $db = Database::connect();

        // ── Parse and validate query parameters ────────────────────────────
        $startDate   = $this->request->getGet('start_date') ?: date('Y-m-d', strtotime('-30 days'));
        $endDate     = $this->request->getGet('end_date') ?: date('Y-m-d');
        $page        = max(1, (int) ($this->request->getGet('page') ?: 1));
        $limit       = min(200, max(1, (int) ($this->request->getGet('limit') ?: 50)));
        $locationId  = (int) ($this->request->getGet('location_id') ?: 1);
        $saleTypeRaw = $this->request->getGet('sale_type');

        // Validate date format (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
        }

        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime   = $endDate . ' 23:59:59';
        $offset        = ($page - 1) * $limit;

        // ── Build WHERE clause with bound parameters ───────────────────────
        $whereClause = 'WHERE s.sale_time BETWEEN ? AND ? AND si.item_location = ? AND s.sale_status = 0';
        $bindings    = [$startDateTime, $endDateTime, $locationId];

        if ($saleTypeRaw !== null && $saleTypeRaw !== '') {
            $whereClause .= ' AND s.sale_type = ?';
            $bindings[]   = (int) $saleTypeRaw;
        }

        // ── Confirmed OSPOS discount formula (from Sale.php) ───────────────
        $lineRevenueExpr = '
            CASE WHEN si.discount_type = 0
                THEN si.quantity_purchased * si.item_unit_price
                     - ROUND(si.quantity_purchased * si.item_unit_price * si.discount / 100, 2)
                ELSE si.quantity_purchased * (si.item_unit_price - si.discount)
            END';

        // ── 1. Count total matching line items for pagination ───────────────
        $countQuery = $db->query("
            SELECT COUNT(*) AS total
            FROM ospos_sales s
            JOIN ospos_sales_items si ON s.sale_id = si.sale_id
            {$whereClause}
        ", $bindings);

        $total      = (int) $countQuery->getRow()->total;
        $totalPages = max(1, (int) ceil($total / $limit));

        // ── 2. Summary aggregation (revenue, tax, transaction count) ───────
        $summaryQuery = $db->query("
            SELECT
                COUNT(DISTINCT s.sale_id) AS total_transactions,
                COALESCE(SUM({$lineRevenueExpr}), 0) AS total_revenue,
                COALESCE(SUM(tax_agg.total_tax), 0) AS total_tax
            FROM ospos_sales s
            JOIN ospos_sales_items si ON s.sale_id = si.sale_id
            LEFT JOIN (
                SELECT sale_id, item_id, line, SUM(item_tax_amount) AS total_tax
                FROM ospos_sales_items_taxes
                GROUP BY sale_id, item_id, line
            ) tax_agg ON si.sale_id = tax_agg.sale_id
                     AND si.item_id = tax_agg.item_id
                     AND si.line = tax_agg.line
            {$whereClause}
        ", $bindings);

        $summary = $summaryQuery->getRow();

        // ── 3. Main paginated data query ───────────────────────────────────
        //    Joins: item metadata, current stock, tax, and EAV attributes
        //    (Brand, Color, Material) via subquery joins on attribute tables.
        $dataQuery = $db->query("
            SELECT
                s.sale_id,
                s.sale_time,
                s.sale_type,
                s.customer_id,
                s.employee_id,
                s.invoice_number,
                si.item_id,
                si.line,
                si.quantity_purchased,
                si.item_cost_price,
                si.item_unit_price,
                si.discount,
                si.discount_type,
                si.item_location,
                i.name AS item_name,
                i.category,
                i.category_id,
                i.item_number AS sku,
                i.reorder_level,
                COALESCE(iq.quantity, 0) AS current_stock,
                {$lineRevenueExpr} AS line_total,
                COALESCE(tax_agg.total_tax, 0) AS tax_amount,
                brand_attr.attr_value AS brand,
                color_attr.attr_value AS color,
                material_attr.attr_value AS material
            FROM ospos_sales s
            JOIN ospos_sales_items si ON s.sale_id = si.sale_id
            JOIN ospos_items i ON si.item_id = i.item_id
            LEFT JOIN ospos_item_quantities iq
                ON si.item_id = iq.item_id AND iq.location_id = ?
            LEFT JOIN (
                SELECT sale_id, item_id, line, SUM(item_tax_amount) AS total_tax
                FROM ospos_sales_items_taxes
                GROUP BY sale_id, item_id, line
            ) tax_agg ON si.sale_id = tax_agg.sale_id
                     AND si.item_id = tax_agg.item_id
                     AND si.line = tax_agg.line
            LEFT JOIN (
                SELECT al.item_id, av.attribute_value AS attr_value
                FROM ospos_attribute_links al
                JOIN ospos_attribute_definitions ad ON al.definition_id = ad.definition_id
                JOIN ospos_attribute_values av ON al.attribute_id = av.attribute_id
                WHERE ad.definition_name = 'Brand' AND ad.deleted = 0
                  AND al.sale_id IS NULL AND al.receiving_id IS NULL AND al.item_id IS NOT NULL
            ) brand_attr ON brand_attr.item_id = si.item_id
            LEFT JOIN (
                SELECT al.item_id, av.attribute_value AS attr_value
                FROM ospos_attribute_links al
                JOIN ospos_attribute_definitions ad ON al.definition_id = ad.definition_id
                JOIN ospos_attribute_values av ON al.attribute_id = av.attribute_id
                WHERE ad.definition_name = 'Color' AND ad.deleted = 0
                  AND al.sale_id IS NULL AND al.receiving_id IS NULL AND al.item_id IS NOT NULL
            ) color_attr ON color_attr.item_id = si.item_id
            LEFT JOIN (
                SELECT al.item_id, av.attribute_value AS attr_value
                FROM ospos_attribute_links al
                JOIN ospos_attribute_definitions ad ON al.definition_id = ad.definition_id
                JOIN ospos_attribute_values av ON al.attribute_id = av.attribute_id
                WHERE ad.definition_name = 'Material' AND ad.deleted = 0
                  AND al.sale_id IS NULL AND al.receiving_id IS NULL AND al.item_id IS NOT NULL
            ) material_attr ON material_attr.item_id = si.item_id
            {$whereClause}
            ORDER BY s.sale_time DESC, s.sale_id DESC, si.line ASC
            LIMIT ? OFFSET ?
        ", array_merge([$locationId], $bindings, [$limit, $offset]));

        $rows = $dataQuery->getResultArray();

        // ── 4. Format response payload ─────────────────────────────────────
        $data = array_map(static fn ($row) => [
            'sale_id'            => (int) $row['sale_id'],
            'sale_time'          => $row['sale_time'],
            'sale_type'          => (int) $row['sale_type'],
            'customer_id'        => $row['customer_id'] ? (int) $row['customer_id'] : null,
            'employee_id'        => (int) $row['employee_id'],
            'invoice_number'     => $row['invoice_number'],
            'item_id'            => (int) $row['item_id'],
            'line'               => (int) $row['line'],
            'item_name'          => $row['item_name'],
            'sku'                => $row['sku'] ?? '',
            'category'           => $row['category'],
            'category_id'        => $row['category_id'] ? (int) $row['category_id'] : null,
            'quantity_purchased' => (float) $row['quantity_purchased'],
            'item_cost_price'    => (float) $row['item_cost_price'],
            'item_unit_price'    => (float) $row['item_unit_price'],
            'discount'           => (float) $row['discount'],
            'discount_type'      => (int) $row['discount_type'],
            'line_total'         => round((float) $row['line_total'], 2),
            'tax_amount'         => round((float) $row['tax_amount'], 4),
            'current_stock'      => (float) $row['current_stock'],
            'reorder_level'      => (float) $row['reorder_level'],
            'brand'              => $row['brand'] ?: null,
            'color'              => $row['color'] ?: null,
            'material'           => $row['material'] ?: null,
        ], $rows);

        return $this->response->setJSON([
            'data' => $data,
            'pagination' => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'totalPages' => $totalPages,
            ],
            'summary' => [
                'totalRevenue'      => round((float) ($summary->total_revenue ?? 0), 2),
                'totalTax'          => round((float) ($summary->total_tax ?? 0), 4),
                'totalTransactions' => (int) ($summary->total_transactions ?? 0),
                'dateRange' => [
                    'start' => $startDate,
                    'end'   => $endDate,
                ],
            ],
        ]);
    }
}
