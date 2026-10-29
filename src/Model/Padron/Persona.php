<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Padron;

use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Address;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Support\Arr;

/**
 * Contribuyente consultado en el padrón de ARCA.
 */
final class Persona
{
    /** idImpuesto del padrón */
    public const IMPUESTO_MONOTRIBUTO = 20;
    public const IMPUESTO_IVA = 30;
    public const IMPUESTO_IVA_EXENTO = 32;
    public const IMPUESTO_IVA_NO_ALCANZADO = 34;

    /**
     * @param array<int, array{id: int, descripcion: string}> $impuestos
     * @param array<int, array{id: int, descripcion: string, orden: ?int}> $actividades
     * @param string[] $errores
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private readonly string $cuit,
        private readonly ?string $tipoPersona,
        private readonly ?string $nombre,
        private readonly ?string $apellido,
        private readonly ?string $razonSocial,
        private readonly ?string $estadoClave,
        private readonly ?Address $domicilioFiscal,
        private readonly array $impuestos,
        private readonly array $actividades,
        private readonly bool $monotributo,
        private readonly ?string $categoriaMonotributo,
        private readonly array $errores,
        private readonly array $raw
    ) {
    }

    /**
     * Construye la persona desde la respuesta de getPersona_v2 (Constancia de Inscripción / A5).
     *
     * @param array<string, mixed> $data personaReturn
     */
    public static function fromA5(array $data): self
    {
        $generales = (array) Arr::get($data, 'datosGenerales', []);
        $impuestos = [];
        $actividades = [];
        foreach (['datosRegimenGeneral', 'datosMonotributo'] as $section) {
            foreach (Arr::list(Arr::get($data, $section . '.impuesto')) as $imp) {
                $impuestos[] = ['id' => (int) Arr::int($imp, 'idImpuesto', 0), 'descripcion' => (string) Arr::string($imp, 'descripcionImpuesto', '')];
            }
            foreach (Arr::list(Arr::get($data, $section . '.actividad')) as $act) {
                $actividades[] = [
                    'id' => (int) Arr::int($act, 'idActividad', 0),
                    'descripcion' => (string) Arr::string($act, 'descripcionActividad', ''),
                    'orden' => Arr::int($act, 'orden'),
                ];
            }
        }
        $errores = [];
        foreach (['errorConstancia', 'errorRegimenGeneral', 'errorMonotributo'] as $section) {
            foreach (Arr::list(Arr::get($data, $section . '.error')) as $error) {
                if (is_string($error) && $error !== '') {
                    $errores[] = $error;
                }
            }
        }

        $monotributo = Arr::get($data, 'datosMonotributo') !== null;

        return new self(
            (string) (Arr::string($generales, 'idPersona') ?? Arr::string($data, 'errorConstancia.idPersona', '')),
            Arr::string($generales, 'tipoPersona'),
            Arr::string($generales, 'nombre'),
            Arr::string($generales, 'apellido'),
            Arr::string($generales, 'razonSocial'),
            Arr::string($generales, 'estadoClave'),
            self::address(Arr::get($generales, 'domicilioFiscal')),
            $impuestos,
            $actividades,
            $monotributo,
            Arr::string($data, 'datosMonotributo.categoriaMonotributo.descripcionCategoria'),
            $errores,
            $data
        );
    }

    /**
     * Construye la persona desde la respuesta de getPersona del padrón A13.
     *
     * @param array<string, mixed> $persona
     */
    public static function fromA13(array $persona): self
    {
        $fiscal = null;
        foreach (Arr::list(Arr::get($persona, 'domicilio')) as $domicilio) {
            if ($fiscal === null || Arr::string($domicilio, 'tipoDomicilio') === 'FISCAL') {
                $fiscal = $domicilio;
            }
        }

        return new self(
            (string) Arr::string($persona, 'idPersona', ''),
            Arr::string($persona, 'tipoPersona'),
            Arr::string($persona, 'nombre'),
            Arr::string($persona, 'apellido'),
            Arr::string($persona, 'razonSocial'),
            Arr::string($persona, 'estadoClave'),
            self::address($fiscal),
            [],
            [],
            false,
            null,
            [],
            $persona
        );
    }

    private static function address(mixed $node): ?Address
    {
        if (!is_array($node)) {
            return null;
        }

        return (new Address())
            ->setDireccion(Arr::string($node, 'direccion'))
            ->setLocalidad(Arr::string($node, 'localidad'))
            ->setCodigoPostal(Arr::string($node, 'codPostal') ?? Arr::string($node, 'codigoPostal'))
            ->setProvincia(Arr::string($node, 'descripcionProvincia'))
            ->setIdProvincia(Arr::int($node, 'idProvincia'))
            ->setPais('Argentina');
    }

    public function getCuit(): string
    {
        return $this->cuit;
    }

    /**
     * FISICA o JURIDICA.
     */
    public function getTipoPersona(): ?string
    {
        return $this->tipoPersona;
    }

    public function getNombre(): ?string
    {
        return $this->nombre;
    }

    public function getApellido(): ?string
    {
        return $this->apellido;
    }

    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    /**
     * Razón social o "Apellido Nombre".
     */
    public function getNombreCompleto(): string
    {
        if ($this->razonSocial !== null && $this->razonSocial !== '') {
            return $this->razonSocial;
        }

        return trim(($this->apellido ?? '') . ' ' . ($this->nombre ?? ''));
    }

    /**
     * ACTIVO, INACTIVO, etc.
     */
    public function getEstadoClave(): ?string
    {
        return $this->estadoClave;
    }

    public function isActiva(): bool
    {
        return $this->estadoClave === 'ACTIVO';
    }

    public function getDomicilioFiscal(): ?Address
    {
        return $this->domicilioFiscal;
    }

    /**
     * @return array<int, array{id: int, descripcion: string}>
     */
    public function getImpuestos(): array
    {
        return $this->impuestos;
    }

    public function hasImpuesto(int $id): bool
    {
        foreach ($this->impuestos as $impuesto) {
            if ($impuesto['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{id: int, descripcion: string, orden: ?int}>
     */
    public function getActividades(): array
    {
        return $this->actividades;
    }

    public function isMonotributo(): bool
    {
        return $this->monotributo;
    }

    public function getCategoriaMonotributo(): ?string
    {
        return $this->categoriaMonotributo;
    }

    /**
     * Mensajes del padrón (ej. "La CUIT no tiene impuestos activos").
     *
     * @return string[]
     */
    public function getErrores(): array
    {
        return $this->errores;
    }

    /**
     * Condición frente al IVA deducida de los impuestos inscriptos ({@see CondicionIva}).
     * Devuelve null si el padrón no informa impuestos (ej. padrón A13).
     */
    public function getCondicionIva(): ?int
    {
        if (!isset($this->raw['datosGenerales'])) {
            return null;
        }
        if ($this->monotributo || $this->hasImpuesto(self::IMPUESTO_MONOTRIBUTO)) {
            return CondicionIva::MONOTRIBUTO;
        }
        if ($this->hasImpuesto(self::IMPUESTO_IVA)) {
            return CondicionIva::RESPONSABLE_INSCRIPTO;
        }
        if ($this->hasImpuesto(self::IMPUESTO_IVA_EXENTO)) {
            return CondicionIva::EXENTO;
        }
        if ($this->hasImpuesto(self::IMPUESTO_IVA_NO_ALCANZADO)) {
            return CondicionIva::NO_ALCANZADO;
        }

        return CondicionIva::CONSUMIDOR_FINAL;
    }

    /**
     * Crea el receptor del comprobante a partir de los datos del padrón.
     */
    public function toClient(): Client
    {
        return (new Client())
            ->setTipoDoc(TipoDocumento::CUIT)
            ->setNumDoc($this->cuit)
            ->setRazonSocial($this->getNombreCompleto())
            ->setCondicionIva($this->getCondicionIva())
            ->setAddress($this->domicilioFiscal);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
}
