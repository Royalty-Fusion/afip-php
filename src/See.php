<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip;

use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Auth\FileTicketStorage;
use RoyaltyFusion\Afip\Auth\MemoryTicketStorage;
use RoyaltyFusion\Afip\Auth\TicketStorageInterface;
use RoyaltyFusion\Afip\Auth\Wsaa;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\ExportAuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\VoucherInfo;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Qr\QrGenerator;
use RoyaltyFusion\Afip\Service\AbstractService;
use RoyaltyFusion\Afip\Service\PadronA13Service;
use RoyaltyFusion\Afip\Service\PadronA5Service;
use RoyaltyFusion\Afip\Service\WsfeService;
use RoyaltyFusion\Afip\Service\WsfexService;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\FileLock;
use RoyaltyFusion\Afip\Support\Num;
use RoyaltyFusion\Afip\Validator\ExportInvoiceValidator;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;
use RoyaltyFusion\Afip\Validator\ValidationResult;
use RoyaltyFusion\Afip\Validator\ValidatorOptions;

/**
 * Sistema de Emisión Electrónica (fachada principal del SDK, al estilo de Greenter).
 *
 * <code>
 * $see = new See(Environment::HOMOLOGACION);
 * $see->setCertificate(Certificate::fromFiles('cert.crt', 'private.key'));
 * $see->setCuit('20123456789');
 * $see->setCacheDirectory(__DIR__ . '/var/afip');
 *
 * $result = $see->send($invoice);   // valida, numera, autoriza
 * if ($result->isSuccess()) {
 *     echo $result->getCae();
 * }
 * </code>
 */
final class See
{
    private ?Certificate $certificate = null;
    private ?string $cuit = null;
    private TicketStorageInterface $ticketStorage;
    private ?SoapTransportInterface $transport = null;
    private ValidatorOptions $validatorOptions;
    private ?FileLock $lock = null;
    /** @var array<string, string> */
    private array $endpoints = [];
    /** @var callable|null */
    private $logger = null;

    /** Recuperar el resultado con FECompConsultar ante errores de comunicación. */
    private bool $autoRecover = true;
    /** Verificar que la fecha no sea anterior a la del último comprobante autorizado. */
    private bool $checkLastVoucherDate = true;
    /** Reintentos de numeración automática ante el error 10016 (número ya utilizado). */
    private int $numberingRetries = 2;
    /** Verificar que el punto de venta esté habilitado (FEParamGetPtosVenta) antes de enviar. */
    private bool $verifySalesPoint = true;
    /** @var array<int, array<string, mixed>>|null */
    private ?array $salesPoints = null;

    private ?AuthManager $authManager = null;
    private ?WsfeService $wsfe = null;
    private ?WsfexService $wsfex = null;
    private ?PadronA5Service $padronA5 = null;
    private ?PadronA13Service $padronA13 = null;
    private ?AbstractService $lastService = null;

    public function __construct(private Environment $environment = Environment::HOMOLOGACION)
    {
        $this->ticketStorage = new MemoryTicketStorage();
        $this->validatorOptions = new ValidatorOptions();
    }

    // ------------------------------------------------------- Configuración

    public function setEnvironment(Environment $environment): self
    {
        $this->environment = $environment;

        return $this->reset();
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }

    public function setCertificate(Certificate $certificate): self
    {
        $this->certificate = $certificate;

        return $this->reset();
    }

    public function setCertificateFiles(string $certificatePath, string $privateKeyPath, ?string $passphrase = null): self
    {
        return $this->setCertificate(Certificate::fromFiles($certificatePath, $privateKeyPath, $passphrase));
    }

    /**
     * CUIT del emisor (contribuyente representado). Si no se indica, se toma del certificado.
     */
    public function setCuit(string $cuit): self
    {
        $this->cuit = Cuit::normalize($cuit);

        return $this->reset();
    }

    public function getCuit(): string
    {
        $cuit = $this->cuit ?? $this->certificate?->getCuit();
        if ($cuit === null || $cuit === '') {
            throw new AfipException('Debe configurar el CUIT del emisor con setCuit().');
        }

        return $cuit;
    }

    public function setTicketStorage(TicketStorageInterface $storage): self
    {
        $this->ticketStorage = $storage;

        return $this->reset();
    }

    /**
     * Directorio para persistir los tickets de acceso y los bloqueos de numeración.
     * Recomendado en producción (fuera del document root).
     */
    public function setCacheDirectory(string $directory): self
    {
        $this->ticketStorage = new FileTicketStorage($directory . DIRECTORY_SEPARATOR . 'tickets');
        $this->lock = new FileLock($directory . DIRECTORY_SEPARATOR . 'locks');

        return $this->reset();
    }

    /**
     * Habilita el bloqueo de numeración entre procesos (si no se usó setCacheDirectory()).
     */
    public function setLockDirectory(?string $directory): self
    {
        $this->lock = $directory === null ? null : new FileLock($directory);

        return $this;
    }

    public function setTransport(SoapTransportInterface $transport): self
    {
        $this->transport = $transport;

        return $this->reset();
    }

    public function setValidatorOptions(ValidatorOptions $options): self
    {
        $this->validatorOptions = $options;

        return $this;
    }

    public function getValidatorOptions(): ValidatorOptions
    {
        return $this->validatorOptions;
    }

    /**
     * Reemplaza la URL de un servicio (Endpoints::WSAA, WSFE, WSFEX, PADRON_A5, PADRON_A13).
     */
    public function setEndpoint(string $service, string $url): self
    {
        $this->endpoints[$service] = $url;

        return $this->reset();
    }

    /**
     * Callback fn(string $operation, string $request, ?string $response, ?\Throwable $error)
     * invocado en cada llamada SOAP (para auditar/guardar los XML).
     */
    public function setLogger(?callable $logger): self
    {
        $this->logger = $logger;

        return $this->reset();
    }

    public function setAutoRecover(bool $autoRecover): self
    {
        $this->autoRecover = $autoRecover;

        return $this;
    }

    public function setCheckLastVoucherDate(bool $check): self
    {
        $this->checkLastVoucherDate = $check;

        return $this;
    }

    public function setVerifySalesPoint(bool $verify): self
    {
        $this->verifySalesPoint = $verify;

        return $this;
    }

    public function setNumberingRetries(int $retries): self
    {
        $this->numberingRetries = max(0, $retries);

        return $this;
    }

    // ------------------------------------------------------------ Servicios

    public function getAuthManager(): AuthManager
    {
        if ($this->authManager === null) {
            if ($this->certificate === null) {
                throw new AfipException('Debe configurar el certificado con setCertificate().');
            }
            $wsaa = new Wsaa($this->certificate, $this->environment, $this->transport(), $this->endpoints[Endpoints::WSAA] ?? null);
            if ($this->logger !== null) {
                $wsaa->getClient()->setLogger($this->logger);
            }
            $this->authManager = new AuthManager($wsaa, $this->ticketStorage, $this->environment);
        }

        return $this->authManager;
    }

    public function getWsfe(): WsfeService
    {
        return $this->wsfe ??= $this->configure(new WsfeService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSFE] ?? null
        ));
    }

    public function getWsfex(): WsfexService
    {
        return $this->wsfex ??= $this->configure(new WsfexService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSFEX] ?? null
        ));
    }

    public function getPadronA5(): PadronA5Service
    {
        return $this->padronA5 ??= $this->configure(new PadronA5Service(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::PADRON_A5] ?? null
        ));
    }

    public function getPadronA13(): PadronA13Service
    {
        return $this->padronA13 ??= $this->configure(new PadronA13Service(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::PADRON_A13] ?? null
        ));
    }

    // ----------------------------------------------------------- Validación

    public function validate(Invoice $invoice): ValidationResult
    {
        return (new InvoiceValidator($this->validatorOptions))->validate($invoice);
    }

    public function validateExport(ExportInvoice $invoice): ValidationResult
    {
        return (new ExportInvoiceValidator($this->validatorOptions))->validate($invoice);
    }

    // ------------------------------------------------------------ Emisión

    /**
     * Valida y autoriza un comprobante (CAE). Si no tiene número, se asigna el siguiente.
     *
     * @throws ValidationException si el comprobante no supera las validaciones locales
     * @throws TransportException  si no se pudo comunicar con ARCA ni recuperar el resultado
     */
    public function send(Invoice $invoice): AuthorizationResult
    {
        return $this->sendBatch([$invoice]);
    }

    /**
     * Valida y autoriza varios comprobantes del mismo tipo y punto de venta en una sola solicitud.
     *
     * @param Invoice[] $invoices
     */
    public function sendBatch(array $invoices): AuthorizationResult
    {
        $invoices = array_values($invoices);
        $validator = new InvoiceValidator($this->validatorOptions);
        $validation = $validator->validateBatch($invoices);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }

        $first = $invoices[0];
        $key = sprintf('wsfe_%s_%d_%d', $this->getCuit(), $first->getPuntoVenta(), $first->getTipoComprobante());

        return $this->locked($key, function () use ($invoices, $first): AuthorizationResult {
            $wsfe = $this->getWsfe();
            $this->checkSalesPoint($wsfe, $first->getPuntoVenta());
            $autoNumber = $first->getNumero() === null;
            $attempts = 0;

            while (true) {
                if ($autoNumber) {
                    $last = $wsfe->getLastVoucher($first->getPuntoVenta(), $first->getTipoComprobante());
                    $this->assignNumbers($invoices, $last + 1);
                    $this->checkDateAgainstLast($wsfe, $first, $last);
                }

                $result = $this->authorizeWithRecovery($wsfe, $invoices);

                $numeroUsado = $result->hasCode(10016);
                if ($autoNumber && !$result->isSuccess() && $numeroUsado && $attempts < $this->numberingRetries) {
                    $attempts++;
                    $last = $wsfe->getLastVoucher($first->getPuntoVenta(), $first->getTipoComprobante());
                    if ($last + 1 !== $first->getNumero()) {
                        continue; // otro proceso usó el número: se renumera y reintenta
                    }
                }

                return $result;
            }
        });
    }

    /**
     * Valida e informa comprobantes emitidos con CAEA (FECAEARegInformativo).
     *
     * @param Invoice|Invoice[] $invoices
     */
    public function informCaea(Invoice|array $invoices): AuthorizationResult
    {
        $invoices = $invoices instanceof Invoice ? [$invoices] : array_values($invoices);
        $validation = (new InvoiceValidator($this->validatorOptions))->validateBatch($invoices, InvoiceValidator::MODE_CAEA);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }

        return $this->getWsfe()->informCaeaVouchers($invoices);
    }

    /**
     * Valida y autoriza un comprobante de exportación (WSFEX). Asigna Id y número si faltan.
     */
    public function sendExport(ExportInvoice $invoice): ExportAuthorizationResult
    {
        $validation = $this->validateExport($invoice);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }

        $key = sprintf('wsfex_%s_%d_%d', $this->getCuit(), $invoice->getPuntoVenta(), $invoice->getTipoComprobante());

        return $this->locked($key, function () use ($invoice): ExportAuthorizationResult {
            $wsfex = $this->getWsfex();
            if ($invoice->getNumero() === null) {
                $invoice->setNumero($wsfex->getLastVoucher($invoice->getPuntoVenta(), $invoice->getTipoComprobante()) + 1);
            }
            if ($invoice->getId() === null) {
                $invoice->setId($wsfex->getLastId() + 1);
            }

            try {
                return $wsfex->authorize($invoice);
            } catch (TransportException $e) {
                if (!$this->autoRecover) {
                    throw $e;
                }
                $voucher = $this->tryRecover(fn (): ?array => $wsfex->getVoucher(
                    $invoice->getTipoComprobante(),
                    $invoice->getPuntoVenta(),
                    (int) $invoice->getNumero()
                ));
                if ($voucher === null || !Num::equals((float) ($voucher['Imp_total'] ?? -1), $invoice->getImpTotal(), 0.01)) {
                    throw $e;
                }
                $result = new ExportAuthorizationResult(
                    'A',
                    (string) $voucher['Cae'],
                    Date::fromAfip((string) ($voucher['Fch_venc_Cae'] ?? '')),
                    (int) $invoice->getNumero(),
                    Date::fromAfip((string) ($voucher['Fecha_cbte'] ?? '')),
                    'S',
                    null,
                    [],
                    [],
                    $voucher
                );
                $invoice->setCae($result->getCae());
                $invoice->setCaeVencimiento($result->getCaeVencimiento());

                return $result->markRecovered();
            }
        });
    }

    /**
     * URL del QR (RG 4291) de un comprobante autorizado.
     */
    public function getQrUrl(Invoice|ExportInvoice $invoice): string
    {
        return (new QrGenerator())->buildUrl($invoice, $this->getCuit());
    }

    public function getLastRequestXml(): ?string
    {
        return $this->lastService?->getLastRequest();
    }

    public function getLastResponseXml(): ?string
    {
        return $this->lastService?->getLastResponse();
    }

    // ------------------------------------------------------------ Internos

    /**
     * @param Invoice[] $invoices
     */
    private function authorizeWithRecovery(WsfeService $wsfe, array $invoices): AuthorizationResult
    {
        $this->lastService = $wsfe;
        try {
            return $wsfe->authorize($invoices);
        } catch (TransportException $e) {
            if (!$this->autoRecover || count($invoices) !== 1) {
                throw $e;
            }
            $invoice = $invoices[0];
            $voucher = $this->tryRecover(fn (): ?VoucherInfo => $wsfe->getVoucher(
                $invoice->getTipoComprobante(),
                $invoice->getPuntoVenta(),
                (int) $invoice->getNumero()
            ));
            if ($voucher === null) {
                throw $e; // ARCA no registró el comprobante: puede reenviarse.
            }
            if (!$this->matches($voucher, $invoice)) {
                throw new AfipException(sprintf(
                    'El número %s ya fue autorizado por ARCA para otro comprobante (total %s, documento %s).',
                    $invoice->getNumeroCompleto(),
                    Num::format($voucher->getImpTotal()),
                    $voucher->getDocNro()
                ), 0, $e);
            }
            $result = AuthorizationResult::fromVoucher($voucher)->markRecovered();
            $invoice->setCae($result->getCae());
            $invoice->setCaeVencimiento($result->getCaeVencimiento());

            return $result;
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $query
     *
     * @return T|null
     */
    private function tryRecover(callable $query): mixed
    {
        // Se reintenta la consulta unos segundos por si el servicio sigue inestable.
        for ($i = 0; $i < 3; $i++) {
            try {
                return $query();
            } catch (TransportException) {
                usleep(1_000_000 * ($i + 1));
            }
        }

        return null;
    }

    private function matches(VoucherInfo $voucher, Invoice $invoice): bool
    {
        $client = $invoice->getClient();

        return Num::equals($voucher->getImpTotal(), $invoice->getImpTotal(), 0.01)
            && $voucher->getDocTipo() === $client?->getTipoDoc()
            && ltrim($voucher->getDocNro(), '0') === ltrim((string) $client?->getNumDoc(), '0')
            && $voucher->getFecha()?->format('Ymd') === $invoice->getFechaEmision()->format('Ymd');
    }

    /**
     * @param Invoice[] $invoices
     */
    private function assignNumbers(array $invoices, int $next): void
    {
        foreach ($invoices as $invoice) {
            $range = $invoice->getNumero() !== null ? (int) $invoice->getNumeroHasta() - (int) $invoice->getNumero() : 0;
            $invoice->setNumero($next);
            $invoice->setNumeroHasta($range > 0 ? $next + $range : null);
            $next += $range + 1;
        }
    }

    private function checkDateAgainstLast(WsfeService $wsfe, Invoice $invoice, int $last): void
    {
        if (!$this->checkLastVoucherDate || $last < 1) {
            return;
        }
        $previous = $wsfe->getVoucher($invoice->getTipoComprobante(), $invoice->getPuntoVenta(), $last);
        $fecha = $previous?->getFecha();
        if ($fecha !== null && Date::diffDays($fecha, $invoice->getFechaEmision()) < 0) {
            $validation = new ValidationResult();
            $validation->error('FECHA_ANTERIOR_ULTIMO', 'fechaEmision', sprintf(
                'La fecha del comprobante (%s) no puede ser anterior a la del último comprobante autorizado %05d-%08d (%s).',
                $invoice->getFechaEmision()->format('d/m/Y'),
                $invoice->getPuntoVenta(),
                $last,
                $fecha->format('d/m/Y')
            ), 10016);
            throw new ValidationException($validation);
        }
    }

    /**
     * Verifica que el punto de venta exista, no esté bloqueado ni dado de baja.
     * En homologación ARCA suele devolver la lista vacía: en ese caso no se verifica.
     */
    private function checkSalesPoint(WsfeService $wsfe, int $puntoVenta): void
    {
        if (!$this->verifySalesPoint) {
            return;
        }
        $this->salesPoints ??= $wsfe->getSalesPoints();
        if ($this->salesPoints === []) {
            return;
        }
        $problem = sprintf('El punto de venta %d no está habilitado para web services (FEParamGetPtosVenta).', $puntoVenta);
        foreach ($this->salesPoints as $point) {
            if ((int) ($point['Nro'] ?? 0) !== $puntoVenta) {
                continue;
            }
            $baja = trim((string) ($point['FchBaja'] ?? ''));
            if (strtoupper((string) ($point['Bloqueado'] ?? 'N')) === 'S') {
                $problem = sprintf('El punto de venta %d está bloqueado en ARCA.', $puntoVenta);
            } elseif ($baja !== '' && strtoupper($baja) !== 'NULL') {
                $problem = sprintf('El punto de venta %d fue dado de baja el %s.', $puntoVenta, $baja);
            } else {
                return;
            }
            break;
        }
        $validation = new ValidationResult();
        $validation->error('PTO_VTA_NO_HABILITADO', 'puntoVenta', $problem, 10004);
        throw new ValidationException($validation);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function locked(string $key, callable $callback): mixed
    {
        return $this->lock === null ? $callback() : $this->lock->run($key, $callback);
    }

    /**
     * @template T of AbstractService
     *
     * @param T $service
     *
     * @return T
     */
    private function configure(AbstractService $service): AbstractService
    {
        if ($this->logger !== null) {
            $service->getClient()->setLogger($this->logger);
        }

        return $service;
    }

    private function transport(): SoapTransportInterface
    {
        return $this->transport ??= new CurlTransport();
    }

    private function reset(): self
    {
        $this->authManager = null;
        $this->wsfe = null;
        $this->wsfex = null;
        $this->padronA5 = null;
        $this->padronA13 = null;
        $this->salesPoints = null;

        return $this;
    }
}
