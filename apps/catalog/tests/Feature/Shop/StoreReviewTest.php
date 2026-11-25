<?php

use App\Models\Outbox;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Security\ClientFingerprint;

beforeEach(function () {
    config(['services.security.ip_pepper' => 'test']);
});

test('zalogowany zapisuje recenzję z odciskiem klienta w outboxie', function () {
    $user = User::factory()->create(['created_at' => now()->subHours(30)]);
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
        ->withHeader('User-Agent', 'TestAgent/1.0')
        ->post(route('products.reviews.store', $product), ['rating' => 5, 'body' => 'ok'])
        ->assertRedirect(route('products.show', $product));

    expect(Review::count())->toBe(1);

    $events = Outbox::where('event_type', 'review.created')->get();
    expect($events)->toHaveCount(1);

    $payload = $events->first()->payload;
    expect($payload['client_ip_hash'])->toMatch('/^[0-9a-f]{16}$/')
        ->and($payload['client_ip_prefix'])->toBe('198.51.100.0/24')
        ->and($payload['client_user_agent'])->toBe('TestAgent/1.0')
        ->and($payload['user_account_age_hours'])->toBe(30)
        ->and($payload['rating'])->toBe(5)
        ->and(json_encode($payload))->not->toContain('198.51.100.23');
});

test('gość jest przekierowany na logowanie i nic nie zapisuje', function () {
    $product = Product::factory()->create();

    $this->post(route('products.reviews.store', $product), ['rating' => 5])
        ->assertRedirect(route('login'));

    expect(Review::count())->toBe(0);
});

test('ocena poza zakresem 1-5 jest odrzucana', function (int $rating) {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->post(route('products.reviews.store', $product), ['rating' => $rating])
        ->assertSessionHasErrors('rating');

    expect(Review::count())->toBe(0);
})->with([0, 6]);

test('druga recenzja tego samego użytkownika na ten sam produkt jest odrzucona', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($user)->post(route('products.reviews.store', $product), ['rating' => 5])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('products.reviews.store', $product), ['rating' => 4])->assertSessionHasErrors('rating');

    expect(Review::count())->toBe(1)
        ->and(Outbox::where('event_type', 'review.created')->count())->toBe(1);
});

test('pusty pepper nie blokuje zapisu, hasz IP jest null', function () {
    config(['services.security.ip_pepper' => '']);
    app()->forgetInstance(ClientFingerprint::class);

    $user = User::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->post(route('products.reviews.store', $product), ['rating' => 3])
        ->assertRedirect(route('products.show', $product));

    $payload = Outbox::where('event_type', 'review.created')->firstOrFail()->payload;
    expect(Review::count())->toBe(1)
        ->and($payload['client_ip_hash'])->toBeNull()
        ->and($payload['client_ip_prefix'])->toBe('127.0.0.0/24');
});

test('User-Agent z niepoprawnym UTF-8 nie psuje zapisu', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($user)
        ->withHeader('User-Agent', "Bad\xC3\x28Agent\xFF")
        ->post(route('products.reviews.store', $product), ['rating' => 4])
        ->assertRedirect(route('products.show', $product));

    $payload = Outbox::where('event_type', 'review.created')->firstOrFail()->payload;
    expect(Review::count())->toBe(1)
        ->and(mb_check_encoding($payload['client_user_agent'], 'UTF-8'))->toBeTrue();
});

test('szóste żądanie w minucie dostaje 429', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post(route('products.reviews.store', $product), ['rating' => 5]);
    }

    $this->actingAs($user)
        ->post(route('products.reviews.store', $product), ['rating' => 5])
        ->assertStatus(429);
});

test('duplikat utworzony po walidacji, a przed zapisem, jest odrzucony pod blokadą', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    // Zapytanie blokujące wiersz użytkownika odpala `retrieved` dopiero w transakcji, już po
    // FormRequest; tam wstawiamy konkurencyjną recenzję, jakby wyprzedziło nas drugie żądanie.
    User::retrieved(function () use ($user, $product) {
        static $done = false;
        if (! $done) {
            $done = true;
            Review::create(['product_id' => $product->id, 'user_id' => $user->id, 'rating' => 1]);
        }
    });

    $this->actingAs($user)
        ->post(route('products.reviews.store', $product), ['rating' => 5])
        ->assertSessionHasErrors('rating');

    // Konkurencyjny wiersz powstał w tej samej transakcji testu, więc cofnięcie go też zabiera;
    // liczy się, że nasza recenzja (ocena 5) nie powstała i nie ma zdarzenia.
    expect(Review::where('rating', 5)->count())->toBe(0)
        ->and(Outbox::where('event_type', 'review.created')->count())->toBe(0);
});
