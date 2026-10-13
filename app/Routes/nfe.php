<?php

declare(strict_types=1);

use App\Controllers\NFeController;
use App\Http\Router;

return static function (Router $router, NFeController $controller): void {
    $router->post('/api/nfe/certificado/validar', [$controller, 'validarCertificado']);
    $router->post('/api/nfe/certificado/salvar', [$controller, 'salvarCertificado']);
    $router->post('/api/nfe/distribuir', [$controller, 'distribuir']);
    $router->post('/api/nfe/sincronizar', [$controller, 'sincronizar']);
    $router->post('/api/nfe/criar', [$controller, 'criar']);
    $router->post('/api/nfe/assinar', [$controller, 'assinar']);
    $router->post('/api/nfe/validar-xml', [$controller, 'validarXml']);
    $router->post('/api/nfe/enviar', [$controller, 'enviar']);
    $router->post('/api/nfe/consultar-protocolo', [$controller, 'consultarRecibo']);
    $router->get('/api/nfe/status-servico', [$controller, 'status']);
    $router->post('/api/nfe/status-servico', [$controller, 'status']);
    $router->get('/api/nfe/{chave}/consultar', [$controller, 'consultar']);
    $router->post('/api/nfe/{chave}/consultar', [$controller, 'consultar']);
    $router->post('/api/nfe/{chave}/cancelar', [$controller, 'cancelar']);
    $router->post('/api/nfe/inutilizar', [$controller, 'inutilizar']);
    $router->post('/api/nfe/consultar-cadastro', [$controller, 'cadastro']);
    $router->get('/api/nfe/{chave}/danfe', [$controller, 'danfe']);
    $router->post('/api/nfe/{chave}/danfe', [$controller, 'danfe']);
};
