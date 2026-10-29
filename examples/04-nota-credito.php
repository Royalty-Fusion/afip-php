<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\CbteAsociado;
use RoyaltyFusion\Afip\Model\Sale\Note;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

$see = see();

$note = (new Note())
    ->setTipoComprobante(TipoComprobante::NOTA_CREDITO_A)
    ->setPuntoVenta(1)
    ->setCompany(company($see))
    ->setClient((new Client())->setTipoDoc(TipoDocumento::CUIT)->setNumDoc('30500010912')->setRazonSocial('BANCO DE LA NACION ARGENTINA')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO))
    // Comprobante que se anula/ajusta (obligatorio en notas de crédito y débito).
    ->addCbteAsociado(new CbteAsociado(TipoComprobante::FACTURA_A, 1, 125, $see->getCuit(), new DateTimeImmutable('-3 days')))
    ->addDetail((new SaleDetail())->setDescripcion('Devolución 1 notebook')->setPrecioUnitario(850000)->setAlicuotaIva(AlicuotaIva::IVA_21));

// Alternativa: $note->setAfectado($facturaOriginal);

(new InvoiceCalculator())->calculate($note);
show($see->send($note));
