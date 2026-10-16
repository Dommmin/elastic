<?php

use App\Support\ConcurrencyConflictTrace;

afterEach(fn () => putenv(ConcurrencyConflictTrace::ENV));

test('bez LOG_CONCURRENCY_PATH ślad konfliktu nic nie zapisuje', function () {
    putenv(ConcurrencyConflictTrace::ENV);
    $default = storage_path('logs/concurrency.log');
    @unlink($default);

    ConcurrencyConflictTrace::record('offer', 1, 3);

    expect(is_file($default))->toBeFalse();
});

test('z LOG_CONCURRENCY_PATH ślad trafia do wskazanego pliku', function () {
    $path = storage_path('framework/testing/trace-'.getmypid().'.log');
    @unlink($path);
    putenv(ConcurrencyConflictTrace::ENV.'='.$path);

    ConcurrencyConflictTrace::record('order', 7, 2);

    expect(file_get_contents($path))->toContain('concurrency_conflict {"aggregate":"order","id":7,"version":2}');
    @unlink($path);
});

test('niezapisywalna ścieżka nie zamienia konfliktu w inny wyjątek', function () {
    putenv(ConcurrencyConflictTrace::ENV.'=/nonexistent-dir/'.uniqid().'/x.log');

    ConcurrencyConflictTrace::record('offer', 1, 1);

    expect(true)->toBeTrue();
});
