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
 * Komenda nadaje `is_admin` (Gate `panel`). `email_verified_at` ustawiamy
 * wyłącznie przy zakładaniu konta: istniejącemu, niezweryfikowanemu kontu nie
 * wolno przy okazji potwierdzać cudzego adresu.
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
        $nowe = ! $user->exists;
        if ($nowe) {
            $user->name = Str::before($email, '@');
            $user->email_verified_at = now();
        }
        $user->password = $password; // cast 'hashed' haszuje
        $user->is_admin = true; // przypisanie, nie fill — flaga nie jest mass-assignable
        $user->save();

        $this->info($nowe
            ? 'Utworzono konto administratora: '.$email
            : 'Zmieniono hasło i nadano uprawnienia administratora: '.$email);

        $this->line('Hasło: '.$password);

        return self::SUCCESS;
    }
}
