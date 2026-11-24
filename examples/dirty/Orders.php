<?php

declare(strict_types=1);

function mixInvoices(array $ordersById, array $refundsById): array
{
    return array_merge($ordersById, $refundsById);
}

function copyInvoices(array $invoiceMap): array
{
    return [...$invoiceMap];
}

function fromPayload(): array
{
    return array_merge([1042 => 'paid'], [1043 => 'open']);
}

function quotedKeys(): array
{
    return array_merge(['1042' => 'paid'], ['1043' => 'open']);
}

function spreadLiteral(): array
{
    return [...[1042 => 'paid', 1043 => 'open']];
}

/** @param array<int, string> $productIndex */
function attach(array $productIndex, array $extra): array
{
    return array_merge($productIndex, $extra);
}
