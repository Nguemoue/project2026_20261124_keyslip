<?php

declare(strict_types=1);

function mixInvoices(array $ordersById, array $refundsById): array
{
    return array_replace($ordersById, $refundsById);
}

function copyInvoices(array $invoiceMap): array
{
    return array_replace([], $invoiceMap);
}

function appendRows(array $rows, array $more): array
{
    return array_merge($rows, $more);
}

function lists(): array
{
    return array_merge(['paid', 'open'], ['late']);
}

function stringKeys(): array
{
    return array_merge(['id_1042' => 'paid'], ['id_1043' => 'open']);
}

function leadingZero(): array
{
    return array_merge(['08' => 'a'], ['09' => 'b']);
}

function keptOnPurpose(array $ordersById): array
{
    return array_merge($ordersById, []); // keyslip:ignore
}

function commented(): array
{
    // array_merge($ordersById, $refundsById);
    return [];
}

function inString(): string
{
    return 'array_merge($ordersById, $x) and [...$invoiceMap]';
}

function spreadList(array $rows): array
{
    return [...$rows, 'tail'];
}

function closures(): array
{
    return array_merge([fn () => 'paid'], [fn () => 'open']);
}
