<?php

namespace Aerys\Session;

use Aerys\Middleware;
use Aerys\Request;
use Aerys\Responder;
use Aerys\Response;
use Amp\Http\Cookie\CookieAttributes;
use Amp\Http\Cookie\ResponseCookie;
use Amp\Promise;
use function Amp\call;

class SessionMiddleware implements Middleware {
    const DEFAULT_COOKIE_NAME = "SESSION_ID";

    /** @var \Aerys\Session\Driver */
    private $driver;

    /** @var string */
    private $cookieName;

    /** @var \Amp\Http\Cookie\CookieAttributes */
    private $cookieAttributes;

    /**
     * @param \Aerys\Session\Driver $driver
     * @param \Amp\Http\Cookie\CookieAttributes|null $cookieAttributes Attribute set for session cookies.
     * @param string $cookieName Name of session identifier cookie.
     */
    public function __construct(
        Driver $driver,
        CookieAttributes $cookieAttributes = null,
        string $cookieName = self::DEFAULT_COOKIE_NAME
    ) {
        $this->driver = $driver;
        $this->cookieName = $cookieName;
        $this->cookieAttributes = $cookieAttributes ?? CookieAttributes::default();
    }

    /**
     * @param Request $request
     * @param Responder $responder Request responder.
     *
     * @return Promise<\Aerys\Response>
     */
    public function process(Request $request, Responder $responder): Promise {
        return call(function () use ($request, $responder) {
            $cookie = $request->getCookie($this->cookieName);

            $session = new Session($this->driver, $cookie ? $cookie->getValue() : null);

            $request->setAttribute(Session::class, $session);

            try {
                $response = yield $responder->respond($request);
            } finally {
                if ($session->isLocked()) {
                    $session->unlock();
                }
            }

            if (!$response instanceof Response) {
                throw new \TypeError("Responder must resolve to an instance of " . Response::class);
            }

            $id = $session->getId();

            if ($id === null || !$session->isRead()) {
                return $response;
            }

            if ($session->isEmpty()) {
                $attributes = $this->cookieAttributes->withExpiry(
                    new \DateTimeImmutable("@0", new \DateTimeZone("UTC"))
                );

                $response->setCookie(new ResponseCookie($this->cookieName, '', $attributes));

                return $response;
            }

            if ($cookie === null || $cookie->getValue() !== $id) {
                $response->setCookie(new ResponseCookie($this->cookieName, $id, $this->cookieAttributes));
            }

            $cacheControl = $response->getHeaderArray("cache-control");

            if (empty($cacheControl)) {
                $response->setHeader("cache-control", "private");
            } else {
                foreach ($cacheControl as $key => $value) {
                    $tokens = \array_map("trim", \explode(",", $value));
                    $tokens = \array_filter($tokens, function ($token) {
                        return $token !== "public";
                    });

                    if (!\in_array("private", $tokens, true)) {
                        $tokens[] = "private";
                    }

                    $cacheControl[$key] = \implode(",", $tokens);
                }

                $response->setHeader("cache-control", $cacheControl);
            }

            return $response;
        });
    }
}
