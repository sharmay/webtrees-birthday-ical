<?php

declare(strict_types=1);

namespace BirthdayIcal;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Adapts a closure to the request-handler object webtrees' router expects. */
final class ClosureHandler implements RequestHandlerInterface
{
    public function __construct(private Closure $callback)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->callback)($request);
    }
}
