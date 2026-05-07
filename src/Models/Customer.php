<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Customer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsertFromReservation(array $reservation): void
    {
        $reservationId = (string) ($reservation['reservation_id'] ?? '');
        $bookerId      = (string) ($reservation['booker_id'] ?? '');

        $existingId = $this->findExistingIdByBookerId($bookerId, $reservationId)
            ?? $this->findExistingIdByReservationId($reservationId);
        $customerName = (string) ($reservation['guest'] ?? '');

        $payload = [
            'code'           => $customerName,
            'name'           => $customerName,
            'notes'          => $bookerId,
            'address'        => null,
            'postcode'       => null,
            'suburb'         => ($reservation['product'] ?? '') !== ''
                ? (string) $reservation['product']
                : null,
            'reservation_id' => $reservationId,
            'created'        => (string) ($reservation['starts_at'] ?? ''),
            'expired'        => (string) ($reservation['ends_at'] ?? ''),
        ];

        if ($existingId !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE tbl_customers
                 SET active         = b\'1\',
                     code           = :code,
                     name           = :name,
                     notes          = :notes,
                     address        = :address,
                     postcode       = :postcode,
                     suburb         = :suburb,
                     reservation_id = :reservation_id,
                     created        = :created,
                     expired        = :expired
                 WHERE id = :id'
            );

            $statement->execute($payload + ['id' => $existingId]);

            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO tbl_customers (
                active,
                code,
                name,
                notes,
                address,
                postcode,
                suburb,
                reservation_id,
                created,
                expired
            ) VALUES (
                b\'1\',
                :code,
                :name,
                :notes,
                :address,
                :postcode,
                :suburb,
                :reservation_id,
                :created,
                :expired
            )'
        );

        $statement->execute($payload);
    }

    private function findExistingIdByReservationId(string $reservationId): ?int
    {
        if ($reservationId === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM tbl_customers
             WHERE reservation_id = :reservation_id
             ORDER BY id ASC
             LIMIT 1'
        );

        $statement->execute(['reservation_id' => $reservationId]);

        $row = $statement->fetch();

        return $row !== false ? (int) $row['id'] : null;
    }

    private function findExistingIdByBookerId(string $bookerId, string $reservationId): ?int
    {
        if ($bookerId === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM tbl_customers
             WHERE notes = :booker_id
             ORDER BY
                 CASE
                     WHEN reservation_id = :reservation_id THEN 0
                     WHEN reservation_id IS NULL OR reservation_id = \'\' OR reservation_id = \'null\' THEN 1
                     ELSE 2
                 END,
                 id DESC
             LIMIT 1'
        );

        $statement->execute([
            'booker_id'      => $bookerId,
            'reservation_id' => $reservationId,
        ]);

        $row = $statement->fetch();

        return $row !== false ? (int) $row['id'] : null;
    }
}
