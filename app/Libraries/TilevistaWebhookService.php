<?php

namespace App\Libraries;

use Config\Database;

/**
 * Service for delivering TileVista outbox webhooks and managing retry lifecycles.
 */
class TilevistaWebhookService
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 10;

    protected $db;
    protected string $webhookUrl;
    protected string $webhookSecret;

    public function __construct(?string $webhookUrl = null, ?string $webhookSecret = null)
    {
        $this->db = Database::connect();

        $resolvedUrl = $webhookUrl ?? (string) env('TILEVISTA_WEBHOOK_URL');
        if (empty($resolvedUrl)) {
            throw new \RuntimeException('TILEVISTA_WEBHOOK_URL environment variable is missing or invalid.');
        }

        $resolvedSecret = $webhookSecret ?? (string) env('TILEVISTA_WEBHOOK_SECRET');
        if (empty($resolvedSecret)) {
            throw new \RuntimeException('TILEVISTA_WEBHOOK_SECRET environment variable is missing or invalid.');
        }

        $this->webhookUrl = $resolvedUrl;
        $this->webhookSecret = $resolvedSecret;
    }

    /**
     * Sends a direct completion HTTP POST request to TileVista.
     * Does NOT persist or update any outbox database table.
     *
     * @param string $reference
     * @param int $saleId
     * @return array
     */
    public function sendCompletionWebhook(string $reference, int $saleId): array
    {
        $now = date('Y-m-d H:i:s');
        $payloadData = [
            'event'         => 'tilevista.order.completed',
            'reference'     => $reference,
            'ospos_sale_id' => (int) $saleId,
            'completed_at'  => $now
        ];

        $payload = json_encode($payloadData);

        $ch = curl_init($this->webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->webhookSecret,
            'X-OSPOS-Timestamp: ' . $now
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        $isSuccess = ($httpCode >= 200 && $httpCode < 300);

        if ($isSuccess) {
            log_message('info', "TileVista direct completion webhook delivered successfully for reference {$reference} (Sale #{$saleId})");
            return [
                'success'   => true,
                'http_code' => $httpCode,
                'reference' => $reference,
                'sale_id'   => $saleId
            ];
        }

        $errorMessage = $curlErrno ? "cURL Error ({$curlErrno}): {$curlError}" : "HTTP Status {$httpCode}";
        log_message('error', "TileVista direct completion webhook failed for reference {$reference} (Sale #{$saleId}): {$errorMessage}");

        return [
            'success'   => false,
            'http_code' => $httpCode,
            'error'     => $errorMessage,
            'reference' => $reference,
            'sale_id'   => $saleId
        ];
    }

    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }

    public function getWebhookSecret(): string
    {
        return $this->webhookSecret;
    }
}
