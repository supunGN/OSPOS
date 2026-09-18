<?php

namespace App\Controllers;

use App\Models\Customer;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Sale;
use App\Models\Stock_location;
use Config\Database;

/**
 * Controller for the TileVista ↔ OSPOS integration API.
 * Exposes live inventory & sales metrics and handles quote synchronization with TileVista.
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
            'item_id' => (int) $result->item_id,
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

    /**
     * POST /api/tilevista/quote
     * Creates a suspended quote in OSPOS from a TileVista quotation payload.
     * Idempotent based on `order_reference`.
     */
    public function postQuote()
    {
        if ($authError = $this->checkAuth()) {
            return $authError;
        }

        helper('text');

        $json = $this->request->getJSON(true);
        if (empty($json) || !is_array($json)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => 'Invalid or missing JSON payload']);
        }

        // 1. Validate reference
        if (empty($json['reference']) || !is_string($json['reference'])) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => 'Field "reference" is required and must be a string']);
        }
        $reference = trim($json['reference']);

        // 2. Validate location_id
        if (!isset($json['location_id']) || !is_numeric($json['location_id'])) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => 'Field "location_id" is required and must be numeric']);
        }
        $locationId = (int) $json['location_id'];

        $stockLocationModel = model(Stock_location::class);
        if (!$stockLocationModel->exists($locationId)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => "Stock location_id {$locationId} does not exist"]);
        }

        $db = Database::connect();

        // Check if stock location is deleted
        $locRow = $db->table('stock_locations')
            ->where('location_id', $locationId)
            ->where('deleted', 0)
            ->get()
            ->getRow();

        if (!$locRow) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => "Stock location_id {$locationId} is deleted or inactive"]);
        }

        // 3. Validate items
        if (empty($json['items']) || !is_array($json['items']) || count($json['items']) === 0) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => 'Field "items" is required and must contain at least one item']);
        }

        $itemModel = model(Item::class);
        $validatedItems = [];

        foreach ($json['items'] as $index => $reqItem) {
            if (!isset($reqItem['ospos_item_id']) || !is_numeric($reqItem['ospos_item_id'])) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['success' => false, 'error' => "Item at index {$index} must have a numeric 'ospos_item_id'"]);
            }
            $itemId = (int) $reqItem['ospos_item_id'];
            if (!$itemModel->exists((string) $itemId)) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['success' => false, 'error' => "ospos_item_id {$itemId} at index {$index} does not exist or is deleted"]);
            }
            $itemInfo = $itemModel->get_info($itemId);

            if (empty($itemInfo) || empty($itemInfo->item_id) || $itemInfo->item_id <= 0 || !empty($itemInfo->deleted)) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['success' => false, 'error' => "ospos_item_id {$itemId} at index {$index} does not exist or is deleted"]);
            }


            if (!isset($reqItem['quantity']) || !is_numeric($reqItem['quantity']) || (float)$reqItem['quantity'] <= 0) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['success' => false, 'error' => "Item at index {$index} must have a quantity > 0"]);
            }
            $quantity = (float) $reqItem['quantity'];

            if (!isset($reqItem['unit_price']) || !is_numeric($reqItem['unit_price']) || (float)$reqItem['unit_price'] < 0) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['success' => false, 'error' => "Item at index {$index} must have a unit_price >= 0"]);
            }
            $unitPrice = (float) $reqItem['unit_price'];

            $validatedItems[] = [
                'item_info'  => $itemInfo,
                'quantity'   => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        // 4. Validate expires_at
        $expiresAtStr = null;
        if (!empty($json['expires_at'])) {
            $ts = strtotime($json['expires_at']);
            if ($ts === false) {
                return $this->response
                    ->setStatusCode(400)
                    ->setJSON(['success' => false, 'error' => 'Field "expires_at" must be a valid datetime string']);
            }
            $expiresAtStr = date('Y-m-d H:i:s', $ts);
        } else {
            $expiresAtStr = date('Y-m-d H:i:s', strtotime('+5 days'));
        }

        // 5. Idempotency Check in ospos_tilevista_quotes
        $existingQuote = $db->table('tilevista_quotes')
            ->where('order_reference', $reference)
            ->get()
            ->getRow();

        if ($existingQuote) {
            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'success'       => true,
                    'message'       => 'TileVista quote already exists',
                    'reference'     => $reference,
                    'ospos_sale_id' => (int) $existingQuote->ospos_sale_id,
                    'status'        => 'SUSPENDED',
                ]);
        }

        // Also check if reference exists in ospos_sales.quote_number
        $saleModel = model(Sale::class);
        if ($saleModel->check_quote_number_exists($reference)) {
            $existingSaleRow = $db->table('sales')
                ->where('quote_number', $reference)
                ->get()
                ->getRow();

            if ($existingSaleRow) {
                return $this->response
                    ->setStatusCode(200)
                    ->setJSON([
                        'success'       => true,
                        'message'       => 'TileVista quote already exists in sales',
                        'reference'     => $reference,
                        'ospos_sale_id' => (int) $existingSaleRow->sale_id,
                        'status'        => ($existingSaleRow->sale_status == SUSPENDED ? 'SUSPENDED' : 'COMPLETED'),
                    ]);
            }
        }

        // 6. Resolve Customer
        $customerData = isset($json['customer']) && is_array($json['customer']) ? $json['customer'] : null;
        $customerId = $this->resolveCustomer($customerData);

        // 7. Prepare comment
        $commentStr = !empty($json['comment']) && is_string($json['comment']) ? trim($json['comment']) : "TileVista Online Showroom Order {$reference}";
        if ($expiresAtStr) {
            $commentStr .= " [EXPIRES: {$expiresAtStr}]";
        }

        // 8. Prepare items array for save_value()
        $itemsArray = [];
        $line = 1;
        foreach ($validatedItems as $vItem) {
            $itemInfo = $vItem['item_info'];
            $itemsArray[$line] = [
                'item_id'       => (int) $itemInfo->item_id,
                'line'          => $line,
                'description'   => $itemInfo->name ?? '',
                'serialnumber'  => '',
                'quantity'      => $vItem['quantity'],
                'discount'      => 0.00,
                'discount_type' => 0, // PERCENT
                'cost_price'    => (float) ($itemInfo->cost_price ?? 0),
                'price'         => $vItem['unit_price'],
                'item_location' => $locationId,
                'print_option'  => 0,
            ];
            $line++;
        }

        // 9. Execute DB Transaction for quote creation + mapping
        $db->transStart();

        $saleStatus = (string) SUSPENDED; // '1'
        $invoiceNumber = null;
        $workOrderNumber = null;
        $quoteNumber = $reference;
        $saleType = SALE_TYPE_QUOTE; // 3
        $payments = [];
        $dinnerTableId = null;
        $salesTaxes = [[], []];

        $saleId = $saleModel->save_value(

            NEW_ENTRY,
            $saleStatus,
            $itemsArray,
            $customerId,
            1, // Employee ID = 1 (Admin/API System user)
            $commentStr,
            $invoiceNumber,
            $workOrderNumber,
            $quoteNumber,
            $saleType,
            $payments,
            $dinnerTableId,
            $salesTaxes
        );

        if ($saleId <= 0 || $saleId === NEW_ENTRY) {
            $db->transRollback();
            return $this->response
                ->setStatusCode(500)
                ->setJSON(['success' => false, 'error' => 'Failed to save quote in OSPOS database']);
        }

        // Insert into ospos_tilevista_quotes mapping table
        $db->table('tilevista_quotes')->insert([
            'order_reference' => $reference,
            'ospos_sale_id'   => $saleId,
            'expires_at'      => $expiresAtStr,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON(['success' => false, 'error' => 'Database transaction failed during quote creation']);
        }

        return $this->response
            ->setStatusCode(201)
            ->setJSON([
                'success'       => true,
                'message'       => 'TileVista quote created successfully',
                'reference'     => $reference,
                'ospos_sale_id' => (int) $saleId,
                'status'        => 'SUSPENDED',
            ]);
    }

    /**
     * Resolves or creates an OSPOS customer ID from incoming customer payload.
     */
    private function resolveCustomer(?array $customerData): int
    {
        if (empty($customerData) || !is_array($customerData)) {
            return NEW_ENTRY; // -1
        }

        $db = Database::connect();

        // 1. Try finding customer by email
        if (!empty($customerData['email']) && is_string($customerData['email'])) {
            $email = trim($customerData['email']);
            $builder = $db->table('customers');
            $builder->join('people', 'people.person_id = customers.person_id');
            $builder->where('people.email', $email);
            $builder->where('customers.deleted', 0);
            $row = $builder->get()->getRow();
            if ($row) {
                return (int) $row->person_id;
            }
        }

        // 2. Try finding customer by phone
        if (!empty($customerData['phone']) && is_string($customerData['phone'])) {
            $phone = trim($customerData['phone']);
            $builder = $db->table('customers');
            $builder->join('people', 'people.person_id = customers.person_id');
            $builder->where('people.phone_number', $phone);
            $builder->where('customers.deleted', 0);
            $row = $builder->get()->getRow();
            if ($row) {
                return (int) $row->person_id;
            }
        }

        // 3. If customer first_name or last_name is provided, create customer
        $firstName = !empty($customerData['first_name']) ? trim($customerData['first_name']) : '';
        $lastName = !empty($customerData['last_name']) ? trim($customerData['last_name']) : '';
        $email = !empty($customerData['email']) ? trim($customerData['email']) : '';
        $phone = !empty($customerData['phone']) ? trim($customerData['phone']) : '';

        if (!empty($firstName) || !empty($lastName) || !empty($email) || !empty($phone)) {
            $personData = [
                'first_name'   => !empty($firstName) ? $firstName : 'TileVista',
                'last_name'    => !empty($lastName) ? $lastName : 'Customer',
                'email'        => $email,
                'phone_number' => $phone,
                'address_1'    => '',
                'address_2'    => '',
                'city'         => '',
                'state'        => '',
                'zip'          => '',
                'country'      => '',
                'comments'     => 'Created automatically via TileVista Integration API'
            ];
            $custData = [
                'account_number' => null,
                'taxable'        => 1,
                'deleted'        => 0
            ];

            $customerModel = model(Customer::class);
            if ($customerModel->save_customer($personData, $custData, NEW_ENTRY)) {
                return (int) $personData['person_id'];
            }
        }

        return NEW_ENTRY; // -1
    }

    /**
     * POST /api/tilevista/quote/cancel
     * Cancels a suspended TileVista quote in OSPOS safely.
     * Prevents cancellation of already-completed sales (HTTP 409) and is idempotent (HTTP 200).
     */
    public function cancelQuote()
    {
        if ($authError = $this->checkAuth()) {
            return $authError;
        }

        $json = $this->request->getJSON(true);
        if (empty($json) || !is_array($json)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => 'Invalid or missing JSON payload']);
        }

        if (empty($json['reference']) || !is_string($json['reference'])) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON(['success' => false, 'error' => 'Field "reference" is required and must be a string']);
        }
        $reference = trim($json['reference']);

        $db = Database::connect();

        // 1. Find mapping record
        $tvQuote = $db->table('tilevista_quotes')
            ->where('order_reference', $reference)
            ->get()
            ->getRow();

        if (!$tvQuote) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['success' => false, 'error' => "TileVista quote with reference '{$reference}' not found"]);
        }

        $saleId = (int) $tvQuote->ospos_sale_id;

        // 2. Fetch current sale row
        $saleRow = $db->table('sales')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow();

        if (!$saleRow) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['success' => false, 'error' => "OSPOS sale ID {$saleId} not found in sales table"]);
        }

        $currentStatus = (int) $saleRow->sale_status;

        // 3. Handle COMPLETED sales (HTTP 409 Conflict)
        if ($currentStatus === COMPLETED) {
            return $this->response
                ->setStatusCode(409)
                ->setJSON([
                    'success'       => false,
                    'error'         => 'Sale already completed',
                    'reference'     => $reference,
                    'ospos_sale_id' => $saleId,
                    'status'        => 'COMPLETED',
                ]);
        }

        // 4. Handle CANCELED sales (Idempotent HTTP 200 OK)
        if ($currentStatus === CANCELED) {
            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'success'       => true,
                    'message'       => 'TileVista quote is already cancelled',
                    'reference'     => $reference,
                    'ospos_sale_id' => $saleId,
                    'status'        => 'CANCELED',
                ]);
        }

        // 5. Perform atomic update from SUSPENDED (1) -> CANCELED (2)
        $db->transStart();

        $builder = $db->table('sales');
        $builder->where('sale_id', $saleId);
        $builder->where('sale_status', SUSPENDED); // 1
        $builder->update(['sale_status' => CANCELED]); // 2

        if ($db->affectedRows() === 0) {
            $db->transRollback();
            return $this->response
                ->setStatusCode(409)
                ->setJSON([
                    'success'       => false,
                    'error'         => 'Sale already completed',
                    'reference'     => $reference,
                    'ospos_sale_id' => $saleId,
                    'status'        => 'COMPLETED',
                ]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON(['success' => false, 'error' => 'Database transaction failed during cancellation']);
        }

        return $this->response
            ->setStatusCode(200)
            ->setJSON([
                'success'       => true,
                'message'       => 'TileVista quote cancelled successfully',
                'reference'     => $reference,
                'ospos_sale_id' => $saleId,
                'status'        => 'CANCELED',
            ]);
    }
}


