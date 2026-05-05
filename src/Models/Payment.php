<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Payment
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function allWithCustomerDetails(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                cs.id,
                CAST(cs.closed AS UNSIGNED) AS closed,
                cs.DATE AS payment_date,
                cs.invoice_id,
                cs.customername AS check_sales_customer_name,
                cs.customer_id,
                cs.subtotal,
                cs.discountamount,
                cs.servicechargeamount,
                cs.amount,
                CAST(cs.trobex AS UNSIGNED) AS trobex,
                cs.appsindoid,
                c.name AS customer_name,
                c.address AS room_type,
                c.suburb AS room_name,
                c.reservation_id,
                c.postcode AS property_id
             FROM check_sales cs
             INNER JOIN tbl_customers c ON c.id = cs.customer_id AND c.reservation_id IS NOT NULL
             WHERE (cs.trobex IS NULL OR CAST(cs.trobex AS UNSIGNED) = 0)
               AND EXISTS (
                   SELECT 1
                   FROM tbl_sales_lines sl
                   WHERE sl.sales_id = cs.id
                     AND sl.type = 3
                     AND CAST(sl.voidPayment AS UNSIGNED) = 0
                     AND sl.description = \'charge to room\'
               )
             ORDER BY cs.DATE DESC, cs.id DESC
             LIMIT 200'
        );

        return $statement->fetchAll();
    }

    public function allUnsentClosed(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                cs.id,
                cs.invoice_id,
                cs.customer_id,
                cs.amount,
                c.name AS customer_name,
                c.id AS customer_table_id,
                c.reservation_id,
                c.postcode AS property_id
             FROM check_sales cs
             INNER JOIN tbl_customers c ON c.id = cs.customer_id AND c.reservation_id IS NOT NULL
             WHERE (cs.trobex IS NULL OR CAST(cs.trobex AS UNSIGNED) = 0)
               AND CAST(cs.closed AS UNSIGNED) = 1
               AND EXISTS (
                   SELECT 1
                   FROM tbl_sales_lines sl
                   WHERE sl.sales_id = cs.id
                     AND sl.type = 3
                     AND CAST(sl.voidPayment AS UNSIGNED) = 0
                     AND sl.description = \'charge to room\'
               )
             ORDER BY cs.DATE ASC, cs.id ASC'
        );

        return $statement->fetchAll();
    }

    public function findById(int $paymentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                cs.id,
                CAST(cs.closed AS UNSIGNED) AS closed,
                cs.DATE AS payment_date,
                cs.invoice_id,
                cs.customername AS check_sales_customer_name,
                cs.customer_id,
                cs.subtotal,
                cs.discountamount,
                cs.servicechargeamount,
                cs.amount,
                CAST(cs.trobex AS UNSIGNED) AS trobex,
                cs.appsindoid,
                c.id AS customer_table_id,
                c.name AS customer_name,
                c.address AS room_type,
                c.suburb AS room_name,
                c.reservation_id,
                c.postcode AS property_id
             FROM check_sales cs
             LEFT JOIN tbl_customers c ON c.id = cs.customer_id
             WHERE cs.id = :id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $paymentId,
        ]);

        $payment = $statement->fetch();

        return $payment ?: null;
    }

    public function markPostedToBookingLayer(int $paymentId, ?int $customerTableId): void
    {
        $this->pdo->beginTransaction();

        try {
            $salesStatement = $this->pdo->prepare(
                'UPDATE tbl_sales
                 SET trobex = b\'1\'
                 WHERE id = :id'
            );

            $salesStatement->execute([
                'id' => $paymentId,
            ]);

            if ($customerTableId !== null) {
                $customerStatement = $this->pdo->prepare(
                    'UPDATE tbl_customers
                     SET active = b\'0\'
                     WHERE id = :id'
                );

                $customerStatement->execute([
                    'id' => $customerTableId,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }
}
