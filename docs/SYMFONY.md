# Integración con Symfony (6.4 / 7.x): multi-empresa

El SDK no depende de ningún framework. En un ERP con varios establecimientos (cada uno con
su CUIT, certificado, punto de venta y ambiente), **no registres `See` como un servicio único**.
Registrá una fábrica que arme un `See` por establecimiento con `See::fromConfig()`.

Los tickets de acceso (WSAA) y los bloqueos de numeración tienen que vivir en la **base de
datos**, no en `%kernel.cache_dir%`:

- Si un despliegue borra la caché, se pierde el ticket vigente.
- Mientras siga vigente (hasta 12 h), ARCA rechaza un nuevo login con `coe.alreadyAuthenticated` y no se puede facturar.

## 1. Tabla de tickets

`PdoTicketStorage` usa una tabla. Podés crearla con una migración de Doctrine o con
`$storage->createTable()`. Para MySQL/MariaDB:

```sql
CREATE TABLE afip_tickets (
    ticket_key      VARCHAR(191) NOT NULL PRIMARY KEY,
    token           TEXT         NOT NULL,
    sign            TEXT         NOT NULL,
    service         VARCHAR(100) NOT NULL,
    generation_time VARCHAR(40)  NOT NULL,
    expiration_time VARCHAR(40)  NOT NULL,
    updated_at      VARCHAR(40)  NOT NULL
);
```

La clave del ticket incluye el ambiente, el servicio y la huella del certificado, así que
cada establecimiento y cada ambiente tienen su propio ticket.

## 2. Fábrica de clientes ARCA

```php
namespace App\Fiscal;

use Doctrine\DBAL\Connection;
use RoyaltyFusion\Afip\Auth\PdoTicketStorage;
use RoyaltyFusion\Afip\Lock\PdoNumberingLock;
use RoyaltyFusion\Afip\See;
use Psr\Log\LoggerInterface;

final class AfipClientFactory
{
    /** @var array<int, See> */
    private array $instances = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly EstablecimientoRepository $establecimientos,
        private readonly CertificadoVault $vault,          // descifra la clave guardada en la BD
        private readonly LoggerInterface $afipLogger,      // canal monolog "afip"
    ) {
    }

    public function forEstablecimiento(int $id): See
    {
        return $this->instances[$id] ??= $this->create($id);
    }

    private function create(int $id): See
    {
        $e = $this->establecimientos->get($id);
        $pdo = $this->connection->getNativeConnection(); // \PDO de Doctrine DBAL

        return See::fromConfig([
            'cert_pem' => $e->getCertificadoPem(),
            'key_pem' => $this->vault->decrypt($e->getClavePrivadaCifrada()),
            'passphrase' => $e->getPassphrase(),          // null si la clave no tiene contraseña
            'cuit' => $e->getCuit(),
            'environment' => $e->getAmbiente(),           // 'homologacion' | 'produccion'
            'ticket_storage' => new PdoTicketStorage($pdo, 'afip_tickets'),
            'numbering_lock' => new PdoNumberingLock($pdo), // GET_LOCK de MariaDB, sirve entre servidores
            'logger' => $this->afipLogger,                  // token, sign, CMS y claves se ocultan
        ]);
    }
}
```

Con un certificado `.p12`, reemplazá `cert_pem`/`key_pem` por `'pkcs12' => $contenido,
'pkcs12_password' => '...'`.

## 3. `config/services.yaml`

```yaml
services:
    App\Fiscal\AfipClientFactory:
        arguments:
            $afipLogger: '@monolog.logger.afip'
```

## 4. Uso

```php
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Exception\UncertainAuthorizationException;
use RoyaltyFusion\Afip\Exception\ValidationException;

$see = $factory->forEstablecimiento($establecimientoId);
(new InvoiceCalculator())->calculate($invoice);

try {
    $result = $see->send($invoice);
    if ($result->isSuccess()) {
        // guardar número, CAE, vencimiento, getRequestXml() y getResponseXml()
    } else {
        // rechazo de ARCA: $result->getErrorMessage(); el número quedó libre
    }
} catch (ValidationException $e) {
    // datos incompletos o inconsistentes: no se envió nada
} catch (UncertainAuthorizationException $e) {
    // estado incierto: guardar $e->toArray() junto al comprobante (número asignado y hash)
    // y dejar que el cron lo reconcilie
}
```

### Cron de reconciliación

```php
// bin/console app:afip:reconciliar
foreach ($pendientes->inciertos() as $comprobante) {
    $see = $factory->forEstablecimiento($comprobante->getEstablecimientoId());
    $invoice = $mapper->toInvoice($comprobante); // mismo número que se envió

    try {
        $result = $see->reconcile($invoice);
        if ($result !== null) {
            // ARCA lo había autorizado: guardar el CAE
        } else {
            // no existe en ARCA: reenviar el MISMO comprobante (mismo número)
            $see->send($invoice);
        }
    } catch (\RoyaltyFusion\Afip\Exception\VoucherMismatchException $e) {
        // el número está autorizado con otros datos: requiere intervención manual
    }
}
```

## Notas

- `PdoNumberingLock` usa `GET_LOCK` en MySQL/MariaDB y `pg_advisory_lock` en PostgreSQL: serializa la numeración por ambiente, CUIT, punto de venta y tipo entre todos los servidores que comparten la base.
- No uses `MemoryTicketStorage` con PHP-FPM: cada request pediría un ticket nuevo.
- Para tests del ERP, `See::setClock()` acepta un reloj PSR-20 o un callable. Así la validación usa la fecha civil que quieras.
