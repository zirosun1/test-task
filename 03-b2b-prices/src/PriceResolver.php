<?php
/**
 * Учебный код для тестового задания. Не относится к реальным системам.
 * Расчёт цены товара для клиента B2B-сайта: тип цен клиента -> базовая цена -> скидка -> кеш.
 */

declare(strict_types=1);

final class FileCache
{
    public function __construct(private string $file, private DateTimeImmutable $now) {}

    public function remember(string $key, int $ttlSeconds, callable $compute): float
    {
        $data = is_file($this->file) ? (json_decode((string) file_get_contents($this->file), true) ?: []) : [];

        if (isset($data[$key]) && new DateTimeImmutable($data[$key]['expires_at']) > $this->now) {
            return (float) $data[$key]['value'];
        }

        $value = $compute();
        $data[$key] = [
            'value'      => $value,
            'expires_at' => $this->now->modify("+{$ttlSeconds} seconds")->format(DATE_ATOM),
            'stored_at'  => $this->now->format(DATE_ATOM),
        ];
        file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $value;
    }
}

final class PriceResolver
{
    /**
     * @param array<string, array<string, float>> $prices   [sku][price_type] => price
     * @param array<string, array<string, string>> $clients [client_id] => row
     * @param array<string, array<string, string>> $products [sku] => row
     * @param list<array<string, mixed>> $rules
     */
    public function __construct(
        private array $prices,
        private array $clients,
        private array $products,
        private array $rules,
        private FileCache $cache,
        private DateTimeImmutable $now,
    ) {}

    public function basePrice(string $clientId, string $sku): float
    {
        if (!isset($this->clients[$clientId])) {
            throw new DomainException("Неизвестный клиент: {$clientId}");
        }

        $type = $this->clients[$clientId]['price_type'];
        if (!isset($this->prices[$sku])) {
            throw new DomainException("Нет цен для товара {$sku}");
        }
        if (!array_key_exists($type, $this->prices[$sku])) {
            throw new DomainException("Для товара {$sku} отсутствует цена типа {$type}");
        }

        return $this->prices[$sku][$type];
    }

    public function discountPercent(string $clientId, string $sku, int $qty): float
    {
        $percent = (float) $this->clients[$clientId]['personal_discount'];

        foreach ($this->rules as $rule) {
            if (!$this->isActive($rule)) {
                continue;
            }
            if ($rule['type'] === 'volume' && $qty >= $rule['min_qty']) {
                $percent = max($percent, (float) $rule['percent']);
            }
            if ($rule['type'] === 'promo' && $rule['category'] === $this->products[$sku]['category']) {
                $percent = max($percent, (float) $rule['percent']);
            }
        }

        return $percent;
    }

    private function isActive(array $rule): bool
    {
        $timezone = $this->now->getTimezone();
        $from = DateTimeImmutable::createFromFormat('!d.m.Y', $rule['valid_from'], $timezone);
        $to = DateTimeImmutable::createFromFormat('!d.m.Y', $rule['valid_to'], $timezone);
        if ($from === false || $to === false) {
            return false;
        }

        $today = $this->now->setTime(0, 0);

        return $today >= $from && $today <= $to;
    }

    public function price(string $clientId, string $sku, int $qty = 1): float
    {
        $base = $this->basePrice($clientId, $sku);
        $discount = $this->discountPercent($clientId, $sku, $qty);
        $priceType = $this->clients[$clientId]['price_type'];
        // Include current pricing inputs so a changed price type, base price, or discount
        // cannot be masked by a still-live cache entry created from older data.
        $signature = hash('sha256', json_encode([$priceType, $base, $discount], JSON_THROW_ON_ERROR));

        return $this->cache->remember("price:v2:{$clientId}:{$sku}:{$qty}:{$signature}", 86400, function () use ($base, $discount): float {
            return round($base * (100 - $discount) / 100, 2);
        });
    }
}
