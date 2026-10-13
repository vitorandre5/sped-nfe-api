<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;
use NFePHP\Common\Certificate;
use NFePHP\Common\Certificate\CertificationChain;
use NFePHP\Common\Certificate\PrivateKey;
use NFePHP\Common\Certificate\PublicKey;
use NFePHP\Common\Exception\CertificateException;

final class CertificateService
{
    public function readPfx(string $content, string $password): Certificate
    {
        try {
            return Certificate::readPfx($content, $password);
        } catch (CertificateException $exception) {
            // OpenSSL 3 does not load RC2 used by older, still-valid A1 containers.
            if (stripos($exception->getMessage(), 'unsupported') === false) {
                throw new FiscalException('Não foi possível abrir o certificado A1. Confira o arquivo e a senha.', 'CERTIFICATE_READ_FAILED', [], 422);
            }
        }

        if (!function_exists('proc_open')) {
            throw new FiscalException('O servidor não possui suporte ao formato de criptografia deste A1.', 'CERTIFICATE_LEGACY_UNAVAILABLE', [], 503);
        }
        $path = tempnam(sys_get_temp_dir(), 'fiscal-pfx-');
        if ($path === false) {
            throw new FiscalException('Não foi possível processar o certificado A1.', 'CERTIFICATE_READ_FAILED', [], 503);
        }
        $process = null;
        $pipes = [];
        try {
            chmod($path, 0600);
            if (file_put_contents($path, $content) !== strlen($content)) {
                throw new FiscalException('Não foi possível processar o certificado A1.', 'CERTIFICATE_READ_FAILED', [], 503);
            }
            // Only the encrypted PFX touches disk. Password and decrypted keys use pipes.
            // The legacy provider is restricted to this subprocess, never to HTTPS.
            $process = proc_open(
                ['openssl', 'pkcs12', '-legacy', '-in', $path, '-nodes', '-passin', 'stdin'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new FiscalException('O servidor não possui suporte ao formato de criptografia deste A1.', 'CERTIFICATE_LEGACY_UNAVAILABLE', [], 503);
            }
            fwrite($pipes[0], $password . "\n");
            fclose($pipes[0]);
            $pem = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]); // Never expose OpenSSL output, paths or credentials.
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $process = null;
            if ($exitCode !== 0 || !is_string($pem)
                || !preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----.*?-----END (?:RSA )?PRIVATE KEY-----/s', $pem, $key)
                || !preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $certs)) {
                throw new FiscalException('Não foi possível abrir o certificado A1. Confira o arquivo e a senha.', 'CERTIFICATE_READ_FAILED', [], 422);
            }
            foreach ($certs[0] as $index => $certificate) {
                if (openssl_x509_check_private_key($certificate, $key[0])) {
                    unset($certs[0][$index]);
                    return new Certificate(new PrivateKey($key[0]), new PublicKey($certificate), new CertificationChain(implode("\n", $certs[0])));
                }
            }
            throw new FiscalException('O certificado A1 não contém uma chave privada correspondente.', 'CERTIFICATE_READ_FAILED', [], 422);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            @unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function assertAvailable(array $context): void
    {
        $certificate = $context['certificado'] ?? [];
        $path = $certificate['path'] ?? null;
        $base64 = $certificate['base64'] ?? null;
        $password = $certificate['password'] ?? null;

        if (empty($password)) {
            throw new FiscalException(
                'Senha do certificado ausente.',
                'CERTIFICATE_PASSWORD_REQUIRED',
                ['required_field' => 'certificado.password'],
                422
            );
        }

        if (empty($base64) && empty($path)) {
            throw new FiscalException(
                'Certificado ausente. Informe certificado.path ou certificado.base64.',
                'CERTIFICATE_REQUIRED',
                ['required_fields' => ['certificado.path', 'certificado.base64']],
                422
            );
        }

        if (!empty($path) && !is_file((string) $path)) {
            throw new FiscalException(
                'Arquivo do certificado não encontrado.',
                'CERTIFICATE_FILE_NOT_FOUND',
                ['path' => $path],
                422
            );
        }

        if (!empty($path) && !is_readable((string) $path)) {
            throw new FiscalException(
                'Arquivo do certificado sem permissão de leitura.',
                'CERTIFICATE_FILE_NOT_READABLE',
                ['path' => $path],
                422
            );
        }
    }

    /**
     * Reempacota um certificado PFX usando openssl.
     * Isso nao renova nem altera juridicamente o certificado.
     *
     * @return array<string, string>
     */
    public function repackPfxWithOpenSsl(
        string $inputPath,
        string $inputPassword,
        string $outputPath,
        ?string $outputPassword = null
    ): array {
        if (!is_file($inputPath)) {
            throw new FiscalException(
                'Arquivo do certificado de origem não encontrado.',
                'CERTIFICATE_FILE_NOT_FOUND',
                ['path' => $inputPath],
                422
            );
        }

        if ($inputPassword === '') {
            throw new FiscalException(
                'Senha do certificado de origem ausente.',
                'CERTIFICATE_PASSWORD_REQUIRED',
                ['required_field' => 'inputPassword'],
                422
            );
        }

        $outputPassword = $outputPassword ?? $inputPassword;

        if ($outputPassword === '') {
            throw new FiscalException(
                'Senha do certificado de destino ausente.',
                'CERTIFICATE_OUTPUT_PASSWORD_REQUIRED',
                ['required_field' => 'outputPassword'],
                422
            );
        }

        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory)) {
            throw new FiscalException(
                'Diretório de destino do certificado não encontrado.',
                'CERTIFICATE_OUTPUT_DIRECTORY_NOT_FOUND',
                ['directory' => $outputDirectory],
                422
            );
        }

        if (!is_writable($outputDirectory)) {
            throw new FiscalException(
                'Diretório de destino do certificado não permite escrita.',
                'CERTIFICATE_OUTPUT_DIRECTORY_NOT_WRITABLE',
                ['directory' => $outputDirectory],
                422
            );
        }

        $pemPath = tempnam(sys_get_temp_dir(), 'sped-nfe-cert-');
        if ($pemPath === false) {
            throw new FiscalException(
                'Não foi possível criar arquivo temporário para o certificado.',
                'CERTIFICATE_TEMP_FILE_ERROR',
                [],
                500
            );
        }

        try {
            $infoOutput = $this->runCommand(sprintf(
                'openssl pkcs12 -in %s -legacy -info -noout -passin pass:%s 2>&1',
                escapeshellarg($inputPath),
                escapeshellarg($inputPassword)
            ));

            $this->runCommand(sprintf(
                'openssl pkcs12 -legacy -in %s -nodes -out %s -passin pass:%s 2>&1',
                escapeshellarg($inputPath),
                escapeshellarg($pemPath),
                escapeshellarg($inputPassword)
            ));

            $this->runCommand(sprintf(
                'openssl pkcs12 -export -in %s -out %s -passout pass:%s 2>&1',
                escapeshellarg($pemPath),
                escapeshellarg($outputPath),
                escapeshellarg($outputPassword)
            ));

            return [
                'input_path' => $inputPath,
                'output_path' => $outputPath,
                'info' => trim($infoOutput),
            ];
        } finally {
            if (is_file($pemPath)) {
                @unlink($pemPath);
            }
        }
    }

    private function runCommand(string $command): string
    {
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        $result = trim(implode(PHP_EOL, $output));

        if ($exitCode !== 0) {
            throw new FiscalException(
                'Falha ao executar openssl para reempacotar o certificado.',
                'OPENSSL_COMMAND_FAILED',
                [
                    'command' => $command,
                    'output' => $result,
                    'exit_code' => $exitCode,
                ],
                500
            );
        }

        return $result;
    }
}
