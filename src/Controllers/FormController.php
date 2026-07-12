<?php

declare(strict_types=1);

namespace Controllers;

use Support\Auth;
use Support\Database;
use Support\Response;

final class FormController
{
    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function fieldsForForm(\PDO $db, int $formId): array
    {
        $stmt = $db->prepare('SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_index ASC, id ASC');
        $stmt->execute([$formId]);
        $fields = $stmt->fetchAll();

        foreach ($fields as &$field) {
            $field['options'] = $field['options_json'] ? json_decode($field['options_json'], true) : [];
            unset($field['options_json']);
        }

        return $fields;
    }

    public function index(): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);

        $forms = $db->query(
            'SELECT intake_forms.*, (SELECT COUNT(*) FROM form_fields WHERE form_fields.form_id = intake_forms.id) AS field_count
             FROM intake_forms ORDER BY created_at DESC'
        )->fetchAll();

        Response::json(['forms' => $forms]);
    }

    public function create(): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $body = $this->body();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Response::error('Form name is required.', 422);
        }

        $db->prepare('INSERT INTO intake_forms (name, description) VALUES (?, ?)')
            ->execute([$name, (string) ($body['description'] ?? '')]);

        Response::json(['ok' => true, 'id' => (int) $db->lastInsertId()], 201);
    }

    public function show(string $id): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);

        $stmt = $db->prepare('SELECT * FROM intake_forms WHERE id = ?');
        $stmt->execute([$id]);
        $form = $stmt->fetch();
        if (!$form) {
            Response::error('Form not found.', 404);
        }

        $form['fields'] = $this->fieldsForForm($db, (int) $id);
        Response::json($form);
    }

    public function update(string $id): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $body = $this->body();

        $stmt = $db->prepare('SELECT * FROM intake_forms WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Form not found.', 404);
        }

        $db->prepare('UPDATE intake_forms SET name = COALESCE(?, name), description = COALESCE(?, description), is_active = COALESCE(?, is_active), updated_at = strftime(\'%Y-%m-%dT%H:%M:%fZ\',\'now\') WHERE id = ?')
            ->execute([
                isset($body['name']) ? trim((string) $body['name']) : null,
                $body['description'] ?? null,
                isset($body['is_active']) ? (int) (bool) $body['is_active'] : null,
                $id,
            ]);

        Response::json(['ok' => true]);
    }

    public function delete(string $id): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $db->prepare('DELETE FROM intake_forms WHERE id = ?')->execute([$id]);
        Response::json(['ok' => true]);
    }

    /** Enforces the subscription tier's max_custom_fields cap. */
    private function assertFieldQuota(array $claims, \PDO $tenantDb): void
    {
        $central = Database::central();
        $stmt = $central->prepare(
            'SELECT subscription_tiers.max_custom_fields FROM tenants
             JOIN subscription_tiers ON subscription_tiers.id = tenants.tier_id
             WHERE tenants.id = ?'
        );
        $stmt->execute([$claims['tenant_id']]);
        $max = $stmt->fetchColumn();

        if ($max === false || (int) $max === -1) {
            return;
        }

        $count = (int) $tenantDb->query('SELECT COUNT(*) FROM form_fields')->fetchColumn();
        if ($count >= (int) $max) {
            Response::error("Your plan allows up to {$max} custom fields. Upgrade to Pro for unlimited fields.", 403);
        }
    }

    public function addField(string $formId): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $body = $this->body();

        $label = trim((string) ($body['label'] ?? ''));
        $type = (string) ($body['field_type'] ?? 'text');
        $allowedTypes = ['text', 'textarea', 'dropdown', 'file', 'date', 'email', 'phone'];

        if ($label === '' || !in_array($type, $allowedTypes, true)) {
            Response::error('A valid label and field_type are required.', 422);
        }

        $this->assertFieldQuota($claims, $db);

        $orderStmt = $db->prepare('SELECT COALESCE(MAX(order_index), -1) + 1 FROM form_fields WHERE form_id = ?');
        $orderStmt->execute([$formId]);
        $nextOrder = (int) $orderStmt->fetchColumn();

        $options = isset($body['options']) && is_array($body['options']) ? json_encode(array_values($body['options'])) : null;

        $db->prepare(
            'INSERT INTO form_fields (form_id, label, field_type, options_json, is_required, order_index) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $formId,
            $label,
            $type,
            $options,
            isset($body['is_required']) ? (int) (bool) $body['is_required'] : 1,
            $nextOrder,
        ]);

        Response::json(['ok' => true, 'id' => (int) $db->lastInsertId()], 201);
    }

    public function updateField(string $formId, string $fieldId): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $body = $this->body();

        $options = array_key_exists('options', $body) && is_array($body['options']) ? json_encode(array_values($body['options'])) : null;

        $db->prepare(
            'UPDATE form_fields SET
                label = COALESCE(?, label),
                field_type = COALESCE(?, field_type),
                options_json = COALESCE(?, options_json),
                is_required = COALESCE(?, is_required),
                order_index = COALESCE(?, order_index)
             WHERE id = ? AND form_id = ?'
        )->execute([
            isset($body['label']) ? trim((string) $body['label']) : null,
            $body['field_type'] ?? null,
            $options,
            isset($body['is_required']) ? (int) (bool) $body['is_required'] : null,
            isset($body['order_index']) ? (int) $body['order_index'] : null,
            $fieldId,
            $formId,
        ]);

        Response::json(['ok' => true]);
    }

    public function deleteField(string $formId, string $fieldId): void
    {
        $claims = Auth::requireTenant();
        $db = Database::forSubdomain($claims['subdomain']);
        $db->prepare('DELETE FROM form_fields WHERE id = ? AND form_id = ?')->execute([$fieldId, $formId]);
        Response::json(['ok' => true]);
    }

    /**
     * Public, unauthenticated: returns the active intake form for whichever
     * tenant subdomain the request arrived on, so a client can render the
     * booking form without ever seeing another tenant's configuration.
     */
    public function publicActiveForm(): void
    {
        $subdomain = Database::currentSubdomain();
        if (!$subdomain || !Database::tenantDbExists($subdomain)) {
            Response::error('Unknown workspace.', 404);
        }

        $db = Database::forSubdomain($subdomain);
        $form = $db->query("SELECT * FROM intake_forms WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch();
        if (!$form) {
            Response::error('This business has not published a booking form yet.', 404);
        }

        $form['fields'] = $this->fieldsForForm($db, (int) $form['id']);
        Response::json($form);
    }
}
