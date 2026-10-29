<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RoyaltyFusion\Afip\Auth\PdoTicketStorage;
use RoyaltyFusion\Afip\Lock\PdoNumberingLock;
use RoyaltyFusion\Afip\See;

/*
 * Multi-empresa: el certificado y la clave privada se guardan en la base de datos (la clave
 * cifrada), igual que los tickets de acceso y el bloqueo de numeración.
 */
$pdo = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'erp', (string) getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare('SELECT cuit, ambiente, certificado_pem, clave_cifrada, passphrase FROM establecimientos WHERE id = ?');
$stmt->execute([(int) ($argv[1] ?? 1)]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$clavePem = openssl_decrypt($row['clave_cifrada'], 'aes-256-gcm', (string) getenv('ERP_KEY')); // usar el vault del ERP

$see = See::fromConfig([
    'cert_pem' => $row['certificado_pem'],
    'key_pem' => $clavePem,
    'passphrase' => $row['passphrase'] ?: null,
    'cuit' => $row['cuit'],
    'environment' => $row['ambiente'],                       // 'homologacion' | 'produccion'
    'ticket_storage' => new PdoTicketStorage($pdo),          // tabla afip_tickets (ver docs/SYMFONY.md)
    'numbering_lock' => new PdoNumberingLock($pdo),          // GET_LOCK de MariaDB
]);

echo 'Último Factura B: ', $see->getWsfe()->getLastVoucher(1, 6), PHP_EOL;
