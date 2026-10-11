<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

/** Wyciąga hasło z linii `Hasło: ...` wypisanej przez komendę. */
function hasloZWyjscia(string $output): string
{
    preg_match('/Hasło: (\S+)/u', $output, $m);

    return $m[1] ?? '';
}

test('tworzy konto z potwierdzonym mailem', function () {
    $this->artisan('marketplace:admin', ['email' => 'a@b.pl'])
        ->expectsOutputToContain('Hasło:')
        ->assertExitCode(0);

    $user = User::where('email', 'a@b.pl')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->name)->toBe('a');
});

test('drugie wywołanie zmienia hasło', function () {
    Artisan::call('marketplace:admin', ['email' => 'a@b.pl']);
    $stare = hasloZWyjscia(Artisan::output());

    Artisan::call('marketplace:admin', ['email' => 'a@b.pl']);
    $nowe = hasloZWyjscia(Artisan::output());

    $user = User::where('email', 'a@b.pl')->firstOrFail();
    expect(strlen($nowe))->toBe(20)
        ->and(Hash::check($stare, $user->password))->toBeFalse()
        ->and(Hash::check($nowe, $user->password))->toBeTrue()
        ->and(User::count())->toBe(1);
});

test('niepoprawny email', function () {
    $this->artisan('marketplace:admin', ['email' => 'nie-mail'])
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});
