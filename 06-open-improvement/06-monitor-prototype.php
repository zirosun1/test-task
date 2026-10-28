<?php

declare(strict_types=1);

// Демонстрационные данные из снимка учебной очереди. В рабочем варианте
// этот массив заменяется результатом SELECT из exchange_queue.
$jobs = [
    ['id' => 88414, 'order_no' => 'ORD-B2B-7781', 'status' => 'pending', 'created_at' => '2026-10-22 07:57:40'],
    ['id' => 88415, 'order_no' => 'ORD-B2B-7782', 'status' => 'pending', 'created_at' => '2026-10-22 08:03:12'],
    ['id' => 88416, 'order_no' => 'ORD-B2B-7783', 'status' => 'pending', 'created_at' => '2026-10-22 08:11:55'],
    ['id' => 88417, 'order_no' => 'ORD-B2B-7784', 'status' => 'pending', 'created_at' => '2026-10-22 08:30:07'],
    ['id' => 88418, 'order_no' => 'ORD-B2B-7785', 'status' => 'pending', 'created_at' => '2026-10-22 09:02:48'],
    ['id' => 88419, 'order_no' => 'ORD-B2B-7786', 'status' => 'pending', 'created_at' => '2026-10-22 09:40:21'],
    ['id' => 88420, 'order_no' => 'ORD-B2B-7787', 'status' => 'pending', 'created_at' => '2026-10-22 10:15:33'],
    ['id' => 88421, 'order_no' => 'ORD-B2B-7788', 'status' => 'pending', 'created_at' => '2026-10-22 10:58:02'],
];

$now = new DateTimeImmutable('2026-10-22 11:05:00', new DateTimeZone('Europe/Minsk'));
$alertAfterMinutes = 10;
$pending = array_values(array_filter($jobs, static fn (array $job): bool => $job['status'] === 'pending'));

if ($pending === []) {
    echo "OK: очередь пуста\n";
    exit(0);
}

usort($pending, static fn (array $a, array $b): int => strcmp($a['created_at'], $b['created_at']));
$oldest = $pending[0];
$createdAt = new DateTimeImmutable($oldest['created_at'], $now->getTimezone());
$waitMinutes = intdiv($now->getTimestamp() - $createdAt->getTimestamp(), 60);

if ($waitMinutes >= $alertAfterMinutes) {
    printf(
        "ВНИМАНИЕ: в очереди %d заказов; задача #%d (%s) ожидает %d мин.\n",
        count($pending),
        $oldest['id'],
        $oldest['order_no'],
        $waitMinutes,
    );
    exit(1);
}

printf("OK: в очереди %d заказов; старейший ожидает %d мин.\n", count($pending), $waitMinutes);
