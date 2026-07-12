<?php

declare(strict_types=1);

namespace Controllers;

use Support\Auth;
use Support\Database;
use Support\Response;

final class TenantController
{
    public function overview(): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);

        $monthStart = date('Y-m-01T00:00:00.000\Z');

        $upcoming = (int) $db->query(
            "SELECT COUNT(*) FROM bookings WHERE status IN ('pending','accepted')"
        )->fetchColumn();

        $monthlyIntakes = $db->prepare('SELECT COUNT(*) FROM bookings WHERE created_at >= ?');
        $monthlyIntakes->execute([$monthStart]);
        $monthlyIntakeCount = (int) $monthlyIntakes->fetchColumn();

        $newCustomers = $db->prepare('SELECT COUNT(*) FROM customers WHERE created_at >= ?');
        $newCustomers->execute([$monthStart]);
        $newCustomerCount = (int) $newCustomers->fetchColumn();

        $recent = $db->query(
            "SELECT bookings.id, bookings.status, bookings.scheduled_at, bookings.created_at,
                    customers.name AS customer_name, intake_forms.name AS form_name
             FROM bookings
             JOIN customers ON customers.id = bookings.customer_id
             JOIN intake_forms ON intake_forms.id = bookings.form_id
             ORDER BY bookings.created_at DESC
             LIMIT 8"
        )->fetchAll();

        Response::json([
            'business_name_hint' => $claims['subdomain'],
            'upcoming_bookings' => $upcoming,
            'monthly_intake_forms' => $monthlyIntakeCount,
            'new_customers_this_month' => $newCustomerCount,
            'recent_bookings' => $recent,
        ]);
    }
}
