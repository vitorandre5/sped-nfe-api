<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Http\Router;

return static function (Router $router, AccountController $controller): void {
    $router->post('/api/account/password', [$controller, 'changePassword']);
};
