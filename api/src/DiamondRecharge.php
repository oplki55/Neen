<?php
declare(strict_types=1);

final class DiamondRecharge
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function activePackages(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, diamond_amount, price, currency_code
             FROM diamond_packages WHERE is_active = 1 ORDER BY price, id',
        );
        return array_map($this->normalizePackage(...), $statement->fetchAll());
    }

    public function adminPackages(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, diamond_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at
             FROM diamond_packages ORDER BY id DESC',
        );
        return array_map($this->normalizePackage(...), $statement->fetchAll());
    }

    public function createPackage(int $adminId, string $name, int $amount, string $price, string $currency): array
    {
        if ($name === '' || strlen($name) > 120 || $amount < 1 || !$this->validPrice($price) || !preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new ApiException('VALIDATION_ERROR', 'Diamond package details are invalid.', 422);
        }
        $price = $this->normalizePrice($price);
        $statement = $this->db->prepare(
            'INSERT INTO diamond_packages
                (name, diamond_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :diamond_amount, :price, :currency_code, 0, :created_by, :updated_by, :created_at, :updated_at)',
        );
        $now = $this->now();
        $statement->execute([
            'name' => $name,
            'diamond_amount' => $amount,
            'price' => $price,
            'currency_code' => $currency,
            'created_by' => $adminId,
            'updated_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->packageById((int) $this->db->lastInsertId());
    }

    public function updatePackage(int $adminId, int $packageId, array $fields): array
    {
        $allowed = ['name', 'diamond_amount', 'price', 'currency_code', 'is_active'];
        if ($fields === [] || array_diff(array_keys($fields), $allowed) !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Diamond package update contains unsupported fields.', 422);
        }
        $updates = [];
        $values = ['id' => $packageId, 'updated_by' => $adminId, 'updated_at' => $this->now()];
        foreach ($fields as $field => $value) {
            if ($field === 'name') {
                if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > 120) {
                    throw new ApiException('VALIDATION_ERROR', 'Diamond package name is invalid.', 422);
                }
                $values[$field] = trim($value);
            } elseif ($field === 'diamond_amount') {
                $values[$field] = $this->positiveInteger($value);
            } elseif ($field === 'price') {
                if (!is_string($value) || !$this->validPrice($value)) {
                    throw new ApiException('VALIDATION_ERROR', 'Diamond package price is invalid.', 422);
                }
                $values[$field] = $this->normalizePrice($value);
            } elseif ($field === 'currency_code') {
                if (!is_string($value) || !preg_match('/\A[A-Z]{3}\z/', $value)) {
                    throw new ApiException('VALIDATION_ERROR', 'Diamond package currency is invalid.', 422);
                }
                $values[$field] = $value;
            } else {
                if (!is_bool($value)) {
                    throw new ApiException('VALIDATION_ERROR', 'Diamond package active state must be boolean.', 422);
                }
                $values[$field] = $value ? 1 : 0;
            }
            $updates[] = $field . ' = :' . $field;
        }
        $updates[] = 'updated_by = :updated_by';
        $updates[] = 'updated_at = :updated_at';
        $statement = $this->db->prepare(
            'UPDATE diamond_packages SET ' . implode(', ', $updates) . ' WHERE id = :id',
        );
        $statement->execute($values);
        if ($statement->rowCount() === 0 && $this->packageByIdOrNull($packageId) === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Diamond package not found.', 404);
        }
        return $this->packageById($packageId);
    }

    public function createOrder(
        int $userId,
        int $packageId,
        string $playerId,
        string $idempotencyKey,
        ?string $paymentReference = null,
    ): array
    {
        $paymentReference = $this->cleanPaymentReference($paymentReference);
        if (
            $userId < 1
            || $packageId < 1
            || !preg_match('/\A[0-9]{6,20}\z/', $playerId)
            || !preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Diamond order details are invalid.', 422);
        }

        return $this->transaction(function () use (
            $userId,
            $packageId,
            $playerId,
            $idempotencyKey,
            $paymentReference,
        ): array {
            $this->lockActiveUser($userId);
            $statement = $this->db->prepare(
                'SELECT id, order_number, package_id, player_id, payment_reference
                 FROM diamond_recharge_orders WHERE user_id = :user_id AND idempotency_key = :idempotency_key',
            );
            $statement->execute(['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
            $existing = $statement->fetch();
            if ($existing !== false) {
                if (
                    (int) $existing['package_id'] !== $packageId
                    || $existing['player_id'] !== $playerId
                    || $existing['payment_reference'] !== $paymentReference
                ) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Idempotency key was already used for a different order.', 409);
                }
                return ['created' => false, 'order' => $this->orderById((int) $existing['id'], $userId)];
            }

            $statement = $this->db->prepare(
                "SELECT id FROM diamond_recharge_orders
                 WHERE user_id = :user_id AND package_id = :package_id AND player_id = :player_id
                   AND order_status IN ('pending', 'payment_verification', 'paid', 'processing')
                 ORDER BY id DESC LIMIT 1",
            );
            $statement->execute([
                'user_id' => $userId,
                'package_id' => $packageId,
                'player_id' => $playerId,
            ]);
            $activeOrderId = $statement->fetchColumn();
            if ($activeOrderId !== false) {
                return ['created' => false, 'order' => $this->orderById((int) $activeOrderId, $userId)];
            }

            $package = $this->packageByIdOrNull($packageId, true);
            if ($package === null || !$package['is_active']) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Active diamond package not found.', 404);
            }
            $now = $this->now();
            $orderNumber = 'ND-' . strtoupper(bin2hex(random_bytes(8)));
            $statement = $this->db->prepare(
                'INSERT INTO diamond_recharge_orders
                    (order_number, user_id, package_id, package_name, diamond_amount, price, currency_code,
                     player_id, idempotency_key, order_status, payment_status, delivery_status, payment_reference,
                     created_at, updated_at)
                 VALUES
                    (:order_number, :user_id, :package_id, :package_name, :diamond_amount, :price, :currency_code,
                     :player_id, :idempotency_key, \'pending\', \'pending\', \'not_started\', :payment_reference,
                     :created_at, :updated_at)',
            );
            $statement->execute([
                'order_number' => $orderNumber,
                'user_id' => $userId,
                'package_id' => $packageId,
                'package_name' => $package['name'],
                'diamond_amount' => $package['diamond_amount'],
                'price' => $package['price'],
                'currency_code' => $package['currency_code'],
                'player_id' => $playerId,
                'idempotency_key' => $idempotencyKey,
                'payment_reference' => $paymentReference,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) $this->db->lastInsertId();
            $this->activity($orderId, $userId, 'order_created', null, 'pending', 'pending', 'not_started', null);
            return ['created' => true, 'order' => $this->orderById($orderId, $userId)];
        });
    }

    public function userOrders(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id FROM diamond_recharge_orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 100',
        );
        $statement->execute(['user_id' => $userId]);
        $orders = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $orders[] = $this->orderById((int) $orderId, $userId);
        }
        return $orders;
    }

    public function adminOrders(?string $search = null, ?string $status = null): array
    {
        $filters = [];
        $values = [];
        if ($search !== null && $search !== '') {
            $filters[] = '(o.order_number LIKE :order_number OR o.player_id LIKE :player_id)';
            $values['order_number'] = '%' . $search . '%';
            $values['player_id'] = '%' . $search . '%';
        }
        if ($status !== null) {
            if (!in_array($status, ['pending', 'payment_verification', 'paid', 'processing', 'delivered', 'rejected', 'failed', 'refunded', 'cancelled'], true)) {
                throw new ApiException('VALIDATION_ERROR', 'Diamond order status filter is invalid.', 422);
            }
            $filters[] = 'o.order_status = :status';
            $values['status'] = $status;
        }
        $where = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
        $statement = $this->db->prepare(
            'SELECT o.id, o.order_number, o.user_id, u.username, o.package_id, o.package_name,
                    o.diamond_amount, o.price, o.currency_code, o.player_id, o.order_status,
                    o.payment_status, o.delivery_status, o.payment_reference, o.created_at, o.updated_at
             FROM diamond_recharge_orders o
             JOIN users u ON u.id = o.user_id' . $where . ' ORDER BY o.id DESC LIMIT 100',
        );
        $statement->execute($values);
        return array_map($this->normalizeOrder(...), $statement->fetchAll());
    }

    public function orderHistory(int $orderId): array
    {
        $order = $this->orderById($orderId);
        $statement = $this->db->prepare(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.activity_type,
                    a.from_order_status, a.to_order_status, a.payment_status, a.delivery_status,
                    a.admin_note, a.created_at
             FROM diamond_order_activity a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.order_id = :order_id ORDER BY a.id',
        );
        $statement->execute(['order_id' => $orderId]);
        $order['activity'] = $statement->fetchAll();
        foreach ($order['activity'] as &$entry) {
            $entry['id'] = (int) $entry['id'];
            $entry['actor_user_id'] = $entry['actor_user_id'] === null ? null : (int) $entry['actor_user_id'];
        }
        unset($entry);
        return $order;
    }

    public function performAdminAction(int $adminId, int $orderId, string $action, string $note, ?string $paymentReference): array
    {
        $transitions = [
            'begin_verification' => ['pending', 'payment_verification', 'verification', 'not_started'],
            'mark_paid' => ['payment_verification', 'paid', 'paid', 'not_started'],
            'reject_payment' => [['pending', 'payment_verification'], 'rejected', 'rejected', 'not_started'],
            'fail_payment' => ['payment_verification', 'failed', 'failed', 'not_started'],
            'begin_delivery' => ['paid', 'processing', 'paid', 'processing'],
            'mark_delivered' => ['processing', 'delivered', 'paid', 'delivered'],
            'fail_delivery' => ['processing', 'failed', 'paid', 'failed'],
            'refund' => ['paid_or_delivery_failed', 'refunded', 'refunded', 'refunded'],
        ];
        if (!isset($transitions[$action]) || strlen(trim($note)) < 3 || strlen($note) > 1000) {
            throw new ApiException('VALIDATION_ERROR', 'Diamond order action and administrative note are required.', 422);
        }

        return $this->transaction(function () use ($adminId, $orderId, $action, $note, $paymentReference, $transitions): array {
            $statement = $this->db->prepare(
                'SELECT id, order_number, user_id, order_status, payment_status, delivery_status
                 FROM diamond_recharge_orders WHERE id = :id' . $this->forUpdate(),
            );
            $statement->execute(['id' => $orderId]);
            $order = $statement->fetch();
            if ($order === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Diamond recharge order not found.', 404);
            }
            [$allowedFrom, $toOrderStatus, $paymentStatus, $deliveryStatus] = $transitions[$action];
            $valid = $allowedFrom === 'paid_or_delivery_failed'
                ? (
                    $order['payment_status'] === 'paid'
                    && $order['delivery_status'] !== 'delivered'
                    && $order['order_status'] !== 'refunded'
                )
                : (is_array($allowedFrom)
                    ? in_array($order['order_status'], $allowedFrom, true)
                    : $order['order_status'] === $allowedFrom);
            if (!$valid) {
                throw new ApiException('INVALID_ORDER_TRANSITION', 'This diamond order cannot make the requested transition.', 409);
            }
            if ($paymentReference !== null && (strlen($paymentReference) > 150 || trim($paymentReference) === '')) {
                throw new ApiException('VALIDATION_ERROR', 'Payment reference is invalid.', 422);
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                'UPDATE diamond_recharge_orders SET order_status = :order_status,
                    payment_status = :payment_status, delivery_status = :delivery_status,
                    payment_reference = COALESCE(:payment_reference, payment_reference), updated_at = :updated_at
                 WHERE id = :id',
            );
            $statement->execute([
                'order_status' => $toOrderStatus,
                'payment_status' => $paymentStatus,
                'delivery_status' => $deliveryStatus,
                'payment_reference' => $paymentReference,
                'updated_at' => $now,
                'id' => $orderId,
            ]);
            $this->activity(
                $orderId,
                $adminId,
                $action,
                (string) $order['order_status'],
                $toOrderStatus,
                $paymentStatus,
                $deliveryStatus,
                $note,
            );
            return [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'order_status' => $toOrderStatus,
                'payment_status' => $paymentStatus,
                'delivery_status' => $deliveryStatus,
                'user_id' => (int) $order['user_id'],
            ];
        });
    }

    private function orderById(int $orderId, ?int $userId = null): array
    {
        $whereUser = $userId === null ? '' : ' AND user_id = :user_id';
        $statement = $this->db->prepare(
            'SELECT id, order_number, user_id, package_id, package_name, diamond_amount, price, currency_code,
                    player_id, order_status, payment_status, delivery_status, payment_reference, created_at, updated_at
             FROM diamond_recharge_orders WHERE id = :id' . $whereUser,
        );
        $values = ['id' => $orderId];
        if ($userId !== null) {
            $values['user_id'] = $userId;
        }
        $statement->execute($values);
        $order = $statement->fetch();
        if ($order === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Diamond recharge order not found.', 404);
        }
        return $this->normalizeOrder($order);
    }

    private function packageById(int $packageId): array
    {
        $package = $this->packageByIdOrNull($packageId);
        if ($package === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Diamond package not found.', 404);
        }
        return $package;
    }

    private function packageByIdOrNull(int $packageId, bool $lock = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, diamond_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at
             FROM diamond_packages WHERE id = :id' . ($lock ? $this->forUpdate() : ''),
        );
        $statement->execute(['id' => $packageId]);
        $package = $statement->fetch();
        return $package === false ? null : $this->normalizePackage($package);
    }

    private function normalizePackage(array $package): array
    {
        foreach (['id', 'diamond_amount', 'created_by', 'updated_by'] as $field) {
            if (array_key_exists($field, $package) && $package[$field] !== null) {
                $package[$field] = (int) $package[$field];
            }
        }
        if (array_key_exists('is_active', $package)) {
            $package['is_active'] = (bool) $package['is_active'];
        }
        if (isset($package['price']) && is_string($package['price']) && $this->validPrice($package['price'])) {
            $package['price'] = $this->normalizePrice($package['price']);
        }
        return $package;
    }

    private function normalizeOrder(array $order): array
    {
        foreach (['id', 'user_id', 'package_id', 'diamond_amount'] as $field) {
            if (isset($order[$field])) {
                $order[$field] = (int) $order[$field];
            }
        }
        return $order;
    }

    private function cleanPaymentReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        if (strlen($reference) > 600 || preg_match('/[\p{C}]/u', $reference) !== 0) {
            throw new ApiException('VALIDATION_ERROR', 'Payment reference may contain up to 150 printable characters.', 422);
        }
        if (preg_match('/\A.{1,150}\z/us', $reference) !== 1) {
            throw new ApiException('VALIDATION_ERROR', 'Payment reference may contain up to 150 printable characters.', 422);
        }
        return $reference;
    }

    private function activity(
        int $orderId,
        ?int $actorId,
        string $type,
        ?string $fromStatus,
        string $toStatus,
        string $paymentStatus,
        string $deliveryStatus,
        ?string $note,
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO diamond_order_activity
                (order_id, actor_user_id, activity_type, from_order_status, to_order_status,
                 payment_status, delivery_status, admin_note, created_at)
             VALUES (:order_id, :actor_user_id, :activity_type, :from_status, :to_status,
                 :payment_status, :delivery_status, :admin_note, :created_at)',
        );
        $statement->execute([
            'order_id' => $orderId,
            'actor_user_id' => $actorId,
            'activity_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'payment_status' => $paymentStatus,
            'delivery_status' => $deliveryStatus,
            'admin_note' => $note,
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
        if ($startedHere && $this->driver() === 'sqlite') {
            $this->db->exec('BEGIN IMMEDIATE');
        } elseif ($startedHere) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($startedHere) {
                $this->driver() === 'sqlite' ? $this->db->exec('COMMIT') : $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($startedHere) {
                if ($this->driver() === 'sqlite') {
                    $this->db->exec('ROLLBACK');
                } elseif ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            }
            throw $exception;
        }
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/', $value)) {
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($number) && $number > 0) {
                return $number;
            }
        }
        throw new ApiException('VALIDATION_ERROR', 'Diamond package amount must be a positive whole number.', 422);
    }

    private function validPrice(string $price): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $price) === 1
            && (float) $price > 0;
    }

    private function normalizePrice(string $price): string
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
        return $whole . '.' . str_pad($fraction, 2, '0');
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
