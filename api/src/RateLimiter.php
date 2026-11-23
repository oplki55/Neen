<?php
declare(strict_types=1);

final class RateLimiter
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $appKey,
    ) {
    }

    public function consume(string $bucket, int $limit, int $windowSeconds): void
    {
        $now = time();
        $cutoff = $now - $windowSeconds;
        $hash = hash_hmac('sha256', $bucket, $this->appKey);
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = 'INSERT INTO auth_rate_limits (bucket_hash, attempts, window_started_at)
                    VALUES (:hash, 1, :now_insert)
                    ON DUPLICATE KEY UPDATE
                        attempts = CASE WHEN window_started_at <= :cutoff_attempts THEN 1 ELSE attempts + 1 END,
                        window_started_at = CASE WHEN window_started_at <= :cutoff_window THEN :now_update ELSE window_started_at END';
        } else {
            $sql = 'INSERT INTO auth_rate_limits (bucket_hash, attempts, window_started_at)
                    VALUES (:hash, 1, :now_insert)
                    ON CONFLICT (bucket_hash) DO UPDATE SET
                        attempts = CASE WHEN window_started_at <= :cutoff_attempts THEN 1 ELSE attempts + 1 END,
                        window_started_at = CASE WHEN window_started_at <= :cutoff_window THEN :now_update ELSE window_started_at END';
        }

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare($sql);
            $statement->execute([
                'hash' => $hash,
                'now_insert' => $now,
                'cutoff_attempts' => $cutoff,
                'cutoff_window' => $cutoff,
                'now_update' => $now,
            ]);

            $statement = $this->db->prepare(
                'SELECT attempts, window_started_at FROM auth_rate_limits WHERE bucket_hash = :hash',
            );
            $statement->execute(['hash' => $hash]);
            $row = $statement->fetch();
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        if ($row !== false && (int) $row['attempts'] > $limit) {
            $retryAfter = max(1, (int) $row['window_started_at'] + $windowSeconds - $now);
            throw new ApiException(
                'RATE_LIMITED',
                'Too many requests. Please try again later.',
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }
    }
}
