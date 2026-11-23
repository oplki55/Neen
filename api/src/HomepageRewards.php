<?php
declare(strict_types=1);

final class HomepageRewards
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function state(int $userId): array
    {
        $configuration = $this->configuration();
        $wallet = $this->wallet($userId);
        $window = $this->window($configuration);
        $claim = null;
        if ($window !== null) {
            $statement = $this->db->prepare(
                'SELECT reward_amount, created_at FROM homepage_reward_claims
                 WHERE user_id = :user_id AND window_key = :window_key',
            );
            $statement->execute(['user_id' => $userId, 'window_key' => $window['key']]);
            $claim = $statement->fetch();
        }

        return [
            'configured' => $configuration['is_active']
                && $configuration['reward_amount'] !== null
                && $configuration['window_seconds'] !== null,
            'can_claim' => $window !== null && $claim === false,
            'reward_amount' => $configuration['reward_amount'],
            'window_seconds' => $configuration['window_seconds'],
            'next_window_at' => $window['ends_at'] ?? null,
            'claimed' => $claim !== false && $claim !== null,
            'last_claim_amount' => $claim === false || $claim === null ? null : (int) $claim['reward_amount'],
            'wallet' => $wallet,
        ];
    }

    public function claim(int $userId): array
    {
        $result = $this->transaction(function () use ($userId): array {
            $this->lockActiveUser($userId);
            $configuration = $this->configuration(true);
            $window = $this->window($configuration);
            if ($window === null) {
                throw new ApiException('REWARD_UNAVAILABLE', 'Homepage rewards are not configured or active.', 409);
            }

            $statement = $this->db->prepare(
                'SELECT id, reward_amount FROM homepage_reward_claims
                 WHERE user_id = :user_id AND window_key = :window_key',
            );
            $statement->execute(['user_id' => $userId, 'window_key' => $window['key']]);
            $existing = $statement->fetch();
            if ($existing !== false) {
                return [
                    'created' => false,
                    'amount' => (int) $existing['reward_amount'],
                    'wallet' => $this->wallet($userId),
                ];
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                'INSERT INTO homepage_reward_claims
                    (user_id, window_key, reward_amount, ledger_entry_id, created_at)
                 VALUES (:user_id, :window_key, :reward_amount, NULL, :created_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'window_key' => $window['key'],
                'reward_amount' => $configuration['reward_amount'],
                'created_at' => $now,
            ]);
            $claimId = (int) $this->db->lastInsertId();
            $ledgerEntryId = (new CoinsLedger($this->db))->credit(
                $userId,
                'earned',
                $configuration['reward_amount'],
                'earned_reward',
                'homepage-reward-' . $userId . '-' . $window['key'],
                'homepage_reward',
                $claimId,
                'Homepage reward for window ' . $window['key'] . '.',
            );

            $statement = $this->db->prepare(
                'UPDATE homepage_reward_claims SET ledger_entry_id = :ledger_entry_id
                 WHERE id = :id AND user_id = :user_id AND ledger_entry_id IS NULL',
            );
            $statement->execute([
                'ledger_entry_id' => $ledgerEntryId,
                'id' => $claimId,
                'user_id' => $userId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Homepage reward could not be linked to its wallet entry.');
            }

            return [
                'created' => true,
                'amount' => $configuration['reward_amount'],
                'wallet' => $this->wallet($userId),
            ];
        });

        return $result;
    }

    public function configuration(bool $lock = false): array
    {
        $lockClause = $lock && $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->query(
            'SELECT reward_amount, window_seconds, is_active, version
             FROM homepage_reward_config WHERE id = 1' . $lockClause,
        );
        $configuration = $statement->fetch();
        if ($configuration === false) {
            throw new RuntimeException('Homepage reward configuration row is missing.');
        }

        return [
            'reward_amount' => $configuration['reward_amount'] === null
                ? null
                : (int) $configuration['reward_amount'],
            'window_seconds' => $configuration['window_seconds'] === null
                ? null
                : (int) $configuration['window_seconds'],
            'is_active' => (bool) $configuration['is_active'],
            'version' => (int) $configuration['version'],
        ];
    }

    public function configure(int $adminUserId, ?int $rewardAmount, ?int $windowSeconds, bool $isActive): array
    {
        if ($rewardAmount !== null && $rewardAmount < 1) {
            throw new InvalidArgumentException('Homepage reward amount must be positive.');
        }
        if ($windowSeconds !== null && $windowSeconds < 1) {
            throw new InvalidArgumentException('Homepage reward window must be positive.');
        }
        if ($isActive && ($rewardAmount === null || $windowSeconds === null)) {
            throw new InvalidArgumentException('An active homepage reward requires an amount and window.');
        }

        $this->transaction(function () use ($adminUserId, $rewardAmount, $windowSeconds, $isActive): void {
            $configuration = $this->configuration(true);
            $statement = $this->db->prepare(
                'UPDATE homepage_reward_config
                 SET reward_amount = :reward_amount, window_seconds = :window_seconds,
                     is_active = :is_active, version = version + 1, updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = 1 AND version = :version',
            );
            $statement->execute([
                'reward_amount' => $rewardAmount,
                'window_seconds' => $windowSeconds,
                'is_active' => $isActive ? 1 : 0,
                'updated_by' => $adminUserId,
                'updated_at' => $this->now(),
                'version' => $configuration['version'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Homepage reward configuration changed concurrently.');
            }
        });

        return $this->configuration();
    }

    private function window(array $configuration): ?array
    {
        if (
            !$configuration['is_active']
            || $configuration['reward_amount'] === null
            || $configuration['window_seconds'] === null
        ) {
            return null;
        }

        $now = time();
        $start = intdiv($now, $configuration['window_seconds']) * $configuration['window_seconds'];
        $end = $start + $configuration['window_seconds'];
        return [
            'key' => 'v' . $configuration['version'] . '-' . $start,
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end),
        ];
    }

    private function wallet(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT coin_balance, purchased_coin_balance, earned_coin_balance
             FROM wallets WHERE user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $wallet = $statement->fetch();
        if ($wallet === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Wallet not found.', 404);
        }
        return [
            'coin_balance' => (int) $wallet['coin_balance'],
            'purchased_coin_balance' => (int) $wallet['purchased_coin_balance'],
            'earned_coin_balance' => (int) $wallet['earned_coin_balance'],
        ];
    }

    private function lockActiveUser(int $userId): void
    {
        $lock = $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            "SELECT id FROM users WHERE id = :user_id AND status = 'active'" . $lock,
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

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
