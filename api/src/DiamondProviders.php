<?php
declare(strict_types=1);

interface DiamondPaymentProvider
{
    public function verifyPayment(array $order): array;
}

interface DiamondDeliveryProvider
{
    public function deliver(array $order): array;
}

final class UnconfiguredDiamondPaymentProvider implements DiamondPaymentProvider
{
    public function verifyPayment(array $order): array
    {
        throw new ApiException(
            'PAYMENT_PROVIDER_NOT_CONFIGURED',
            'No approved payment provider is configured for this order.',
            503,
        );
    }
}

final class UnconfiguredDiamondDeliveryProvider implements DiamondDeliveryProvider
{
    public function deliver(array $order): array
    {
        throw new ApiException(
            'DELIVERY_PROVIDER_NOT_CONFIGURED',
            'No approved diamond delivery provider is configured for this order.',
            503,
        );
    }
}
