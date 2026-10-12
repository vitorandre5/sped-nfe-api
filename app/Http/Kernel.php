<?php

declare(strict_types=1);

namespace App\Http;

use App\Adapters\SpedNFeAdapter;
use App\Controllers\AccountController;
use App\Controllers\CnpjRegistryController;
use App\Controllers\NFCeController;
use App\Controllers\NFeController;
use App\Exceptions\FiscalException;
use App\Services\Fiscal\CertificateService;
use App\Services\Fiscal\AccountPasswordService;
use App\Services\Fiscal\CnpjRegistryService;
use App\Services\Fiscal\CompanyContextResolver;
use App\Services\Fiscal\DanfeService;
use App\Services\Fiscal\NFCeService;
use App\Services\Fiscal\NFeService;
use App\Services\Fiscal\SefazService;
use Throwable;

final class Kernel
{
    public function handle(Request $request): JsonResponse
    {
        $headers = $this->corsHeaders($request);

        try {
            if ($request->method() === 'OPTIONS') {
                return new JsonResponse(204, ['success' => true], $headers);
            }

            $this->assertOriginAllowed($request);
            $this->assertAuthorized($request);

            $response = $this->router()->dispatch($request);

            return new JsonResponse(
                $response->status(),
                $response->payload(),
                array_merge($headers, $response->headers())
            );
        } catch (FiscalException $exception) {
            return new JsonResponse($exception->httpStatus(), [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
                'details' => $exception->details(),
            ], $headers);
        } catch (Throwable $exception) {
            return new JsonResponse(500, [
                'success' => false,
                'message' => 'Erro interno ao processar a operação fiscal.',
                'code' => 'INTERNAL_SERVER_ERROR',
                'details' => [
                    'exception' => $exception->getMessage(),
                ],
            ], $headers);
        }
    }

    private function assertAuthorized(Request $request): void
    {
        if ($request->path() === '/health') {
            return;
        }

        $origin = trim((string) ($request->header('origin') ?? ''));
        $expectedKey = $this->expectedApiKeyForOrigin($origin);
        if ($expectedKey === null) {
            return;
        }

        $providedKey = trim((string) ($request->header('x-api-key') ?? ''));
        if ($providedKey === '') {
            $authorization = trim((string) ($request->header('authorization') ?? ''));
            if (stripos($authorization, 'Bearer ') === 0) {
                $providedKey = trim(substr($authorization, 7));
            }
        }

        if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            throw new FiscalException(
                'Requisição não autorizada.',
                'UNAUTHORIZED',
                [
                    'origin' => $origin !== '' ? $origin : null,
                    'required_headers' => ['X-API-Key', 'Authorization: Bearer <token>'],
                ],
                401
            );
        }
    }

    private function assertOriginAllowed(Request $request): void
    {
        $origin = trim((string) ($request->header('origin') ?? ''));
        if ($origin === '') {
            return;
        }

        $allowedOrigins = array_keys($this->originKeys());
        if ($allowedOrigins === [] || in_array('*', $allowedOrigins, true)) {
            return;
        }

        if (!in_array($origin, $allowedOrigins, true)) {
            throw new FiscalException(
                'Origin não autorizada.',
                'ORIGIN_NOT_ALLOWED',
                ['origin' => $origin],
                403
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(Request $request): array
    {
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-API-Key',
            'Vary' => 'Origin',
        ];

        $origin = trim((string) ($request->header('origin') ?? ''));
        $allowedOrigins = array_keys($this->originKeys());
        if ($origin !== '' && (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true))) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function originKeys(): array
    {
        $raw = trim((string) (getenv('API_ORIGIN_KEYS') ?: ''));
        if ($raw === '') {
            return $this->fallbackOriginKeys();
        }

        $pairs = preg_split('/[\r\n,]+/', $raw) ?: [];
        $map = [];

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            $separator = strpos($pair, '=');
            if ($separator === false) {
                continue;
            }

            $origin = trim(substr($pair, 0, $separator));
            $key = trim(substr($pair, $separator + 1));
            if ($origin === '' || $key === '') {
                continue;
            }

            $map[$origin] = $key;
        }

        if ($map !== []) {
            return $map;
        }

        return $this->fallbackOriginKeys();
    }

    private function expectedApiKeyForOrigin(string $origin): ?string
    {
        $originKeys = $this->originKeys();

        if ($origin !== '' && isset($originKeys[$origin])) {
            return $originKeys[$origin];
        }

        if (isset($originKeys['*'])) {
            return $originKeys['*'];
        }

        return null;
    }

    /**
     * Compatibilidade com a configuração antiga.
     *
     * @return array<string, string>
     */
    private function fallbackOriginKeys(): array
    {
        $allowedOriginsRaw = trim((string) (getenv('API_ALLOWED_ORIGINS') ?: ''));
        $apiKey = trim((string) (getenv('API_KEY') ?: ''));

        if ($allowedOriginsRaw === '' || $apiKey === '') {
            return [];
        }

        $origins = array_map('trim', explode(',', $allowedOriginsRaw));
        $origins = array_filter($origins, static fn (string $candidate): bool => $candidate !== '');
        $map = [];

        foreach ($origins as $candidate) {
            $map[$candidate] = $apiKey;
        }

        return $map;
    }

    private function router(): Router
    {
        $router = new Router();
        $certificateService = new CertificateService();
        $contextResolver = new CompanyContextResolver();
        $adapter = new SpedNFeAdapter();
        $danfeService = new DanfeService();
        $sefazService = new SefazService($contextResolver, $certificateService, $adapter, $danfeService);
        $nfeService = new NFeService($sefazService);
        $syncService = new \App\Services\Fiscal\NFeDistributionSyncService(
            $nfeService,
            new \App\Services\Fiscal\FiscalDocumentXmlParser()
        );
        $nfeController = new NFeController($nfeService, $syncService);
        $nfceController = new NFCeController(new NFCeService($sefazService));

        $health = static function (Request $request): JsonResponse {
            return new JsonResponse(200, [
                'success' => true,
                'message' => 'Serviço fiscal disponível.',
                'data' => [
                    'service' => 'sped-nfe-http-bridge',
                    'timestamp' => date(DATE_ATOM),
                ],
            ]);
        };

        $router->get('/health', $health);

        $accountController = new AccountController(new AccountPasswordService(
            trim((string) (getenv('SUPABASE_API_URL') ?: '')),
            trim((string) (getenv('SUPABASE_ANON_KEY') ?: '')),
            trim((string) (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: ''))
        ));
        /** @var callable $accountRoutes */
        $accountRoutes = require dirname(__DIR__) . '/Routes/account.php';
        $accountRoutes($router, $accountController);

        $cnpjRegistryController = new CnpjRegistryController(new CnpjRegistryService(
            trim((string) (getenv('SUPABASE_API_URL') ?: '')),
            trim((string) (getenv('SUPABASE_ANON_KEY') ?: ''))
        ));
        /** @var callable $cnpjRoutes */
        $cnpjRoutes = require dirname(__DIR__) . '/Routes/cnpj.php';
        $cnpjRoutes($router, $cnpjRegistryController);

        /** @var callable $nfeRoutes */
        $nfeRoutes = require dirname(__DIR__) . '/Routes/nfe.php';
        $nfeRoutes($router, $nfeController);

        /** @var callable $nfceRoutes */
        $nfceRoutes = require dirname(__DIR__) . '/Routes/nfce.php';
        $nfceRoutes($router, $nfceController);

        return $router;
    }
}
