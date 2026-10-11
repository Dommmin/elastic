<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

test('istniejące niezweryfikowane konto: odmowa, baza bez zmian', function () {
    // Ktoś mógł zarejestrować cudzy/oczekiwany adres wcześniej niż operator.
    $user = User::factory()->unverified()->create(['email' => 'x@y.pl', 'password' => 'stare-haslo']);

    $this->artisan('marketplace:admin', ['email' => 'x@y.pl'])
        ->expectsOutputToContain('odmawiam')
        ->assertExitCode(1);

    $user->refresh();
    expect($user->is_admin)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('stare-haslo', $user->password))->toBeTrue();
});

test('istniejące zweryfikowane konto traci sesje i remember_token', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['email' => 'x@y.pl']);
    $user->forceFill(['remember_token' => 'stary'])->save();
    $inny = User::factory()->create();

    foreach ([['s1', $user->id], ['s2', $inny->id]] as [$id, $uid]) {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $uid, 'payload' => 'x', 'last_activity' => time(),
        ]);
    }

    $this->artisan('marketplace:admin', ['email' => 'x@y.pl'])->assertExitCode(0);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $inny->id)->count())->toBe(1)
        ->and($user->refresh()->remember_token)->not->toBe('stary')
        ->and($user->is_admin)->toBeTrue();
});

test('niepoprawny email', function () {
    $this->artisan('marketplace:admin', ['email' => 'nie-mail'])
        ->assertExitCode(1);

    expect(User::count())->toBe(0);
});
