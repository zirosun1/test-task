<?php

declare(strict_types=1);

require __DIR__ . '/src/PriceResolver.php';

function readCsvRows(string $file): array
{
    $handle = fopen($file, 'r');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть {$file}");
    }
    $header = fgetcsv($handle, null, ';', '"', '');
    $rows = [];
    while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);

    return $rows;
}

function same(mixed $expected, mixed $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$case}: ожидалось " . var_export($expected, true) . ', получено ' . var_export($actual, true));
    }
    echo "OK: {$case}\n";
}

$prices = [];
foreach (readCsvRows(__DIR__ . '/data/prices.csv') as $row) {
    $prices[$row['sku']][$row['price_type']] = (float) $row['price'];
}
$clients = array_column(readCsvRows(__DIR__ . '/data/clients.csv'), null, 'client_id');
$products = array_column(readCsvRows(__DIR__ . '/data/products.csv'), null, 'sku');
$rules = json_decode((string) file_get_contents(__DIR__ . '/data/discount_rules.json'), true, 512, JSON_THROW_ON_ERROR)['rules'];
$zone = new DateTimeZone('Europe/Minsk');
$cacheFile = sys_get_temp_dir() . '/price-resolver-test-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($cacheFile, json_encode([
    'price:K-004:B-103:1' => [
        'value' => 15.48,
        'expires_at' => '2026-10-23T09:05:00+03:00',
        'stored_at' => '2026-10-22T09:05:00+03:00',
    ],
], JSON_THROW_ON_ERROR));

try {
    $now = new DateTimeImmutable('2026-10-22 12:00:00', $zone);
    $resolver = new PriceResolver($prices, $clients, $products, $rules, new FileCache($cacheFile, $now), $now);

    try {
        $resolver->basePrice('K-003', 'B-105');
        throw new RuntimeException('Для отсутствующей дилерской цены ожидалось исключение');
    } catch (DomainException $e) {
        same(true, str_contains($e->getMessage(), 'DEALER'), 'нет дилерской цены: не подменяем розничной');
    }

    same(0.0, $resolver->discountPercent('K-001', 'B-101', 1), 'сентябрьская акция не действует 22 октября');
    same(14.75, $resolver->price('K-001', 'B-101'), 'цена K-001 / B-101 без завершённой акции');
    same(5.0, $resolver->discountPercent('K-002', 'B-106', 12), 'выбирается максимальная скидка, а не сумма');
    same(3.51, $resolver->price('K-002', 'B-106', 12), 'цена K-002 / B-106 с максимальной скидкой');
    same(15.72, $resolver->price('K-004', 'B-103'), 'пересчитанная цена не берётся из устаревшего кеша');
    same(10.89, $resolver->price('K-005', 'B-107'), 'цена OPT1 для K-005 с октябрьской акцией');
    same(10.28, $resolver->price('K-006', 'B-107'), 'цена OPT2 для K-006 с октябрьской акцией');

    $octoberEnd = new DateTimeImmutable('2026-10-31 12:00:00', $zone);
    $endResolver = new PriceResolver($prices, $clients, $products, $rules, new FileCache($cacheFile, $octoberEnd), $octoberEnd);
    same(4.0, $endResolver->discountPercent('K-001', 'B-106', 1), 'акция действует включительно в valid_to');
} finally {
    @unlink($cacheFile);
}
