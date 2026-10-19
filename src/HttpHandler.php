<?php declare(strict_types=1);

use Bref\Context\Context;
use Bref\Event\Http\HttpHandler as BrefHttpHandler;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\HttpResponse;

final class PayrollHttpHandler extends BrefHttpHandler
{
    public function handleRequest(HttpRequestEvent $event, Context $context): HttpResponse
    {
        $result = (new PayrollApi())->dispatch($event->getMethod(), $event->getPath());

        return new HttpResponse(
            json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ['content-type' => 'application/json; charset=utf-8'],
            $result['statusCode']
        );
    }
}
