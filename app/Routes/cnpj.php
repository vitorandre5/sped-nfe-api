<?php

declare(strict_types=1);

use App\Controllers\CnpjRegistryController;
use App\Http\Router;

return static function (Router $router, CnpjRegistryController $controller): void {
    $router->post('/api/cnpj/consultar', [$controller, 'lookup']);
};
