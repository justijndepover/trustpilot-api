# Laravel Support

The package doesn't add support for Laravel (yet). To install the package please follow the steps below.

## Adding the configuration files

open the `config/services.php` file and add the following to the list of services:

```php
'trustpilot' => [
    'client_id' => env('TRUSTPILOT_CLIENT_ID'),
    'client_secret' => env('TRUSTPILOT_CLIENT_SECRET'),
    'redirect_uri' => env('TRUSTPILOT_REDIRECT_URI'),
],
```

Make sure those env values exist in your `.env` file

```
TRUSTPILOT_CLIENT_ID=abc
TRUSTPILOT_CLIENT_SECRET=abc
TRUSTPILOT_REDIRECT_URI=https://yourapplication.com/trustpilot/accept
```

## Register a service provider

open up your console and type the following command:

```sh
php artisan make:provider TrustpilotServiceProvider
```

This is the content of that file:

```php
<?php

namespace App\Providers;

use Exception;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Storage;
use Justijndepover\Trustpilot\Trustpilot;

class TrustpilotServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register()
    {
        $this->app->singleton(Trustpilot::class, function ($app) {
            $trustpilot = new Trustpilot(
                config('services.trustpilot.client_id'),
                config('services.trustpilot.client_secret'),
                config('services.trustpilot.redirect_uri'),
            );

            $trustpilot->setTokenUpdateCallback(function ($trustpilot) {
                Storage::disk('local')->put('trustpilot.json', json_encode([
                    'accessToken' => $trustpilot->getAccessToken(),
                    'refreshToken' => $trustpilot->getRefreshToken(),
                    'expiresAt' => $trustpilot->getTokenExpiresAt(),
                ]));
            });

            if (Storage::exists('trustpilot.json') && $json = Storage::get('trustpilot.json')) {
                try {
                    $json = json_decode($json);
                    $trustpilot->setAccessToken($json->accessToken);
                    $trustpilot->setRefreshToken($json->refreshToken);
                    $trustpilot->setTokenExpiresAt($json->expiresAt);
                } catch (Exception $e) {
                }
            }

            if (! empty($trustpilot->getRefreshToken()) && $trustpilot->shouldRefreshToken()) {
                try {
                    $trustpilot->connect();
                } catch (\Throwable $th) {
                    $trustpilot->setRefreshToken('');

                    Storage::disk('local')->put('trustpilot.json', json_encode([
                        'accessToken' => $trustpilot->getAccessToken(),
                        'refreshToken' => $trustpilot->getRefreshToken(),
                        'expiresAt' => $trustpilot->getTokenExpiresAt(),
                    ]));
                }
            }

            return $trustpilot;
        });
    }

    public function provides()
    {
        return [Trustpilot::class];
    }
}
```

## Setting up the routes

Add the following 3 routes to your `routes/web.php` file

```php
Route::get('trustpilot', [SettingsController::class, 'index'])->name('settings.index');
Route::post('trustpilot/authorize', [SettingsController::class, 'redirectForAuthorization'])->name('settings.trustpilot.authorize');
Route::get('trustpilot/accept', [SettingsController::class, 'accept']);
```

Note: the `trustpilot/accept` route should always be accessible. Don't put it behind auth middleware. This route is called by Trustpilot.

## Creating the Controller

Next, create the controller that handles the routes:

```sh
php artisan make:controller SettingsController
```

This is the content of that file

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Justijndepover\Trustpilot\Trustpilot;

class SettingsController extends Controller
{
    public function index(Trustpilot $trustpilot)
    {
        // this view should show a 'connect to Trustpilot' button
        // or when logged in, show a message: 'You are logged in'

        return view('settings.index', [
            'trustpilot' => $trustpilot,
        ]);
    }

    public function redirectForAuthorization(Trustpilot $trustpilot)
    {
        return redirect($trustpilot->redirectForAuthorizationUrl());
    }

    public function accept(Request $request, Trustpilot $trustpilot)
    {
        if ($request->error) {
            return redirect()->route('settings.index')->with('error', __('The user refused to connect'));
        }

        if ($request->state != $trustpilot->getState()) {
            return redirect()->route('settings.index')->with('error', __('The state parameter doesn\'t match.'));
        }

        $trustpilot->setAuthorizationCode($request->code);
        $trustpilot->connect();

        Storage::disk('local')->put('trustpilot.json', json_encode([
            'accessToken' => $trustpilot->getAccessToken(),
            'refreshToken' => $trustpilot->getRefreshToken(),
            'expiresAt' => $trustpilot->getTokenExpiresAt(),
        ]));

        return redirect()->route('settings.index')->with('message', __('You are connected with Trustpilot'));
    }
}
```

## Adding a view

The code above assumes you have a `settings.index` view. This could be the content of that file:

```blade.php
@if (session()->has('message'))
    {{ session()->get('message') }}
@endif

@if (session()->has('error'))
    {{ session()->get('error') }}
@endif

@if ($trustpilot->shouldAuthorize())
    <form action="{{ route('settings.trustpilot.authorize') }}" method="POST">
        @csrf
        <button type="submit">{{ __('default.trustpilot.connect') }}</button>
    </form>
@else
    <p>You are already connected with Trustpilot</p>
@endif
```