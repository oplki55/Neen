<?php
declare(strict_types=1);

final class CoinsLedger
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function credit(
        int $userId,
        string $balanceType,
        int $amount,
        string $source,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
    ): int {
        $allowedSources = ['purchase_approval', 'earned_reward', 'game_reward', 'admin_adjustment', 'refund'];
        if (!in_array($source, $allowedSources, true)) {
            throw new InvalidArgumentException('Unsupported Coins credit source.');
        }
        if ($source === 'purchase_approval' && $balanceType !== 'purchased') {
            throw new InvalidArgumentException('Purchase approvals must credit Purchased Coins.');
        }
        if (in_array($source, ['earned_reward', 'game_reward'], true) && $balanceType !== 'earned') {
            throw new InvalidArgumentException('Rewards must credit Earned Coins.');
        }

        return $this->move(
            $userId,
            $balanceType,
            $amount,
            'credit',
            $source,
            $idempotencyKey,
            $referenceType,
            $referenceId,
            $note,
        );
    }

    public function creditInCurrentTransaction(
        int $userId,
        string $balanceType,
        int $amount,
        string $source,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
    ): int {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Coins credit must execute inside the caller transaction.');
        }
        if (
            $userId < 1
            || $balanceType !== 'earned'
            || $amount < 1
            || !in_array($source, ['earned_reward', 'game_reward'], true)
            || !preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)
        ) {
            throw new InvalidArgumentException('Invalid in-transaction Coins credit.');
        }

        $wallet = $this->lockWallet($userId);
        $existing = $this->findIdempotentEntry($userId, $idempotencyKey);
        if ($existing !== false) {
            if (
                $existing['operation_type'] !== 'credit'
                || $existing['source'] !== $source
                || $existing['balance_type'] !== $balanceType
                || (int) $existing['amount'] !== $amount
                || $existing['reference_type'] !== $referenceType
                || ($existing['reference_id'] === null ? null : (int) $existing['reference_id']) !== $referenceId
                || $existing['note'] !== $note
            ) {
                throw new ApiException('DUPLICATE_REQUEST', 'Idempotency key was already used.', 409);
            }
            return (int) $existing['id'];
        }

        $before = $this->bucketBalance($wallet, $balanceType);
        $totalBefore = (int) $wallet['coin_balance'];
        if ((int) $wallet['purchased_coin_balance'] + (int) $wallet['earned_coin_balance'] !== $totalBefore) {
            throw new RuntimeException('Wallet balance snapshot is inconsistent.');
        }
        if ($totalBefore > PHP_INT_MAX - $amount) {
            throw new RuntimeException('Wallet balance limit would be exceeded.');
        }

        $after = $before + $amount;
        $totalAfter = $totalBefore + $amount;
        $entryId = $this->insertEntry(
            $wallet,
            $userId,
            'credit',
            $source,
            $amount,
            $balanceType,
            $before,
            $after,
            $totalBefore,
            $totalAfter,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            null,
            $note,
        );
        $this->updateWallet($wallet, $balanceType, $after, $totalAfter);
        return $entryId;
    }

    public function debitAfterConfirmedUsage(
        int $userId,
        string $balanceType,
        int $amount,
        int $usageReferenceId,
        string $idempotencyKey,
        string $note,
    ): int {
        if ($usageReferenceId < 1 || trim($note) === '') {
            throw new InvalidArgumentException('Confirmed usage requires a reference and explanation.');
        }

        return $this->move(
            $userId,
            $balanceType,
            $amount,
            'debit',
            'service_usage',
            $idempotencyKey,
            'service_usage',
            $usageReferenceId,
            $note,
        );
    }

    public function debitForGameAttempt(
        int $userId,
        int $amount,
        string $idempotencyKey,
        string $gameSlug,
    ): int {
        if ($amount !== 100 || trim($gameSlug) === '') {
            throw new InvalidArgumentException('Invalid game attempt purchase.');
        }
        return $this->move(
            $userId,
            'earned',
            $amount,
            'debit',
            'service_usage',
            $idempotencyKey,
            'game_attempt',
            null,
            'Extra attempt for ' . $gameSlug,
        );
    }

    public function adjust(
        int $userId,
        string $balanceType,
        int $amount,
        string $direction,
        int $adminUserId,
        string $reason,
        string $idempotencyKey,
    ): int {
        if (!in_array($direction, ['credit', 'debit'], true) || trim($reason) === '') {
            throw new InvalidArgumentException('Wallet adjustment requires a direction and reason.');
        }

        return $this->move(
            $userId,
            $balanceType,
            $amount,
            $direction,
            'admin_adjustment',
            $idempotencyKey,
            'admin_adjustment',
            $adminUserId,
            $reason,
        );
    }

    public function reverse(int $userId, int $ledgerEntryId, string $reason, string $idempotencyKey): int
    {
        if (
            $ledgerEntryId < 1
            || trim($reason) === ''
            || strlen($reason) > 1000
            || !preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)
        ) {
            throw new InvalidArgumentException('A ledger reference and reversal reason are required.');
        }

        return $this->transaction(function () use ($userId, $ledgerEntryId, $reason, $idempotencyKey): int {
            $original = $this->findEntry($userId, $ledgerEntryId, true);
            if ($original === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Ledger entry not found.', 404);
            }

            $existing = $this->findIdempotentEntry($userId, $idempotencyKey);
            if ($existing !== false) {
                if (
                    $existing['operation_type'] !== 'reversal'
                    || (int) $existing['reversal_of_id'] !== $ledgerEntryId
                    || $existing['note'] !== $reason
                ) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Idempotency key was already used.', 409);
                }
                return (int) $existing['id'];
            }

            $statement = $this->db->prepare(
                'SELECT id FROM coin_ledger WHERE reversal_of_id = :entry_id',
            );
            $statement->execute(['entry_id' => $ledgerEntryId]);
            if ($statement->fetchColumn() !== false) {
                throw new ApiException('DUPLICATE_REQUEST', 'Ledger entry has already been reversed.', 409);
            }

            $wallet = $this->lockWallet($userId);
            $balanceType = (string) $original['balance_type'];
            $amount = (int) $original['amount'];
            $originalOperation = (string) $original['operation_type'];
            $direction = $originalOperation === 'credit' ? 'debit' : 'credit';
            $before = $this->bucketBalance($wallet, $balanceType);
            $totalBefore = (int) $wallet['coin_balance'];

            if ($direction === 'debit' && $before < $amount) {
                throw new ApiException(
                    'INSUFFICIENT_BALANCE',
                    'This credit cannot be reversed because the affected balance has already been spent.',
                    409,
                );
            }

            $after = $direction === 'credit' ? $before + $amount : $before - $amount;
            $totalAfter = $direction === 'credit' ? $totalBefore + $amount : $totalBefore - $amount;
            $entryId = $this->insertEntry(
                $wallet,
                $userId,
                'reversal',
                'correction',
                $amount,
                $balanceType,
                $before,
                $after,
                $totalBefore,
                $totalAfter,
                'coin_ledger',
                $ledgerEntryId,
                $idempotencyKey,
                $ledgerEntryId,
                $reason,
            );
            $this->updateWallet($wallet, $balanceType, $after, $totalAfter);

            return $entryId;
        });
    }

    private function move(
        int $userId,
        string $balanceType,
        int $amount,
        string $direction,
        string $source,
        string $idempotencyKey,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note,
    ): int {
        if ($userId < 1 || !in_array($balanceType, ['purchased', 'earned'], true)) {
            throw new InvalidArgumentException('Invalid wallet or Coins balance type.');
        }
        if ($amount < 1 || $amount > PHP_INT_MAX - 1) {
            throw new InvalidArgumentException('Coins amount must be a positive whole number.');
        }
        if (!preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)) {
            throw new InvalidArgumentException('Idempotency key must be 8 to 100 safe characters.');
        }
        if (
            ($referenceType !== null && !preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $referenceType))
            || ($referenceId !== null && $referenceId < 1)
            || ($note !== null && (trim($note) === '' || strlen($note) > 1000))
        ) {
            throw new InvalidArgumentException('Invalid Coins ledger reference or note.');
        }

        return $this->transaction(function () use (
            $userId,
            $balanceType,
            $amount,
            $direction,
            $source,
            $idempotencyKey,
            $referenceType,
            $referenceId,
            $note,
        ): int {
            $wallet = $this->lockWallet($userId);
            $existing = $this->findIdempotentEntry($userId, $idempotencyKey);
            if ($existing !== false) {
                if (
                    $existing['operation_type'] !== $direction
                    || $existing['source'] !== $source
                    || $existing['balance_type'] !== $balanceType
                    || (int) $existing['amount'] !== $amount
                    || $existing['reference_type'] !== $referenceType
                    || ($existing['reference_id'] === null ? null : (int) $existing['reference_id']) !== $referenceId
                    || $existing['note'] !== $note
                ) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Idempotency key was already used.', 409);
                }
                return (int) $existing['id'];
            }

            $before = $this->bucketBalance($wallet, $balanceType);
            $totalBefore = (int) $wallet['coin_balance'];
            if ((int) $wallet['purchased_coin_balance'] + (int) $wallet['earned_coin_balance'] !== $totalBefore) {
                throw new RuntimeException('Wallet balance snapshot is inconsistent.');
            }

            if ($direction === 'debit' && $before < $amount) {
                throw new ApiException('INSUFFICIENT_BALANCE', 'Insufficient Coins balance.', 409);
            }
            if ($direction === 'credit' && $totalBefore > PHP_INT_MAX - $amount) {
                throw new RuntimeException('Wallet balance limit would be exceeded.');
            }

            $after = $direction === 'credit' ? $before + $amount : $before - $amount;
            $totalAfter = $direction === 'credit' ? $totalBefore + $amount : $totalBefore - $amount;
            $entryId = $this->insertEntry(
                $wallet,
                $userId,
                $direction,
                $source,
                $amount,
                $balanceType,
                $before,
                $after,
                $totalBefore,
                $totalAfter,
                $referenceType,
                $referenceId,
                $idempotencyKey,
                null,
                $note,
            );
            $this->updateWallet($wallet, $balanceType, $after, $totalAfter);

            return $entryId;
        });
    }

    private function insertEntry(
        array $wallet,
        int $userId,
        string $operation,
        string $source,
        int $amount,
        string $balanceType,
        int $before,
        int $after,
        int $totalBefore,
        int $totalAfter,
        ?string $referenceType,
        ?int $referenceId,
        string $idempotencyKey,
        ?int $reversalOfId,
        ?string $note,
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO coin_ledger
                (wallet_id, user_id, operation_type, source, amount, balance_type,
                 balance_before, balance_after, total_balance_before, total_balance_after,
                 status, reference_type, reference_id, idempotency_key, reversal_of_id, note, created_at)
             VALUES
                (:wallet_id, :user_id, :operation_type, :source, :amount, :balance_type,
                 :balance_before, :balance_after, :total_balance_before, :total_balance_after,
                 \'completed\', :reference_type, :reference_id, :idempotency_key, :reversal_of_id, :note, :created_at)',
        );
        $statement->execute([
            'wallet_id' => $wallet['id'],
            'user_id' => $userId,
            'operation_type' => $operation,
            'source' => $source,
            'amount' => $amount,
            'balance_type' => $balanceType,
            'balance_before' => $before,
            'balance_after' => $after,
            'total_balance_before' => $totalBefore,
            'total_balance_after' => $totalAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'reversal_of_id' => $reversalOfId,
            'note' => $note,
            'created_at' => $this->now(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function updateWallet(array $wallet, string $balanceType, int $bucketAfter, int $totalAfter): void
    {
        $column = $balanceType === 'purchased' ? 'purchased_coin_balance' : 'earned_coin_balance';
        $statement = $this->db->prepare(
            'UPDATE wallets SET ' . $column . ' = :bucket_after, coin_balance = :total_after,
                version = version + 1, updated_at = :updated_at
             WHERE id = :wallet_id AND version = :version',
        );
        $statement->execute([
            'bucket_after' => $bucketAfter,
            'total_after' => $totalAfter,
            'updated_at' => $this->now(),
            'wallet_id' => $wallet['id'],
            'version' => $wallet['version'],
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Wallet was concurrently modified.');
        }
    }

    private function lockWallet(int $userId): array
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT id, user_id, coin_balance, purchased_coin_balance, earned_coin_balance, version
             FROM wallets WHERE user_id = :user_id' . $lock,
        );
        $statement->execute(['user_id' => $userId]);
        $wallet = $statement->fetch();
        if ($wallet === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Wallet not found.', 404);
        }
        return $wallet;
    }

    private function findIdempotentEntry(int $userId, string $key): array|false
    {
        $statement = $this->db->prepare(
            'SELECT id, operation_type, source, amount, balance_type, reference_type, reference_id, reversal_of_id, note
             FROM coin_ledger WHERE user_id = :user_id AND idempotency_key = :idempotency_key',
        );
        $statement->execute(['user_id' => $userId, 'idempotency_key' => $key]);
        return $statement->fetch();
    }

    private function findEntry(int $userId, int $entryId, bool $lock): array|false
    {
        $lockClause = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT id, wallet_id, operation_type, amount, balance_type, status
             FROM coin_ledger WHERE user_id = :user_id AND id = :id' . $lockClause,
        );
        $statement->execute(['user_id' => $userId, 'id' => $entryId]);
        return $statement->fetch();
    }

    private function bucketBalance(array $wallet, string $balanceType): int
    {
        $column = $balanceType === 'purchased' ? 'purchased_coin_balance' : 'earned_coin_balance';
        return (int) $wallet[$column];
    }

    private function transaction(callable $operation): mixed
    {
        $startedHere = !$this->db->inTransaction();
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($startedHere) {
            if ($driver === 'sqlite') {
                $this->db->exec('BEGIN IMMEDIATE');
            } else {
                $this->db->beginTransaction();
            }
        }

        try {
            $result = $operation();
            if ($startedHere) {
                if ($driver === 'sqlite') {
                    $this->db->exec('COMMIT');
                } else {
                    $this->db->commit();
                }
            }
            return $result;
        } catch (Throwable $exception) {
            if ($startedHere) {
                if ($driver === 'sqlite') {
                    $this->db->exec('ROLLBACK');
                } elseif ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
