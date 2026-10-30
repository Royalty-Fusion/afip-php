<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip;

use RoyaltyFusion\Afip\Emission\FoundVoucher;
use RoyaltyFusion\Afip\Emission\VoucherChannel;
use RoyaltyFusion\Afip\Emission\WsfeChannel;
use RoyaltyFusion\Afip\Emission\WsctChannel;
use RoyaltyFusion\Afip\Emission\WsmtxcaChannel;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Auth\FileTicketStorage;
use RoyaltyFusion\Afip\Auth\TicketStorageInterface;
use RoyaltyFusion\Afip\Auth\Wsaa;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Exception\UncertainAuthorizationException;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\ExportAuthorizationResult;
use RoyaltyFusion\Afip\Model\Sale\BonoFiscalInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\TurismoInvoice;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Libro\RegistroFactory;
use RoyaltyFusion\Afip\Libro\RegistroIva;
use RoyaltyFusion\Afip\Model\Response\OperationResult;
use RoyaltyFusion\Afip\Qr\QrGenerator;
use RoyaltyFusion\Afip\Service\AbstractService;
use RoyaltyFusion\Afip\Service\PadronA13Service;
use RoyaltyFusion\Afip\Service\PadronA5Service;
use RoyaltyFusion\Afip\Service\WsfeService;
use RoyaltyFusion\Afip\Service\WsfexService;
use RoyaltyFusion\Afip\Service\WsmtxcaService;
use RoyaltyFusion\Afip\Service\WsbfeService;
use RoyaltyFusion\Afip\Service\WsctService;
use RoyaltyFusion\Afip\Service\WscdcService;
use RoyaltyFusion\Afip\Service\WsfecredService;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Clock;
use RoyaltyFusion\Afip\Support\FileLock;
use RoyaltyFusion\Afip\Support\Fingerprint;
use RoyaltyFusion\Afip\Support\Redactor;
use RoyaltyFusion\Afip\Report\RepresentationData;
use RoyaltyFusion\Afip\Exception\VoucherMismatchException;
use RoyaltyFusion\Afip\Lock\NumberingLockInterface;
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
    private ?TicketStorageInterface $ticketStorage = null;
    private ?SoapTransportInterface $transport = null;
    private ValidatorOptions $validatorOptions;
    private ?NumberingLockInterface $lock = null;
    /** @var array<string, string> */
    private array $endpoints = [];
    /** @var callable|object|null callable o logger PSR-3 */
    private $logger = null;
    private bool $redactLogs = true;
    private Clock $clock;

    /** Recuperar el resultado con FECompConsultar ante errores de comunicación. */
    private bool $autoRecover = true;
    /** Verificar que la fecha no sea anterior a la del último comprobante autorizado. */
    private bool $checkLastVoucherDate = true;
    /** Reintentos de numeración automática ante el error 10016 (número ya utilizado). */
    private int $numberingRetries = 2;
    /** Consultas FECompConsultar para recuperar el resultado tras un error de comunicación. */
    private int $recoveryAttempts = 3;
    /** Segundos entre consultas de recuperación (se multiplica por el número de intento). */
    private float $recoveryDelay = 2.0;
    /** Usar el directorio temporal del sistema para tickets y bloqueos si no se configuró otro. */
    private bool $useDefaultLock = true;
    /** Verificar que el punto de venta esté habilitado (FEParamGetPtosVenta) antes de enviar. */
    private bool $verifySalesPoint = true;
    /** Emisor MiPyME: verificar con WSFECRED si corresponde (o no) emitir FCE. */
    private bool $fceCheck = false;
    /** Desvío máximo (%) de la cotización informada respecto de la oficial; null = sin control. */
    private ?float $quotationTolerance = null;
    /** Verificar que los comprobantes asociados propios existan en ARCA (mismo web service). */
    private bool $checkAssociated = false;
    /** Verificar la condición frente al IVA y el estado del receptor en el padrón A5. */
    private bool $padronCheck = false;
    /** @var array<string, mixed> */
    private array $onlineCache = [];
    /** @var array<string, array<int, array{numero: int, bloqueado: bool, baja: ?string}>|null> */
    private array $salesPoints = [];

    private ?AuthManager $authManager = null;
    private ?WsfeService $wsfe = null;
    private ?WsfexService $wsfex = null;
    private ?PadronA5Service $padronA5 = null;
    private ?PadronA13Service $padronA13 = null;
    private ?WsmtxcaService $wsmtxca = null;
    private ?WsbfeService $wsbfe = null;
    private ?WsctService $wsct = null;
    private ?WsfecredService $wsfecred = null;
    private ?WscdcService $wscdc = null;
    private ?AbstractService $lastService = null;

    private Environment $environment;

    /**
     * @param Environment|string $environment también acepta "homologacion" o "produccion"
     *                                        (útil para configurarlo desde variables de entorno)
     */
    public function __construct(Environment|string $environment = Environment::HOMOLOGACION)
    {
        $this->environment = self::environment($environment);
        $this->clock = new Clock();
        $this->validatorOptions = new ValidatorOptions();
    }

    /**
     * Crea una instancia para un emisor (CUIT + certificado + ambiente) sin estado global.
     * Ver {@see SeeConfig::fromArray()} para las claves admitidas.
     *
     * @param array<string, mixed>|SeeConfig $config
     */
    public static function fromConfig(array|SeeConfig $config): self
    {
        $config = $config instanceof SeeConfig ? $config : SeeConfig::fromArray($config);
        $see = (new self($config->environment))->setCertificate($config->certificate);
        if ($config->cuit !== null) {
            $see->setCuit($config->cuit);
        }
        if ($config->cacheDirectory !== null) {
            $see->setCacheDirectory($config->cacheDirectory);
        }
        if ($config->ticketStorage !== null) {
            $see->setTicketStorage($config->ticketStorage);
        }
        if ($config->numberingLock !== null) {
            $see->setNumberingLock($config->numberingLock);
        }
        if ($config->transport !== null) {
            $see->setTransport($config->transport);
        }
        if ($config->validatorOptions !== null) {
            $see->setValidatorOptions($config->validatorOptions);
        }
        foreach ($config->endpoints as $service => $url) {
            $see->setEndpoint($service, $url);
        }
        if ($config->logger !== null) {
            $see->setLogger($config->logger);
        }
        if ($config->clock !== null) {
            $see->setClock($config->clock);
        }

        return $see;
    }

    // ------------------------------------------------------- Configuración

    /**
     * Reloj para la fecha de referencia de las validaciones (fecha civil de la oficina en tests).
     * Acepta un reloj PSR-20 o un callable que devuelva un DateTimeInterface. El login al WSAA
     * siempre usa la hora real del sistema (ARCA la compara con la suya).
     */
    public function setClock(callable|object|null $clock): self
    {
        $this->clock = new Clock($clock);

        return $this;
    }

    public function setEnvironment(Environment|string $environment): self
    {
        $this->environment = self::environment($environment);

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
        $this->useDefaultLock = false;

        return $this->reset();
    }

    /**
     * Bloqueo de numeración enchufable (por ejemplo {@see \RoyaltyFusion\Afip\Lock\PdoNumberingLock}
     * para varios servidores). null desactiva el bloqueo.
     */
    public function setNumberingLock(?NumberingLockInterface $lock): self
    {
        $this->lock = $lock;
        $this->useDefaultLock = false;

        return $this;
    }

    /**
     * Habilita el bloqueo de numeración por archivos en un directorio (si no se usó setCacheDirectory()).
     */
    public function setLockDirectory(?string $directory): self
    {
        $this->lock = $directory === null ? null : new FileLock($directory);
        $this->useDefaultLock = false;

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
     * Registra cada llamada SOAP. Acepta un logger PSR-3 (debug en cada llamada, error si falla)
     * o un callable fn(string $operation, string $request, ?string $response, ?\Throwable $error).
     *
     * Con $redact = true (por defecto) se ocultan token, sign, el CMS del login y las claves
     * privadas antes de registrar. Los XML de AuthorizationResult::getRequestXml() no se modifican.
     */
    public function setLogger(callable|object|null $logger, bool $redact = true): self
    {
        if ($logger !== null && !is_callable($logger) && !method_exists($logger, 'log')) {
            throw new \InvalidArgumentException('El logger debe ser un callable o un logger PSR-3.');
        }
        $this->logger = $logger;
        $this->redactLogs = $redact;

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

    /**
     * Política de recuperación ante errores de comunicación al autorizar.
     */
    public function setRecoveryPolicy(int $attempts, float $delaySeconds): self
    {
        $this->recoveryAttempts = max(1, $attempts);
        $this->recoveryDelay = max(0.0, $delaySeconds);

        return $this;
    }

    /**
     * Para emisores MiPyME: antes de enviar, consulta WSFECRED y exige FCE cuando el receptor
     * está obligado y el importe alcanza el mínimo (o rechaza la FCE si el receptor no está
     * obligado). Requiere el servicio wsfecred habilitado en el certificado.
     */
    public function setFceCheck(bool $emisorMiPyme): self
    {
        $this->fceCheck = $emisorMiPyme;

        return $this;
    }

    /**
     * Controla que la cotización de moneda extranjera no se desvíe más de $percent % de la
     * oficial de ARCA. null desactiva el control.
     */
    public function setQuotationTolerance(?float $percent): self
    {
        $this->quotationTolerance = $percent;

        return $this;
    }

    /**
     * Verifica en ARCA que los comprobantes asociados propios existan (notas de crédito/débito).
     */
    public function setCheckAssociatedVouchers(bool $check): self
    {
        $this->checkAssociated = $check;

        return $this;
    }

    /**
     * Verifica en el padrón (constancia de inscripción) que el receptor esté activo y que su
     * condición frente al IVA coincida con la informada. Requiere ws_sr_constancia_inscripcion.
     */
    public function setPadronCheck(bool $check): self
    {
        $this->padronCheck = $check;

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
                $wsaa->getClient()->setLogger($this->soapLogger());
            }
            $this->ticketStorage ??= new FileTicketStorage(self::defaultCacheDirectory() . DIRECTORY_SEPARATOR . 'tickets');
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

    public function getWsmtxca(): WsmtxcaService
    {
        return $this->wsmtxca ??= $this->configure(new WsmtxcaService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSMTXCA] ?? null
        ));
    }

    public function getWsbfe(): WsbfeService
    {
        return $this->wsbfe ??= $this->configure(new WsbfeService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSBFE] ?? null
        ));
    }

    public function getWsct(): WsctService
    {
        return $this->wsct ??= $this->configure(new WsctService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSCT] ?? null
        ));
    }

    public function getWsfecred(): WsfecredService
    {
        return $this->wsfecred ??= $this->configure(new WsfecredService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSFECRED] ?? null
        ));
    }

    public function getWscdc(): WscdcService
    {
        return $this->wscdc ??= $this->configure(new WscdcService(
            $this->getAuthManager(),
            $this->getCuit(),
            $this->environment,
            $this->transport(),
            $this->endpoints[Endpoints::WSCDC] ?? null
        ));
    }

    // ----------------------------------------------------------- Validación

    public function validate(Invoice $invoice): ValidationResult
    {
        return $this->invoiceValidator()->validate($invoice);
    }

    public function validateExport(ExportInvoice $invoice): ValidationResult
    {
        return (new ExportInvoiceValidator($this->effectiveOptions()))->validate($invoice);
    }

    // ------------------------------------------------------------ Emisión

    /**
     * Valida y autoriza un comprobante (CAE). Si no tiene número, se asigna el siguiente.
     *
     * @throws ValidationException              si el comprobante no supera las validaciones (no se envía)
     * @throws UncertainAuthorizationException  si falló la comunicación y no se pudo confirmar el
     *                                          resultado: reenviar el MISMO comprobante (mismo número)
     * @throws TransportException               si falló la comunicación antes de enviar el comprobante
     */
    public function send(Invoice $invoice): AuthorizationResult
    {
        return $this->sendBatch([$invoice]);
    }

    /**
     * Valida y autoriza varios comprobantes del mismo tipo y punto de venta en una sola solicitud.
     *
     * Si la numeración es automática y ARCA rechaza algún comprobante, los rechazados quedan
     * sin número para poder corregirlos y reenviarlos.
     *
     * @param Invoice[] $invoices
     */
    public function sendBatch(array $invoices): AuthorizationResult
    {
        return $this->emit(new WsfeChannel($this->getWsfe(), $this->validatorOptions->maxRegistrosPorRequest), $invoices);
    }

    /**
     * Flujo común de emisión (WSFEv1, WSMTXCA, WSCT): valida, numera, autoriza, reintenta ante
     * números ocupados y recupera el resultado ante errores de comunicación.
     *
     * @param Invoice[] $invoices
     */
    private function emit(VoucherChannel $channel, array $invoices): AuthorizationResult
    {
        $invoices = array_values($invoices);
        foreach ($invoices as $invoice) {
            $company = $invoice->getCompany();
            if ($company !== null && $company->getCuit() === '') {
                $company->setCuit($this->getCuit());
            }
        }
        $validator = $this->invoiceValidator();
        $validation = $validator->validateBatch($invoices, $channel->validationMode());
        if (count($invoices) > $channel->maxBatch()) {
            $validation->error('LOTE_EXCEDIDO', 'invoices', sprintf('Este servicio admite como máximo %d comprobante(s) por solicitud.', $channel->maxBatch()));
        }
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }

        $first = $invoices[0];
        $key = sprintf('%s_%s_%d_%d', $channel->service()->getServiceName(), $this->getCuit(), $first->getPuntoVenta(), $first->getTipoComprobante());

        return $this->locked($key, function () use ($channel, $invoices, $first): AuthorizationResult {
            $this->lastService = $channel->service();
            $this->checkSalesPoint($channel, $first->getPuntoVenta());
            $this->onlineChecks($channel, $invoices);
            $autoNumber = $first->getNumero() === null;
            $ranges = array_map(static fn (Invoice $i): int => $i->getNumero() === null ? 0 : (int) $i->getNumeroHasta() - (int) $i->getNumero(), $invoices);
            $attempts = 0;

            try {
                while (true) {
                    if ($autoNumber) {
                        $last = $channel->lastNumber($first->getPuntoVenta(), $first->getTipoComprobante());
                        $this->assignNumbers($invoices, $ranges, $last + 1);
                        $this->checkDateAgainstLast($channel, $first, $last);
                    }

                    $result = $this->authorizeWithRecovery($channel, $invoices);
                    $result = $this->checkAlreadyAuthorized($channel, $invoices, $result);

                    if ($autoNumber && $this->isNumberingConflict($result, $channel->numberingConflictCodes()) && $attempts < $this->numberingRetries) {
                        $attempts++;
                        $last = $channel->lastNumber($first->getPuntoVenta(), $first->getTipoComprobante());
                        if ($last + 1 !== $first->getNumero()) {
                            continue; // otro proceso usó el número: se renumera y reintenta
                        }
                    }

                    if ($autoNumber) {
                        $this->releaseNumbers($invoices);
                    }

                    return $result;
                }
            } catch (UncertainAuthorizationException $e) {
                throw $e; // se conserva el número para reenviar el mismo comprobante
            } catch (\Throwable $e) {
                // Nada fue autorizado: se liberan los números asignados automáticamente.
                if ($autoNumber) {
                    $this->releaseNumbers($invoices);
                }
                throw $e;
            }
        });
    }

    /**
     * Valida y autoriza un comprobante con detalle de ítems por WSMTXCA (clases A, B y M).
     * Calcular antes con InvoiceCalculator::calculate($invoice, true).
     *
     * @throws ValidationException|UncertainAuthorizationException
     */
    public function sendMtxca(Invoice $invoice): AuthorizationResult
    {
        return $this->emit(new WsmtxcaChannel($this->getWsmtxca()), [$invoice]);
    }

    /**
     * Valida y autoriza un comprobante de turismo clase T (WSCT).
     * Calcular antes con InvoiceCalculator::calculate($invoice, true).
     *
     * @throws ValidationException|UncertainAuthorizationException
     */
    public function sendTurismo(TurismoInvoice $invoice): AuthorizationResult
    {
        return $this->emit(new WsctChannel($this->getWsct()), [$invoice]);
    }

    /**
     * Valida y autoriza un Bono Fiscal Electrónico (WSBFE). Asigna Id y número si faltan.
     * Calcular antes con InvoiceCalculator::calculate($invoice, true).
     *
     * @throws ValidationException|UncertainAuthorizationException
     */
    public function sendBfe(BonoFiscalInvoice $invoice): ExportAuthorizationResult
    {
        $company = $invoice->getCompany();
        if ($company !== null && $company->getCuit() === '') {
            $company->setCuit($this->getCuit());
        }
        $validation = $this->invoiceValidator()->validate($invoice, InvoiceValidator::MODE_BFE);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }

        $key = sprintf('wsbfe_%s_%d_%d', $this->getCuit(), $invoice->getPuntoVenta(), $invoice->getTipoComprobante());

        return $this->locked($key, function () use ($invoice): ExportAuthorizationResult {
            $wsbfe = $this->getWsbfe();
            $this->lastService = $wsbfe;
            $autoNumber = $invoice->getNumero() === null;
            $autoId = $invoice->getId() === null;

            try {
                if ($autoNumber) {
                    $invoice->setNumero($wsbfe->getLastVoucher($invoice->getPuntoVenta(), $invoice->getTipoComprobante()) + 1);
                }
                if ($autoId) {
                    $invoice->setId($wsbfe->getLastId() + 1);
                }
                try {
                    $result = $wsbfe->authorize($invoice);
                } catch (TransportException $e) {
                    return $this->recoverBfe($wsbfe, $invoice, $e);
                }
            } catch (UncertainAuthorizationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->releaseBfe($invoice, $autoNumber, $autoId);
                throw $e;
            }
            if (!$result->isSuccess()) {
                $this->releaseBfe($invoice, $autoNumber, $autoId);
            }

            return $result;
        });
    }

    /**
     * Valida e informa por WSMTXCA un comprobante emitido con CAEA.
     */
    public function informCaeaMtxca(Invoice $invoice): AuthorizationResult
    {
        $validation = $this->invoiceValidator()->validate($invoice, InvoiceValidator::MODE_MTXCA_CAEA);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }
        $service = $this->getWsmtxca();
        $this->lastService = $service;

        return $service->informCaeaVoucher($invoice);
    }

    /**
     * Valida e informa comprobantes emitidos con CAEA (FECAEARegInformativo).
     *
     * @param Invoice|Invoice[] $invoices
     */
    public function informCaea(Invoice|array $invoices): AuthorizationResult
    {
        $invoices = $invoices instanceof Invoice ? [$invoices] : array_values($invoices);
        $validation = $this->invoiceValidator()->validateBatch($invoices, InvoiceValidator::MODE_CAEA);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }
        $wsfe = $this->getWsfe();
        $this->lastService = $wsfe;

        return $wsfe->informCaeaVouchers($invoices);
    }

    /**
     * Valida y autoriza un comprobante de exportación (WSFEX). Asigna Id y número si faltan.
     *
     * @throws UncertainAuthorizationException si no se pudo confirmar el resultado (reenviar el mismo comprobante)
     */
    public function sendExport(ExportInvoice $invoice): ExportAuthorizationResult
    {
        $company = $invoice->getCompany();
        if ($company !== null && $company->getCuit() === '') {
            $company->setCuit($this->getCuit());
        }
        $validation = $this->validateExport($invoice);
        if (!$validation->isValid()) {
            throw new ValidationException($validation);
        }

        $key = sprintf('wsfex_%s_%d_%d', $this->getCuit(), $invoice->getPuntoVenta(), $invoice->getTipoComprobante());

        return $this->locked($key, function () use ($invoice): ExportAuthorizationResult {
            $wsfex = $this->getWsfex();
            $this->lastService = $wsfex;
            $autoNumber = $invoice->getNumero() === null;
            $autoId = $invoice->getId() === null;

            try {
                if ($autoNumber) {
                    $invoice->setNumero($wsfex->getLastVoucher($invoice->getPuntoVenta(), $invoice->getTipoComprobante()) + 1);
                }
                if ($autoId) {
                    $invoice->setId($wsfex->getLastId() + 1);
                }

                try {
                    $result = $wsfex->authorize($invoice);
                } catch (TransportException $e) {
                    return $this->recoverExport($wsfex, $invoice, $e);
                }
            } catch (UncertainAuthorizationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->releaseExport($invoice, $autoNumber, $autoId);
                throw $e;
            }

            if (!$result->isSuccess()) {
                $this->releaseExport($invoice, $autoNumber, $autoId);
            }

            return $result;
        });
    }

    /**
     * Retoma un comprobante desde cualquier proceso (por ejemplo, un cron que reintenta un
     * estado incierto): lo consulta en ARCA por tipo, punto de venta y número.
     *
     * - Si está autorizado con los mismos datos (importe, documento y fecha), completa el CAE en
     *   el Invoice y devuelve el resultado ({@see AuthorizationResult::isRecovered()}).
     * - Si no existe, devuelve null: puede reenviarse con el mismo número.
     * - Si el número está autorizado con otros datos, lanza VoucherMismatchException.
     *
     * @param string|null $servicio wsfe (por defecto), wsmtxca o wsct (TurismoInvoice usa wsct)
     *
     * @throws VoucherMismatchException
     */
    public function reconcile(Invoice $invoice, ?string $servicio = null): ?AuthorizationResult
    {
        if ($invoice->getNumero() === null) {
            throw new \InvalidArgumentException('Para reconciliar, el comprobante debe tener número.');
        }
        $channel = match (true) {
            $invoice instanceof TurismoInvoice || $servicio === WsctService::SERVICE => new WsctChannel($this->getWsct()),
            $servicio === WsmtxcaService::SERVICE => new WsmtxcaChannel($this->getWsmtxca()),
            default => new WsfeChannel($this->getWsfe()),
        };
        $this->lastService = $channel->service();
        $found = $channel->find($invoice->getTipoComprobante(), $invoice->getPuntoVenta(), (int) $invoice->getNumero());
        if ($found === null) {
            return null;
        }
        if (!$this->matches($found, $invoice)) {
            throw new VoucherMismatchException(sprintf(
                'El número %s está autorizado en ARCA con otros datos (total %s, documento %s, fecha %s).',
                $invoice->getNumeroCompleto(),
                Num::format($found->total),
                $found->docNro,
                $found->fecha?->format('d/m/Y') ?? '-'
            ), $found->raw);
        }
        $invoice->setCae($found->cae);
        $invoice->setCaeVencimiento($found->vencimiento);

        return AuthorizationResult::recovered($invoice->getPuntoVenta(), $invoice->getTipoComprobante(), $found);
    }

    /**
     * Cotización oficial de ARCA (FEParamGetCotizacion), redondeada a 6 decimales, lista para
     * informar en el comprobante. PES devuelve 1.
     *
     * Si el comprobante se cancela en la misma moneda extranjera (CanMisMonExt = S), ARCA
     * exige la cotización del día hábil anterior: indicar esa fecha.
     */
    public function getCotizacion(string $moneda, ?\DateTimeInterface $fecha = null): float
    {
        if ($moneda === 'PES') {
            return 1.0;
        }
        $wsfe = $this->getWsfe();
        $this->lastService = $wsfe;

        return round($wsfe->getQuotation($moneda, $fecha)['cotizacion'], 6);
    }

    /**
     * Datos para dibujar la representación impresa con el motor del ERP (TCPDF, etc.):
     * letra, código, punto de venta (5 dígitos), número (8), CAE, vencimiento, URL del QR,
     * importes y leyendas (Ley 27.743, RG 5003, RG 5700).
     *
     * @return array<string, mixed>
     */
    public function getRepresentationData(Invoice|ExportInvoice $invoice): array
    {
        $cuit = (string) $invoice->getCompany()?->getCuit();

        return RepresentationData::build($invoice, $cuit !== '' ? $cuit : $this->getCuit());
    }

    /**
     * URL del QR (RG 4291) de un comprobante autorizado.
     */
    public function getQrUrl(Invoice|ExportInvoice $invoice): string
    {
        return (new QrGenerator())->buildUrl($invoice, $this->getCuit());
    }

    /**
     * Constata con WSCDC un comprobante recibido (por ejemplo, uno importado de "Mis
     * Comprobantes" o leído del QR). Resultado A: el comprobante existe y coincide.
     *
     * @param bool $informarReceptor envía la CUIT propia como receptor (obligatorio en A y M)
     */
    public function verifyReceived(RegistroIva $compra, bool $informarReceptor = true): OperationResult
    {
        if (!TipoDocumento::isCuitLike($compra->getDocTipo()) || !Cuit::isValid($compra->getDocNro())) {
            throw new \InvalidArgumentException('Para constatar el comprobante hace falta la CUIT válida del emisor.');
        }
        $codigo = $compra->getCodigoAutorizacion();
        if ($codigo === null) {
            throw new \InvalidArgumentException(sprintf('El comprobante %s no tiene código de autorización (CAE, CAEA o CAI).', $compra->getClave()));
        }
        $modo = match ($compra->getTipoCodigoAutorizacion()) {
            'CAEA' => WscdcService::MODO_CAEA,
            'CAI' => WscdcService::MODO_CAI,
            default => WscdcService::MODO_CAE,
        };

        return $this->getWscdc()->verify(
            $compra->getDocNro(),
            $compra->getPuntoVenta(),
            $compra->getTipoComprobante(),
            $compra->getNumero(),
            $compra->getFecha(),
            $compra->getTotal(),
            $codigo,
            $modo,
            $informarReceptor ? TipoDocumento::CUIT : null,
            $informarReceptor ? $this->getCuit() : null
        );
    }

    /**
     * Constata con WSCDC el comprobante de un QR (URL https://www.afip.gob.ar/fe/qr/?p=...).
     * Se informa como receptor el documento que trae el QR.
     */
    public function verifyQr(string $url): OperationResult
    {
        $data = QrGenerator::decode($url);
        $registro = RegistroFactory::fromQr($url);
        $codigo = (string) $registro->getCodigoAutorizacion();

        return $this->getWscdc()->verify(
            $registro->getDocNro(),
            $registro->getPuntoVenta(),
            $registro->getTipoComprobante(),
            $registro->getNumero(),
            $registro->getFecha(),
            $registro->getTotal(),
            $codigo,
            $registro->getTipoCodigoAutorizacion() === 'CAEA' ? WscdcService::MODO_CAEA : WscdcService::MODO_CAE,
            isset($data['tipoDocRec']) ? (int) $data['tipoDocRec'] : null,
            isset($data['nroDocRec']) ? (string) $data['nroDocRec'] : null
        );
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
    private function authorizeWithRecovery(VoucherChannel $channel, array $invoices): AuthorizationResult
    {
        try {
            return $channel->authorize($invoices);
        } catch (TransportException $e) {
            $first = $invoices[0];
            $numbers = array_map(static fn (Invoice $i): int => (int) $i->getNumero(), $invoices);
            if (!$this->autoRecover) {
                throw $this->uncertain($invoices, $channel->service()->getServiceName(), false, $e);
            }

            $found = [];
            $allNotFound = true;
            foreach ($invoices as $invoice) {
                [$voucher, $confirmedMissing] = $this->lookup(fn (): ?FoundVoucher => $channel->find(
                    $invoice->getTipoComprobante(),
                    $invoice->getPuntoVenta(),
                    (int) $invoice->getNumero()
                ));
                $allNotFound = $allNotFound && $confirmedMissing;
                if ($voucher === null) {
                    continue;
                }
                if (!$this->matches($voucher, $invoice)) {
                    throw new AfipException(sprintf(
                        'El número %s ya fue autorizado por ARCA para otro comprobante (total %s, documento %s).',
                        $invoice->getNumeroCompleto(),
                        Num::format($voucher->total),
                        $voucher->docNro
                    ), 0, $e);
                }
                $found[] = $voucher;
            }

            if (count($found) !== count($invoices)) {
                throw $this->uncertain($invoices, $channel->service()->getServiceName(), $allNotFound, $e);
            }

            foreach ($invoices as $i => $invoice) {
                $invoice->setCae($found[$i]->cae);
                $invoice->setCaeVencimiento($found[$i]->vencimiento);
            }

            return AuthorizationResult::recovered($first->getPuntoVenta(), $first->getTipoComprobante(), ...$found);
        }
    }

    private function recoverExport(WsfexService $wsfex, ExportInvoice $invoice, TransportException $e): ExportAuthorizationResult
    {
        $numbers = [(int) $invoice->getNumero()];
        if (!$this->autoRecover) {
            throw $this->uncertain([$invoice], 'wsfex', false, $e);
        }
        [$voucher, $confirmedMissing] = $this->lookup(fn (): ?array => $wsfex->getVoucher(
            $invoice->getTipoComprobante(),
            $invoice->getPuntoVenta(),
            (int) $invoice->getNumero()
        ));
        if ($voucher === null) {
            throw $this->uncertain([$invoice], 'wsfex', $confirmedMissing, $e);
        }

        $sameClient = static fn (?string $a, ?string $b): bool => preg_replace('/\W/', '', (string) $a) === preg_replace('/\W/', '', (string) $b);
        $matches = (int) ($voucher['Cbte_nro'] ?? 0) === (int) $invoice->getNumero()
            && Num::equals((float) ($voucher['Imp_total'] ?? -1), $invoice->getImpTotal(), 0.01)
            && (string) ($voucher['Fecha_cbte'] ?? '') === $invoice->getFechaEmision()->format('Ymd')
            && $sameClient($voucher['Cuit_pais_cliente'] ?? null, $invoice->getCuitPaisCliente() ?? '0')
            && $sameClient($voucher['Id_impositivo'] ?? null, $invoice->getIdImpositivo());
        if (!$matches) {
            throw new AfipException(sprintf(
                'El número %s ya fue autorizado por ARCA para otro comprobante de exportación.',
                $invoice->getNumeroCompleto()
            ), 0, $e);
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

    /**
     * Consulta un comprobante reintentando ante errores de comunicación o respuestas
     * "inexistente" tempranas (ARCA puede seguir procesando la solicitud original).
     *
     * @template T
     *
     * @param callable(): ?T $query
     *
     * @return array{0: T|null, 1: bool} [comprobante, true si ARCA confirmó que no existe]
     */
    private function lookup(callable $query): array
    {
        $confirmedMissing = false;
        for ($i = 0; $i < $this->recoveryAttempts; $i++) {
            if ($i > 0 && $this->recoveryDelay > 0) {
                usleep((int) ($this->recoveryDelay * 1_000_000 * $i));
            }
            try {
                $found = $query();
                if ($found !== null) {
                    return [$found, false];
                }
                $confirmedMissing = true;
            } catch (\Throwable) {
                // Error de red o respuesta inesperada de ARCA: el resultado sigue siendo incierto.
                $confirmedMissing = false;
            }
        }

        return [null, $confirmedMissing];
    }

    /**
     * @param array<int, Invoice|ExportInvoice> $invoices
     */
    private function uncertain(array $invoices, string $servicio, bool $notFound, TransportException $previous): UncertainAuthorizationException
    {
        $first = $invoices[0];
        $numbers = array_map(static fn (Invoice|ExportInvoice $i): int => (int) $i->getNumero(), $invoices);

        return new UncertainAuthorizationException(
            sprintf(
                'No se pudo confirmar si ARCA autorizó el comprobante %s %05d-%s (%s). Reintente con See::reconcile() o reenvíe '
                . 'el MISMO comprobante con el mismo número: si ya estaba autorizado, el SDK devuelve ese CAE. No genere uno nuevo.',
                TipoComprobante::description($first->getTipoComprobante()),
                $first->getPuntoVenta(),
                implode(', ', array_map(static fn (int $n): string => sprintf('%08d', $n), $numbers)),
                $previous->getMessage()
            ),
            $first->getPuntoVenta(),
            $first->getTipoComprobante(),
            $numbers,
            $notFound,
            $previous,
            Fingerprint::ofBatch($invoices, $this->getCuit()),
            $servicio,
            $this->getCuit(),
            $this->environment->value
        );
    }

    /**
     * Servicios sin reproceso (WSMTXCA, WSCT): si ARCA rechaza un comprobante cuyo número ya
     * figura autorizado con los mismos datos (reenvío tras un error de comunicación), se
     * devuelve ese resultado en lugar del rechazo.
     *
     * @param Invoice[] $invoices
     */
    private function checkAlreadyAuthorized(VoucherChannel $channel, array $invoices, AuthorizationResult $result): AuthorizationResult
    {
        if ($result->isSuccess() || $channel->supportsReprocess() || count($invoices) !== 1) {
            return $result;
        }
        $invoice = $invoices[0];
        try {
            $found = $channel->find($invoice->getTipoComprobante(), $invoice->getPuntoVenta(), (int) $invoice->getNumero());
        } catch (\Throwable) {
            return $result;
        }
        if ($found === null || $found->cae === null || !$this->matches($found, $invoice)) {
            return $result;
        }
        $invoice->setCae($found->cae);
        $invoice->setCaeVencimiento($found->vencimiento);

        return AuthorizationResult::recovered($invoice->getPuntoVenta(), $invoice->getTipoComprobante(), $found);
    }

    /**
     * Error 10016 sin ningún comprobante aprobado: el número ya fue usado por otro proceso.
     */
    /**
     * @param int[] $codes
     */
    private function isNumberingConflict(AuthorizationResult $result, array $codes): bool
    {
        if ($result->getResultado() !== 'R') {
            return false;
        }
        foreach ($result->getDetails() as $detail) {
            if ($detail->isApproved()) {
                return false;
            }
        }
        foreach ($result->getErrors() as $error) {
            if (in_array($error->getCode(), $codes, true)) {
                return true;
            }
        }
        $first = $result->getDetail();
        foreach ($first?->getObservaciones() ?? [] as $obs) {
            if (in_array($obs->getCode(), $codes, true)) {
                return true;
            }
        }

        return false;
    }

    private function matches(FoundVoucher $voucher, Invoice $invoice): bool
    {
        $client = $invoice->getClient();
        if ($client === null) {
            return false;
        }

        return Num::equals($voucher->total, $invoice->getImpTotal(), 0.01)
            && $voucher->docTipo === $client->getTipoDoc()
            && ltrim($voucher->docNro, '0') === ltrim($client->getNumDoc(), '0')
            && $voucher->fecha?->format('Ymd') === $invoice->getFechaEmision()->format('Ymd');
    }

    /**
     * @param Invoice[] $invoices
     * @param int[]     $ranges cantidad de comprobantes adicionales de cada rango
     */
    private function assignNumbers(array $invoices, array $ranges, int $next): void
    {
        foreach ($invoices as $i => $invoice) {
            $range = $ranges[$i] ?? 0;
            $invoice->setNumero($next);
            $invoice->setNumeroHasta($range > 0 ? $next + $range : null);
            $next += $range + 1;
        }
    }

    /**
     * Quita el número asignado automáticamente a los comprobantes que no se autorizaron.
     *
     * @param Invoice[] $invoices
     */
    private function releaseNumbers(array $invoices): void
    {
        foreach ($invoices as $invoice) {
            if (!$invoice->isAuthorized()) {
                $invoice->setNumero(null);
                $invoice->setNumeroHasta(null);
            }
        }
    }

    private function recoverBfe(WsbfeService $wsbfe, BonoFiscalInvoice $invoice, TransportException $e): ExportAuthorizationResult
    {
        $numbers = [(int) $invoice->getNumero()];
        if (!$this->autoRecover) {
            throw $this->uncertain([$invoice], 'wsbfe', false, $e);
        }
        [$voucher, $confirmedMissing] = $this->lookup(fn (): ?array => $wsbfe->getVoucher(
            $invoice->getTipoComprobante(),
            $invoice->getPuntoVenta(),
            (int) $invoice->getNumero()
        ));
        if ($voucher === null) {
            throw $this->uncertain([$invoice], 'wsbfe', $confirmedMissing, $e);
        }
        $fecha = (string) ($voucher['Fecha_cbte_orig'] ?? $voucher['Fecha_cbte_cae'] ?? '');
        $matches = Num::equals((float) ($voucher['Imp_total'] ?? -1), $invoice->getImpTotal(), 0.01)
            && ltrim((string) ($voucher['Nro_doc'] ?? ''), '0') === ltrim((string) $invoice->getClient()?->getNumDoc(), '0')
            && ($fecha === '' || $fecha === $invoice->getFechaEmision()->format('Ymd'));
        if (!$matches) {
            throw new AfipException(sprintf('El número %s ya fue autorizado por ARCA para otro bono fiscal.', $invoice->getNumeroCompleto()), 0, $e);
        }
        $result = new ExportAuthorizationResult(
            'A',
            trim((string) $voucher['Cae']),
            Date::fromAfip((string) ($voucher['Fch_venc_Cae'] ?? '')),
            (int) $invoice->getNumero(),
            Date::fromAfip($fecha),
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

    private function releaseBfe(BonoFiscalInvoice $invoice, bool $autoNumber, bool $autoId): void
    {
        if ($invoice->isAuthorized()) {
            return;
        }
        if ($autoNumber) {
            $invoice->setNumero(null);
        }
        if ($autoId) {
            $invoice->setId(null);
        }
    }

    private function releaseExport(ExportInvoice $invoice, bool $autoNumber, bool $autoId): void
    {
        if ($invoice->isAuthorized()) {
            return;
        }
        if ($autoNumber) {
            $invoice->setNumero(null);
        }
        if ($autoId) {
            $invoice->setId(null);
        }
    }

    private function checkDateAgainstLast(VoucherChannel $channel, Invoice $invoice, int $last): void
    {
        if (!$this->checkLastVoucherDate || $last < 1) {
            return;
        }
        $fecha = $channel->find($invoice->getTipoComprobante(), $invoice->getPuntoVenta(), $last)?->fecha;
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
    /**
     * Controles opcionales contra ARCA antes de enviar (ver setFceCheck, setQuotationTolerance,
     * setCheckAssociatedVouchers, setPadronCheck).
     *
     * @param Invoice[] $invoices
     */
    private function onlineChecks(VoucherChannel $channel, array $invoices): void
    {
        $result = new ValidationResult();
        foreach ($invoices as $i => $invoice) {
            $prefix = count($invoices) > 1 ? sprintf('[%d].', $i) : '';
            $client = $invoice->getClient();
            $tipo = $invoice->getTipoComprobante();
            $moneda = $invoice->getMoneda();

            // 1) Cotización vs. oficial
            if ($this->quotationTolerance !== null && $moneda !== 'PES' && $channel instanceof WsfeChannel) {
                $official = $this->onlineCache['ctz_' . $moneda] ??= $this->getWsfe()->getQuotation($moneda)['cotizacion'];
                if ($official > 0 && abs($invoice->getCotizacion() - $official) / $official * 100 > $this->quotationTolerance) {
                    $result->error('COTIZACION_FUERA_DE_RANGO', $prefix . 'cotizacion', sprintf(
                        'La cotización informada (%s) se desvía más de %s %% de la oficial de ARCA (%s).',
                        Num::formatCompact($invoice->getCotizacion()),
                        Num::formatCompact($this->quotationTolerance),
                        Num::formatCompact($official)
                    ));
                }
            }

            // 2) Comprobantes asociados propios
            if ($this->checkAssociated && TipoComprobante::isNota($tipo)) {
                foreach ($invoice->getCbtesAsociados() as $j => $asoc) {
                    if ($asoc->getCuit() !== null && $asoc->getCuit() !== $this->getCuit()) {
                        continue; // de otro emisor: no se puede consultar
                    }
                    try {
                        $found = $channel->find($asoc->getTipo(), $asoc->getPuntoVenta(), $asoc->getNumero());
                    } catch (\Throwable) {
                        continue; // no se pudo consultar: no se bloquea el envío
                    }
                    if ($found === null) {
                        $result->error('CBTE_ASOC_INEXISTENTE', sprintf('%scbtesAsociados[%d]', $prefix, $j), sprintf(
                            'El comprobante asociado %s %05d-%08d no existe como autorizado en ARCA.',
                            TipoComprobante::description($asoc->getTipo()),
                            $asoc->getPuntoVenta(),
                            $asoc->getNumero()
                        ), 10041);
                    }
                }
            }

            if ($client === null || $client->getTipoDoc() !== 80) {
                continue;
            }

            // 3) FCE MiPyME
            if ($this->fceCheck && in_array(TipoComprobante::clase($tipo), ['A', 'B', 'C'], true) && TipoComprobante::isFactura($tipo)) {
                $key = 'fce_' . $client->getNumDoc() . '_' . $invoice->getFechaEmision()->format('Ymd');
                $obligation = $this->onlineCache[$key] ??= $this->getWsfecred()->getObligation($client->getNumDoc(), $invoice->getFechaEmision());
                $totalPesos = $invoice->getImpTotal() * ($moneda === 'PES' ? 1.0 : $invoice->getCotizacion());
                if (TipoComprobante::isFce($tipo) && !$obligation['obligado']) {
                    $result->error('FCE_RECEPTOR_NO_OBLIGADO', $prefix . 'tipoComprobante', 'El receptor no está obligado ni optó por recibir Factura de Crédito Electrónica MiPyME: emita una factura común.', 150);
                } elseif (!TipoComprobante::isFce($tipo) && $obligation['obligado'] && $totalPesos >= $obligation['montoDesde']) {
                    $result->error('FCE_OBLIGATORIA', $prefix . 'tipoComprobante', sprintf(
                        'El receptor está obligado a recibir FCE MiPyME desde $ %s: corresponde emitir Factura de Crédito Electrónica (201/206/211).',
                        Num::display($obligation['montoDesde'])
                    ));
                }
            }

            // 4) Padrón: receptor activo y condición IVA coherente
            if ($this->padronCheck) {
                $persona = $this->onlineCache['padron_' . $client->getNumDoc()] ??= $this->getPadronA5()->getPersona($client->getNumDoc()) ?? false;
                if ($persona === false) {
                    $result->error('RECEPTOR_INEXISTENTE', $prefix . 'client.numDoc', 'El CUIT del receptor no existe en el padrón de ARCA.');
                } elseif (!$persona->isActiva()) {
                    $result->error('RECEPTOR_INACTIVO', $prefix . 'client.numDoc', sprintf('El CUIT del receptor no está activo en ARCA (%s).', (string) $persona->getEstadoClave()));
                } elseif ($persona->getCondicionIva() !== null && $client->getCondicionIva() !== null
                    && self::ivaGroup($persona->getCondicionIva()) !== null
                    && self::ivaGroup($persona->getCondicionIva()) !== self::ivaGroup((int) $client->getCondicionIva())) {
                    $result->error('CONDICION_IVA_PADRON', $prefix . 'client.condicionIva', sprintf(
                        'La condición frente al IVA informada (%s) no coincide con el padrón de ARCA (%s).',
                        \RoyaltyFusion\Afip\Catalog\CondicionIva::description((int) $client->getCondicionIva()),
                        \RoyaltyFusion\Afip\Catalog\CondicionIva::description((int) $persona->getCondicionIva())
                    ));
                }
            }
        }
        if (!$result->isValid()) {
            throw new ValidationException($result);
        }
    }

    private function invoiceValidator(): InvoiceValidator
    {
        return new InvoiceValidator($this->effectiveOptions());
    }

    /**
     * Opciones de validación con la fecha de referencia del reloj inyectado.
     */
    private function effectiveOptions(): ValidatorOptions
    {
        if ($this->validatorOptions->fechaReferencia !== null) {
            return $this->validatorOptions;
        }
        $options = clone $this->validatorOptions;
        $options->fechaReferencia = $this->clock->today();

        return $options;
    }

    /**
     * Adapta el logger configurado (callable o PSR-3) y oculta credenciales si corresponde.
     */
    private function soapLogger(): ?callable
    {
        $logger = $this->logger;
        if ($logger === null) {
            return null;
        }
        $redact = $this->redactLogs;

        return static function (string $operation, string $request, ?string $response, ?\Throwable $error) use ($logger, $redact): void {
            if ($redact) {
                $request = (string) Redactor::xml($request);
                $response = Redactor::xml($response);
            }
            if (is_callable($logger)) {
                $logger($operation, $request, $response, $error);

                return;
            }
            $context = ['operation' => $operation, 'request' => $request, 'response' => $response];
            if ($error !== null) {
                $context['exception'] = $error;
                $logger->log('error', 'ARCA {operation}: ' . $error->getMessage(), $context);
            } else {
                $logger->log('debug', 'ARCA {operation}', $context);
            }
        };
    }

    /**
     * Agrupa condiciones equivalentes para comparar con el padrón (que no distingue, por
     * ejemplo, monotributo social de monotributo). null = el padrón no permite concluir.
     */
    private static function ivaGroup(int $condicion): ?string
    {
        return match ($condicion) {
            1 => 'RI',
            6, 13, 16 => 'MONOTRIBUTO',
            4, 10, 15 => 'EXENTO',
            default => null,
        };
    }

    private function checkSalesPoint(VoucherChannel $channel, int $puntoVenta): void
    {
        if (!$this->verifySalesPoint) {
            return;
        }
        $key = $channel->service()->getServiceName();
        if (!array_key_exists($key, $this->salesPoints)) {
            $this->salesPoints[$key] = $channel->salesPoints();
        }
        $points = $this->salesPoints[$key];
        if ($points === null || $points === []) {
            return; // el servicio no informa puntos de venta (o la tabla está vacía, ej. homologación)
        }
        $problem = sprintf('El punto de venta %d no está habilitado para este web service.', $puntoVenta);
        foreach ($points as $point) {
            if ($point['numero'] !== $puntoVenta) {
                continue;
            }
            if ($point['bloqueado']) {
                $problem = sprintf('El punto de venta %d está bloqueado en ARCA.', $puntoVenta);
            } elseif ($point['baja'] !== null) {
                $problem = sprintf('El punto de venta %d fue dado de baja el %s.', $puntoVenta, $point['baja']);
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
        if ($this->lock === null && $this->useDefaultLock) {
            $this->lock = new FileLock(self::defaultCacheDirectory() . DIRECTORY_SEPARATOR . 'locks');
        }

        // La clave incluye el ambiente: homologación y producción numeran por separado.
        $key = $this->environment->value . '_' . $key;

        return $this->lock === null ? $callback() : $this->lock->run($key, $callback);
    }

    /**
     * Directorio por defecto (temporal del sistema, separado por usuario del sistema operativo).
     * En producción se recomienda setCacheDirectory() con una ruta persistente.
     */
    private static function defaultCacheDirectory(): string
    {
        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : preg_replace('/\W/', '', get_current_user());

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'afip-php-' . $user;
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
            $service->getClient()->setLogger($this->soapLogger());
        }

        return $service;
    }

    private function transport(): SoapTransportInterface
    {
        return $this->transport ??= new CurlTransport();
    }

    private static function environment(Environment|string $environment): Environment
    {
        if ($environment instanceof Environment) {
            return $environment;
        }
        $value = strtolower(trim($environment));
        $value = match ($value) {
            'homo', 'testing', 'test', 'dev' => 'homologacion',
            'prod', 'production' => 'produccion',
            default => $value,
        };

        return Environment::tryFrom($value)
            ?? throw new \InvalidArgumentException(sprintf('Ambiente "%s" inválido: use "homologacion" o "produccion".', $environment));
    }

    private function reset(): self
    {
        $this->authManager = null;
        $this->wsfe = null;
        $this->wsfex = null;
        $this->padronA5 = null;
        $this->padronA13 = null;
        $this->wsmtxca = null;
        $this->wsbfe = null;
        $this->wsct = null;
        $this->wsfecred = null;
        $this->wscdc = null;
        $this->salesPoints = [];
        $this->onlineCache = [];

        return $this;
    }
}
