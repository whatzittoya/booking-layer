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

        // A name written entirely in a non-latin1 script folds away to nothing,
        // which would leave POS staff with a blank row they cannot match to a
        // guest. Fall back to the booking reference, which is always printable.
        $customerName = (string) ($reservation['guest'] ?? '');
        $foldedName   = (string) self::latin1Safe($customerName);

        if (trim($foldedName) === '' && trim($customerName) !== '') {
            $reference  = trim((string) ($reservation['reference'] ?? ''));
            $foldedName = $reference !== '' ? 'Guest ' . $reference : 'Guest';
        }

        $payload = [
            'code'           => $foldedName,
            'name'           => $foldedName,
            'notes'          => self::latin1Safe($bookerId),
            'address'        => null,
            'postcode'       => null,
            'suburb'         => ($reservation['product'] ?? '') !== ''
                ? self::latin1Safe((string) $reservation['product'])
                : null,
            'reservation_id' => $reservationId,
            // Null rather than '' — tbl_customers.created/expired are DATE
            // columns and a dateless booking would be rejected in strict mode.
            'created'        => Reservation::nullableDateTime($reservation['starts_at'] ?? null),
            'expired'        => Reservation::nullableDateTime($reservation['ends_at'] ?? null),
        ];

        if ($existingId !== null) {
            $statement = $this->pdo->prepare(
                // bill_id is cleared when this row is reassigned to a different
                // reservation — the old bill belongs to the previous booking.
                // Assignments evaluate left to right, so the CASE still sees the
                // pre-update reservation_id.
                'UPDATE tbl_customers
                 SET active         = b\'1\',
                     code           = :code,
                     name           = :name,
                     notes          = :notes,
                     address        = :address,
                     postcode       = :postcode,
                     suburb         = :suburb,
                     bill_id        = CASE
                                          WHEN reservation_id = :reservation_id_check THEN bill_id
                                          ELSE NULL
                                      END,
                     reservation_id = :reservation_id,
                     created        = :created,
                     expired        = :expired
                 WHERE id = :id'
            );

            $statement->execute($payload + [
                'id'                   => $existingId,
                'reservation_id_check' => $reservationId,
            ]);

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

    /**
     * tbl_customers is a latin1 table owned by the POS, so a value MySQL cannot
     * represent there aborts the insert:
     *
     *   1366 Incorrect string value: '\xE2\x86\x92 Le...' for column 'suburb'
     *
     * Booking Layer product titles and guest names routinely carry characters
     * outside latin1 — "Boat Transfer - Serangan → Lembongan" is one. Widening
     * the column instead would let values in that the POS itself cannot read
     * back over a latin1 connection, so fold them down here rather than there.
     *
     * MySQL's latin1 is really cp1252, which does cover the en dash, but there
     * is no reason to rely on that distinction.
     */
    private static function latin1Safe(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Characters with a sensible ASCII reading, spelled out rather than
        // left to iconv, whose //TRANSLIT output varies between platforms.
        $value = strtr($value, [
            '→' => '->',  '←' => '<-',  '↔' => '<->', '⇒' => '=>',
            '–' => '-',   '—' => '-',   '−' => '-',   '‐' => '-',
            '“' => '"',   '”' => '"',   '„' => '"',   '‘' => "'",
            '’' => "'",   '‚' => "'",   '…' => '...', '•' => '*',
            '·' => '-',   '×' => 'x',   '÷' => '/',   '™' => 'TM',
            '½' => '1/2', '¼' => '1/4', '¾' => '3/4', '°' => ' deg',
        ]);

        // Anything still outside latin1 (e.g. non-Latin guest names) is
        // transliterated where possible and dropped where not.
        $folded = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $value);

        if ($folded !== false) {
            $value = (string) iconv('ISO-8859-1', 'UTF-8', $folded);
        }

        // Belt and braces: iconv on some builds passes characters through.
        return preg_replace('/[^\x{0000}-\x{00FF}]/u', '', $value) ?? $value;
    }

    /**
     * The Booking Layer bill that POS charges for this customer are posted to.
     * Lives here rather than on tbl_reservation_b_layer because that table is a
     * mirror of currently-active reservations only — a guest's row disappears at
     * checkout, while sales closed just before checkout may still be unsent.
     */
    public function billId(int $customerId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT bill_id
             FROM tbl_customers
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute(['id' => $customerId]);

        $billId = (string) ($statement->fetchColumn() ?: '');

        return $billId !== '' ? $billId : null;
    }

    public function saveBillId(int $customerId, string $billId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tbl_customers
             SET bill_id = :bill_id
             WHERE id = :id'
        );

        $statement->execute([
            'bill_id' => $billId,
            'id'      => $customerId,
        ]);
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
