# Trustpilot API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/justijndepover/trustpilot-api.svg?style=flat-square)](https://packagist.org/packages/justijndepover/trustpilot-api)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/justijndepover/trustpilot-api.svg?style=flat-square)](https://packagist.org/packages/justijndepover/trustpilot-api)

PHP Client for the Trustpilot API

## Caution

This application is still in development and could implement breaking changes. Please use at your own risk.

## Installation

You can install the package with composer

```sh
composer require justijndepover/trustpilot-api
```

## Installing the package in Laravel

To use the plugin in Laravel applications, please refer to the [Laravel usage page](docs/laravel-usage.md)

## Usage

Connecting to Trustpilot:
```php
$trustpilot = new Trustpilot(CLIENT_ID, CLIENT_SECRET, REDIRECT_URI);
// open the trustpilot login
header("Location: {$trustpilot->redirectForAuthorizationUrl()}");
exit;
```

After connecting, Trustpilot will send a request back to your redirect uri.
```php
$trustpilot = new Trustpilot(CLIENT_ID, CLIENT_SECRET, REDIRECT_URI);

if ($_GET['error']) {
    // your application should handle this error
}

$trustpilot->setAuthorizationCode($_GET['code']);
$trustpilot->connect();

// store these values:
$accessToken = $trustpilot->getAccessToken();
$refreshToken = $trustpilot->getRefreshToken();
$expiresAt = $trustpilot->getTokenExpiresAt();
```

Your application is now connected. To start fetching data:
```php
$trustpilot = new Trustpilot(CLIENT_ID, CLIENT_SECRET, REDIRECT_URI);
$trustpilot->setAccessToken($accessToken);
$trustpilot->setRefreshToken($refreshToken);
$trustpilot->setTokenExpiresAt($expiresAt);

// fetch data:
$trustpilot->get($url);

// you should always store your tokens at the end of a call
$accessToken = $trustpilot->getAccessToken();
$refreshToken = $trustpilot->getRefreshToken();
$expiresAt = $trustpilot->getTokenExpiresAt();
```

## Security

If you find any security related issues, please open an issue or contact me directly at [justijndepover@gmail.com](justijndepover@gmail.com).

## Contribution

If you wish to make any changes or improvements to the package, feel free to make a pull request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
