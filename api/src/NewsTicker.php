<?php
declare(strict_types=1);

final class NewsTicker
{
    public const THEMES = [
        'pink_indigo' => [
            'background' => '#17132f', 'text' => '#fff8fc', 'sender' => '#f3a3c6',
            'separator' => '#c8c8ed', 'glow' => '#d65f96',
        ],
        'electric_purple' => [
            'background' => '#24103d', 'text' => '#fffaff', 'sender' => '#e5a8ff',
            'separator' => '#b46cff', 'glow' => '#9d4edd',
        ],
        'cyber_blue' => [
            'background' => '#09233d', 'text' => '#eefcff', 'sender' => '#78e7ff',
            'separator' => '#25b8df', 'glow' => '#00c2ff',
        ],
        'combat_red' => [
            'background' => '#311318', 'text' => '#fff6f3', 'sender' => '#ffad9f',
            'separator' => '#ff5a4f', 'glow' => '#ef3e36',
        ],
        'legendary_gold' => [
            'background' => '#30220a', 'text' => '#fffaf0', 'sender' => '#ffd873',
            'separator' => '#f1bd55', 'glow' => '#e7a82b',
        ],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function publicData(): array
    {
        try {
            $settings = $this->settings();
        } catch (PDOException $exception) {
            $settings = [
                'enabled' => true,
                'theme' => 'pink_indigo',
                'speed_seconds' => 55,
                'colors' => self::THEMES['pink_indigo'],
            ];
            return ['settings' => $settings, 'messages' => []];
        }
        $messages = [];
        if ((bool) $settings['enabled']) {
            try {
                $statement = $this->db->prepare(
                    "SELECT id, sender_name, message, source
                     FROM news_ticker_messages
                     WHERE status = 'Approved' AND display_active = 1
                     ORDER BY approved_at DESC, id DESC
                     LIMIT 50",
                );
                $statement->execute();
                foreach ($statement->fetchAll() as $row) {
                    $messages[] = [
                        'id' => (int) $row['id'],
                        'sender_name' => (string) $row['sender_name'],
                        'message' => (string) $row['message'],
                        'source' => (string) $row['source'],
                    ];
                }
            } catch (PDOException $exception) {
                $messages = [];
            }
        }
        return ['settings' => $settings, 'messages' => $messages];
    }

    public function submit(string $senderName, string $message, string $ipAddress): array
    {
        $senderName = $this->cleanText($senderName, 2, 40, 'Sender name');
        $message = $this->cleanText($message, 3, 280, 'Message');
        $now = gmdate('Y-m-d H:i:s');

        try {
            $statement = $this->db->prepare(
                "INSERT INTO news_ticker_messages
                    (sender_name, message, status, display_active, ip_address, source, created_at, updated_at)
                 VALUES (:sender_name, :message, 'Pending', 0, :ip_address, 'Visitor', :created_at, :updated_at)",
            );
            $statement->execute([
            'sender_name' => $senderName,
            'message' => $message,
            'ip_address' => $ipAddress,
            'created_at' => $now,
            'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            throw new ApiException(
                'NEWS_TICKER_NOT_MIGRATED',
                'News Ticker is not available yet. The database migration must be applied.',
                503,
            );
        }

        return [
            'id' => (int) $this->db->lastInsertId(),
            'status' => 'Pending',
            'message' => 'Your message was received and is waiting for owner review.',
        ];
    }

    public function ownerData(): array
    {
        try {
            $settings = $this->settings();
            $statement = $this->db->query(
            'SELECT id, sender_name, message, status, display_active,
                    ip_address, source, created_at, approved_at, updated_at
             FROM news_ticker_messages
             ORDER BY
                CASE status WHEN \'Pending\' THEN 0 WHEN \'Approved\' THEN 1 ELSE 2 END,
                id DESC',
        );
        $messages = $statement->fetchAll();
        foreach ($messages as &$message) {
            $message['id'] = (int) $message['id'];
            $message['display_active'] = (bool) $message['display_active'];
        }
        unset($message);

            return ['settings' => $settings, 'messages' => $messages, 'themes' => self::THEMES, 'migration_ready' => true];
        } catch (PDOException $exception) {
            return [
                'settings' => [
                    'enabled' => true,
                    'theme' => 'pink_indigo',
                    'speed_seconds' => 55,
                    'colors' => self::THEMES['pink_indigo'],
                ],
                'messages' => [],
                'themes' => self::THEMES,
                'migration_ready' => false,
                'error' => 'News Ticker tables are not available. Apply php api/migrate.php.',
            ];
        }
    }

    public function publishOwnerMessage(string $senderName, string $message, int $ownerUserId): array
    {
        $senderName = $this->cleanText($senderName, 2, 40, 'Sender name');
        $message = $this->cleanText($message, 3, 280, 'Message');
        $now = gmdate('Y-m-d H:i:s');
        try {
            $statement = $this->db->prepare(
                "INSERT INTO news_ticker_messages
                    (sender_name, message, status, display_active, source, approved_by, approved_at, created_at, updated_at)
                 VALUES (:sender_name, :message, 'Approved', 1, 'Owner', :approved_by, :approved_at, :created_at, :updated_at)",
            );
            $statement->execute([
            'sender_name' => $senderName,
            'message' => $message,
            'approved_by' => $ownerUserId,
            'approved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            throw new ApiException(
                'NEWS_TICKER_NOT_MIGRATED',
                'News Ticker is not available yet. The database migration must be applied.',
                503,
            );
        }
        return $this->message((int) $this->db->lastInsertId());
    }

    public function updateSettings(array $body): array
    {
        $allowedThemes = array_keys(self::THEMES);
        $theme = $body['theme'] ?? null;
        $enabled = $body['enabled'] ?? null;
        $speed = $body['speed_seconds'] ?? null;
        $colors = $body['colors'] ?? null;

        if (!is_string($theme) || !in_array($theme, $allowedThemes, true)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid ticker theme is required.', 422);
        }
        if (!is_bool($enabled)) {
            throw new ApiException('VALIDATION_ERROR', 'Ticker enabled state must be true or false.', 422);
        }
        if (!is_int($speed) && !(is_string($speed) && ctype_digit($speed))) {
            throw new ApiException('VALIDATION_ERROR', 'Ticker speed is invalid.', 422);
        }
        $speed = (int) $speed;
        if ($speed < 20 || $speed > 120) {
            throw new ApiException('VALIDATION_ERROR', 'Ticker speed must be between 20 and 120 seconds.', 422);
        }
        if (!is_array($colors)) {
            throw new ApiException('VALIDATION_ERROR', 'Ticker theme colors are required.', 422);
        }

        $validated = [];
        foreach (['background', 'text', 'sender', 'separator', 'glow'] as $field) {
            $value = $colors[$field] ?? null;
            if (!is_string($value) || !preg_match('/\A#[0-9A-Fa-f]{6}\z/', $value)) {
                throw new ApiException('VALIDATION_ERROR', 'All ticker colors must use six-digit hexadecimal values.', 422);
            }
            $validated[$field] = strtoupper($value);
        }

        $now = gmdate('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE news_ticker_settings SET enabled = :enabled, theme = :theme,
                    speed_seconds = :speed_seconds, background_color = :background_color,
                    text_color = :text_color, sender_color = :sender_color,
                    separator_color = :separator_color, glow_color = :glow_color,
                    updated_at = :updated_at WHERE singleton = 1',
        );
        $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'theme' => $theme,
            'speed_seconds' => $speed,
            'background_color' => $validated['background'],
            'text_color' => $validated['text'],
            'sender_color' => $validated['sender'],
            'separator_color' => $validated['separator'],
            'glow_color' => $validated['glow'],
            'updated_at' => $now,
        ]);
        return $this->settings();
    }

    public function updateMessage(int $messageId, array $body): array
    {
        $statement = $this->db->prepare('SELECT id FROM news_ticker_messages WHERE id = :id AND status != \'Deleted\' LIMIT 1');
        $statement->execute(['id' => $messageId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Ticker message not found.', 404);
        }

        $fields = [];
        $values = ['id' => $messageId];
        if (array_key_exists('sender_name', $body)) {
            $fields[] = 'sender_name = :sender_name';
            $values['sender_name'] = $this->cleanText((string) $body['sender_name'], 2, 40, 'Sender name');
        }
        if (array_key_exists('message', $body)) {
            $fields[] = 'message = :message';
            $values['message'] = $this->cleanText((string) $body['message'], 3, 280, 'Message');
        }
        if (array_key_exists('display_active', $body)) {
            if (!is_bool($body['display_active'])) {
                throw new ApiException('VALIDATION_ERROR', 'Display state must be true or false.', 422);
            }
            $fields[] = 'display_active = :display_active';
            $values['display_active'] = $body['display_active'] ? 1 : 0;
        }
        if ($fields === []) {
            throw new ApiException('VALIDATION_ERROR', 'No ticker changes were provided.', 422);
        }

        $fields[] = 'updated_at = :updated_at';
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->prepare(
            'UPDATE news_ticker_messages SET ' . implode(', ', $fields) . ' WHERE id = :id',
        )->execute($values);

        return $this->message($messageId);
    }

    public function setStatus(int $messageId, string $status, ?int $actorUserId = null): array
    {
        if (!in_array($status, ['Approved', 'Rejected', 'Deleted'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid ticker message status.', 422);
        }
        $statement = $this->db->prepare('SELECT id, status FROM news_ticker_messages WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $messageId]);
        $existing = $statement->fetch();
        if ($existing === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Ticker message not found.', 404);
        }

        $now = gmdate('Y-m-d H:i:s');
        $displayActive = $status === 'Approved';
        $statement = $this->db->prepare(
            'UPDATE news_ticker_messages SET status = :status, display_active = :display_active,
                    approved_by = CASE WHEN :status_approved_by = \'Approved\' THEN :approved_by ELSE approved_by END,
                    approved_at = CASE WHEN :status_approved_at = \'Approved\' THEN :approved_at ELSE approved_at END,
                    updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'status' => $status,
            'display_active' => $displayActive ? 1 : 0,
            'status_approved_by' => $status,
            'status_approved_at' => $status,
            'approved_by' => $actorUserId,
            'approved_at' => $now,
            'updated_at' => $now,
            'id' => $messageId,
        ]);
        return $this->message($messageId);
    }

    public function pause(int $messageId): array
    {
        return $this->setDisplay($messageId, false);
    }

    public function resume(int $messageId): array
    {
        return $this->setDisplay($messageId, true);
    }

    private function setDisplay(int $messageId, bool $active): array
    {
        $statement = $this->db->prepare(
            "SELECT id FROM news_ticker_messages WHERE id = :id AND status = 'Approved' LIMIT 1",
        );
        $statement->execute(['id' => $messageId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('CONFLICT', 'Only approved messages can be paused or resumed.', 409);
        }
        $statement = $this->db->prepare(
            'UPDATE news_ticker_messages SET display_active = :display_active, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'display_active' => $active ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $messageId,
        ]);
        return $this->message($messageId);
    }

    private function message(int $messageId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, sender_name, message, status, display_active, ip_address, source, created_at, approved_at, updated_at
             FROM news_ticker_messages WHERE id = :id LIMIT 1',
        );
        $statement->execute(['id' => $messageId]);
        $message = $statement->fetch();
        if ($message === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Ticker message not found.', 404);
        }
        $message['id'] = (int) $message['id'];
        $message['display_active'] = (bool) $message['display_active'];
        return $message;
    }

    private function settings(): array
    {
        $statement = $this->db->query('SELECT * FROM news_ticker_settings WHERE singleton = 1 LIMIT 1');
        $settings = $statement->fetch();
        if ($settings === false) {
            $theme = self::THEMES['pink_indigo'];
            $statement = $this->db->prepare(
                'INSERT INTO news_ticker_settings
                    (singleton, enabled, theme, speed_seconds, background_color, text_color,
                     sender_color, separator_color, glow_color, updated_at)
                 VALUES (1, 1, \'pink_indigo\', 55, :background, :text, :sender, :separator, :glow, :now)',
            );
            $statement->execute([
                'background' => $theme['background'], 'text' => $theme['text'], 'sender' => $theme['sender'],
                'separator' => $theme['separator'], 'glow' => $theme['glow'], 'now' => gmdate('Y-m-d H:i:s'),
            ]);
            $statement = $this->db->query('SELECT * FROM news_ticker_settings WHERE singleton = 1 LIMIT 1');
            $settings = $statement->fetch();
        }
        return [
            'enabled' => (bool) $settings['enabled'],
            'theme' => (string) $settings['theme'],
            'speed_seconds' => (int) $settings['speed_seconds'],
            'colors' => [
                'background' => (string) $settings['background_color'],
                'text' => (string) $settings['text_color'],
                'sender' => (string) $settings['sender_color'],
                'separator' => (string) $settings['separator_color'],
                'glow' => (string) $settings['glow_color'],
            ],
        ];
    }

    private function cleanText(string $value, int $minimum, int $maximum, string $label): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (
            $length < $minimum
            || $length > $maximum
            || preg_match('/<[^>]*>|[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value) === 1
        ) {
            throw new ApiException(
                'VALIDATION_ERROR',
                $label . ' must be plain text between ' . $minimum . ' and ' . $maximum . ' characters.',
                422,
            );
        }
        return $value;
    }
}
