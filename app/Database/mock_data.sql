-- =========================================================================
-- TILE VISTA - MOCK DATA POPULATION SCRIPT
-- Project: Tile Vista for Alahapperuma Trade Center
-- Target Tables: ospos_items, ospos_item_quantities, ospos_sales, 
--                ospos_sales_items, ospos_sales_payments, ospos_inventory,
--                ospos_attribute_values, ospos_attribute_links
-- =========================================================================

-- Clear existing data to avoid key conflicts and maintain integrity
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `ospos_sales_items`;
TRUNCATE TABLE `ospos_sales_payments`;
DELETE FROM `ospos_sales`;
ALTER TABLE `ospos_sales` AUTO_INCREMENT = 1;
TRUNCATE TABLE `ospos_item_quantities`;
TRUNCATE TABLE `ospos_inventory`;
TRUNCATE TABLE `ospos_attribute_links`;
TRUNCATE TABLE `ospos_attribute_values`;
DELETE FROM `ospos_items`;
ALTER TABLE `ospos_items` AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. POPULATE ospos_items
-- Denominated in Sri Lankan Rupees (Rs.).
INSERT INTO `ospos_items` (
    `item_id`, `name`, `category`, `supplier_id`, `item_number`, `description`, 
    `cost_price`, `unit_price`, `reorder_level`, `receiving_quantity`, 
    `allow_alt_description`, `is_serialized`, `deleted`, 
    `stock_type`, `item_type`, `qty_per_pack`, `pack_name`, `low_sell_item_id`, `hsn_code`
) VALUES
(1, 'Rocell Royal Onyx Ceramic Floor Tile (60x60)', 'Tiles', NULL, 'T-ONYX-60', 'Glossy finish royal onyx floor tile', 1850.00, 2450.00, 40.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(2, 'Lanka Tiles Matte Grey Bathroom Tile (30x30)', 'Tiles', NULL, 'T-MGREY-30', 'Slip-resistant matte floor tile', 950.00, 1350.00, 50.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(3, 'Rocell Pearl White Glossy Wall Tile (30x60)', 'Tiles', NULL, 'T-PWHITE-30', 'Classic glossy pearl white wall tile', 1250.00, 1750.00, 30.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(4, 'Lanka Tiles Terrazzo Style Ceramic Tile (60x60)', 'Tiles', NULL, 'T-TERRA-60', 'Modern terrazzo style floor tile', 2100.00, 2900.00, 40.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(5, 'Rocell Titanium Black Porcelain Slab (120x240)', 'Tiles', NULL, 'T-TIBLACK-120', 'High-end black large format porcelain slab', 12000.00, 18500.00, 15.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(6, 'Grohe Tempesta Cosmopolitan Shower Kit', 'Bathware', NULL, 'B-TEMP-SHW', 'Luxury multi-function chrome shower head and rail', 38000.00, 55000.00, 10.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(7, 'Grohe BauEdge Single-Lever Basin Mixer Faucet', 'Bathware', NULL, 'B-BAU-MIX', 'Sleek basin water-saving mixer tap', 18000.00, 26500.00, 12.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(8, 'Rocell Water-Saving Wall Hung Bidet', 'Sanitaryware', NULL, 'S-WALL-BID', 'Wall-mounted premium bidet with soft close', 24000.00, 34500.00, 8.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(9, 'Rocell Millennium Smart Luxury Commode', 'Sanitaryware', NULL, 'S-MIL-COM', 'Automatic flush smart commode with seat warmer', 85000.00, 125000.00, 5.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(10, 'Chartered Ceramic Pedestal Wash Basin', 'Sanitaryware', NULL, 'S-PED-BAS', 'Standard standalone pedestal wash basin', 11000.00, 16500.00, 15.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(11, 'Grohe Bau Ceramic Wall-Mounted WC', 'Sanitaryware', NULL, 'S-GRO-WC', 'Modern rimless wall-mounted commode', 42000.00, 62000.00, 6.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(12, 'Rocell Acrylic Corner Freestanding Bathtub', 'Bathware', NULL, 'B-COR-BAT', 'Ergonomic corner freestanding bathtub', 95000.00, 140000.00, 4.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(13, 'Lanka Tiles Mosaic Glass Border Tile', 'Tiles', NULL, 'T-MOS-BOR', 'Decorative border tile for feature walls', 450.00, 750.00, 100.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(14, 'Chartered Chrome Towel Rail & Ring Set', 'Bathware', NULL, 'B-TOW-SET', 'Wall-mounted chrome bathroom accessory set', 4500.00, 7200.00, 20.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(15, 'Rocell Concept-Series Semi-Recessed Basin', 'Sanitaryware', NULL, 'S-CON-BAS', 'Elegant vanity semi-recessed wash basin', 15000.00, 22000.00, 10.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(16, 'Grohe Rainshower Mono Head Shower', 'Bathware', NULL, 'B-RAIN-SHW', 'Top-mount high pressure rainshower', 28000.00, 41000.00, 8.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, ''),
(17, 'Chartered Stainless Steel Kitchen Sink (Double)', 'Bathware', NULL, 'B-SS-SINK', 'Double bowl heavy duty kitchen sink', 16000.00, 23500.00, 10.000, 1.000, 0, 0, 0, 0, 0, 1.000, 'Each', 0, '');


-- 2. POPULATE BRAND ATTRIBUTE VALUES & LINKS
-- definition_id = 1 is our 'Brand' attribute.
-- Insert brand name strings
INSERT INTO `ospos_attribute_values` (`attribute_id`, `attribute_value`) VALUES
(1, 'Rocell'),
(2, 'Lanka Tiles'),
(3, 'Grohe'),
(4, 'Chartered');

-- Associate items to their respective brands in the Galle showroom
INSERT INTO `ospos_attribute_links` (`definition_id`, `item_id`, `attribute_id`) VALUES
(1, 1, 1),   -- Item 1 -> Rocell
(1, 2, 2),   -- Item 2 -> Lanka Tiles
(1, 3, 1),   -- Item 3 -> Rocell
(1, 4, 2),   -- Item 4 -> Lanka Tiles
(1, 5, 1),   -- Item 5 -> Rocell
(1, 6, 3),   -- Item 6 -> Grohe
(1, 7, 3),   -- Item 7 -> Grohe
(1, 8, 1),   -- Item 8 -> Rocell
(1, 9, 1),   -- Item 9 -> Rocell
(1, 10, 4),  -- Item 10 -> Chartered
(1, 11, 3),  -- Item 11 -> Grohe
(1, 12, 1),  -- Item 12 -> Rocell
(1, 13, 2),  -- Item 13 -> Lanka Tiles
(1, 14, 4),  -- Item 14 -> Chartered
(1, 15, 1),  -- Item 15 -> Rocell
(1, 16, 3),  -- Item 16 -> Grohe
(1, 17, 4);  -- Item 17 -> Chartered


-- 3. POPULATE ospos_item_quantities
-- location_id = 1 (Galle Showroom). Vary stock levels to include:
-- * Abundant stock (Item 2, 13, 14)
-- * Normal stock (Item 1, 3, 7, 10)
-- * Dangerously low stock (Item 5, 6, 9, 11, 12) - below reorder_level
INSERT INTO `ospos_item_quantities` (`item_id`, `location_id`, `quantity`) VALUES
(1, 1, 80.000),   -- Onyx Tile (normal: stock 80 > reorder 40)
(2, 1, 250.000),  -- Matte Grey Tile (abundant: stock 250 > reorder 50)
(3, 1, 60.000),   -- Wall Tile (normal: stock 60 > reorder 30)
(4, 1, 50.000),   -- Terrazzo Tile (normal: stock 50 > reorder 40)
(5, 1, 3.000),    -- Porcelain Slab (dangerously low: stock 3 < reorder 15)
(6, 1, 2.000),    -- Shower Kit (dangerously low: stock 2 < reorder 10)
(7, 1, 20.000),   -- Mixer Faucet (normal: stock 20 > reorder 12)
(8, 1, 10.000),   -- Bidet (normal: stock 10 > reorder 8)
(9, 1, 1.000),    -- Smart Commode (dangerously low: stock 1 < reorder 5)
(10, 1, 25.000),  -- Pedestal Basin (normal: stock 25 > reorder 15)
(11, 1, 1.000),   -- Grohe WC (dangerously low: stock 1 < reorder 6)
(12, 1, 0.000),   -- Freestanding Bathtub (dangerously low: stock 0 < reorder 4)
(13, 1, 400.000), -- Border Tile (abundant: stock 400 > reorder 100)
(14, 1, 120.000), -- Towel Rail (abundant: stock 120 > reorder 20)
(15, 1, 15.000),  -- Semi-Recessed Basin (normal: stock 15 > reorder 10)
(16, 1, 12.000),  -- Head Shower (normal: stock 12 > reorder 8)
(17, 1, 18.000);  -- Kitchen Sink (normal: stock 18 > reorder 10)


-- 4. POPULATE HISTORICAL SALES (10 Transactions across last 60 days)
INSERT INTO `ospos_sales` (`sale_id`, `sale_time`, `customer_id`, `employee_id`, `comment`) VALUES
(1, '2026-04-20 10:30:00', NULL, 1, 'Bulk construction checkout - Galle Villa project'),
(2, '2026-04-25 14:15:00', NULL, 1, 'Individual home builder fittings checkout'),
(3, '2026-05-02 11:00:00', NULL, 1, 'Bulk order for luxury bathroom remodeling'),
(4, '2026-05-10 16:45:00', NULL, 1, 'Sanitaryware upgrade checkout'),
(5, '2026-05-18 09:20:00', NULL, 1, 'Luxury master bathroom suite upgrade'),
(6, '2026-05-24 15:30:00', NULL, 1, 'Kitchen renovation accessories checkout'),
(7, '2026-06-01 12:10:00', NULL, 1, 'Small bathroom wall tile renovation order'),
(8, '2026-06-05 10:05:00', NULL, 1, 'Commercial office restroom fittings order'),
(9, '2026-06-12 14:50:00', NULL, 1, 'Premium showroom porcelain slab purchase'),
(10, '2026-06-16 11:30:00', NULL, 1, 'Bathware accessories & wall borders package');


-- 5. POPULATE HISTORICAL SALES ITEMS (Tiles in bulk, bathware in singles)
INSERT INTO `ospos_sales_items` (`sale_id`, `item_id`, `line`, `quantity_purchased`, `item_cost_price`, `item_unit_price`, `discount`, `discount_type`, `item_location`) VALUES
-- Sale 1: Bulk tiles
(1, 1, 1, 60.000, 1850.00, 2450.00, 5.00, 0, 1),
(1, 4, 2, 40.000, 2100.00, 2900.00, 5.00, 0, 1),
-- Sale 2: Shower kit + tap
(2, 6, 1, 1.000, 38000.00, 55000.00, 0.00, 0, 1),
(2, 7, 2, 2.000, 18000.00, 26500.00, 0.00, 0, 1),
-- Sale 3: Bulk matte tiles
(3, 2, 1, 120.000, 950.00, 1350.00, 10.00, 0, 1),
-- Sale 4: Smart toilet + basin
(4, 9, 1, 1.000, 85000.00, 125000.00, 0.00, 0, 1),
(4, 10, 2, 1.000, 11000.00, 16500.00, 0.00, 0, 1),
-- Sale 5: Premium corner bath + shower
(5, 12, 1, 1.000, 95000.00, 140000.00, 10.00, 0, 1),
(5, 6, 2, 1.000, 38000.00, 55000.00, 10.00, 0, 1),
-- Sale 6: Kitchen sinks + taps
(6, 17, 1, 2.000, 16000.00, 23500.00, 5.00, 0, 1),
(6, 7, 2, 2.000, 18000.00, 26500.00, 5.00, 0, 1),
-- Sale 7: Wall tiles + towel rails
(7, 3, 1, 30.000, 1250.00, 1750.00, 0.00, 0, 1),
(7, 14, 2, 3.000, 4500.00, 7200.00, 0.00, 0, 1),
-- Sale 8: Commercial commodes + basins
(8, 11, 1, 4.000, 42000.00, 62000.00, 8.00, 0, 1),
(8, 15, 2, 4.000, 15000.00, 22000.00, 8.00, 0, 1),
-- Sale 9: Premium porcelain slabs
(9, 5, 1, 8.000, 12000.00, 18500.00, 0.00, 0, 1),
-- Sale 10: Accessories and borders
(10, 14, 1, 5.000, 4500.00, 7200.00, 15.00, 0, 1),
(10, 13, 2, 50.000, 450.00, 750.00, 15.00, 0, 1);


-- 6. POPULATE SALES PAYMENTS (Matching the total order value)
INSERT INTO `ospos_sales_payments` (`sale_id`, `payment_type`, `payment_amount`) VALUES
(1, 'Cash', 249850.00),
(2, 'Debit Card', 108000.00),
(3, 'Bank Transfer', 145800.00),
(4, 'Cash', 141500.00),
(5, 'Credit Card', 175500.00),
(6, 'Debit Card', 95000.00),
(7, 'Cash', 74100.00),
(8, 'Bank Transfer', 309120.00),
(9, 'Credit Card', 148000.00),
(10, 'Cash', 62475.00);


-- 7. POPULATE MOCK INVENTORY TRANSACTION TRACKING
-- Records transaction history to back up stock count changes
INSERT INTO `ospos_inventory` (`trans_items`, `trans_user`, `trans_comment`, `trans_location`, `trans_inventory`) VALUES
(1, 1, 'TileVista Initial Stock Import', 1, 140.000),
(1, 1, 'Sale sale_id: 1', 1, -60.000),
(2, 1, 'TileVista Initial Stock Import', 1, 370.000),
(2, 1, 'Sale sale_id: 3', 1, -120.000),
(3, 1, 'TileVista Initial Stock Import', 1, 90.000),
(3, 1, 'Sale sale_id: 7', 1, -30.000),
(4, 1, 'TileVista Initial Stock Import', 1, 90.000),
(4, 1, 'Sale sale_id: 1', 1, -40.000),
(5, 1, 'TileVista Initial Stock Import', 1, 11.000),
(5, 1, 'Sale sale_id: 9', 1, -8.000),
(6, 1, 'TileVista Initial Stock Import', 1, 4.000),
(6, 1, 'Sale sale_id: 2', 1, -1.000),
(6, 1, 'Sale sale_id: 5', 1, -1.000),
(7, 1, 'TileVista Initial Stock Import', 1, 24.000),
(7, 1, 'Sale sale_id: 2', 1, -2.000),
(7, 1, 'Sale sale_id: 6', 1, -2.000),
(8, 1, 'TileVista Initial Stock Import', 1, 10.000),
(9, 1, 'TileVista Initial Stock Import', 1, 2.000),
(9, 1, 'Sale sale_id: 4', 1, -1.000),
(10, 1, 'TileVista Initial Stock Import', 1, 26.000),
(10, 1, 'Sale sale_id: 4', 1, -1.000),
(11, 1, 'TileVista Initial Stock Import', 1, 5.000),
(11, 1, 'Sale sale_id: 8', 1, -4.000),
(12, 1, 'TileVista Initial Stock Import', 1, 1.000),
(12, 1, 'Sale sale_id: 5', 1, -1.000),
(13, 1, 'TileVista Initial Stock Import', 1, 450.000),
(13, 1, 'Sale sale_id: 10', 1, -50.000),
(14, 1, 'TileVista Initial Stock Import', 1, 128.000),
(14, 1, 'Sale sale_id: 7', 1, -3.000),
(14, 1, 'Sale sale_id: 10', 1, -5.000),
(15, 1, 'TileVista Initial Stock Import', 1, 19.000),
(15, 1, 'Sale sale_id: 8', 1, -4.000),
(16, 1, 'TileVista Initial Stock Import', 1, 12.000),
(17, 1, 'TileVista Initial Stock Import', 1, 20.000),
(17, 1, 'Sale sale_id: 6', 1, -2.000);
