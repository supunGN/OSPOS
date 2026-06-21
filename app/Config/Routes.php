<?php

use CodeIgniter\Router\RouteCollection;
use App\Controllers\Login;
use App\Controllers\No_access;
use App\Controllers\Reports;
use App\Controllers\TilevistaApi;

/**
 * @var RouteCollection $routes
 */
$routes->setDefaultController('Login');

$routes->get('/', '\\' . Login::class . '::index');
$routes->get('login', '\\' . Login::class . '::index');
$routes->post('login', '\\' . Login::class . '::index');

$routes->add('no_access/index/(:segment)', '\\' . No_access::class . '::index/$1');
$routes->add('no_access/index/(:segment)/(:segment)', '\\' . No_access::class . '::index/$1/$2');

$routes->add('reports/summary_(:any)/(:any)/(:any)', '\\' . Reports::class . '::Summary_$1/$2/$3/$4');
$routes->add('reports/summary_expenses_categories', '\\' . Reports::class . '::date_input_only');
$routes->add('reports/summary_payments', '\\' . Reports::class . '::date_input_only');
$routes->add('reports/summary_discounts', '\\' . Reports::class . '::summary_discounts_input');
$routes->add('reports/summary_(:any)', '\\' . Reports::class . '::date_input');

$routes->add('reports/graphical_(:any)/(:any)/(:any)', '\\' . Reports::class . '::Graphical_$1/$2/$3/$4');
$routes->add('reports/graphical_summary_expenses_categories', '\\' . Reports::class . '::date_input_only');
$routes->add('reports/graphical_summary_discounts', '\\' . Reports::class . '::summary_discounts_input');
$routes->add('reports/graphical_(:any)', '\\' . Reports::class . '::date_input');

$routes->add('reports/inventory_(:any)/(:any)', '\\' . Reports::class . '::Inventory_$1/$2');
$routes->add('reports/inventory_low', '\\' . Reports::class . '::inventory_low');
$routes->add('reports/inventory_summary', '\\' . Reports::class . '::inventory_summary_input');
$routes->add('reports/inventory_summary/(:any)/(:any)/(:any)', '\\' . Reports::class . '::inventory_summary/$1/$2/$3');

$routes->add('reports/detailed_(:any)/(:any)/(:any)/(:any)', '\\' . Reports::class . '::Detailed_$1/$2/$3/$4');
$routes->add('reports/detailed_sales', '\\' . Reports::class . '::date_input_sales');
$routes->add('reports/detailed_receivings', '\\' . Reports::class . '::date_input_recv');

$routes->add('reports/specific_(:any)/(:any)/(:any)/(:any)', '\\' . Reports::class . '::Specific_$1/$2/$3/$4');
$routes->add('reports/specific_customers', '\\' . Reports::class . '::specific_customer_input');
$routes->add('reports/specific_employees', '\\' . Reports::class . '::specific_employee_input');
$routes->add('reports/specific_discounts', '\\' . Reports::class . '::specific_discount_input');
$routes->add('reports/specific_suppliers', '\\' . Reports::class . '::specific_supplier_input');

$routes->group('api/tilevista', ['filter' => 'tilevista_auth'], static function ($routes) {
    $routes->get('items',        '\\' . TilevistaApi::class . '::getItems');
    $routes->get('stock/(:num)', '\\' . TilevistaApi::class . '::getStock/$1');
});

