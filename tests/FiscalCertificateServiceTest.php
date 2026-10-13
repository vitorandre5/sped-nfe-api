<?php

declare(strict_types=1);

namespace NFePHP\NFe\Tests;

use App\Exceptions\FiscalException;
use App\Services\Fiscal\CertificateService;
use NFePHP\Common\Certificate;
use PHPUnit\Framework\TestCase;

final class FiscalCertificateServiceTest extends TestCase
{
    public function testReadsModernPfxWithoutChangingIdentity(): void
    {
        $content = (string) file_get_contents(__DIR__ . '/fixtures/certs/novo_cert_cnpj_06157250000116_senha_minhasenha.pfx');
        $certificate = (new CertificateService())->readPfx($content, 'minhasenha');
        self::assertSame('06157250000116', $certificate->getCnpj());
        self::assertFalse($certificate->isExpired());
    }

    public function testReadsLegacyPfxAndPreservesSigningKey(): void
    {
        $legacy = $this->legacyPfx();
        $filesBefore = glob(sys_get_temp_dir() . '/fiscal-pfx-*');
        $certificate = (new CertificateService())->readPfx($legacy, 'fixture-password');
        self::assertSame('06157250000116', $certificate->getCnpj());
        $signature = $certificate->privateKey->sign('test-message', OPENSSL_ALGO_SHA256);
        self::assertSame(1, openssl_verify('test-message', $signature, (string) $certificate->publicKey, OPENSSL_ALGO_SHA256));
        self::assertSame($filesBefore, glob(sys_get_temp_dir() . '/fiscal-pfx-*'));
    }

    public function testRejectsWrongPasswordWithoutExposingIt(): void
    {
        $legacy = $this->legacyPfx();
        $filesBefore = glob(sys_get_temp_dir() . '/fiscal-pfx-*');
        try {
            (new CertificateService())->readPfx($legacy, 'wrong-private-password');
            self::fail('A wrong password must be rejected.');
        } catch (FiscalException $exception) {
            self::assertSame('CERTIFICATE_READ_FAILED', $exception->errorCode());
            self::assertSame([], $exception->details());
            self::assertStringNotContainsString('wrong-private-password', $exception->getMessage());
        }
        self::assertSame($filesBefore, glob(sys_get_temp_dir() . '/fiscal-pfx-*'));
    }

    public function testRejectsInvalidPfxWithAnActionableError(): void
    {
        $this->expectException(FiscalException::class);
        (new CertificateService())->readPfx('not-a-pfx', 'fixture-password');
    }

    private function legacyPfx(): string
    {
        $modern = Certificate::readPfx((string) file_get_contents(__DIR__ . '/fixtures/certs/novo_cert_cnpj_06157250000116_senha_minhasenha.pfx'), 'minhasenha');
        $pemPath = tempnam(sys_get_temp_dir(), 'fixture-pem-');
        try {
            file_put_contents($pemPath, (string) $modern->publicKey . "\n" . (string) $modern->privateKey);
            $process = proc_open(['openssl', 'pkcs12', '-export', '-legacy', '-in', $pemPath, '-passout', 'stdin'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], "fixture-password\n");
            fclose($pipes[0]);
            $content = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            return $content;
        } finally {
            unlink($pemPath);
        }
    }
}
