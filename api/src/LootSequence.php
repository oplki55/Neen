<?php
declare(strict_types=1);

final class LootSequence extends ChallengeGame
{
    private const GAME_SLUG = 'loot_sequence';
    private const STICKERS = [
        ['id' => 'snoop-sticker-01', 'src' => '/assets/hero-loot/snoop-sticker-01.webp'],
        ['id' => 'snoop-sticker-02', 'src' => '/assets/hero-loot/snoop-sticker-02.webp'],
        ['id' => 'snoop-sticker-03', 'src' => '/assets/hero-loot/snoop-sticker-03.webp'],
        ['id' => 'snoop-sticker-04', 'src' => '/assets/hero-loot/snoop-sticker-04.webp'],
        ['id' => 'snoop-sticker-05', 'src' => '/assets/hero-loot/snoop-sticker-05.webp'],
        ['id' => 'snoop-sticker-06', 'src' => '/assets/hero-loot/snoop-sticker-06.webp'],
    ];
    private const ROUND_LENGTHS = [3, 4, 5, 6];

    protected function slug(): string
    {
        return self::GAME_SLUG;
    }

    public function catalog(): array
    {
        return parent::catalog() + ['stickers' => self::STICKERS];
    }

    protected function initializeState(int $sessionId): void
    {
        $ids = array_column(self::STICKERS, 'id');
        $sequences = [];
        foreach (self::ROUND_LENGTHS as $length) {
            $sequence = [];
            for ($step = 0; $step < $length; $step++) {
                $sequence[] = $ids[random_int(0, count($ids) - 1)];
            }
            $sequences[] = $sequence;
        }
        $statement = $this->db->prepare(
            'INSERT INTO loot_sequence_states
                (session_id, sequences_json, inputs_json, round_number)
             VALUES (:session_id, :sequences_json, :inputs_json, 1)',
        );
        $statement->execute([
            'session_id' => $sessionId,
            'sequences_json' => json_encode($sequences, JSON_THROW_ON_ERROR),
            'inputs_json' => '[]',
        ]);
    }

    protected function publicGameState(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT sequences_json, round_number FROM loot_sequence_states WHERE session_id = :session_id',
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_STATE_INVALID', 'The sequence state is unavailable.', 409);
        }
        $sequences = $this->jsonArray((string) $state['sequences_json']);
        $round = (int) $state['round_number'];
        return [
            'round_number' => $round,
            'rounds_total' => count(self::ROUND_LENGTHS),
            'sequence' => $sequences[$round - 1] ?? [],
            'sequence_length' => self::ROUND_LENGTHS[$round - 1] ?? 0,
            'stickers' => self::STICKERS,
        ];
    }

    public function submitPattern(int $userId, int $sessionId, mixed $pattern): array
    {
        if (!is_array($pattern) || !array_is_list($pattern)) {
            throw new ApiException('VALIDATION_ERROR', 'A completed sequence pattern is required.', 422);
        }

        return $this->transaction(function () use ($userId, $sessionId, $pattern): array {
            $this->lockedSession($userId, $sessionId);
            $statement = $this->db->prepare(
                'SELECT sequences_json, inputs_json, round_number FROM loot_sequence_states
                 WHERE session_id = :session_id' . $this->lockClause(),
            );
            $statement->execute(['session_id' => $sessionId]);
            $stored = $statement->fetch();
            if ($stored === false) {
                throw new ApiException('GAME_STATE_INVALID', 'The sequence state is unavailable.', 409);
            }
            $sequences = $this->jsonArray((string) $stored['sequences_json']);
            $inputs = $this->jsonArray((string) $stored['inputs_json']);
            $round = (int) $stored['round_number'];
            $expected = $sequences[$round - 1] ?? null;
            if (
                !is_array($expected)
                || count($pattern) !== count($expected)
                || count(array_filter($pattern, static fn (mixed $id): bool =>
                    is_string($id) && in_array($id, array_column(self::STICKERS, 'id'), true))) !== count($pattern)
            ) {
                throw new ApiException('VALIDATION_ERROR', 'The pattern must contain exactly the current round length in supported stickers.', 422);
            }
            if ($pattern !== $expected) {
                $now = $this->now();
                $this->updateSession($sessionId, 'failed', $round, null, $now);
                return [
                    'session' => array_merge($this->getState($userId, $sessionId), ['status' => 'failed']),
                    'correct' => false,
                    'attempts_remaining_today' => $this->attemptsRemainingToday($userId),
                ];
            }

            $inputs[] = array_values($pattern);
            $nextRound = $round === count(self::ROUND_LENGTHS) ? $round : $round + 1;
            $statement = $this->db->prepare(
                'UPDATE loot_sequence_states SET inputs_json = :inputs_json, round_number = :round_number
                 WHERE session_id = :session_id',
            );
            $statement->execute([
                'inputs_json' => json_encode($inputs, JSON_THROW_ON_ERROR),
                'round_number' => $nextRound,
                'session_id' => $sessionId,
            ]);
            $now = $this->now();
            if ($round === count(self::ROUND_LENGTHS)) {
                $result = $this->finish($userId, $sessionId, $round, $now);
                $result['correct'] = true;
                return $result;
            }

            $this->updateSession($sessionId, 'in_progress', $round, null, $now);
            return [
                'session' => $this->getState($userId, $sessionId),
                'correct' => true,
                'attempts_remaining_today' => $this->attemptsRemainingToday($userId),
            ];
        });
    }
}
