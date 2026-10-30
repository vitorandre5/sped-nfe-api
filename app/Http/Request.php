<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\FiscalException;

final class Request
{
    private string $method;

    private string $path;

    /**
     * @var array<string, mixed>
     */
    private array $query;

    /**
     * @var array<string, mixed>
     */
    private array $body;

    /**
     * @var array<string, string>
     */
    private array $attributes;

    /**
     * @var array<string, string>
     */
    private array $headers;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $attributes
     * @param array<string, string> $headers
     */
    public function __construct(
        string $method,
        string $path,
        array $query,
        array $body,
        array $attributes = [],
        array $headers = []
    )
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->attributes = $attributes;
        $this->headers = $headers;
    }

    public static function capture(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = rtrim((string) $path, '/') ?: '/';
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $headers = self::captureHeaders();
        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        $body = [];
        $maxBodyBytes = self::maxBodyBytes((string) $path);
        $contentLength = (int) ($headers['content-length'] ?? 0);
        if ($contentLength > $maxBodyBytes) {
            throw new FiscalException('O corpo da requisição excede o limite permitido.', 'REQUEST_BODY_TOO_LARGE', [], 413);
        }

        if ($contentType === 'multipart/form-data' || $contentType === 'application/x-www-form-urlencoded') {
            $body = is_array($_POST) ? self::normalizeArray($_POST) : [];
            $body = self::mergeMultipartJsonPayload($body);
            $body = self::attachUploadedCertificate($body, self::normalizeFiles($_FILES ?? []));
        } else {
            $raw = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
            if (is_string($raw) && strlen($raw) > $maxBodyBytes) {
                throw new FiscalException('O corpo da requisição excede o limite permitido.', 'REQUEST_BODY_TOO_LARGE', [], 413);
            }

            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new FiscalException(
                        'JSON inválido no corpo da requisição.',
                        'INVALID_JSON',
                        [],
                        400
                    );
                }
                $body = $decoded;
            }
        }

        return new self($method, $path, $_GET, $body, [], $headers);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace_recursive($this->query, $this->body);
    }

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function attribute(string $key, ?string $default = null): ?string
    {
        return $this->attributes[$key] ?? $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $attributes, $this->headers);
    }

    /**
     * @return array<string, string>
     */
    private static function captureHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }

            return $headers;
        }

        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$headerName] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function mergeMultipartJsonPayload(array $body): array
    {
        $payload = $body['payload'] ?? null;
        if (!is_string($payload) || trim($payload) === '') {
            return $body;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new FiscalException(
                'O campo payload do multipart deve conter um JSON válido.',
                'INVALID_MULTIPART_PAYLOAD',
                ['field' => 'payload'],
                400
            );
        }

        unset($body['payload']);

        return array_replace_recursive($decoded, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function attachUploadedCertificate(array $body, array $files): array
    {
        $certificateFile = null;

        foreach (['certificado', 'certificado_arquivo', 'certFile'] as $candidate) {
            if (isset($files[$candidate])) {
                $certificateFile = $files[$candidate];
                break;
            }
        }

        if (is_array($certificateFile) && isset($certificateFile['tmp_name'])) {
            $body['certificado'] = is_array($body['certificado'] ?? null) ? $body['certificado'] : [];
            $body['certificado']['path'] = $certificateFile['tmp_name'];
            $body['certificado']['filename'] = $certificateFile['name'] ?? null;
            $body['certificado']['mime_type'] = $certificateFile['type'] ?? null;
            $body['certificado']['upload_error'] = (int) ($certificateFile['error'] ?? UPLOAD_ERR_OK);
            $body['certificado']['size'] = (int) ($certificateFile['size'] ?? 0);
            $body['certificado']['uploaded'] = true;
        }

        if (!isset($body['certificado']) || !is_array($body['certificado'])) {
            $body['certificado'] = [];
        }

        if (!empty($body['certPassword']) && empty($body['certificado']['password'])) {
            $body['certificado']['password'] = $body['certPassword'];
        }

        if (!empty($body['certificado_password']) && empty($body['certificado']['password'])) {
            $body['certificado']['password'] = $body['certificado_password'];
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function normalizeArray(array $input): array
    {
        $normalized = [];

        foreach ($input as $key => $value) {
            $normalized[(string) $key] = is_array($value) ? self::normalizeArray($value) : $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $normalized[(string) $field] = self::normalizeFileSpec($spec);
        }

        return $normalized;
    }

    private static function maxBodyBytes(string $path): int
    {
        $default = $path === '/api/documentos/pdf' ? 2 * 1024 * 1024 : 8 * 1024 * 1024;
        $configured = (int) (getenv($path === '/api/documentos/pdf'
            ? 'FISCAL_PDF_MAX_BODY_BYTES'
            : 'FISCAL_API_BODY_MAX_BYTES') ?: 0);

        return $configured > 0 ? $configured : $default;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function normalizeFileSpec(array $spec): array
    {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $spec)) {
                return $spec;
            }
        }

        if (!is_array($spec['name'])) {
            return [
                'name' => (string) $spec['name'],
                'type' => (string) $spec['type'],
                'tmp_name' => (string) $spec['tmp_name'],
                'error' => (int) $spec['error'],
                'size' => (int) $spec['size'],
            ];
        }

        $normalized = [];
        foreach (array_keys($spec['name']) as $key) {
            $normalized[(string) $key] = self::normalizeFileSpec([
                'name' => $spec['name'][$key] ?? '',
                'type' => $spec['type'][$key] ?? '',
                'tmp_name' => $spec['tmp_name'][$key] ?? '',
                'error' => $spec['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size' => $spec['size'][$key] ?? 0,
            ]);
        }

        return $normalized;
    }
}
