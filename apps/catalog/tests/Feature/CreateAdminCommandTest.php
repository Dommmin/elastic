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

test('tworzy konto administratora z potwierdzonym mailem', function () {
    $this->artisan('marketplace:admin', ['email' => 'a@b.pl'])
        ->expectsOutputToContain('Utworzono konto administratora: a@b.pl')
        ->expectsOutputToContain('Hasło:')
        ->assertExitCode(0);

    $user = User::where('email', 'a@b.pl')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_admin)->toBeTrue()
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

test('istniejące niezweryfikowane konto dostaje uprawnienia i nowe hasło, ale zostaje niezweryfikowane', function () {
    // Nadanie uprawnień nie może przy okazji potwierdzać cudzego adresu.
    $user = User::factory()->unverified()->create(['email' => 'x@y.pl']);
    $stare = $user->password;

    $this->artisan('marketplace:admin', ['email' => 'x@y.pl'])
        ->expectsOutputToContain('Zmieniono hasło i nadano uprawnienia administratora: x@y.pl')
        ->expectsOutputToContain('Hasło:')
        ->assertExitCode(0);

    $user->refresh();
    expect($user->is_admin)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->password)->not->toBe($stare);
});

test('niepoprawny email', function () {
    $this->artisan('marketplace:admin', ['email' => 'nie-mail'])
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});
