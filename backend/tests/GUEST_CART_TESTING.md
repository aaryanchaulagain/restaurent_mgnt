# Guest cart automated tests

Laravel JSON test requests **do not attach cookies** unless you opt in.

For guest cart resolution tests:

1. Call `$this->disableCookieEncryption()` in `setUp()` (Laravel testing helper).
2. After `POST /api/v1/cart/items`, read the plain token from `$response->getCookie(config('cart.cookie_name'))->getValue()`.
3. Send follow-up requests with `$this->call('GET', '/api/v1/cart', [], [$cookieName => $plainToken], [], ['HTTP_ACCEPT' => 'application/json'])`.

Alternatively use `$this->withCredentials()->withUnencryptedCookie($name, $token)->getJson(...)` — `withCredentials()` is required because `prepareCookiesForJsonRequest()` omits cookies otherwise.

Production cookies are **HttpOnly**, **SameSite=Lax**, and **Secure** when `APP_ENV=production`.
