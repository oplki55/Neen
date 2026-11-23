<?php
declare(strict_types=1);

final class GameRewards
{
    private const TARGET_RUSH_TARGET_INTERVAL_MS = 1_100;
    private const TARGET_RUSH_TARGET_COUNT = 28;
    private const TARGET_RUSH_NETWORK_GRACE_MS = 300;

    public function __construct(private readonly PDO $db)
    {
    }

    public function awardCompletedWin(int $userId, string $gameSlug, int $sessionId): array
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Game rewards must be issued inside the game completion transaction.');
        }
        if ($userId < 1 || $sessionId < 1 || !in_array(
            $gameSlug,
            ['memory-match', 'picture-jigsaw', 'vault_cracker', 'loot_sequence', 'sunny_island'],
            true,
        )) {
            throw new InvalidArgumentException('Invalid completed game reference.');
        }

        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT s.id, s.user_id, s.status, s.expires_at, s.completed_at, s.matched_pairs,
                    s.move_count, s.score, s.started_at,
                    g.slug, g.status AS game_status, g.reward_amount_coins
             FROM game_sessions s
             JOIN game_catalog g ON g.id = s.game_id
             WHERE s.id = :session_id AND s.user_id = :user_id AND g.slug = :slug'
             . $lock,
        );
        $statement->execute([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'slug' => $gameSlug,
        ]);
        $session = $statement->fetch();
        if (
            $session === false
            || $session['status'] !== 'completed'
            || $session['completed_at'] === null
            || $session['game_status'] !== 'active'
            || $session['expires_at'] <= $this->now()
        ) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'A completed active game session is required for a reward.', 409);
        }

        if ($gameSlug === 'memory-match') {
            $this->verifyMemoryMatch($sessionId, (int) $session['matched_pairs']);
        } elseif ($gameSlug === 'picture-jigsaw') {
            $this->verifyPictureJigsaw($sessionId);
        } elseif ($gameSlug === 'vault_cracker') {
            $this->verifyTargetRush(
                $sessionId,
                (int) $session['score'],
                (int) $session['move_count'],
                (string) $session['started_at'],
            );
        } elseif ($gameSlug === 'loot_sequence') {
            $this->verifyLootSequence($sessionId);
        } else {
            $this->verifySunnyIsland($sessionId);
        }

        $coinsLedger = new CoinsLedger($this->db);
        $ledgerEntryId = $gameSlug === 'sunny_island'
            ? $coinsLedger->creditInCurrentTransaction(
                $userId,
                'earned',
                (int) $session['reward_amount_coins'],
                'game_reward',
                'game-win-' . $gameSlug . '-' . $sessionId,
                'game_session',
                $sessionId,
                'Server-verified ' . $gameSlug . ' win.',
            )
            : $coinsLedger->credit(
                $userId,
                'earned',
                (int) $session['reward_amount_coins'],
                'game_reward',
                'game-win-' . $gameSlug . '-' . $sessionId,
                'game_session',
                $sessionId,
                'Server-verified ' . $gameSlug . ' win.',
            );
        $entry = $this->db->prepare(
            'SELECT amount, balance_after, total_balance_after
             FROM coin_ledger WHERE id = :id AND user_id = :user_id',
        );
        $entry->execute(['id' => $ledgerEntryId, 'user_id' => $userId]);
        $balances = $entry->fetch();
        if ($balances === false) {
            throw new RuntimeException('The game reward ledger entry could not be read after credit.');
        }

        $xpAwarded = 0;

        return [
            'amount' => (int) $balances['amount'],
            'balance_type' => 'earned',
            'earned_coin_balance' => (int) $balances['balance_after'],
            'coin_balance' => (int) $balances['total_balance_after'],
            'ledger_entry_id' => $ledgerEntryId,
            'xp_awarded' => $xpAwarded,
        ];
    }

    private function verifyMemoryMatch(int $sessionId, int $matchedPairs): void
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT deck_json, matched_cards_json, open_cards_json, mismatch_at
             FROM memory_match_states WHERE session_id = :session_id' . $lock,
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Memory Match board state is unavailable.', 409);
        }

        try {
            $deck = json_decode($state['deck_json'], true, 16, JSON_THROW_ON_ERROR);
            $matchedCards = json_decode($state['matched_cards_json'], true, 16, JSON_THROW_ON_ERROR);
            $openCards = json_decode($state['open_cards_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Memory Match board state is invalid.', 409);
        }
        if (
            !is_array($deck)
            || count($deck) < 2
            || count($deck) % 2 !== 0
            || !is_array($matchedCards)
            || !is_array($openCards)
            || $openCards !== []
            || $state['mismatch_at'] !== null
            || $matchedPairs !== count($deck) / 2
            || count($matchedCards) !== count($deck)
        ) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'Every Memory Match pair must be matched before awarding a win.', 409);
        }

        $imageCounts = [];
        foreach ($deck as $imageId) {
            if (!is_string($imageId) || $imageId === '') {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Memory Match deck is invalid.', 409);
            }
            $imageCounts[$imageId] = ($imageCounts[$imageId] ?? 0) + 1;
        }
        foreach ($imageCounts as $count) {
            if ($count !== 2) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Memory Match deck does not contain complete pairs.', 409);
            }
        }
        foreach ($deck as $index => $imageId) {
            if (($matchedCards[(string) $index] ?? null) !== $imageId) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Memory Match result does not match the stored deck.', 409);
            }
        }
    }

    private function verifyPictureJigsaw(int $sessionId): void
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT permutation_json, board_size FROM picture_jigsaw_states
             WHERE session_id = :session_id' . $lock,
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Picture Jigsaw board state is unavailable.', 409);
        }

        try {
            $permutation = json_decode($state['permutation_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Picture Jigsaw board state is invalid.', 409);
        }
        $boardSize = (int) $state['board_size'];
        $pieceCount = $boardSize ** 2;
        if (
            $boardSize < 2
            || !is_array($permutation)
            || count($permutation) !== $pieceCount
            || array_values($permutation) !== range(0, $pieceCount - 1)
        ) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'Every Picture Jigsaw piece must be in its correct position.', 409);
        }
    }

    private function verifyTargetRush(int $sessionId, int $storedScore, int $moveCount, string $sessionStartedAt): void
    {
        $statement = $this->db->prepare(
            'SELECT secret_json, guesses_json FROM vault_cracker_states WHERE session_id = :session_id'
            . ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Target Rush state is unavailable.', 409);
        }
        try {
            $targets = json_decode($state['secret_json'], true, 16, JSON_THROW_ON_ERROR);
            $run = json_decode($state['guesses_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Target Rush state is invalid.', 409);
        }
        $nowMs = (int) floor(microtime(true) * 1000);
        if (
            !is_array($targets)
            || !array_is_list($targets)
            || count($targets) !== self::TARGET_RUSH_TARGET_COUNT
            || !is_array($run)
            || array_is_list($run)
            || !is_int($run['started_at_ms'] ?? null)
            || !is_int($run['finished_at_ms'] ?? null)
            || !is_array($run['hits'] ?? null)
            || !array_is_list($run['hits'])
            || count($run['hits']) !== $moveCount
            || $run['finished_at_ms'] - $run['started_at_ms'] < 30_000
            || $run['finished_at_ms'] > $nowMs + 1_000
            || $run['started_at_ms'] < ((int) strtotime($sessionStartedAt) * 1000)
            || $run['started_at_ms'] > $nowMs
        ) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Target Rush run timing or state is invalid.', 409);
        }

        $tokens = [];
        foreach ($targets as $target) {
            if (
                !is_array($target)
                || !is_string($target['token'] ?? null)
                || !preg_match('/\A[a-f0-9]{32}\z/', $target['token'])
                || !in_array($target['type'] ?? null, ['normal', 'gold', 'danger'], true)
                || !in_array($target['position'] ?? null, [
                    'upper-left', 'upper-right', 'middle-left', 'middle-right', 'lower-left', 'lower-right',
                ], true)
                || isset($tokens[$target['token']])
            ) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'A Target Rush target is invalid.', 409);
            }
            $tokens[$target['token']] = true;
        }

        $score = 0;
        $combo = 0;
        $previousIndex = null;
        $seenIndexes = [];
        foreach ($run['hits'] as $hit) {
            if (!is_array($hit)) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'A Target Rush hit is invalid.', 409);
            }
            $index = $hit['index'] ?? null;
            if (
                !is_int($index)
                || $index < 0
                || $index >= count($targets)
                || !isset($targets[$index])
                || ($hit['token'] ?? null) !== $targets[$index]['token']
                || ($hit['type'] ?? null) !== $targets[$index]['type']
                || !is_int($hit['received_at_ms'] ?? null)
                || isset($seenIndexes[$index])
                || ($previousIndex !== null && $index <= $previousIndex)
                || $hit['received_at_ms'] < $run['started_at_ms'] + ($index * self::TARGET_RUSH_TARGET_INTERVAL_MS)
                || $hit['received_at_ms'] > min(
                    $run['started_at_ms'] + (($index + 1) * self::TARGET_RUSH_TARGET_INTERVAL_MS),
                    $run['started_at_ms'] + 30_000,
                ) + self::TARGET_RUSH_NETWORK_GRACE_MS
            ) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'A Target Rush hit is invalid or outside its server time slot.', 409);
            }
            $seenIndexes[$index] = true;
            if ($previousIndex !== null && $index !== $previousIndex + 1) {
                $combo = 0;
            }
            if ($targets[$index]['type'] === 'danger') {
                $score = max(0, $score - 15);
                $combo = 0;
            } else {
                $combo++;
                $bonus = min(5, $combo - 1);
                $score += $targets[$index]['type'] === 'gold' ? 20 + ($bonus * 3) : 10 + ($bonus * 2);
            }
            $previousIndex = $index;
        }

        if ($score < 200 || $storedScore !== $score) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Target Rush score does not meet the verified win goal.', 409);
        }
    }

    private function verifyLootSequence(int $sessionId): void
    {
        $statement = $this->db->prepare(
            'SELECT sequences_json, inputs_json, round_number
             FROM loot_sequence_states WHERE session_id = :session_id'
            . ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Loot Sequence state is unavailable.', 409);
        }
        try {
            $sequences = json_decode($state['sequences_json'], true, 16, JSON_THROW_ON_ERROR);
            $inputs = json_decode($state['inputs_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Loot Sequence state is invalid.', 409);
        }
        $validSequences = is_array($sequences) && array_is_list($sequences) && count($sequences) === 4;
        if ($validSequences) {
            foreach ($sequences as $index => $sequence) {
                if (!is_array($sequence) || !array_is_list($sequence) || count($sequence) !== $index + 3) {
                    $validSequences = false;
                    break;
                }
            }
        }
        if (
            (int) $state['round_number'] !== 4
            || !$validSequences
            || !is_array($inputs)
            || !array_is_list($inputs)
            || count($inputs) !== 4
            || $inputs !== $sequences
        ) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'Every Loot Sequence round must match the server pattern.', 409);
        }
    }

    private function verifySunnyIsland(int $sessionId): void
    {
        $statement = $this->db->prepare(
            'SELECT contract_json, completed_objectives_json
             FROM sunny_island_states WHERE session_id = :session_id'
            . ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Sunny Island adventure state is unavailable.', 409);
        }
        try {
            $contract = json_decode($state['contract_json'], true, 16, JSON_THROW_ON_ERROR);
            $completed = json_decode($state['completed_objectives_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Sunny Island adventure state is invalid.', 409);
        }
        $objectives = $contract['objectives'] ?? null;
        if (
            !is_array($contract)
            || array_is_list($contract)
            || ($contract['version'] ?? null) !== 1
            || !is_array($objectives)
            || !array_is_list($objectives)
            || count($objectives) !== 3
            || !is_array($completed)
            || !array_is_list($completed)
            || count($completed) !== count($objectives)
        ) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Sunny Island adventure contract is invalid.', 409);
        }

        $objectiveIds = SunnyIsland::objectiveIds();
        $required = [];
        foreach ($objectives as $objective) {
            if (
                !is_array($objective)
                || !is_string($objective['id'] ?? null)
                || !in_array($objective['id'], $objectiveIds, true)
                || !is_string($objective['token'] ?? null)
                || strlen($objective['token']) < 32
                || in_array($objective['id'], $required, true)
            ) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'The Sunny Island adventure objectives are invalid.', 409);
            }
            $required[$objective['id']] = $objective['token'];
        }
        $completedIds = [];
        foreach ($completed as $receipt) {
            if (
                !is_array($receipt)
                || !is_string($receipt['id'] ?? null)
                || !is_string($receipt['proof'] ?? null)
                || !isset($required[$receipt['id']])
                || !hash_equals(
                    hash_hmac('sha256', $receipt['id'], $required[$receipt['id']]),
                    $receipt['proof'],
                )
                || in_array($receipt['id'], $completedIds, true)
            ) {
                throw new ApiException('GAME_WIN_NOT_VERIFIED', 'A Sunny Island objective receipt is invalid.', 409);
            }
            $completedIds[] = $receipt['id'];
        }
        $requiredIds = array_keys($required);
        sort($requiredIds);
        sort($completedIds);
        if ($completedIds !== $requiredIds) {
            throw new ApiException('GAME_WIN_NOT_VERIFIED', 'Every Sunny Island objective must be verified.', 409);
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
