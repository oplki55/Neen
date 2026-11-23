<?php
declare(strict_types=1);

final class Attendance
{
    private const TIMEZONE = 'Africa/Cairo';
    private const DAILY_REWARD = 100;
    private const CYCLE_DAYS = 7;

    public function __construct(
        private readonly PDO $db,
        private readonly ?Closure $todayProvider = null,
    ) {
    }

    public function state(int $userId): array
    {
        $today = $this->today();
        $latest = $this->latestAttendance($userId);
        $todayRecord = $latest !== null && $latest['attendance_date'] === $today
            ? $latest
            : null;
        $cycleNumber = 1;
        $progress = 0;
        $nextDay = 1;
        $days = [];

        if ($latest !== null) {
            $lastDate = new DateTimeImmutable($latest['attendance_date'], new DateTimeZone(self::TIMEZONE));
            $todayDate = new DateTimeImmutable($today, new DateTimeZone(self::TIMEZONE));
            $daysSinceLast = (int) $lastDate->diff($todayDate)->format('%r%a');
            if ($daysSinceLast < 0) {
                throw new ApiException('ATTENDANCE_DATE_INVALID', 'The server date precedes the last recorded attendance.', 409);
            }

            if ($todayRecord !== null) {
                $cycleNumber = (int) $todayRecord['cycle_number'];
                $progress = (int) $todayRecord['cycle_day'];
                $nextDay = $progress === self::CYCLE_DAYS ? 1 : $progress + 1;
            } elseif ($daysSinceLast === 1 && (int) $latest['cycle_day'] < self::CYCLE_DAYS) {
                $cycleNumber = (int) $latest['cycle_number'];
                $progress = (int) $latest['cycle_day'];
                $nextDay = $progress + 1;
            } else {
                $cycleNumber = (int) $latest['cycle_number'] + 1;
                $nextDay = 1;
            }

            if ($todayRecord !== null || ($daysSinceLast === 1 && (int) $latest['cycle_day'] < self::CYCLE_DAYS)) {
                $cycleDays = $this->cycleAttendances($userId, $cycleNumber);
                foreach ($cycleDays as $record) {
                    $day = (int) $record['cycle_day'];
                    $days[$day] = [
                        'day' => $day,
                        'attended' => true,
                        'attendance_date' => $record['attendance_date'],
                        'reward_amount' => (int) $record['reward_amount'],
                        'is_today' => $record['attendance_date'] === $today,
                    ];
                }
            }
        }

        $schedule = [];
        for ($day = 1; $day <= self::CYCLE_DAYS; $day++) {
            $schedule[] = $days[$day] ?? [
                'day' => $day,
                'attended' => false,
                'attendance_date' => null,
                'reward_amount' => null,
                'is_today' => false,
            ];
        }

        return [
            'today' => $today,
            'timezone' => self::TIMEZONE,
            'cycle_number' => $cycleNumber,
            'cycle_progress' => $progress,
            'next_cycle_day' => $nextDay,
            'checked_in_today' => $todayRecord !== null,
            'today_reward' => self::DAILY_REWARD,
            'cycle_reward_total' => self::DAILY_REWARD * self::CYCLE_DAYS,
            'cycle_days' => $schedule,
            'wallet' => $this->wallet($userId),
        ];
    }

    public function checkIn(int $userId): array
    {
        $today = $this->today();
        $result = $this->transaction(function () use ($userId, $today): array {
            $this->lockUser($userId);
            $latest = $this->latestAttendance($userId, true);
            if ($latest !== null && $latest['attendance_date'] === $today) {
                return ['created' => false];
            }

            $cycleNumber = 1;
            $cycleDay = 1;
            if ($latest !== null) {
                $lastDate = new DateTimeImmutable($latest['attendance_date'], new DateTimeZone(self::TIMEZONE));
                $todayDate = new DateTimeImmutable($today, new DateTimeZone(self::TIMEZONE));
                $daysSinceLast = (int) $lastDate->diff($todayDate)->format('%r%a');
                if ($daysSinceLast < 0) {
                    throw new ApiException(
                        'ATTENDANCE_DATE_INVALID',
                        'The server date precedes the last recorded attendance.',
                        409,
                    );
                }
                if ($daysSinceLast === 1 && (int) $latest['cycle_day'] < self::CYCLE_DAYS) {
                    $cycleNumber = (int) $latest['cycle_number'];
                    $cycleDay = (int) $latest['cycle_day'] + 1;
                } else {
                    $cycleNumber = (int) $latest['cycle_number'] + 1;
                }
            }

            $insert = $this->db->prepare(
                'INSERT INTO daily_attendance
                    (user_id, attendance_date, cycle_number, cycle_day, reward_amount, created_at)
                 VALUES (:user_id, :attendance_date, :cycle_number, :cycle_day, :reward_amount, :created_at)',
            );
            $insert->execute([
                'user_id' => $userId,
                'attendance_date' => $today,
                'cycle_number' => $cycleNumber,
                'cycle_day' => $cycleDay,
                'reward_amount' => self::DAILY_REWARD,
                'created_at' => $this->now(),
            ]);
            $attendanceId = (int) $this->db->lastInsertId();

            $ledgerEntryId = (new CoinsLedger($this->db))->credit(
                $userId,
                'earned',
                self::DAILY_REWARD,
                'earned_reward',
                'daily-attendance-' . $today,
                'daily_attendance',
                $attendanceId,
                sprintf('Daily attendance %s (cycle %d, day %d).', $today, $cycleNumber, $cycleDay),
            );
            $update = $this->db->prepare(
                'UPDATE daily_attendance SET ledger_entry_id = :ledger_entry_id
                 WHERE id = :id AND user_id = :user_id AND attendance_date = :attendance_date',
            );
            $update->execute([
                'ledger_entry_id' => $ledgerEntryId,
                'id' => $attendanceId,
                'user_id' => $userId,
                'attendance_date' => $today,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Daily attendance could not be linked to its ledger entry.');
            }

            return ['created' => true];
        });

        return [
            'created' => $result['created'],
            'attendance' => $this->state($userId),
        ];
    }

    private function lockUser(int $userId): void
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

    private function latestAttendance(int $userId, bool $lock = false): ?array
    {
        $forUpdate = $lock && $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT id, attendance_date, cycle_number, cycle_day, reward_amount, ledger_entry_id
             FROM daily_attendance WHERE user_id = :user_id
             ORDER BY attendance_date DESC LIMIT 1' . $forUpdate,
        );
        $statement->execute(['user_id' => $userId]);
        $record = $statement->fetch();
        return $record === false ? null : $record;
    }

    private function cycleAttendances(int $userId, int $cycleNumber): array
    {
        $statement = $this->db->prepare(
            'SELECT cycle_day, attendance_date, reward_amount
             FROM daily_attendance
             WHERE user_id = :user_id AND cycle_number = :cycle_number
             ORDER BY cycle_day',
        );
        $statement->execute(['user_id' => $userId, 'cycle_number' => $cycleNumber]);
        return $statement->fetchAll();
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

    private function today(): string
    {
        if ($this->todayProvider !== null) {
            $today = ($this->todayProvider)();
            if (!is_string($today) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $today)) {
                throw new RuntimeException('Attendance test date provider returned an invalid date.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone(self::TIMEZONE));
            if ($date === false || $date->format('Y-m-d') !== $today) {
                throw new RuntimeException('Attendance test date provider returned an invalid date.');
            }
            return $today;
        }
        return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
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
}
