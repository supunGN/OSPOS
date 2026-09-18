<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class TilevistaIntegrationTables extends Migration
{
    /**
     * Perform a migration step.
     */
    public function up(): void
    {
        $quotesTable = $this->db->prefixTable('tilevista_quotes');
        $webhooksTable = $this->db->prefixTable('tilevista_webhooks');

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `{$quotesTable}` (
                `order_reference` VARCHAR(100) NOT NULL,
                `ospos_sale_id` INT(10) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (`order_reference`),
                UNIQUE KEY `idx_ospos_sale_id` (`ospos_sale_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `{$webhooksTable}` (
                `webhook_id` INT(11) NOT NULL AUTO_INCREMENT,
                `reference` VARCHAR(100) NOT NULL,
                `ospos_sale_id` INT(10) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` ENUM('pending', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
                `attempts` INT(3) NOT NULL DEFAULT 0,
                `last_attempt_at` DATETIME NULL,
                `next_attempt_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (`webhook_id`),
                KEY `idx_status_next` (`status`, `next_attempt_at`),
                KEY `idx_reference` (`reference`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    /**
     * Revert a migration step.
     */
    public function down(): void
    {
        $quotesTable = $this->db->prefixTable('tilevista_quotes');
        $webhooksTable = $this->db->prefixTable('tilevista_webhooks');

        $this->db->query("DROP TABLE IF EXISTS `{$webhooksTable}`");
        $this->db->query("DROP TABLE IF EXISTS `{$quotesTable}`");
    }
}
