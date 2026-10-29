<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Calculator;

use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\Iva;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Calcula los importes que exige WSFEv1 a partir de los ítems y tributos del comprobante:
 * ImpNeto, ImpIVA, ImpOpEx, ImpTotConc, ImpTrib, ImpTotal y los subtotales por alícuota.
 *
 * Los importes calculados cumplen las reglas de ARCA:
 *  - ImpTotal = ImpTotConc + ImpNeto + ImpOpEx + ImpTrib + ImpIVA
 *  - Σ AlicIva.BaseImp = ImpNeto y Σ AlicIva.Importe = ImpIVA
 *  - AlicIva.Importe = BaseImp × alícuota (redondeo a 2 decimales)
 */
final class InvoiceCalculator
{
    public function calculate(Invoice $invoice): Invoice
    {
        $details = $invoice->getDetails();

        if ($details !== []) {
            if (TipoComprobante::sinIva($invoice->getTipoComprobante())) {
                $this->calculateWithoutVat($invoice, $details);
            } else {
                $this->calculateWithVat($invoice, $details);
            }
        } elseif ($invoice->getIvas() !== []) {
            $base = 0.0;
            $iva = 0.0;
            foreach ($invoice->getIvas() as $line) {
                $base += $line->getBaseImp();
                $iva += $line->getImporte();
            }
            $invoice->setImpNeto(Num::round($base));
            $invoice->setImpIva(Num::round($iva));
        }

        $tributos = 0.0;
        foreach ($invoice->getTributos() as $tributo) {
            $tributos += $tributo->getImporte();
        }
        $invoice->setImpTributos(Num::round($tributos));

        $invoice->setImpTotal(Num::round(
            $invoice->getImpNoGravado()
            + $invoice->getImpNeto()
            + $invoice->getImpExento()
            + $invoice->getImpTributos()
            + $invoice->getImpIva()
        ));

        return $invoice;
    }

    /**
     * Clase C / bienes usados: no se discrimina IVA, ImpNeto es el subtotal.
     *
     * @param SaleDetail[] $details
     */
    private function calculateWithoutVat(Invoice $invoice, array $details): void
    {
        $total = 0.0;
        foreach ($details as $detail) {
            $total += $detail->getSubtotal();
        }
        $invoice->setIvas([]);
        $invoice->setImpIva(0.0);
        $invoice->setImpExento(0.0);
        $invoice->setImpNoGravado(0.0);
        $invoice->setImpNeto(Num::round($total));
    }

    /**
     * @param SaleDetail[] $details
     */
    private function calculateWithVat(Invoice $invoice, array $details): void
    {
        /** @var array<int, array{net: float, gross: float}> $groups */
        $groups = [];
        $exento = 0.0;
        $noGravado = 0.0;

        foreach ($details as $index => $detail) {
            $subtotal = $detail->getSubtotal();
            switch ($detail->getTratamientoIva()) {
                case SaleDetail::EXENTO:
                    $exento += $subtotal;
                    break;
                case SaleDetail::NO_GRAVADO:
                    $noGravado += $subtotal;
                    break;
                default:
                    $id = $detail->getAlicuotaIva();
                    if ($id === null || !AlicuotaIva::exists($id)) {
                        throw new \InvalidArgumentException(sprintf(
                            'El ítem #%d ("%s") está gravado pero no tiene una alícuota de IVA válida.',
                            $index + 1,
                            $detail->getDescripcion()
                        ));
                    }
                    $groups[$id] ??= ['net' => 0.0, 'gross' => 0.0];
                    if ($detail->isPrecioIncluyeIva()) {
                        $groups[$id]['gross'] += $subtotal;
                    } else {
                        $groups[$id]['net'] += $subtotal;
                    }
            }
        }

        ksort($groups);
        $ivas = [];
        $totalBase = 0.0;
        $totalIva = 0.0;
        foreach ($groups as $id => $group) {
            $rate = AlicuotaIva::rate($id) / 100;
            if (Num::round($group['gross'], 6) == 0.0) {
                $base = Num::round($group['net']);
                $iva = Num::round($base * $rate);
            } else {
                // Precio final con IVA incluido: se respeta el total cobrado y se desagrega.
                $target = Num::round($group['net'] * (1 + $rate) + $group['gross']);
                $base = Num::round($target / (1 + $rate));
                $iva = Num::round($target - $base);
            }
            if ($base == 0.0 && $iva == 0.0) {
                continue;
            }
            $ivas[] = new Iva($id, $base, $iva);
            $totalBase += $base;
            $totalIva += $iva;
        }

        $invoice->setIvas($ivas);
        $invoice->setImpNeto(Num::round($totalBase));
        $invoice->setImpIva(Num::round($totalIva));
        $invoice->setImpExento(Num::round($exento));
        $invoice->setImpNoGravado(Num::round($noGravado));
    }
}
