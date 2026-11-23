<?php
declare(strict_types=1);

final class CommunityEvents
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function publicEvents(?int $userId = null): array
    {
        $statement = $this->db->query(
            "SELECT id, title, description, starts_at, ends_at, status
             FROM community_events
             WHERE status IN ('published', 'paused', 'results_published')
             ORDER BY starts_at DESC, id DESC",
        );
        return array_map(fn (array $event): array => $this->eventDetails((int) $event['id'], $userId), $statement->fetchAll());
    }

    public function publicLeaderboards(): array
    {
        $earned = $this->db->query(
            "SELECT u.username, p.display_name, w.earned_coin_balance AS score
             FROM wallets w
             JOIN users u ON u.id = w.user_id AND u.status = 'active'
             JOIN user_profiles p ON p.user_id = u.id
             WHERE w.earned_coin_balance > 0
             ORDER BY w.earned_coin_balance DESC, u.username ASC
             LIMIT 10",
        )->fetchAll();

        $wins = $this->db->query(
            "SELECT u.username, p.display_name, COUNT(*) AS score
             FROM game_sessions s
             JOIN users u ON u.id = s.user_id AND u.status = 'active'
             JOIN user_profiles p ON p.user_id = u.id
             WHERE s.status = 'completed' AND s.completed_at IS NOT NULL
             GROUP BY u.id, u.username, p.display_name
             ORDER BY COUNT(*) DESC, u.username ASC
             LIMIT 10",
        )->fetchAll();

        $attendance = $this->db->query(
            "SELECT u.id, u.username, p.display_name, a.attendance_date
             FROM daily_attendance a
             JOIN users u ON u.id = a.user_id AND u.status = 'active'
             JOIN user_profiles p ON p.user_id = u.id
             ORDER BY u.id, a.attendance_date DESC",
        )->fetchAll();
        $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Cairo'));
        $streaks = [];
        $currentUser = null;
        $dates = [];
        $finishUser = static function (array $rows, DateTimeImmutable $today): ?array {
            if ($rows === []) {
                return null;
            }
            $latest = new DateTimeImmutable((string) $rows[0]['attendance_date'], new DateTimeZone('Africa/Cairo'));
            $daysBehind = (int) $latest->diff($today)->format('%r%a');
            if ($daysBehind < 0 || $daysBehind > 1) {
                return null;
            }

            $streak = 0;
            $expected = $latest;
            foreach ($rows as $row) {
                $date = new DateTimeImmutable((string) $row['attendance_date'], new DateTimeZone('Africa/Cairo'));
                if ($date->format('Y-m-d') !== $expected->format('Y-m-d')) {
                    break;
                }
                $streak++;
                $expected = $expected->modify('-1 day');
            }
            return $streak > 0 ? [
                'username' => (string) $rows[0]['username'],
                'display_name' => $rows[0]['display_name'] === null ? null : (string) $rows[0]['display_name'],
                'score' => $streak,
            ] : null;
        };
        foreach ($attendance as $row) {
            $userId = (int) $row['id'];
            if ($currentUser !== null && $userId !== $currentUser) {
                $entry = $finishUser($dates, $today);
                if ($entry !== null) {
                    $streaks[] = $entry;
                }
                $dates = [];
            }
            $currentUser = $userId;
            $dates[] = $row;
        }
        if ($currentUser !== null) {
            $entry = $finishUser($dates, $today);
            if ($entry !== null) {
                $streaks[] = $entry;
            }
        }
        usort($streaks, static fn (array $left, array $right): int =>
            $right['score'] <=> $left['score'] ?: strcmp($left['username'], $right['username']));

        return [
            'earned_coins' => $this->normalizeLeaderboardRows($earned),
            'game_wins' => $this->normalizeLeaderboardRows($wins),
            'attendance_streaks' => $this->normalizeLeaderboardRows(array_slice($streaks, 0, 10)),
        ];
    }

    public function eventDetails(int $eventId, ?int $userId = null): array
    {
        $event = $this->findEvent($eventId);
        if ($event === null || !in_array($event['status'], ['published', 'paused', 'results_published'], true)) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Community event not found.', 404);
        }

        $statement = $this->db->prepare(
            'SELECT o.id, o.label, o.position, COUNT(v.id) AS vote_count
             FROM community_event_options o
             LEFT JOIN community_event_votes v ON v.event_id = o.event_id AND v.option_id = o.id
             WHERE o.event_id = :event_id
             GROUP BY o.id, o.label, o.position
             ORDER BY o.position, o.id',
        );
        $statement->execute(['event_id' => $eventId]);
        $options = $statement->fetchAll();
        foreach ($options as &$option) {
            $option['id'] = (int) $option['id'];
            $option['position'] = (int) $option['position'];
            $option['vote_count'] = (int) $option['vote_count'];
        }
        unset($option);

        $totalVotes = array_sum(array_column($options, 'vote_count'));
        $myVote = null;
        if ($userId !== null) {
            $statement = $this->db->prepare(
                'SELECT option_id FROM community_event_votes WHERE event_id = :event_id AND user_id = :user_id',
            );
            $statement->execute(['event_id' => $eventId, 'user_id' => $userId]);
            $myVote = $statement->fetchColumn();
            $myVote = $myVote === false ? null : (int) $myVote;
        }

        $results = null;
        if ($event['status'] === 'results_published') {
            $statement = $this->db->prepare(
                'SELECT r.option_id, o.label, r.vote_count, r.approved_at
                 FROM community_event_results r
                 JOIN community_event_options o ON o.id = r.option_id AND o.event_id = r.event_id
                 WHERE r.event_id = :event_id
                 ORDER BY r.vote_count DESC, o.position, o.id',
            );
            $statement->execute(['event_id' => $eventId]);
            $results = $statement->fetchAll();
            foreach ($results as &$result) {
                $result['option_id'] = (int) $result['option_id'];
                $result['vote_count'] = (int) $result['vote_count'];
            }
            unset($result);
        }

        $event['id'] = (int) $event['id'];
        $event['total_votes'] = $totalVotes;
        $event['options'] = $options;
        $event['my_vote_option_id'] = $myVote;
        $event['can_vote'] = $event['status'] === 'published'
            && $event['starts_at'] <= $this->now()
            && $event['ends_at'] > $this->now()
            && $myVote === null;
        $event['results'] = $results;
        return $event;
    }

    public function submitSuggestion(int $userId, string $title, string $description): int
    {
        $title = trim($title);
        $description = trim($description);
        if ($userId < 1 || $title === '' || strlen($title) > 150 || $description === '' || strlen($description) > 4000) {
            throw new ApiException('VALIDATION_ERROR', 'Suggestion title or description is invalid.', 422);
        }

        return $this->transaction(function () use ($userId, $title, $description): int {
            $this->lockActiveUser($userId);
            $statement = $this->db->prepare(
                'INSERT INTO community_event_suggestions
                    (user_id, title, description, status, created_at)
                 VALUES (:user_id, :title, :description, \'pending\', :created_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'title' => $title,
                'description' => $description,
                'created_at' => $this->now(),
            ]);
            $suggestionId = (int) $this->db->lastInsertId();
            $this->logActivity(null, $userId, 'suggestion_submitted', ['suggestion_id' => $suggestionId]);
            return $suggestionId;
        });
    }

    public function mySuggestions(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, title, description, status, admin_note, created_at, reviewed_at
             FROM community_event_suggestions WHERE user_id = :user_id ORDER BY id DESC',
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function myRewards(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.id, r.event_id, e.title AS event_title, r.reward_amount, r.reason, r.created_at
             FROM community_event_rewards r
             JOIN community_events e ON e.id = r.event_id
             WHERE r.user_id = :user_id ORDER BY r.id DESC',
        );
        $statement->execute(['user_id' => $userId]);
        $rewards = $statement->fetchAll();
        foreach ($rewards as &$reward) {
            foreach (['id', 'event_id', 'reward_amount'] as $field) {
                $reward[$field] = (int) $reward[$field];
            }
        }
        unset($reward);
        return $rewards;
    }

    public function castVote(int $userId, int $eventId, int $optionId): array
    {
        return $this->transaction(function () use ($userId, $eventId, $optionId): array {
            $this->lockActiveUser($userId);
            $event = $this->findEvent($eventId, true);
            $now = $this->now();
            if (
                $event === null
                || $event['status'] !== 'published'
                || $event['starts_at'] > $now
                || $event['ends_at'] <= $now
            ) {
                throw new ApiException('EVENT_VOTING_CLOSED', 'This event is not open for voting.', 409);
            }

            $statement = $this->db->prepare(
                'SELECT id FROM community_event_options WHERE id = :option_id AND event_id = :event_id',
            );
            $statement->execute(['option_id' => $optionId, 'event_id' => $eventId]);
            if ($statement->fetchColumn() === false) {
                throw new ApiException('VALIDATION_ERROR', 'The selected event option does not exist.', 422);
            }

            $statement = $this->db->prepare(
                'SELECT option_id FROM community_event_votes WHERE event_id = :event_id AND user_id = :user_id',
            );
            $statement->execute(['event_id' => $eventId, 'user_id' => $userId]);
            if ($statement->fetchColumn() !== false) {
                throw new ApiException('DUPLICATE_REQUEST', 'You have already voted in this event.', 409);
            }

            $statement = $this->db->prepare(
                'INSERT INTO community_event_votes (event_id, option_id, user_id, created_at)
                 VALUES (:event_id, :option_id, :user_id, :created_at)',
            );
            $statement->execute([
                'event_id' => $eventId,
                'option_id' => $optionId,
                'user_id' => $userId,
                'created_at' => $now,
            ]);
            $this->logActivity($eventId, $userId, 'vote_cast', ['option_id' => $optionId]);
            return $this->eventDetails($eventId, $userId);
        });
    }

    public function adminEvents(): array
    {
        $statement = $this->db->query('SELECT id FROM community_events ORDER BY id DESC');
        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $eventId) {
            $events[] = $this->adminEvent((int) $eventId);
        }
        return $events;
    }

    public function activity(int $eventId): array
    {
        $event = $this->findEvent($eventId);
        if ($event === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Community event not found.', 404);
        }
        $statement = $this->db->prepare(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.activity_type, a.metadata, a.created_at
             FROM community_event_activity a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.event_id = :event_id ORDER BY a.id DESC LIMIT 100',
        );
        $statement->execute(['event_id' => $eventId]);
        $entries = $statement->fetchAll();
        foreach ($entries as &$entry) {
            $entry['id'] = (int) $entry['id'];
            $entry['actor_user_id'] = $entry['actor_user_id'] === null ? null : (int) $entry['actor_user_id'];
            $entry['metadata'] = $entry['metadata'] === null
                ? null
                : json_decode((string) $entry['metadata'], true, 16, JSON_THROW_ON_ERROR);
        }
        unset($entry);
        return $entries;
    }

    public function createEvent(
        int $adminId,
        string $title,
        string $description,
        string $startsAt,
        string $endsAt,
        array $options,
    ): array {
        $title = trim($title);
        $description = trim($description);
        $start = $this->parseUtc($startsAt);
        $end = $this->parseUtc($endsAt);
        $options = $this->validateOptions($options);
        if ($title === '' || strlen($title) > 150 || $description === '' || strlen($description) > 4000 || $end <= $start) {
            throw new ApiException('VALIDATION_ERROR', 'Event details or dates are invalid.', 422);
        }

        return $this->transaction(function () use ($adminId, $title, $description, $startsAt, $endsAt, $options): array {
            $now = $this->now();
            $statement = $this->db->prepare(
                'INSERT INTO community_events
                    (title, description, starts_at, ends_at, status, created_by, updated_by, created_at, updated_at)
                 VALUES (:title, :description, :starts_at, :ends_at, \'draft\', :created_by, :updated_by, :created_at, :updated_at)',
            );
            $statement->execute([
                'title' => $title,
                'description' => $description,
                'starts_at' => $this->toDatabaseDate($startsAt),
                'ends_at' => $this->toDatabaseDate($endsAt),
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $eventId = (int) $this->db->lastInsertId();
            $this->replaceOptions($eventId, $options);
            $this->logActivity($eventId, $adminId, 'event_created', []);
            return $this->adminEvent($eventId);
        });
    }

    public function updateEvent(int $adminId, int $eventId, array $fields): array
    {
        $allowed = ['title', 'description', 'starts_at', 'ends_at', 'status', 'options'];
        if ($fields === [] || array_diff(array_keys($fields), $allowed) !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Event update contains unsupported fields.', 422);
        }
        return $this->transaction(function () use ($adminId, $eventId, $fields): array {
            $event = $this->findEvent($eventId, true);
            if ($event === null) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Community event not found.', 404);
            }
            if ($event['status'] === 'results_published') {
                throw new ApiException('EVENT_LOCKED', 'Published event results cannot be edited.', 409);
            }
            $updates = [];
            $parameters = ['id' => $eventId, 'updated_by' => $adminId, 'updated_at' => $this->now()];
            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $fields)) {
                    if (!is_string($fields[$field])) {
                        throw new ApiException('VALIDATION_ERROR', 'Event text fields must be strings.', 422);
                    }
                    $value = trim($fields[$field]);
                    $limit = $field === 'title' ? 150 : 4000;
                    if ($value === '' || strlen($value) > $limit) {
                        throw new ApiException('VALIDATION_ERROR', 'Event text fields are invalid.', 422);
                    }
                    $updates[] = $field . ' = :' . $field;
                    $parameters[$field] = $value;
                }
            }
            foreach (['starts_at', 'ends_at'] as $field) {
                if (array_key_exists($field, $fields)) {
                    if (!is_string($fields[$field])) {
                        throw new ApiException('VALIDATION_ERROR', 'Event dates must be UTC strings.', 422);
                    }
                    $this->parseUtc($fields[$field]);
                    $updates[] = $field . ' = :' . $field;
                    $parameters[$field] = $this->toDatabaseDate($fields[$field]);
                }
            }
            if (array_key_exists('status', $fields)) {
                if (!is_string($fields['status']) || !in_array($fields['status'], ['draft', 'published', 'paused', 'cancelled'], true)) {
                    throw new ApiException('VALIDATION_ERROR', 'Event status is invalid.', 422);
                }
                $updates[] = 'status = :status';
                $parameters['status'] = $fields['status'];
            }

            $start = $fields['starts_at'] ?? $this->fromDatabaseDate($event['starts_at']);
            $end = $fields['ends_at'] ?? $this->fromDatabaseDate($event['ends_at']);
            if ($this->parseUtc($end) <= $this->parseUtc($start)) {
                throw new ApiException('VALIDATION_ERROR', 'Event end time must follow its start time.', 422);
            }

            if ($updates !== []) {
                $updates[] = 'updated_by = :updated_by';
                $updates[] = 'updated_at = :updated_at';
                $statement = $this->db->prepare(
                    'UPDATE community_events SET ' . implode(', ', $updates) . ' WHERE id = :id',
                );
                $statement->execute($parameters);
            }
            if (array_key_exists('options', $fields)) {
                if (!is_array($fields['options'])) {
                    throw new ApiException('VALIDATION_ERROR', 'Event options must be a list.', 422);
                }
                $statement = $this->db->prepare(
                    'SELECT COUNT(*) FROM community_event_votes WHERE event_id = :event_id',
                );
                $statement->execute(['event_id' => $eventId]);
                if ((int) $statement->fetchColumn() > 0) {
                    throw new ApiException('EVENT_LOCKED', 'Event options cannot change after voting starts.', 409);
                }
                $this->replaceOptions($eventId, $this->validateOptions($fields['options']));
            }
            $this->logActivity($eventId, $adminId, 'event_updated', ['fields' => array_keys($fields)]);
            return $this->adminEvent($eventId);
        });
    }

    public function adminEvent(int $eventId): array
    {
        $event = $this->findEvent($eventId);
        if ($event === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Community event not found.', 404);
        }
        $event['starts_at'] = $this->fromDatabaseDate($event['starts_at']);
        $event['ends_at'] = $this->fromDatabaseDate($event['ends_at']);
        $statement = $this->db->prepare(
            'SELECT id, label, position FROM community_event_options WHERE event_id = :event_id ORDER BY position, id',
        );
        $statement->execute(['event_id' => $eventId]);
        $event['id'] = (int) $event['id'];
        $event['options'] = $statement->fetchAll();
        foreach ($event['options'] as &$option) {
            $option['id'] = (int) $option['id'];
            $option['position'] = (int) $option['position'];
        }
        unset($option);
        $event['total_votes'] = $this->countVotes($eventId);
        return $event;
    }

    public function suggestions(bool $pendingOnly = false): array
    {
        $where = $pendingOnly ? "WHERE s.status = 'pending'" : '';
        $statement = $this->db->query(
            'SELECT s.id, s.user_id, u.username, s.title, s.description, s.status, s.admin_note,
                    s.created_at, s.reviewed_at
             FROM community_event_suggestions s
             JOIN users u ON u.id = s.user_id ' . $where . ' ORDER BY s.id DESC LIMIT 200',
        );
        $suggestions = $statement->fetchAll();
        foreach ($suggestions as &$suggestion) {
            $suggestion['id'] = (int) $suggestion['id'];
            $suggestion['user_id'] = (int) $suggestion['user_id'];
        }
        unset($suggestion);
        return $suggestions;
    }

    public function reviewSuggestion(int $adminId, int $suggestionId, string $decision, string $note): array
    {
        if (!in_array($decision, ['accepted', 'rejected'], true) || strlen($note) > 1000) {
            throw new ApiException('VALIDATION_ERROR', 'Suggestion review is invalid.', 422);
        }
        return $this->transaction(function () use ($adminId, $suggestionId, $decision, $note): array {
            $lock = $this->forUpdate();
            $statement = $this->db->prepare(
                'SELECT id, status FROM community_event_suggestions WHERE id = :id' . $lock,
            );
            $statement->execute(['id' => $suggestionId]);
            $suggestion = $statement->fetch();
            if ($suggestion === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Event suggestion not found.', 404);
            }
            if ($suggestion['status'] !== 'pending') {
                throw new ApiException('DUPLICATE_REQUEST', 'This suggestion has already been reviewed.', 409);
            }
            $statement = $this->db->prepare(
                'UPDATE community_event_suggestions SET status = :status, admin_note = :admin_note,
                    reviewed_by = :reviewed_by, reviewed_at = :reviewed_at WHERE id = :id AND status = \'pending\'',
            );
            $statement->execute([
                'status' => $decision,
                'admin_note' => $note === '' ? null : $note,
                'reviewed_by' => $adminId,
                'reviewed_at' => $this->now(),
                'id' => $suggestionId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('DUPLICATE_REQUEST', 'This suggestion has already been reviewed.', 409);
            }
            $this->logActivity(null, $adminId, 'suggestion_' . $decision, ['suggestion_id' => $suggestionId]);
            return ['id' => $suggestionId, 'status' => $decision];
        });
    }

    public function approveResults(int $adminId, int $eventId): array
    {
        return $this->transaction(function () use ($adminId, $eventId): array {
            $event = $this->findEvent($eventId, true);
            if ($event === null) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Community event not found.', 404);
            }
            if ($event['status'] === 'results_published') {
                return $this->eventDetails($eventId);
            }
            if ($event['status'] !== 'published' || $event['ends_at'] > $this->now()) {
                throw new ApiException('EVENT_NOT_ENDED', 'Results can only be approved after a published event has ended.', 409);
            }
            $statement = $this->db->prepare(
                'SELECT o.id, COUNT(v.id) AS vote_count
                 FROM community_event_options o
                 LEFT JOIN community_event_votes v ON v.event_id = o.event_id AND v.option_id = o.id
                 WHERE o.event_id = :event_id
                 GROUP BY o.id ORDER BY o.position, o.id',
            );
            $statement->execute(['event_id' => $eventId]);
            $now = $this->now();
            foreach ($statement->fetchAll() as $result) {
                $insert = $this->db->prepare(
                    'INSERT INTO community_event_results
                        (event_id, option_id, vote_count, approved_by, approved_at)
                     VALUES (:event_id, :option_id, :vote_count, :approved_by, :approved_at)',
                );
                $insert->execute([
                    'event_id' => $eventId,
                    'option_id' => $result['id'],
                    'vote_count' => $result['vote_count'],
                    'approved_by' => $adminId,
                    'approved_at' => $now,
                ]);
            }
            $update = $this->db->prepare(
                "UPDATE community_events SET status = 'results_published', updated_by = :admin_id, updated_at = :now
                 WHERE id = :event_id AND status = 'published'",
            );
            $update->execute(['admin_id' => $adminId, 'now' => $now, 'event_id' => $eventId]);
            if ($update->rowCount() !== 1) {
                throw new ApiException('DUPLICATE_REQUEST', 'Event results have already been approved.', 409);
            }
            $this->logActivity($eventId, $adminId, 'results_approved', []);
            return $this->eventDetails($eventId);
        });
    }

    public function awardEventReward(int $adminId, int $eventId, int $userId, int $amount, string $reason): array
    {
        if ($amount < 1 || trim($reason) === '' || strlen($reason) > 1000) {
            throw new ApiException('VALIDATION_ERROR', 'Event reward amount and reason are required.', 422);
        }
        return $this->transaction(function () use ($adminId, $eventId, $userId, $amount, $reason): array {
            $event = $this->findEvent($eventId, true);
            if ($event === null || $event['status'] !== 'results_published') {
                throw new ApiException('EVENT_RESULTS_REQUIRED', 'Event results must be approved before rewards are issued.', 409);
            }
            $statement = $this->db->prepare(
                'SELECT id FROM community_event_votes WHERE event_id = :event_id AND user_id = :user_id',
            );
            $statement->execute(['event_id' => $eventId, 'user_id' => $userId]);
            if ($statement->fetchColumn() === false) {
                throw new ApiException('REWARD_NOT_ELIGIBLE', 'Only participants with a recorded event vote are eligible.', 409);
            }
            $statement = $this->db->prepare(
                'SELECT id, reward_amount FROM community_event_rewards WHERE event_id = :event_id AND user_id = :user_id',
            );
            $statement->execute(['event_id' => $eventId, 'user_id' => $userId]);
            $existing = $statement->fetch();
            if ($existing !== false) {
                if ((int) $existing['reward_amount'] !== $amount) {
                    throw new ApiException('DUPLICATE_REQUEST', 'An event reward has already been issued to this participant.', 409);
                }
                return ['created' => false, 'reward_id' => (int) $existing['id']];
            }

            $statement = $this->db->prepare(
                'INSERT INTO community_event_rewards
                    (event_id, user_id, reward_amount, reason, ledger_entry_id, awarded_by, created_at)
                 VALUES (:event_id, :user_id, :reward_amount, :reason, NULL, :awarded_by, :created_at)',
            );
            $statement->execute([
                'event_id' => $eventId,
                'user_id' => $userId,
                'reward_amount' => $amount,
                'reason' => $reason,
                'awarded_by' => $adminId,
                'created_at' => $this->now(),
            ]);
            $rewardId = (int) $this->db->lastInsertId();
            $ledgerEntryId = (new CoinsLedger($this->db))->credit(
                $userId,
                'earned',
                $amount,
                'earned_reward',
                'event-reward-' . $eventId . '-' . $userId,
                'community_event_reward',
                $rewardId,
                $reason,
            );
            $update = $this->db->prepare(
                'UPDATE community_event_rewards SET ledger_entry_id = :ledger_entry_id WHERE id = :id',
            );
            $update->execute(['ledger_entry_id' => $ledgerEntryId, 'id' => $rewardId]);
            $this->logActivity($eventId, $adminId, 'reward_issued', [
                'user_id' => $userId,
                'reward_id' => $rewardId,
                'amount' => $amount,
            ]);
            return ['created' => true, 'reward_id' => $rewardId, 'ledger_entry_id' => $ledgerEntryId];
        });
    }

    private function findEvent(int $eventId, bool $lock = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, title, description, starts_at, ends_at, status, created_by, updated_by, created_at, updated_at
             FROM community_events WHERE id = :id' . ($lock ? $this->forUpdate() : ''),
        );
        $statement->execute(['id' => $eventId]);
        $event = $statement->fetch();
        return $event === false ? null : $event;
    }

    private function replaceOptions(int $eventId, array $options): void
    {
        $delete = $this->db->prepare('DELETE FROM community_event_options WHERE event_id = :event_id');
        $delete->execute(['event_id' => $eventId]);
        $insert = $this->db->prepare(
            'INSERT INTO community_event_options (event_id, label, position) VALUES (:event_id, :label, :position)',
        );
        foreach ($options as $position => $label) {
            $insert->execute(['event_id' => $eventId, 'label' => $label, 'position' => $position + 1]);
        }
    }

    private function validateOptions(array $options): array
    {
        if (!array_is_list($options) || count($options) < 2 || count($options) > 10) {
            throw new ApiException('VALIDATION_ERROR', 'Provide between two and ten event options.', 422);
        }
        $validated = [];
        foreach ($options as $option) {
            if (!is_string($option) || trim($option) === '' || strlen(trim($option)) > 150) {
                throw new ApiException('VALIDATION_ERROR', 'Event option labels must be between 1 and 150 characters.', 422);
            }
            $validated[] = trim($option);
        }
        if (count(array_unique(array_map('strtolower', $validated))) !== count($validated)) {
            throw new ApiException('VALIDATION_ERROR', 'Event options must be unique.', 422);
        }
        return $validated;
    }

    private function countVotes(int $eventId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM community_event_votes WHERE event_id = :event_id');
        $statement->execute(['event_id' => $eventId]);
        return (int) $statement->fetchColumn();
    }

    private function normalizeLeaderboardRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $index => $row) {
            $normalized[] = [
                'rank' => $index + 1,
                'username' => (string) $row['username'],
                'display_name' => $row['display_name'] === null ? null : (string) $row['display_name'],
                'score' => (int) $row['score'],
            ];
        }
        return $normalized;
    }

    private function logActivity(?int $eventId, ?int $actorId, string $type, array $metadata): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO community_event_activity
                (event_id, actor_user_id, activity_type, metadata, created_at)
             VALUES (:event_id, :actor_user_id, :activity_type, :metadata, :created_at)',
        );
        $statement->execute([
            'event_id' => $eventId,
            'actor_user_id' => $actorId,
            'activity_type' => $type,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $this->now(),
        ]);
    }

    private function lockActiveUser(int $userId): void
    {
        $statement = $this->db->prepare(
            "SELECT id FROM users WHERE id = :user_id AND status = 'active'" . $this->forUpdate(),
        );
        $statement->execute(['user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Account not found.', 404);
        }
    }

    private function transaction(callable $operation): mixed
    {
        $startedHere = !$this->db->inTransaction();
        if ($startedHere) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($startedHere) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($startedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Event times must use an exact UTC timestamp.', 422);
        }
        return $date;
    }

    private function toDatabaseDate(string $value): string
    {
        return $this->parseUtc($value)->format('Y-m-d H:i:s');
    }

    private function fromDatabaseDate(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function forUpdate(): string
    {
        return $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
