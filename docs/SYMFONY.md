# Integración con Symfony (7.4 / 6.4)

El SDK no depende de ningún framework: se registra como servicio. Configuración probada con
Symfony 7.4 y PHP 8.3, con los tickets de acceso guardados en la caché de Symfony.

## 1. Variables de entorno (`.env.local`, nunca en el repositorio)

```dotenv
AFIP_ENV=homologacion            # o produccion
AFIP_CERT_PATH=/etc/erp/afip/cert.crt
AFIP_KEY_PATH=/etc/erp/afip/private.key
AFIP_CUIT=20123456789
```

## 2. `config/services.yaml`

```yaml
services:
    # Caché PSR-16 de Symfony para los tickets de acceso (válidos 12 h).
    # Con varios servidores usá un adapter compartido (Redis, Memcached, PDO).
    afip.cache:
        class: Symfony\Component\Cache\Adapter\FilesystemAdapter
        arguments: ['afip', 0, '%kernel.cache_dir%/afip']

    afip.psr16:
        class: Symfony\Component\Cache\Psr16Cache
        arguments: ['@afip.cache']

    RoyaltyFusion\Afip\Auth\CacheTicketStorage:
        arguments: ['@afip.psr16']

    RoyaltyFusion\Afip\Auth\Certificate:
        factory: ['RoyaltyFusion\Afip\Auth\Certificate', 'fromFiles']
        arguments: ['%env(AFIP_CERT_PATH)%', '%env(AFIP_KEY_PATH)%']

    RoyaltyFusion\Afip\See:
        arguments: ['%env(AFIP_ENV)%']
        calls:
            - setCertificate: ['@RoyaltyFusion\Afip\Auth\Certificate']
            - setCuit: ['%env(AFIP_CUIT)%']
            - setTicketStorage: ['@RoyaltyFusion\Afip\Auth\CacheTicketStorage']
            # Bloqueo de numeración entre procesos (en el mismo servidor)
            - setLockDirectory: ['%kernel.project_dir%/var/afip/locks']
```

## 3. Uso en un servicio o controlador

```php
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Exception\UncertainAuthorizationException;

final class FacturacionService
{
    public function __construct(private readonly See $see)
    {
    }

    public function emitir(Invoice $invoice): void
    {
        (new InvoiceCalculator())->calculate($invoice);
        try {
            $result = $this->see->send($invoice);
            // Guardar $invoice->getNumero(), $result->getCae(), $result->getCaeVencimiento(),
            // $result->getRequestXml() y $result->getResponseXml()
        } catch (ValidationException $e) {
            // Mostrar $e->getResult()->getErrors() al usuario: no se envió nada a ARCA
        } catch (UncertainAuthorizationException $e) {
            // Guardar el número ($e->getNumeros()) y reintentar luego con el MISMO comprobante
        }
    }
}
```

## Notas

- Si la aplicación corre en varios servidores, usá un adapter de caché compartido para los tickets. Para la numeración, emití cada punto de venta desde un solo worker (por ejemplo, un consumer de Messenger) o reemplazá el bloqueo por uno distribuido.
- En PHP-FPM no uses `MemoryTicketStorage`: cada request pediría un ticket nuevo y ARCA lo rechaza con `coe.alreadyAuthenticated`.
