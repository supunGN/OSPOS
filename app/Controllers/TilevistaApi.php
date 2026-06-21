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
     * GET /api/tilevista/items
     * Returns all active, non-deleted items joined with their live quantity at location 1 (Weerawila Showroom).
     */
    public function getItems()
    {
        $db = Database::connect();

        $query = $db->query('
            SELECT
                i.item_id,
                i.name,
                i.category,
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
            'sku'         => $row['item_number'] ?? '',
            'description' => $row['description'],
            'price'       => (float) $row['unit_price'],
            'quantity'    => (float) $row['quantity'],
        ], $rows);

        return $this->response->setJSON($items);
    }

    /**
     * GET /api/tilevista/stock/{item_id}
     * Returns the current stock level for a single item at location 1 (Weerawila Showroom).
     */
    public function getStock($itemId = null)
    {
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
