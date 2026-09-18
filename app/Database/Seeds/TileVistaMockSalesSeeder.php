<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * TileVista Mock Sales Data Seeder
 *
 * Generates ~180 realistic sales transactions over 6 months using
 * existing item_ids and employee_ids from the live OSPOS database.
 *
 * Usage:
 *   php spark db:seed TileVistaMockSalesSeeder
 *   php spark db:seed TileVistaMockSalesSeeder --clean   (removes previous mock data first)
 *
 * Design:
 *   - Sale IDs start from 10000 to avoid conflicts with real POS data.
 *   - All items are at location_id = 1 (Weerawila Showroom).
 *   - Discount mix: ~60% none, ~25% percentage (5-20%), ~15% fixed (Rs.50-500/unit).
 *   - ~5% of sales include return lines (negative quantity).
 *   - Sale types: 70% POS (0), 20% Invoice (1), 10% Quote (3).
 */
class TileVistaMockSalesSeeder extends Seeder
{
    private const MOCK_SALE_ID_START = 10000;
    private const LOCATION_ID       = 1;
    private const MONTHS             = 6;
    private const SALES_PER_DAY_MIN = 0;
    private const SALES_PER_DAY_MAX = 3;
    private const ITEMS_PER_SALE_MIN = 1;
    private const ITEMS_PER_SALE_MAX = 5;

    public function run()
    {
        $db = \Config\Database::connect();

        // Check for --clean flag (CI4 seeders don't natively support CLI args,
        // so we check the SERVER superglobal for argv)
        $argv = $_SERVER['argv'] ?? [];
        if (in_array('--clean', $argv, true)) {
            $this->cleanMockData($db);
            echo "✓ Cleaned previous mock sales data (sale_id >= " . self::MOCK_SALE_ID_START . ")\n";
        }

        // ── 1. Fetch existing item_ids with their prices ───────────────────
        $itemRows = $db->query("
            SELECT item_id, unit_price, cost_price, category
            FROM ospos_items
            WHERE deleted = 0
        ")->getResultArray();

        if (empty($itemRows)) {
            echo "✗ No items found in ospos_items. Cannot generate mock sales.\n";
            return;
        }

        $items = array_map(fn ($r) => [
            'item_id'    => (int) $r['item_id'],
            'unit_price' => (float) $r['unit_price'],
            'cost_price' => (float) $r['cost_price'],
            'category'   => $r['category'],
        ], $itemRows);

        echo "  Found " . count($items) . " items to sample from.\n";

        // ── 2. Fetch existing employee_ids ─────────────────────────────────
        $employeeRows = $db->query("
            SELECT person_id FROM ospos_employees WHERE deleted = 0
        ")->getResultArray();

        $employeeIds = array_map(fn ($r) => (int) $r['person_id'], $employeeRows);
        if (empty($employeeIds)) {
            $employeeIds = [1]; // Fallback to default employee
        }

        echo "  Found " . count($employeeIds) . " employees.\n";

        // ── 3. Fetch existing customer_ids (optional) ──────────────────────
        $customerRows = $db->query("
            SELECT person_id FROM ospos_customers WHERE deleted = 0
        ")->getResultArray();

        $customerIds = array_map(fn ($r) => (int) $r['person_id'], $customerRows);
        // null = no customer assigned (walk-in)

        // ── 4. Generate sales across the date range ────────────────────────
        $endDate   = new \DateTime();
        $startDate = (clone $endDate)->modify('-' . self::MONTHS . ' months');

        $saleId       = self::MOCK_SALE_ID_START;
        $totalSales   = 0;
        $totalLines   = 0;
        $salesData    = [];
        $lineData     = [];
        $taxData      = [];

        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            // Random number of sales this day (0-3)
            $salesThisDay = mt_rand(self::SALES_PER_DAY_MIN, self::SALES_PER_DAY_MAX);

            for ($s = 0; $s < $salesThisDay; $s++) {
                // Random time within the business day (8:00 - 18:00)
                $hour   = mt_rand(8, 17);
                $minute = mt_rand(0, 59);
                $second = mt_rand(0, 59);
                $saleTime = $currentDate->format('Y-m-d') . sprintf(' %02d:%02d:%02d', $hour, $minute, $second);

                // Sale type distribution: 70% POS, 20% Invoice, 10% Quote
                $typeRoll = mt_rand(1, 100);
                $saleType = ($typeRoll <= 70) ? 0 : (($typeRoll <= 90) ? 1 : 3);

                // Assign employee and optional customer
                $employeeId = $employeeIds[array_rand($employeeIds)];
                $customerId = (mt_rand(1, 100) <= 40 && !empty($customerIds))
                    ? $customerIds[array_rand($customerIds)]
                    : null;

                // Is this a return transaction? (~5%)
                $isReturn = (mt_rand(1, 100) <= 5);

                $salesData[] = [
                    'sale_id'       => $saleId,
                    'sale_time'     => $saleTime,
                    'customer_id'   => $customerId,
                    'employee_id'   => $employeeId,
                    'comment'       => 'Mock sale generated by TileVista seeder',
                    'invoice_number' => ($saleType === 1) ? 'INV-MOCK-' . $saleId : null,
                    'sale_status'   => 0, // Completed
                    'sale_type'     => $saleType,
                ];

                // Generate line items for this sale
                $numItems = mt_rand(self::ITEMS_PER_SALE_MIN, self::ITEMS_PER_SALE_MAX);
                $selectedItems = array_rand($items, min($numItems, count($items)));
                if (!is_array($selectedItems)) {
                    $selectedItems = [$selectedItems];
                }

                $line = 0;
                foreach ($selectedItems as $idx) {
                    $item = $items[$idx];
                    $quantity = $isReturn ? -mt_rand(1, 3) : mt_rand(1, 10);

                    // Discount distribution: 60% none, 25% percentage, 15% fixed
                    $discountRoll = mt_rand(1, 100);
                    if ($discountRoll <= 60) {
                        $discount     = 0;
                        $discountType = 0;
                    } elseif ($discountRoll <= 85) {
                        $discount     = mt_rand(5, 20); // 5% to 20%
                        $discountType = 0; // PERCENT
                    } else {
                        $maxFixed     = min(500, (int) ($item['unit_price'] * 0.3)); // Cap at 30% of price
                        $discount     = max(50, mt_rand(50, max(50, $maxFixed)));
                        $discountType = 1; // FIXED per unit
                    }

                    $lineData[] = [
                        'sale_id'            => $saleId,
                        'item_id'            => $item['item_id'],
                        'line'               => $line,
                        'description'        => null,
                        'serialnumber'       => null,
                        'quantity_purchased' => $quantity,
                        'item_cost_price'    => $item['cost_price'],
                        'item_unit_price'    => $item['unit_price'],
                        'discount'           => $discount,
                        'discount_type'      => $discountType,
                        'item_location'      => self::LOCATION_ID,
                        'print_option'       => 0,
                    ];

                    // Generate tax entry (simple 0% — adjust if store has tax rules)
                    // Using 0% since the Weerawila showroom's tax configuration may vary.
                    // The seeder creates the tax row structure so the JOIN works correctly.
                    $taxData[] = [
                        'sale_id'          => $saleId,
                        'item_id'          => $item['item_id'],
                        'line'             => $line,
                        'name'             => 'No Tax',
                        'percent'          => 0,
                        'tax_type'         => 0,
                        'rounding_code'    => 0,
                        'cascade_sequence' => 0,
                        'item_tax_amount'  => 0,
                    ];

                    $line++;
                    $totalLines++;
                }

                $saleId++;
                $totalSales++;
            }

            $currentDate->modify('+1 day');
        }

        // ── 5. Batch insert all generated data ─────────────────────────────
        echo "  Inserting {$totalSales} sales with {$totalLines} line items...\n";

        // Insert in batches to avoid memory issues
        $batchSize = 100;

        foreach (array_chunk($salesData, $batchSize) as $batch) {
            $db->table('ospos_sales')->insertBatch($batch);
        }

        foreach (array_chunk($lineData, $batchSize) as $batch) {
            $db->table('ospos_sales_items')->insertBatch($batch);
        }

        foreach (array_chunk($taxData, $batchSize) as $batch) {
            $db->table('ospos_sales_items_taxes')->insertBatch($batch);
        }

        echo "✓ Successfully seeded {$totalSales} mock sales ({$totalLines} line items)\n";
        echo "  Sale ID range: " . self::MOCK_SALE_ID_START . " – " . ($saleId - 1) . "\n";
        echo "  Date range: " . $startDate->format('Y-m-d') . " – " . $endDate->format('Y-m-d') . "\n";
    }

    /**
     * Remove previously seeded mock data (sale_id >= MOCK_SALE_ID_START).
     */
    private function cleanMockData($db): void
    {
        $minId = self::MOCK_SALE_ID_START;

        // Delete in correct FK order: taxes → items → sales
        $db->query("DELETE FROM ospos_sales_items_taxes WHERE sale_id >= ?", [$minId]);
        $db->query("DELETE FROM ospos_sales_items WHERE sale_id >= ?", [$minId]);
        $db->query("DELETE FROM ospos_sales WHERE sale_id >= ?", [$minId]);
    }
}
