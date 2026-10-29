<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Reloj inyectable: acepta un reloj PSR-20 (cualquier objeto con now()) o un callable que
 * devuelva un DateTimeInterface. Sin reloj, usa la hora actual de Argentina.
 */
final class Clock
{
    /** @var callable(): \DateTimeInterface */
    private $now;

    public function __construct(callable|object|null $clock = null)
    {
        if ($clock === null) {
            $this->now = static fn (): \DateTimeInterface => Date::now();
        } elseif (is_callable($clock)) {
            $this->now = $clock;
        } elseif (method_exists($clock, 'now')) {
            $this->now = static fn (): \DateTimeInterface => $clock->now();
        } else {
            throw new \InvalidArgumentException('El reloj debe ser un callable o un objeto con now() (PSR-20).');
        }
    }

    public function now(): \DateTimeImmutable
    {
        $now = ($this->now)();

        return \DateTimeImmutable::createFromInterface($now)->setTimezone(Date::timezone());
    }

    /**
     * Fecha civil de hoy en Argentina.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }
}
