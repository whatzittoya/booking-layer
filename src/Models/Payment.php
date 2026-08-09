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
            // check_sales is a view joining tbl_sales to tbl_sales_lines, so a
            // sale with more than one "charge to room" line appears once per
            // line. Grouping collapses it back to one row and sums what was
            // actually charged to the room.
            'SELECT
                cs.id,
                MAX(CAST(cs.closed AS UNSIGNED)) AS closed,
                MAX(cs.DATE) AS payment_date,
                MAX(cs.invoice_id) AS invoice_id,
                MAX(cs.customername) AS check_sales_customer_name,
                MAX(cs.customer_id) AS customer_id,
                MAX(cs.subtotal) AS subtotal,
                MAX(cs.discountamount) AS discountamount,
                MAX(cs.servicechargeamount) AS servicechargeamount,
                SUM(cs.amount) AS amount,
                MAX(CAST(cs.trobex AS UNSIGNED)) AS trobex,
                MAX(cs.appsindoid) AS appsindoid,
                MAX(c.name) AS customer_name,
                MAX(c.address) AS room_type,
                MAX(c.suburb) AS room_name,
                MAX(c.reservation_id) AS reservation_id,
                MAX(c.postcode) AS property_id
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
             GROUP BY cs.id
             ORDER BY payment_date DESC, cs.id DESC
             LIMIT 200'
        );

        return $statement->fetchAll();
    }

    public function allUnsentClosed(): array
    {
        $statement = $this->pdo->query(
            // Grouped for the same reason as allWithCustomerDetails(): without
            // it a split "charge to room" check would be sent to Booking Layer
            // once per payment line.
            'SELECT
                cs.id,
                MAX(cs.invoice_id) AS invoice_id,
                MAX(cs.customer_id) AS customer_id,
                MAX(cs.subtotal) AS subtotal,
                MAX(cs.discountamount) AS discountamount,
                MAX(cs.servicechargeamount) AS servicechargeamount,
                SUM(cs.amount) AS amount,
                MAX(c.name) AS customer_name,
                MAX(c.id) AS customer_table_id,
                MAX(c.reservation_id) AS reservation_id,
                MAX(cs.DATE) AS payment_date
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
             GROUP BY cs.id
             ORDER BY payment_date ASC, cs.id ASC'
        );

        return $statement->fetchAll();
    }

    public function findById(int $paymentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                cs.id,
                MAX(CAST(cs.closed AS UNSIGNED)) AS closed,
                MAX(cs.DATE) AS payment_date,
                MAX(cs.invoice_id) AS invoice_id,
                MAX(cs.customername) AS check_sales_customer_name,
                MAX(cs.customer_id) AS customer_id,
                MAX(cs.subtotal) AS subtotal,
                MAX(cs.discountamount) AS discountamount,
                MAX(cs.servicechargeamount) AS servicechargeamount,
                SUM(cs.amount) AS amount,
                MAX(CAST(cs.trobex AS UNSIGNED)) AS trobex,
                MAX(cs.appsindoid) AS appsindoid,
                MAX(c.id) AS customer_table_id,
                MAX(c.name) AS customer_name,
                MAX(c.address) AS room_type,
                MAX(c.suburb) AS room_name,
                MAX(c.reservation_id) AS reservation_id,
                MAX(c.postcode) AS property_id
             FROM check_sales cs
             LEFT JOIN tbl_customers c ON c.id = cs.customer_id
             WHERE cs.id = :id
             GROUP BY cs.id
             LIMIT 1'
        );

        $statement->execute([
            'id' => $paymentId,
        ]);

        $payment = $statement->fetch();

        return $payment ?: null;
    }

    public function markPostedToBookingLayer(int $paymentId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tbl_sales
             SET trobex = b\'1\'
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $paymentId,
        ]);
    }
}
