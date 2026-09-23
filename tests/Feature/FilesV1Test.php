<?php

use App\Models\File;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(fn () => (new PlanCatalogueSeeder)->run());

function filesShop(): array
{
    return [makeMerchant('2580'), ownerToken()];
}

it('uploads a product image, resized and re-encoded to webp with a thumbnail', function () {
    [, $token] = filesShop();

    $response = test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'product_image',
        'file' => UploadedFile::fake()->image('rice.jpg', 1600, 1200),
    ])->assertCreated()
        ->assertJsonPath('message', 'Upload complete')
        ->assertJsonPath('data.purpose', 'product_image')
        ->assertJsonPath('data.width', 1200)
        ->assertJsonPath('data.height', 900)
        ->assertJsonPath('data.content_type', 'image/webp')
        ->assertJsonPath('data.attached', false);

    expect($response->json('data.file_id'))->toStartWith('file_')
        ->and($response->json('data.url'))->toContain('.webp')
        ->and($response->json('data.thumb_url'))->toContain('_thumb.webp')
        ->and($response->json('data.bytes'))->toBeGreaterThan(0);
});

it('replays the same upload when the client_uuid repeats', function () {
    [, $token] = filesShop();
    $uuid = (string) Str::uuid();

    $first = test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'product_image', 'client_uuid' => $uuid, 'file' => UploadedFile::fake()->image('a.jpg', 800, 600),
    ])->assertCreated()->json('data.file_id');

    test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'product_image', 'client_uuid' => $uuid, 'file' => UploadedFile::fake()->image('b.jpg', 400, 300),
    ])->assertOk()->assertJsonPath('data.file_id', $first);

    expect(File::where('client_uuid', $uuid)->count())->toBe(1);
});

it('uploads a signature and flattens it without a thumbnail', function () {
    [, $token] = filesShop();

    test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'signature', 'file' => UploadedFile::fake()->image('sig.png', 300, 100),
    ])->assertCreated()
        ->assertJsonPath('data.purpose', 'signature')
        ->assertJsonPath('data.content_type', 'image/png')
        ->assertJsonPath('data.thumb_url', null)
        ->assertJsonPath('data.width', 300)
        ->assertJsonPath('data.height', 100);
});

it('uploads a merchant logo, resized and kept in its original format', function () {
    [, $token] = filesShop();

    test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'merchant_logo', 'file' => UploadedFile::fake()->image('logo.jpg', 1000, 1000),
    ])->assertCreated()
        ->assertJsonPath('data.purpose', 'merchant_logo')
        ->assertJsonPath('data.content_type', 'image/jpeg')
        ->assertJsonPath('data.width', 512)
        ->assertJsonPath('data.height', 512)
        ->assertJsonPath('data.thumb_url', null);
});

it('refuses a file over the limit for its purpose', function () {
    [, $token] = filesShop();

    test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'product_image', 'file' => UploadedFile::fake()->image('big.jpg')->size(6000),
    ])->assertStatus(413)->assertJsonPath('error.code', 'file.too_large')->assertJsonPath('error.details.max_bytes', 5 * 1024 * 1024);
});

it('refuses a type that is not accepted for the purpose', function () {
    [, $token] = filesShop();

    test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'signature', 'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
    ])->assertStatus(415)->assertJsonPath('error.code', 'file.type_unsupported')->assertJsonPath('error.details.accepted', ['image/png']);
});

it('refuses a file that is not a decodable image', function () {
    [, $token] = filesShop();

    test()->withToken($token)->post('/api/v1/files', [
        'purpose' => 'product_image', 'file' => UploadedFile::fake()->create('bad.png', 10, 'image/png'),
    ])->assertStatus(422)->assertJsonPath('error.code', 'file.corrupt');
});

it('validates the upload request', function () {
    [, $token] = filesShop();

    test()->withToken($token)->postJson('/api/v1/files', ['purpose' => 'product_image'])->assertStatus(422);
    test()->withToken($token)->post('/api/v1/files', ['file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(422);
    test()->withToken($token)->post('/api/v1/files', ['purpose' => 'bogus', 'file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(422);
});

it('needs a token to upload', function () {
    test()->post('/api/v1/files', ['purpose' => 'product_image', 'file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(401);
});

it('resolves a file id to a fresh signed url', function () {
    [, $token] = filesShop();
    $fileId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'product_image', 'file' => UploadedFile::fake()->image('a.jpg', 400, 300)])->json('data.file_id');

    $response = test()->withToken($token)->getJson('/api/v1/files/'.$fileId)->assertOk();

    expect($response->json('data.url'))->toContain('signature=')->and($response->json('data.expires_in'))->toBe(3600);

    test()->getJson($response->json('data.url'))->assertOk();
});

it('returns 404 for a file that is not this shops or does not exist', function () {
    [, $token] = filesShop();
    $other = otherShop();
    $foreign = File::create([
        'public_id' => 'file_FOREIGN01', 'merchant_id' => $other->id, 'purpose' => 'product_image', 'disk' => 'public',
        'path' => 'files/foreign.webp', 'content_type' => 'image/webp', 'bytes' => 1,
    ]);

    test()->withToken($token)->getJson('/api/v1/files/'.$foreign->public_id)->assertStatus(404)->assertJsonPath('error.code', 'file.not_found');
    test()->withToken($token)->getJson('/api/v1/files/file_UNKNOWN')->assertStatus(404);
});

it('deletes an unattached file but refuses one that is in use', function () {
    [$owner, $token] = filesShop();
    $fileId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'product_image', 'file' => UploadedFile::fake()->image('a.jpg', 400, 300)])->json('data.file_id');

    test()->withToken($token)->deleteJson('/api/v1/files/'.$fileId)->assertOk()->assertJsonPath('message', 'File deleted')->assertJsonPath('data.deleted', true);
    expect(File::where('public_id', $fileId)->exists())->toBeFalse();

    $attachedId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'product_image', 'file' => UploadedFile::fake()->image('b.jpg', 400, 300)])->json('data.file_id');
    $product = addProduct($token, ['bar_code' => 'IMG-1', 'image_file_id' => $attachedId]);

    test()->withToken($token)->deleteJson('/api/v1/files/'.$attachedId)
        ->assertStatus(409)->assertJsonPath('error.code', 'file.in_use')
        ->assertJsonPath('error.details.attached_to.type', 'product')->assertJsonPath('error.details.attached_to.id', $product['id']);
});

it('attaches an uploaded photo to a product and serves it back', function () {
    [, $token] = filesShop();
    $fileId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'product_image', 'file' => UploadedFile::fake()->image('rice.jpg', 800, 600)])->json('data.file_id');

    $product = addProduct($token, ['bar_code' => 'IMG-2', 'image_file_id' => $fileId]);

    expect($product['image']['id'])->toBe($fileId)->and($product['image']['url'])->toContain('.webp')->and($product['image']['thumb_url'])->toContain('_thumb');

    test()->withToken($token)->getJson('/api/v1/products/lookup?barcode=IMG-2')->assertOk()->assertJsonPath('data.image.thumb_url', $product['image']['thumb_url']);

    expect(File::where('public_id', $fileId)->value('attached_at'))->not->toBeNull();
});

it('refuses a photo that was not uploaded as a product image', function () {
    [, $token] = filesShop();
    $logoId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'merchant_logo', 'file' => UploadedFile::fake()->image('logo.jpg', 400, 400)])->json('data.file_id');

    test()->withToken($token)->postJson('/api/v1/products', productBody(['bar_code' => 'IMG-3', 'image_file_id' => $logoId]))
        ->assertStatus(422)->assertJsonPath('error.field', 'image_file_id');
});

it('sets and clears the shop logo', function () {
    [$owner, $token] = filesShop();
    $logoId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'merchant_logo', 'file' => UploadedFile::fake()->image('logo.jpg', 400, 400)])->json('data.file_id');

    test()->withToken($token)->patchJson('/api/v1/merchant', ['logo_file_id' => $logoId])->assertOk()
        ->assertJsonPath('data.logo.id', $logoId);

    app('auth')->forgetGuards();
    test()->withToken($token)->getJson('/api/v1/merchant')->assertJsonPath('data.logo.id', $logoId);

    app('auth')->forgetGuards();
    test()->withToken($token)->patchJson('/api/v1/merchant', ['logo_file_id' => null])->assertOk()->assertJsonPath('data.logo', null);
});

it('attaches a signature to a held order', function () {
    [$owner, $token] = filesShop();
    $rice = addProduct($token, ['bar_code' => 'SIG-1', 'quantity' => 5]);
    addLine($token, $rice['id'], 1)->assertOk();

    $sigId = test()->withToken($token)->post('/api/v1/files', ['purpose' => 'signature', 'file' => UploadedFile::fake()->image('sig.png', 200, 80)])->json('data.file_id');

    $held = test()->withToken($token)->postJson('/api/v1/cart/hold', [
        'customer' => ['name' => 'Amina Yusuf', 'mobile_number' => '+252635550101'], 'signature_file_id' => $sigId, 'idempotency_key' => freshKey(),
    ])->assertCreated()->json('data.order.id');

    test()->withToken($token)->getJson('/api/v1/orders/'.$held)->assertOk()
        ->assertJsonPath('data.signature.file_id', $sigId);

    test()->withToken($token)->getJson('/api/v1/orders/'.$held.'/receipt')->assertOk()
        ->assertJsonPath('data.signature_url', fn ($url) => $url !== null);
});
