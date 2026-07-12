<?php

declare(strict_types=1);

// Let the PHP built-in dev server serve real static files (HTML/JS/CSS)
// directly; only unresolved paths — i.e. our /api/v1/* routes — fall
// through to this front controller.
if (PHP_SAPI === 'cli-server') {
    $requestedPath = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

    if ($requestedPath === '/') {
        readfile(__DIR__ . '/index.html');

        return;
    }

    $requestedFile = __DIR__ . $requestedPath;
    if (is_file($requestedFile)) {
        return false;
    }
}

spl_autoload_register(function (string $class): void {
    $root = dirname(__DIR__) . '/src/';
    $map = ['App\\Router' => $root . 'Router.php'];

    if (isset($map[$class])) {
        require $map[$class];

        return;
    }
    foreach (['Support\\' => 'Support/', 'Controllers\\' => 'Controllers/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            require $root . $dir . substr($class, strlen($prefix)) . '.php';

            return;
        }
    }
});

use App\Router;
use Controllers\AdminController;
use Controllers\AuthController;
use Controllers\BookingController;
use Controllers\ContactController;
use Controllers\FormController;
use Controllers\TenantController;
use Support\Response;

header('X-Content-Type-Options: nosniff');

$router = new Router();

// --- Auth ---------------------------------------------------------------
$router->post('/api/v1/register', fn () => (new AuthController())->register());
$router->post('/api/v1/login', fn () => (new AuthController())->login());
$router->post('/api/v1/admin/login', fn () => (new AuthController())->adminLogin());
$router->post('/api/v1/logout', fn () => (new AuthController())->logout());
$router->get('/api/v1/me', fn () => (new AuthController())->me());

// --- Tenant workspace (private, subdomain-scoped) ------------------------
$router->get('/api/v1/tenant/overview', fn () => (new TenantController())->overview());

$router->get('/api/v1/tenant/forms', fn () => (new FormController())->index());
$router->post('/api/v1/tenant/forms', fn () => (new FormController())->create());
$router->get('/api/v1/tenant/forms/{id}', fn ($p) => (new FormController())->show($p['id']));
$router->patch('/api/v1/tenant/forms/{id}', fn ($p) => (new FormController())->update($p['id']));
$router->delete('/api/v1/tenant/forms/{id}', fn ($p) => (new FormController())->delete($p['id']));
$router->post('/api/v1/tenant/forms/{id}/fields', fn ($p) => (new FormController())->addField($p['id']));
$router->patch('/api/v1/tenant/forms/{id}/fields/{fieldId}', fn ($p) => (new FormController())->updateField($p['id'], $p['fieldId']));
$router->delete('/api/v1/tenant/forms/{id}/fields/{fieldId}', fn ($p) => (new FormController())->deleteField($p['id'], $p['fieldId']));

$router->get('/api/v1/tenant/bookings', fn () => (new BookingController())->index());
$router->get('/api/v1/tenant/bookings/{id}', fn ($p) => (new BookingController())->show($p['id']));
$router->patch('/api/v1/tenant/bookings/{id}', fn ($p) => (new BookingController())->update($p['id']));

// --- Public booking surface (no auth, lives on the tenant subdomain) -----
$router->get('/api/v1/public/form', fn () => (new FormController())->publicActiveForm());
$router->post('/api/v1/public/bookings', fn () => (new BookingController())->submit());

// --- Marketing site contact form (no auth) --------------------------------
$router->post('/api/v1/contact', fn () => (new ContactController())->submit());

// --- Platform admin (apex host only) -------------------------------------
$router->get('/api/v1/admin/metrics', fn () => (new AdminController())->metrics());
$router->get('/api/v1/admin/tenants', fn () => (new AdminController())->tenants());
$router->patch('/api/v1/admin/tenants/{id}', fn ($p) => (new AdminController())->updateTenant($p['id']));
$router->get('/api/v1/admin/logs', fn () => (new AdminController())->logs());
$router->get('/api/v1/admin/messages', fn () => (new ContactController())->index());

try {
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 500);
}
