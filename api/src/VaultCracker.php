<?php
declare(strict_types=1);

final class VaultCracker extends ChallengeGame
{
    private const GAME_SLUG = 'vault_cracker';
    private const ICONS = [
        ['id' => 'weapon-ak47', 'src' => '/assets/hero-loot/weapon-ak47.webp'],
        ['id' => 'weapon-mp40', 'src' => '/assets/hero-loot/weapon-mp40.webp'],
        ['id' => 'loot-crate', 'src' => '/assets/hero-loot/loot-crate.webp'],
        ['id' => 'backpack', 'src' => '/assets/hero-loot/backpack.webp'],
        ['id' => 'gold-coin', 'src' => '/assets/hero-loot/gold-coin.webp'],
        ['id' => 'snoop-sticker-01', 'src' => '/assets/hero-loot/snoop-sticker-01.webp'],
    ];
    private const MAX_ATTEMPTS = 6;

    protected function slug(): string
    {
        return self::GAME_SLUG;
    }

    public function catalog(): array
    {
        return parent::catalog() + ['icons' => self::ICONS];
    }

    protected function initializeState(int $sessionId): void
    {
        $icons = array_column(self::ICONS, 'id');
        for ($index = count($icons) - 1; $index > 0; $index--) {
            $swap = random_int(0, $index);
            [$icons[$index], $icons[$swap]] = [$icons[$swap], $icons[$index]];
        }
        $secret = array_slice($icons, 0, 4);
        $statement = $this->db->prepare(
            'INSERT INTO vault_cracker_states (session_id, secret_json, guesses_json)
             VALUES (:session_id, :secret_json, :guesses_json)',
        );
        $statement->execute([
            'session_id' => $sessionId,
            'secret_json' => json_encode($secret, JSON_THROW_ON_ERROR),
            'guesses_json' => '[]',
        ]);
    }

    protected function publicGameState(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT guesses_json FROM vault_cracker_states WHERE session_id = :session_id',
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_STATE_INVALID', 'The vault state is unavailable.', 409);
        }
        $guesses = $this->jsonArray((string) $state['guesses_json']);
        return [
            'attempts_used' => count($guesses),
            'attempts_remaining' => max(0, self::MAX_ATTEMPTS - count($guesses)),
            'max_attempts' => self::MAX_ATTEMPTS,
            'icons' => self::ICONS,
            'guesses' => $guesses,
        ];
    }

    public function submitGuess(int $userId, int $sessionId, mixed $guess): array
    {
        if (!is_array($guess) || !array_is_list($guess) || count($guess) !== 4) {
            throw new ApiException('VALIDATION_ERROR', 'A vault guess must contain four distinct icons.', 422);
        }
        $allowed = array_column(self::ICONS, 'id');
        if (
            count(array_filter($guess, static fn (mixed $icon): bool =>
                is_string($icon) && in_array($icon, $allowed, true))) !== 4
            || count(array_unique($guess)) !== 4
        ) {
            throw new ApiException('VALIDATION_ERROR', 'A vault guess must contain four distinct supported icons.', 422);
        }

        return $this->transaction(function () use ($userId, $sessionId, $guess): array {
            $session = $this->lockedSession($userId, $sessionId);
            $statement = $this->db->prepare(
                'SELECT secret_json, guesses_json FROM vault_cracker_states
                 WHERE session_id = :session_id' . $this->lockClause(),
            );
            $statement->execute(['session_id' => $sessionId]);
            $stored = $statement->fetch();
            if ($stored === false) {
                throw new ApiException('GAME_STATE_INVALID', 'The vault state is unavailable.', 409);
            }
            $secret = $this->jsonArray((string) $stored['secret_json']);
            $guesses = $this->jsonArray((string) $stored['guesses_json']);
            if (count($guesses) >= self::MAX_ATTEMPTS) {
                throw new ApiException('GAME_SESSION_CLOSED', 'This vault has no attempts remaining.', 409);
            }

            $exact = 0;
            foreach ($guess as $index => $icon) {
                if ($secret[$index] === $icon) {
                    $exact++;
                }
            }
            $matches = count(array_intersect($secret, $guess));
            $guesses[] = [
                'icons' => array_values($guess),
                'exact_matches' => $exact,
                'wrong_position_matches' => $matches - $exact,
            ];
            $statement = $this->db->prepare(
                'UPDATE vault_cracker_states SET guesses_json = :guesses_json WHERE session_id = :session_id',
            );
            $statement->execute([
                'guesses_json' => json_encode($guesses, JSON_THROW_ON_ERROR),
                'session_id' => $sessionId,
            ]);

            $now = $this->now();
            if ($exact === 4) {
                $result = $this->finish($userId, $sessionId, count($guesses), $now);
                $result['attempt_result'] = end($guesses);
                return $result;
            }
            $status = count($guesses) === self::MAX_ATTEMPTS ? 'failed' : 'in_progress';
            $this->updateSession($sessionId, $status, count($guesses), null, $now);
            return [
                'session' => $this->getState($userId, $sessionId),
                'attempt_result' => end($guesses),
                'attempts_remaining_today' => $this->attemptsRemainingToday($userId),
            ];
        });
    }
}
