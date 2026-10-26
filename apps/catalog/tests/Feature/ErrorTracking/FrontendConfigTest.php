<?php

test('bez DSN frontendu strona nie ma meta śledzenia błędów', function () {
    config(['error_tracking.frontend.dsn' => null]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('error-tracking-', false);
});

test('z DSN frontendu strona niesie dsn, środowisko i release w meta', function () {
    config([
        'error_tracking.frontend.dsn' => 'http://abc123@glitchtip:8000/2',
        'error_tracking.frontend.environment' => 'staging',
        'error_tracking.frontend.release' => 'a1b2c3d',
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="error-tracking-dsn" content="http://abc123@glitchtip:8000/2">', false)
        ->assertSee('<meta name="error-tracking-environment" content="staging">', false)
        ->assertSee('<meta name="error-tracking-release" content="a1b2c3d">', false);
});

test('wartości meta są escapowane, więc release z cudzysłowem nie wyłamie się z atrybutu', function () {
    config([
        'error_tracking.frontend.dsn' => 'http://abc123@glitchtip:8000/2',
        'error_tracking.frontend.release' => 'x"><script>alert(1)</script>',
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false);
});
