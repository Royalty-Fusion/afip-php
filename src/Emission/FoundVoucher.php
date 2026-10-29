<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Emission;

/**
 * Comprobante ya autorizado, normalizado entre servicios (para recuperación y controles).
 *
 * @internal
 */
final class FoundVoucher
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $numero,
        public readonly ?string $cae,
        public readonly ?\DateTimeImmutable $vencimiento,
        public readonly float $total,
        public readonly int $docTipo,
        public readonly string $docNro,
        public readonly ?\DateTimeImmutable $fecha,
        public readonly array $raw = []
    ) {
    }
}
