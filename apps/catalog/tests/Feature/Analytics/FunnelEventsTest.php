<?php

use App\Analytics\AnalyticsRecorder;
use App\Analytics\SearchContextStore;
use App\Models\CartItem;
use App\Models\Offer as OfferRow;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\Seller as SellerRow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery\MockInterface;

afterEach(function () {
    foreach (Redis::keys('*analytics:search:funnel-*') as $key) {
        Redis::del(preg_replace('/^.*?(analytics:search:)/', '$1', $key));
    }
});

function funnelContext(string $searchId, string $query = 'buty'): void
{
    app(SearchContextStore::class)->put($searchId, $query, [1, 2, 3]);
}

function funnelOffer(?Product $product = null, int $price = 10000): OfferRow
{
    $seller = SellerRow::factory()->forUser(User::factory()->create())->create();

    return OfferRow::factory()->create([
        'seller_id' => $seller->id,
        'product_id' => ($product ?? Product::factory()->create())->id,
        'price_cents' => $price,
        'stock' => 5,
    ]);
}

test('wejście na produkt z sid i pos zapisuje user.clicked_result z frazą i kluczem dedup', function () {
    $product = Product::factory()->create();
    $sid = (string) Str::uuid();
    app(SearchContextStore::class)->put('funnel-s1', 'buty', [$product->id + 1000, $product->id + 1001, $product->id]);

    $this->withUnencryptedCookie('mp_sid', $sid)->get("/products/{$product->id}?sid=funnel-s1&pos=3")->assertOk();

    $row = Outbox::where('event_type', 'user.clicked_result')->sole();
    expect($row->aggregate_id)->toBe($sid)
        ->and($row->payload['search_id'])->toBe('funnel-s1')
        ->and($row->payload['query'])->toBe('buty')
        ->and($row->payload['product_id'])->toBe($product->id)
        ->and($row->payload['position'])->toBe(3)
        ->and($row->payload['dedup_key'])->toBe("click:funnel-s1:{$product->id}");
});

test('kliknięcie bez kontekstu w Redisie nie tworzy zdarzenia', function () {
    $product = Product::factory()->create();

    $this->get("/products/{$product->id}?sid=funnel-wygasl&pos=1")->assertOk();

    expect(Outbox::where('event_type', 'user.clicked_result')->count())->toBe(0);
});

test('kliknięcie w produkt spoza kontekstu wyszukiwania nie tworzy zdarzenia', function () {
    $product = Product::factory()->create();
    app(SearchContextStore::class)->put('funnel-obcy', 'buty', [$product->id + 1000, $product->id + 1001]);

    $this->get("/products/{$product->id}?sid=funnel-obcy&pos=1")->assertOk();

    expect(Outbox::where('event_type', 'user.clicked_result')->count())->toBe(0);
});

test('pozycja kliknięcia pochodzi z kontekstu, a sfałszowane pos jest ignorowane', function (string $qs) {
    $product = Product::factory()->create();
    app(SearchContextStore::class)->put('funnel-pos', 'buty', [$product->id + 1000, $product->id, $product->id + 1001]);

    $this->get("/products/{$product->id}?sid=funnel-pos{$qs}")->assertOk();

    expect(Outbox::where('event_type', 'user.clicked_result')->sole()->payload['position'])->toBe(2);
})->with(['&pos=20', '&pos=1', '&pos=abc', '']);

test('sid spoza alfabetu ULID/UUID nie jest używany jako klucz w Redisie', function (string $sid) {
    $product = Product::factory()->create();
    $this->mock(SearchContextStore::class)->shouldNotReceive('get');

    $this->get("/products/{$product->id}?sid=".urlencode($sid).'&pos=1')->assertOk()
        ->assertInertia(fn ($page) => $page->where('searchId', null));

    expect(Outbox::where('event_type', 'user.clicked_result')->count())->toBe(0);
})->with(['a:b', 'funnel s1', 'x*', str_repeat('a', 65)]);

test('niepoprawne pos albo brak sid nie tworzy zdarzenia, a strona działa', function (string $qs) {
    $product = Product::factory()->create();

    $this->get("/products/{$product->id}{$qs}")->assertOk();

    expect(Outbox::where('event_type', 'user.clicked_result')->count())->toBe(0);
})->with(['?sid=funnel-x&pos=abc', '?sid=funnel-x&pos=0', '?sid=funnel-x&pos=-2', '?sid=funnel-x', '?pos=2', '?sid[]=a&pos=1', '']);

test('zalogowany użytkownik dokłada user_id do kliknięcia', function () {
    $product = Product::factory()->create();
    $user = User::factory()->create();
    app(SearchContextStore::class)->put('funnel-u', 'buty', [$product->id + 1000, $product->id]);

    $this->actingAs($user)->get("/products/{$product->id}?sid=funnel-u&pos=2")->assertOk();

    expect(Outbox::where('event_type', 'user.clicked_result')->sole()->payload['user_id'])->toBe($user->id);
});

test('dodanie do koszyka z sid niesie search_id i query', function () {
    $buyer = User::factory()->create();
    $offer = funnelOffer();
    $sid = (string) Str::uuid();
    funnelContext('funnel-s2', 'sandały');

    $this->actingAs($buyer)->withUnencryptedCookie('mp_sid', $sid)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents, 'sid' => 'funnel-s2',
    ])->assertSessionHasNoErrors();

    $row = Outbox::where('event_type', 'user.added_to_cart')->sole();
    expect($row->aggregate_id)->toBe($sid)
        ->and($row->payload['product_id'])->toBe($offer->product_id)
        ->and($row->payload['offer_id'])->toBe($offer->id)
        ->and($row->payload['search_id'])->toBe('funnel-s2')
        ->and($row->payload['query'])->toBe('sandały')
        ->and($row->payload['user_id'])->toBe($buyer->id);
});

test('dodanie do koszyka bez sid nie ma search_id ani query', function () {
    $buyer = User::factory()->create();
    $offer = funnelOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents,
    ])->assertSessionHasNoErrors();

    $row = Outbox::where('event_type', 'user.added_to_cart')->sole();
    expect($row->payload)->not->toHaveKeys(['search_id', 'query'])
        ->and($row->payload['offer_id'])->toBe($offer->id);
});

test('dodanie do koszyka z sid bez kontekstu w Redisie nie ma search_id ani query', function () {
    $buyer = User::factory()->create();
    $offer = funnelOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents, 'sid' => 'funnel-wygasl',
    ])->assertSessionHasNoErrors();

    expect(Outbox::where('event_type', 'user.added_to_cart')->sole()->payload)->not->toHaveKeys(['search_id', 'query']);
});

test('sid spoza alfabetu ULID/UUID w koszyku to błąd walidacji', function () {
    $buyer = User::factory()->create();
    $offer = funnelOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents, 'sid' => 'a:b*',
    ])->assertSessionHasErrors('sid');

    expect(CartItem::count())->toBe(0);
});

test('odrzucone dodanie do koszyka nie zapisuje zdarzenia', function () {
    $buyer = User::factory()->create();
    $offer = funnelOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents + 1,
    ])->assertSessionHasErrors('offer_id');

    expect(Outbox::where('event_type', 'user.added_to_cart')->count())->toBe(0)
        ->and(CartItem::count())->toBe(0);
});

test('za długi sid w koszyku to błąd walidacji', function () {
    $buyer = User::factory()->create();
    $offer = funnelOffer();

    $this->actingAs($buyer)->post(route('cart.items.store'), [
        'offer_id' => $offer->id, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents, 'sid' => str_repeat('a', 65),
    ])->assertSessionHasErrors('sid');
});

test('checkout z dwoma sprzedawcami zapisuje jedno user.checked_out ze wszystkimi zamówieniami', function () {
    $buyer = User::factory()->create();
    $a = funnelOffer(price: 10000);
    $b = funnelOffer(price: 2500);
    $sid = (string) Str::uuid();
    foreach ([$a, $b] as $offer) {
        CartItem::create(['user_id' => $buyer->id, 'offer_id' => $offer->id, 'quantity' => 2, 'added_price_cents' => $offer->price_cents]);
    }

    $this->actingAs($buyer)->withUnencryptedCookie('mp_sid', $sid)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $a->id, 'quantity' => 2, 'unit_price_cents' => 10000],
        ['offer_id' => $b->id, 'quantity' => 2, 'unit_price_cents' => 2500],
    ]])->assertRedirect('/orders');

    $row = Outbox::where('event_type', 'user.checked_out')->sole();
    $orderIds = DB::table('orders')->where('buyer_user_id', $buyer->id)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

    expect($row->aggregate_id)->toBe($sid)
        ->and($row->payload['order_ids'])->toHaveCount(2)
        ->and(collect($row->payload['order_ids'])->map(fn ($id) => (string) $id)->sort()->values()->all())->toBe(collect($orderIds)->sort()->values()->all())
        ->and($row->payload['product_ids'])->toEqualCanonicalizing([$a->product_id, $b->product_id])
        ->and($row->payload['amount_cents'])->toBe(2 * 10000 + 2 * 2500)
        ->and($row->payload['user_id'])->toBe($buyer->id);
});

test('awaria zapisu user.checked_out nie psuje checkoutu: zamówienia są, przekierowanie na listę', function () {
    $buyer = User::factory()->create();
    $a = funnelOffer();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $a->id, 'quantity' => 1, 'added_price_cents' => $a->price_cents]);
    $this->mock(AnalyticsRecorder::class, function (MockInterface $m) {
        $m->shouldReceive('record')->andThrow(new RuntimeException('analityka leży'));
    });
    Exceptions::fake();

    $this->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $a->id, 'quantity' => 1, 'unit_price_cents' => $a->price_cents],
    ]])->assertRedirect('/orders');

    expect(DB::table('orders')->where('buyer_user_id', $buyer->id)->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'analityka leży');
});

test('odrzucony checkout nie zapisuje user.checked_out', function () {
    $buyer = User::factory()->create();
    $a = funnelOffer();
    CartItem::create(['user_id' => $buyer->id, 'offer_id' => $a->id, 'quantity' => 2, 'added_price_cents' => $a->price_cents]);
    $a->update(['price_cents' => 12000]);

    $this->actingAs($buyer)->post(route('cart.checkout'), ['lines' => [
        ['offer_id' => $a->id, 'quantity' => 2, 'unit_price_cents' => 10000],
    ]])->assertSessionHasErrors('cart');

    expect(Outbox::where('event_type', 'user.checked_out')->count())->toBe(0);
});
