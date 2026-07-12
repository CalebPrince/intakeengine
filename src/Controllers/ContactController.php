<?php

declare(strict_types=1);

namespace Controllers;

use Support\Auth;
use Support\Database;
use Support\Response;

final class ContactController
{
    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public function submit(): void
    {
        $body = $this->body();
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));

        if ($name === '' || $message === '') {
            Response::error('Name and message are required.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address.', 422);
        }

        Database::central()
            ->prepare('INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, $subject ?: null, $message]);

        Response::json(['ok' => true], 201);
    }

    public function index(): void
    {
        Auth::requireAdmin();

        $messages = Database::central()
            ->query('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 200')
            ->fetchAll();

        Response::json(['messages' => $messages]);
    }
}
