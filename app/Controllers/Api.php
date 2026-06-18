<?php

namespace App\Controllers;

// For CodeIgniter 4 compatibility with the user's legacy CI_Controller extending requirement:
if (! class_exists('CI_Controller')) {
    class_alias('CodeIgniter\Controller', 'CI_Controller');
}

use App\Models\Inventory;
use App\Models\Item_quantity;
use CI_Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Psr\Log\LoggerInterface;

/**
 * Custom API Controller for Tile Vista integration.
 * Exposes live inventory metrics for the Galle Showroom outlet location (location_id = 1).
 */
class Api extends CI_Controller
{
    /**
     * Secure Bearer Token required for authorization
     */
    private const SECURE_TOKEN = 'Bearer your_secret_ospos_token_here';

    /**
     * @var Item_quantity
     */
    public $Item_quantity;

    /**
     * @var Inventory
     */
    public $Inventory;

    /**
     * Flag to track if the request is successfully authenticated
     */
    private bool $authenticated = false;

    /**
     * Class Constructor
     */
    public function __construct()
    {
        // 1. Force the response content-type header to always be application/json
        header('Content-Type: application/json; charset=utf-8');

        // Invoke parent constructor safely if it exists
        if (method_exists(get_parent_class($this), '__construct')) {
            parent::__construct();
        }

        // CodeIgniter 4 Loader Compatibility Layer
        if (! isset($this->load)) {
            $this->load = new class ($this) {
                private $controller;

                public function __construct($controller)
                {
                    $this->controller = $controller;
                }

                /**
                 * Mimic the CodeIgniter 3 $this->load->model() method with a compatibility proxy wrapper
                 */
                public function model(string $model_name): void
                {
                    $class_name     = '\\App\\Models\\' . $model_name;
                    $model_instance = model($class_name);

                    // Wrap the model instance in a dynamic proxy to handle method signature differences
                    $this->controller->{$model_name} = new class ($model_instance) {
                        private $model;

                        public function __construct($model)
                        {
                            $this->model = $model;
                        }

                        /**
                         * Forward method calls to the actual model, intercepting CI3 signature mismatches
                         */
                        public function __call(string $name, array $arguments)
                        {
                            // Intercept save(data, item_id, location_id) and map to save_value(data, item_id, location_id) in CI4
                            if ($name === 'save' && count($arguments) === 3) {
                                return $this->model->save_value($arguments[0], $arguments[1], $arguments[2]);
                            }

                            return call_user_func_array([$this->model, $name], $arguments);
                        }

                        /**
                         * Forward property getters to the actual model
                         */
                        public function __get(string $name)
                        {
                            return $this->model->{$name};
                        }

                        /**
                         * Forward property setters to the actual model
                         *
                         * @param mixed $value
                         */
                        public function __set(string $name, $value): void
                        {
                            $this->model->{$name} = $value;
                        }
                    };
                }
            };
        }

        // 2. Perform early security check
        $this->validate_auth(false);
    }

    /**
     * CodeIgniter 4 controller initialization hook
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Re-validate authentication using the initialized CI4 Request object
        $this->validate_auth(false);
    }

    /**
     * Validates the Authorization Bearer Token
     *
     * @param bool $force If true, validation failure terminates the request immediately.
     *                    If false, allows deferring if headers are not yet fully processed by the framework.
     */
    private function validate_auth(bool $force = false): void
    {
        if ($this->authenticated) {
            return;
        }

        $auth_header = '';

        // 1. Intercept via native PHP $_SERVER superglobals
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        // 2. Intercept via Apache request headers if available
        elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $auth_header = $headers['Authorization'];
            } elseif (isset($headers['authorization'])) {
                $auth_header = $headers['authorization'];
            }
        }

        // 3. Intercept via CodeIgniter 4 Request object
        if (empty($auth_header) && isset($this->request) && method_exists($this->request, 'getHeaderLine')) {
            $auth_header = $this->request->getHeaderLine('Authorization');
        }

        // 4. Intercept via CodeIgniter 3 Input library
        if (empty($auth_header) && isset($this->input) && method_exists($this->input, 'get_request_header')) {
            $auth_header = $this->input->get_request_header('Authorization');
        }

        // Validate target bearer token matches exactly
        if ($auth_header === self::SECURE_TOKEN) {
            $this->authenticated = true;

            return;
        }

        // Defer validation check if we are in early initialization and token is not yet detected
        if (! $force && empty($auth_header)) {
            return;
        }

        // Terminate request immediately upon authorization failure
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized access to showroom inventory']);

        exit;
    }

    /**
     * Legacy GET /index.php/api/stock endpoint mapper
     */
    public function stock(): void
    {
        $this->getStock();
    }

    /**
     * GET /index.php/api/stock
     * URL query parameter: item_id
     */
    public function getStock(): void
    {
        // Enforce bearer token validation prior to endpoint processing
        $this->validate_auth(true);

        // Retrieve the query parameter 'item_id'
        $item_id = '';
        if (isset($this->request) && method_exists($this->request, 'getGet')) {
            $item_id = $this->request->getGet('item_id');
        } elseif (isset($this->input) && method_exists($this->input, 'get')) {
            $item_id = $this->input->get('item_id');
        } else {
            $item_id = $_GET['item_id'] ?? '';
        }

        // Return HTTP 400 Bad Request if the parameter is empty/missing
        if ($item_id === null || $item_id === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing item_id parameter']);

            exit;
        }

        // Load the native internal OSPOS Item_quantity model
        $this->load->model('Item_quantity');

        // Look up stock for the Galle Showroom (location_id = 1)
        $result = $this->Item_quantity->get_item_quantity($item_id, 1);

        // If the item doesn't exist or is not tracked in the quantity registry, return HTTP 404
        if (
            empty($result)
            || $result instanceof Item_quantity
            || ! isset($result->item_id)
            || $result->item_id === ''
            || $result->item_id === null
        ) {
            http_response_code(404);
            echo json_encode(['error' => 'Product identifier not found in inventory record']);

            exit;
        }

        // Output the JSON response with integer and decimal formats preserved
        $response = [
            'item_id'            => (int) $result->item_id,
            'quantity_available' => (float) $result->quantity,
        ];

        http_response_code(200);
        echo json_encode($response);

        exit;
    }

    /**
     * Legacy POST /index.php/api/deduct_stock endpoint mapper
     */
    public function deduct_stock(): void
    {
        $this->postDeduct_stock();
    }

    /**
     * CodeIgniter 4 camelCase verb-prefixed route method for POST /api/deduct_stock
     */
    public function postDeductStock(): void
    {
        $this->postDeduct_stock();
    }

    /**
     * POST /index.php/api/deduct_stock
     * URL query parameter: none (JSON payload body)
     */
    public function postDeduct_stock(): void
    {
        // Enforce bearer token validation prior to endpoint processing
        $this->validate_auth(true);

        // Fetch query parameters / JSON payload
        $json = [];
        if (isset($this->request) && method_exists($this->request, 'getJSON')) {
            $json = $this->request->getJSON(true) ?? [];
        } else {
            $raw_input = file_get_contents('php://input');
            $json      = json_decode($raw_input, true) ?? [];
        }

        $item_id            = $json['item_id'] ?? null;
        $quantity_to_deduct = $json['quantity_to_deduct'] ?? null;

        // Return HTTP 400 Bad Request if parameters are missing, empty, or non-numeric
        if (
            $item_id === null || $item_id === '' || ! is_numeric($item_id)
                              || $quantity_to_deduct === null || $quantity_to_deduct === '' || ! is_numeric($quantity_to_deduct)
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing parameters']);

            exit;
        }

        $item_id                  = (int) $item_id;
        $quantity_to_deduct_float = (float) $quantity_to_deduct;

        // Load the native internal OSPOS Item_quantity model
        $this->load->model('Item_quantity');

        // Look up stock for the Galle Showroom (location_id = 1)
        $result = $this->Item_quantity->get_item_quantity($item_id, 1);

        // If the item doesn't exist or is not tracked in the quantity registry, return HTTP 404
        if (
            empty($result)
            || $result instanceof Item_quantity
            || ! isset($result->item_id)
            || $result->item_id === ''
            || $result->item_id === null
        ) {
            http_response_code(404);
            echo json_encode(['error' => 'Product identifier not found in inventory record']);

            exit;
        }

        $current_quantity = (float) $result->quantity;

        // Calculate the new lower balance
        $new_quantity = $current_quantity - $quantity_to_deduct_float;

        // Call the native save sequence to update the record in ospos_item_quantities
        $this->Item_quantity->save(['quantity' => $new_quantity], $item_id, 1);

        // Load the inventory tracking model
        $this->load->model('Inventory');

        // Prepare the audit transaction data for ospos_inventory trail
        $inventory_data = [
            'trans_items'     => $item_id,
            'trans_user'      => 1, // Default Admin User ID
            'trans_comment'   => 'Deducted via TileVista Web Portal Order Sync',
            'trans_inventory' => -$quantity_to_deduct_float, // Negative float value showing deduction
            'trans_location'  => 1,
            'trans_date'      => date('Y-m-d H:i:s'),
        ];

        // Insert log into the native inventory trail table
        $this->Inventory->insert($inventory_data);

        // Return HTTP 200 OK with success confirmation
        http_response_code(200);
        echo json_encode([
            'success'   => true,
            'item_id'   => $item_id,
            'new_stock' => $new_quantity,
        ]);

        exit;
    }

    /**
     * GET /index.php/api/items
     * Returns all active, non-deleted items joined with their live quantity at location 1.
     */
    public function getItems(): void
    {
        // Enforce bearer token validation
        $this->validate_auth(true);

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

        http_response_code(200);
        echo json_encode($items);

        exit;
    }
}
