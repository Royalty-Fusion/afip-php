<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

use RoyaltyFusion\Afip\Support\Date;

/**
 * Ticket de Acceso (TA) devuelto por el WSAA. Tiene una vigencia de 12 horas y debe
 * reutilizarse: ARCA rechaza un nuevo login mientras exista un TA vigente
 * (coe.alreadyAuthenticated).
 */
final class AccessTicket
{
    public function __construct(
        private readonly string $token,
        private readonly string $sign,
        private readonly \DateTimeImmutable $generationTime,
        private readonly \DateTimeImmutable $expirationTime,
        private readonly string $service = ''
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getSign(): string
    {
        return $this->sign;
    }

    public function getGenerationTime(): \DateTimeImmutable
    {
        return $this->generationTime;
    }

    public function getExpirationTime(): \DateTimeImmutable
    {
        return $this->expirationTime;
    }

    public function getService(): string
    {
        return $this->service;
    }

    /**
     * @param int $marginSeconds margen de seguridad antes del vencimiento
     */
    public function isValid(int $marginSeconds = 120, ?\DateTimeInterface $now = null): bool
    {
        $now ??= Date::now();

        return $this->expirationTime->getTimestamp() - $marginSeconds > $now->getTimestamp();
    }

    /**
     * Interpreta el XML loginTicketResponse.
     */
    public static function fromXml(string $xml, string $service = ''): self
    {
        $previous = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($sx === false || !isset($sx->credentials->token, $sx->credentials->sign)) {
            throw new \RuntimeException('loginTicketResponse inválido.');
        }

        return new self(
            trim((string) $sx->credentials->token),
            trim((string) $sx->credentials->sign),
            new \DateTimeImmutable(trim((string) $sx->header->generationTime)),
            new \DateTimeImmutable(trim((string) $sx->header->expirationTime)),
            $service
        );
    }

    /**
     * @return array{token: string, sign: string, generationTime: string, expirationTime: string, service: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'sign' => $this->sign,
            'generationTime' => $this->generationTime->format(DATE_ATOM),
            'expirationTime' => $this->expirationTime->format(DATE_ATOM),
            'service' => $this->service,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['token'],
            (string) $data['sign'],
            new \DateTimeImmutable((string) $data['generationTime']),
            new \DateTimeImmutable((string) $data['expirationTime']),
            (string) ($data['service'] ?? '')
        );
    }
}
