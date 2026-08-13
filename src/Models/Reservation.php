<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Reservation
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function latestPulledAt(): ?string
    {
        $statement = $this->pdo->query(
            'SELECT MAX(updated_at) AS latest_pulled_at
             FROM tbl_reservation_b_layer'
        );

        $row = $statement->fetch();
        $value = $row['latest_pulled_at'] ?? null;

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function customerCandidates(string $search = ''): array
    {
        $sql = 'SELECT
                    r.id AS reservation_id,
                    r.reference,
                    r.guest,
                    r.status,
                    r.starts_at,
                    r.ends_at,
                    r.product,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM tbl_customers c
                            WHERE c.reservation_id = r.id
                              AND CAST(c.active AS UNSIGNED) = 1
                        ) THEN 1
                        ELSE 0
                    END AS customer_added
                FROM tbl_reservation_b_layer r';
        $parameters = [];

        if ($search !== '') {
            $sql .= ' WHERE r.guest LIKE :search';
            $parameters['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY r.updated_at DESC, r.starts_at DESC LIMIT 100';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function findByReservationId(string $reservationId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id AS reservation_id,
                reference,
                guest,
                guest_gender,
                guest_age,
                guest_email,
                status,
                starts_at,
                ends_at,
                final_price_excl_tax,
                final_price_incl_tax,
                duration_in_days,
                duration_in_nights,
                product,
                booker_id
             FROM tbl_reservation_b_layer
             WHERE id = :id
             LIMIT 1'
        );

        $statement->execute(['id' => $reservationId]);

        $detail = $statement->fetch();

        return $detail ?: null;
    }

    public function allCritical(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                r.id AS reservation_id,
                r.reference,
                r.guest,
                r.status,
                r.starts_at,
                r.ends_at,
                r.product,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM tbl_customers c
                        WHERE c.reservation_id = r.id
                          AND CAST(c.active AS UNSIGNED) = 1
                    ) THEN 1
                    ELSE 0
                END AS customer_added
             FROM tbl_reservation_b_layer r
             ORDER BY r.starts_at DESC, r.updated_at DESC'
        );

        return $statement->fetchAll();
    }

    public function filterActiveGuests(array $reservations): array
    {
        return array_values(array_filter($reservations, fn($reservation) => $this->isActiveGuestReservation($reservation)));
    }

    public function deactivateCheckedOutCustomers(array $reservations): int
    {
        $reservationIds = array_values(array_unique(array_filter(array_map(
            fn($reservation) => $this->isCheckedOutReservation($reservation)
                ? (string) ($reservation['id'] ?? '')
                : '',
            $reservations,
        ))));

        if ($reservationIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE tbl_customers
             SET active = b'0'
             WHERE reservation_id IN ($placeholders)
               AND CAST(active AS UNSIGNED) = 1"
        );

        $statement->execute($reservationIds);

        return $statement->rowCount();
    }

    /**
     * Each item in $reservations must be a raw Booking Layer booking object
     * with an optional '_products' key (array of backoffice_title strings)
     * already merged in by the caller.
     */
    public function upsertMany(array $reservations): int
    {
        if ($reservations === []) {
            $this->pdo->exec('DELETE FROM tbl_reservation_b_layer');

            return 0;
        }

        $ids = array_values(array_filter(
            array_map(fn($r) => (string) ($r['id'] ?? ''), $reservations),
            fn($id) => $id !== '',
        ));

        $statement = $this->pdo->prepare(
            'INSERT INTO tbl_reservation_b_layer (
                id,
                reference,
                starts_at,
                ends_at,
                status,
                booker_id,
                guest,
                guest_gender,
                guest_age,
                guest_email,
                final_price_excl_tax,
                final_price_incl_tax,
                duration_in_days,
                duration_in_nights,
                product,
                updated_at
            ) VALUES (
                :id,
                :reference,
                :starts_at,
                :ends_at,
                :status,
                :booker_id,
                :guest,
                :guest_gender,
                :guest_age,
                :guest_email,
                :final_price_excl_tax,
                :final_price_incl_tax,
                :duration_in_days,
                :duration_in_nights,
                :product,
                CURRENT_TIMESTAMP
            ) ON DUPLICATE KEY UPDATE
                reference            = VALUES(reference),
                starts_at            = VALUES(starts_at),
                ends_at              = VALUES(ends_at),
                status               = VALUES(status),
                booker_id            = VALUES(booker_id),
                guest                = VALUES(guest),
                guest_gender         = VALUES(guest_gender),
                guest_age            = VALUES(guest_age),
                guest_email          = VALUES(guest_email),
                final_price_excl_tax = VALUES(final_price_excl_tax),
                final_price_incl_tax = VALUES(final_price_incl_tax),
                duration_in_days     = VALUES(duration_in_days),
                duration_in_nights   = VALUES(duration_in_nights),
                product              = VALUES(product),
                updated_at           = CURRENT_TIMESTAMP'
        );

        $this->pdo->beginTransaction();

        try {
            foreach ($reservations as $reservation) {
                $firstGuest = isset($reservation['guests'][0]['person'])
                    && is_array($reservation['guests'][0]['person'])
                    ? $reservation['guests'][0]['person']
                    : [];

                $guestName = trim(
                    trim((string) ($firstGuest['first_name'] ?? '')) . ' ' .
                    trim((string) ($firstGuest['last_name'] ?? ''))
                );

                $products = isset($reservation['_products']) && is_array($reservation['_products'])
                    ? implode('; ', $reservation['_products'])
                    : null;

                $statement->execute([
                    'id'                   => (string) ($reservation['id'] ?? ''),
                    'reference'            => (string) ($reservation['reference'] ?? ''),
                    'starts_at'            => self::nullableDateTime($reservation['starts_at'] ?? null),
                    'ends_at'              => self::nullableDateTime($reservation['ends_at'] ?? null),
                    'status'               => (string) ($reservation['status'] ?? ''),
                    'booker_id'            => (string) ($reservation['booker_id'] ?? ''),
                    'guest'                => $guestName,
                    'guest_gender'         => (string) ($firstGuest['gender'] ?? ''),
                    'guest_age'            => isset($firstGuest['age']) && $firstGuest['age'] !== null
                        ? (int) $firstGuest['age']
                        : null,
                    'guest_email'          => (string) ($firstGuest['email'] ?? ''),
                    'final_price_excl_tax' => (float) ($reservation['final_price_excl_tax'] ?? 0),
                    'final_price_incl_tax' => (float) ($reservation['final_price_incl_tax'] ?? 0),
                    'duration_in_days'     => (int) ($reservation['duration_in_days'] ?? 0),
                    'duration_in_nights'   => (int) ($reservation['duration_in_nights'] ?? 0),
                    'product'              => $products,
                ]);
            }

            // Remove local rows no longer in the pulled set
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $this->pdo->prepare(
                    "DELETE FROM tbl_reservation_b_layer WHERE id NOT IN ($placeholders)"
                )->execute($ids);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return count($reservations);
    }

    /**
     * Booking Layer allows dateless bookings, which arrive with a null
     * starts_at/ends_at. Casting those to '' makes MySQL reject the row under
     * STRICT_TRANS_TABLES and aborts the whole pull, so keep them null.
     */
    public static function nullableDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isActiveGuestReservation(mixed $reservation): bool
    {
        if (!is_array($reservation)) {
            return false;
        }

        $checkedInStatuses = ['somebody', 'everybody'];
        $checkedOutStatuses = [null, '', 'nobody'];

        $checkInStatus = $this->normalizeStatus($reservation['check_in_status'] ?? null);
        $webCheckInStatus = $this->normalizeStatus($reservation['web_check_in_status'] ?? null);
        $checkOutStatus = $this->normalizeStatus($reservation['check_out_status'] ?? null);

        return (
            in_array($checkInStatus, $checkedInStatuses, true)
            || in_array($webCheckInStatus, $checkedInStatuses, true)
        ) && in_array($checkOutStatus, $checkedOutStatuses, true);
    }

    private function isCheckedOutReservation(mixed $reservation): bool
    {
        if (!is_array($reservation)) {
            return false;
        }

        return in_array(
            $this->normalizeStatus($reservation['check_out_status'] ?? null),
            ['everybody', 'every body'],
            true
        );
    }

    private function normalizeStatus(mixed $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return strtolower(trim((string) $status));
    }
}
