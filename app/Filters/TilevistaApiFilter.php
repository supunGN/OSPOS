<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Config\Services;

class TilevistaApiFilter implements FilterInterface
{
    /**
     * Validate the bearer token before allowing the request to proceed to the controller.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Fetch TILEVISTA_API_TOKEN from env/server/global scope
        $configuredToken = env('TILEVISTA_API_TOKEN') ?: ($_ENV['TILEVISTA_API_TOKEN'] ?? '');

        if (empty($configuredToken)) {
            // Fallback for safety/dev
            $configuredToken = 'your_secret_ospos_token_here';
        }

        // Get the Authorization header from the request
        $authHeader = $request->getServer('HTTP_AUTHORIZATION') ?: $request->getHeaderLine('Authorization');

        // Normalize both to compare them cleanly with/without 'Bearer ' prefix and strip any quotes
        $configuredToken = trim($configuredToken, '"\'');
        $normalizedConfigToken = trim(str_replace('Bearer ', '', $configuredToken));
        $normalizedAuthHeader = trim(str_replace('Bearer ', '', $authHeader));

        if (empty($normalizedAuthHeader) || $normalizedAuthHeader !== $normalizedConfigToken) {
            return Services::response()
                ->setStatusCode(401)
                ->setJSON([
                    'error' => 'Unauthorized',
                    'message' => 'Access Denied: Invalid or missing API authorization token.'
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No action required after execution
    }
}
