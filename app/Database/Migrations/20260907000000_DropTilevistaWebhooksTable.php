<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class DropTilevistaWebhooksTable extends Migration
{
    /**
     * Perform a migration step to remove the obsolete outbox webhooks queue table.
     */
    public function up(): void
    {
        $webhooksTable = $this->db->prefixTable('tilevista_webhooks');
        $this->db->query("DROP TABLE IF EXISTS `{$webhooksTable}`");
    }

    /**
     * Revert the migration step by restoring the original table structure.
     */
    public function down(): void
    {
        $webhooksTable = $this->db->prefixTable('tilevista_webhooks');

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
}
