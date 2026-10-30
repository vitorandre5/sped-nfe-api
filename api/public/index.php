<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Exceptions\FiscalException;
use Throwable;

require dirname(__DIR__, 2) . '/bootstrap.php';

$kernel = new Kernel();
$headers = [
    'Strict-Transport-Security' => 'max-age=31536000',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://supabase-fiscal.vitorandre.com.br wss://supabase-fiscal.vitorandre.com.br; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    'X-Frame-Options' => 'DENY',
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
];
try {
    $response = $kernel->handle(Request::capture());
} catch (FiscalException $exception) {
    $response = new JsonResponse($exception->httpStatus(), [
        'success' => false,
        'message' => $exception->getMessage(),
        'code' => $exception->errorCode(),
        'details' => $exception->details(),
    ], $headers);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Fiscal API request capture error: %s at %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    $response = new JsonResponse(500, [
        'success' => false,
        'message' => 'Erro interno ao processar a operação fiscal.',
        'code' => 'INTERNAL_SERVER_ERROR',
        'details' => [],
    ], $headers);
}
$response->send();
