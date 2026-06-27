<?php

namespace App\Controllers;

use App\Models\Item_quantity;
use Config\Database;

/**
 * Controller for the TileVista ↔ OSPOS integration API.
 * Exposes live inventory metrics for the Weerawila Showroom outlet location (location_id = 1).
 * This controller is read-only. Stock mutations must be performed through the OSPOS Cashier UI.
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
                COALESCE(iq.quantity, 0) AS quantity
            FROM ospos_items i
            LEFT JOIN ospos_item_quantities iq
                ON i.item_id = iq.item_id AND iq.location_id = 1
            WHERE i.deleted = 0
            ORDER BY i.item_id ASC
        ');

        $rows = $query->getResultArray();

        $items = array_map(static fn ($row) => [
            'item_id'     => (int) $row['item_id'],
            'name'        => $row['name'],
            'category'    => $row['category'],
            'category_id' => $row['category_id'] ? (int) $row['category_id'] : null,
            'subcategory_id' => $row['subcategory_id'] ? (int) $row['subcategory_id'] : null,
            'sku'         => $row['item_number'] ?? '',
            'description' => $row['description'],
            'price'       => (float) $row['unit_price'],
            'quantity'    => (float) $row['quantity'],
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
                'id' => (int)$cat['id'],
                'name' => $cat['name'],
                'subcategories' => []
            ];
        }
        
        foreach ($subs as $sub) {
            if (isset($categories[$sub['category_id']])) {
                $categories[$sub['category_id']]['subcategories'][] = [
                    'id' => (int)$sub['id'],
                    'category_id' => (int)$sub['category_id'],
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
}
