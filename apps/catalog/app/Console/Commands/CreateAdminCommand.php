<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * `php artisan marketplace:admin {email}` — zakłada (lub resetuje hasło)
 * konto do panelu edycji katalogu.
 *
 * DLACZEGO komenda, a nie rejestracja: panel jest narzędziem operatora, a
 * hasło ma wyjść dokładnie raz na stdout i nigdzie więcej (ani plik, ani log).
 * `email_verified_at` ustawiamy mimo że trasy panelu nie wymagają `verified` —
 * konto nie utknie, gdyby ktoś dopiął ten middleware później.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'marketplace:admin {email : Adres e-mail konta do panelu}';

    protected $description = 'Tworzy konto do panelu edycji katalogu albo zmienia jego hasło; hasło wypisuje raz';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);
        if ($validator->fails()) {
            $this->error('Niepoprawny adres e-mail: '.$email);

            return self::FAILURE;
        }

        $password = Str::password(20, symbols: false);

        $user = User::firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->name = Str::before($email, '@');
        }
        $user->password = $password; // cast 'hashed' haszuje
        $user->email_verified_at ??= now();
        $user->save();

        $this->line('Hasło: '.$password);

        return self::SUCCESS;
    }
}
