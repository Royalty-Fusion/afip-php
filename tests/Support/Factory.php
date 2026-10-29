<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Support;

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Address;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Company;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Support\Date;

final class Factory
{
    public static function company(int $condicion = CondicionIva::RESPONSABLE_INSCRIPTO): Company
    {
        return (new Company())
            ->setCuit('20111111112')
            ->setRazonSocial('EMPRESA DE PRUEBA S.A.')
            ->setCondicionIva($condicion)
            ->setIngresosBrutos('901-123456-7')
            ->setInicioActividades(new \DateTimeImmutable('2020-01-01'))
            ->setAddress((new Address())->setDireccion('Av. Siempre Viva 742')->setLocalidad('CABA')->setProvincia('Ciudad Autónoma de Buenos Aires'));
    }

    public static function clientRi(): Client
    {
        return (new Client())
            ->setTipoDoc(TipoDocumento::CUIT)
            ->setNumDoc('30500010912')
            ->setRazonSocial('BANCO DE LA NACION ARGENTINA')
            ->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO);
    }

    public static function detail(string $desc, float $qty, float $price, ?int $iva = AlicuotaIva::IVA_21): SaleDetail
    {
        return (new SaleDetail())
            ->setCodigo('P' . substr(md5($desc), 0, 4))
            ->setDescripcion($desc)
            ->setCantidad($qty)
            ->setPrecioUnitario($price)
            ->setAlicuotaIva($iva);
    }

    public static function facturaA(): Invoice
    {
        $invoice = (new Invoice())
            ->setTipoComprobante(TipoComprobante::FACTURA_A)
            ->setPuntoVenta(1)
            ->setConcepto(Concepto::PRODUCTOS)
            ->setFechaEmision(Date::today())
            ->setCompany(self::company())
            ->setClient(self::clientRi())
            ->addDetail(self::detail('Notebook', 2, 1000))
            ->addDetail(self::detail('Libro', 3, 50, AlicuotaIva::IVA_10_5));

        return (new InvoiceCalculator())->calculate($invoice);
    }

    public static function facturaB(): Invoice
    {
        $invoice = (new Invoice())
            ->setTipoComprobante(TipoComprobante::FACTURA_B)
            ->setPuntoVenta(1)
            ->setConcepto(Concepto::PRODUCTOS)
            ->setFechaEmision(Date::today())
            ->setCompany(self::company())
            ->setClient(Client::consumidorFinal())
            ->addDetail(self::detail('Gaseosa', 1, 121)->setPrecioIncluyeIva(true));

        return (new InvoiceCalculator())->calculate($invoice);
    }

    public static function facturaC(): Invoice
    {
        $invoice = (new Invoice())
            ->setTipoComprobante(TipoComprobante::FACTURA_C)
            ->setPuntoVenta(2)
            ->setConcepto(Concepto::SERVICIOS)
            ->setFechaEmision(Date::today())
            ->setPeriodoServicio(Date::today()->modify('first day of this month'), Date::today(), Date::today()->modify('+10 days'))
            ->setCompany(self::company(CondicionIva::MONOTRIBUTO))
            ->setClient(Client::consumidorFinal())
            ->addDetail(self::detail('Consultoría', 1, 5000, null));

        return (new InvoiceCalculator())->calculate($invoice);
    }
}
