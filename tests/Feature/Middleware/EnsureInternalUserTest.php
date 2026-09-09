<?php

use App\Http\Middleware\EnsureInternalUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

test('allows owner users', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureInternalUser;
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('allows admin users', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureInternalUser;
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('allows staff users', function () {
    $user = User::factory()->create(['role' => 'staff']);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureInternalUser;
    $response = $middleware->handle($request, fn ($req) => response('OK'));

    expect($response->getContent())->toBe('OK');
});

test('denies client users', function () {
    $user = User::factory()->create(['role' => 'client']);
    $request = Request::create('/test');
    $request->setUserResolver(fn () => $user);

    $middleware = new EnsureInternalUser;

    $middleware->handle($request, fn ($req) => response('OK'));
})->throws(HttpException::class);

test('denies unauthenticated requests', function () {
    $request = Request::create('/test');
    $request->setUserResolver(fn () => null);

    $middleware = new EnsureInternalUser;

    $middleware->handle($request, fn ($req) => response('OK'));
})->throws(HttpException::class);
