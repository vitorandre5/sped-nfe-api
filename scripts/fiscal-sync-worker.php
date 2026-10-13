<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Adapters\SpedNFeAdapter;
use App\Services\Fiscal\CertificateService;
use App\Services\Fiscal\CompanyCertificateVault;
use App\Services\Fiscal\DanfeService;
use App\Services\Fiscal\FiscalDocumentXmlParser;
use App\Services\Fiscal\NFeDistributionSyncService;
use App\Services\Fiscal\NFeService;
use App\Services\Fiscal\SefazService;
use App\Services\Fiscal\CompanyContextResolver;
$adapter = new SpedNFeAdapter();
$certificateService = new CertificateService();
$sefazService = new SefazService(new CompanyContextResolver(), $certificateService, $adapter, new DanfeService());
$syncService = new NFeDistributionSyncService(
    new NFeService($sefazService),
    new FiscalDocumentXmlParser(),
    new CompanyCertificateVault()
);

while (true) {
    try {
        $result = $syncService->runScheduledBatch();
        if ($result['processed'] > 0 || $result['failed'] > 0) {
            fwrite(STDOUT, sprintf(
                "Fiscal sync worker: %d consultas; %d aguardando; %d falhas.\n",
                $result['processed'],
                $result['skipped'],
                $result['failed']
            ));
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, "Fiscal sync worker: ciclo indisponível; nova tentativa em 60 segundos.\n");
    }

    sleep(60);
}
