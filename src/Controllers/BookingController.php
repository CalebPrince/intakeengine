<?php

declare(strict_types=1);

namespace Controllers;

use Support\Auth;
use Support\Database;
use Support\Response;

final class BookingController
{
    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public function index(): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);

        $status = $_GET['status'] ?? null;
        $sql = "SELECT bookings.id, bookings.status, bookings.scheduled_at, bookings.created_at,
                       customers.name AS customer_name, customers.email AS customer_email,
                       intake_forms.name AS form_name
                FROM bookings
                JOIN customers ON customers.id = bookings.customer_id
                JOIN intake_forms ON intake_forms.id = bookings.form_id";
        $params = [];
        if ($status) {
            $sql .= ' WHERE bookings.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY bookings.created_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Response::json(['bookings' => $stmt->fetchAll()]);
    }

    public function show(string $id): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);

        $stmt = $db->prepare(
            "SELECT bookings.*, customers.name AS customer_name, customers.email AS customer_email,
                    customers.phone AS customer_phone, intake_forms.name AS form_name
             FROM bookings
             JOIN customers ON customers.id = bookings.customer_id
             JOIN intake_forms ON intake_forms.id = bookings.form_id
             WHERE bookings.id = ?"
        );
        $stmt->execute([$id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            Response::error('Booking not found.', 404);
        }

        $responses = json_decode($booking['responses_json'], true) ?: [];
        $fieldsStmt = $db->prepare('SELECT id, label FROM form_fields WHERE form_id = ?');
        $fieldsStmt->execute([$booking['form_id']]);
        $labels = array_column($fieldsStmt->fetchAll(), 'label', 'id');

        $booking['responses'] = [];
        foreach ($responses as $fieldId => $value) {
            $booking['responses'][] = [
                'field_id' => $fieldId,
                'label' => $labels[$fieldId] ?? "Field #{$fieldId}",
                'value' => $value,
            ];
        }
        unset($booking['responses_json']);

        Response::json($booking);
    }

    public function update(string $id): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $body = $this->body();

        $allowedStatuses = ['pending', 'accepted', 'rescheduled', 'cancelled', 'completed'];
        if (isset($body['status']) && !in_array($body['status'], $allowedStatuses, true)) {
            Response::error('Invalid status.', 422);
        }

        $stmt = $db->prepare('SELECT id FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Booking not found.', 404);
        }

        $db->prepare(
            "UPDATE bookings SET
                status = COALESCE(?, status),
                scheduled_at = COALESCE(?, scheduled_at),
                updated_at = strftime('%Y-%m-%dT%H:%M:%fZ','now')
             WHERE id = ?"
        )->execute([
            $body['status'] ?? null,
            $body['scheduled_at'] ?? null,
            $id,
        ]);

        Response::json(['ok' => true]);
    }

    /**
     * Public, unauthenticated intake submission. Lives on the tenant's own
     * subdomain so responses land only in that tenant's isolated database.
     */
    public function submit(): void
    {
        $subdomain = Database::currentSubdomain();
        if (!$subdomain || !Database::tenantDbExists($subdomain)) {
            Response::error('Unknown workspace.', 404);
        }
        $db = Database::forSubdomain($subdomain);
        $body = $this->body();

        $formId = (int) ($body['form_id'] ?? 0);
        $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
        $responses = is_array($body['responses'] ?? null) ? $body['responses'] : [];
        $name = trim((string) ($customer['name'] ?? ''));
        $email = trim((string) ($customer['email'] ?? ''));

        $formStmt = $db->prepare('SELECT * FROM intake_forms WHERE id = ? AND is_active = 1');
        $formStmt->execute([$formId]);
        $form = $formStmt->fetch();
        if (!$form) {
            Response::error('This form is not available.', 404);
        }
        if ($name === '') {
            Response::error('Your name is required.', 422);
        }

        $fieldsStmt = $db->prepare('SELECT * FROM form_fields WHERE form_id = ?');
        $fieldsStmt->execute([$formId]);
        foreach ($fieldsStmt->fetchAll() as $field) {
            $value = $responses[$field['id']] ?? null;
            if ((int) $field['is_required'] === 1 && ($value === null || $value === '')) {
                Response::error("\"{$field['label']}\" is required.", 422);
            }
        }

        $customerStmt = $db->prepare('SELECT id FROM customers WHERE email = ? AND email IS NOT NULL AND email != \'\'');
        $customerStmt->execute([$email]);
        $existingCustomer = $email !== '' ? $customerStmt->fetch() : false;

        if ($existingCustomer) {
            $customerId = (int) $existingCustomer['id'];
        } else {
            $db->prepare('INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)')
                ->execute([$name, $email ?: null, $customer['phone'] ?? null]);
            $customerId = (int) $db->lastInsertId();
        }

        $db->prepare(
            'INSERT INTO bookings (form_id, customer_id, status, scheduled_at, responses_json) VALUES (?, ?, \'pending\', ?, ?)'
        )->execute([$formId, $customerId, $body['scheduled_at'] ?? null, json_encode($responses)]);

        Response::json(['ok' => true, 'booking_id' => (int) $db->lastInsertId()], 201);
    }
}
