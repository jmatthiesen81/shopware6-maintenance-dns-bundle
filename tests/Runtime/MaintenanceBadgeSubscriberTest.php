<?php

declare(strict_types=1);

namespace Devable\MaintenanceDnsBundle\Tests\Runtime;

use Devable\MaintenanceDnsBundle\Runtime\MaintenanceBadgeSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MaintenanceBadgeSubscriberTest extends TestCase
{
    private const PAGE = '<html><body><main>Shop</main></body></html>';

    public function testInsertsBadgeBeforeClosingBody(): void
    {
        $response = $this->dispatch($this->request(), new Response(self::PAGE, 200, [ 'Content-Type' => 'text/html; charset=UTF-8', 'Content-Length' => '42' ]));

        $content = (string) $response->getContent();
        static::assertStringContainsString('<div class="devable-maintenance-badge" role="status">Maintenance mode</div></body></html>', $content);
        static::assertStringContainsString('position:fixed;top:12px;right:12px;', $content);
        static::assertStringContainsString('<style>', $content);
        static::assertFalse($response->headers->has('Content-Length'));
        static::assertTrue($response->headers->hasCacheControlDirective('private'));
        static::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testUsesTextPositionAndCspNonce(): void
    {
        $request = $this->request();
        $request->attributes->set('_cspNonce', 'abc123');

        $response = $this->dispatch($request, new Response(self::PAGE), new MaintenanceBadgeSubscriber('Wartung <aktiv> & "intern"', 'bottom-left'));

        $content = (string) $response->getContent();
        static::assertStringContainsString('<style nonce="abc123">', $content);
        static::assertStringContainsString('position:fixed;bottom:12px;left:12px;', $content);
        static::assertStringContainsString('>Wartung &lt;aktiv&gt; &amp; &quot;intern&quot;</div>', $content);
    }

    public function testInsertsBeforeLastClosingBodyOnly(): void
    {
        $page = '<html><body><script>var s = "</body>";</script></BODY></html>';

        $content = (string) $this->dispatch($this->request(), new Response($page))->getContent();

        static::assertSame(1, \substr_count($content, 'devable-maintenance-badge" role'));
        static::assertStringEndsWith('Maintenance mode</div></BODY></html>', $content);
        static::assertStringContainsString('var s = "</body>";', $content);
    }

    /**
     * @return iterable<string, array{\Closure(Request): void, \Closure(): Response}>
     */
    public static function notApplicableProvider(): iterable
    {
        $page = static fn (): Response => new Response(self::PAGE);
        $noop = static function (Request $request): void {};

        yield 'not in maintenance' => [ static fn (Request $r) => $r->attributes->set('sw-maintenance', false), $page ];
        yield 'no maintenance attribute' => [ static fn (Request $r) => $r->attributes->remove('sw-maintenance'), $page ];
        yield 'store api' => [ static fn (Request $r) => $r->attributes->set('_routeScope', [ 'store-api' ]), $page ];
        yield 'admin api' => [ static fn (Request $r) => $r->attributes->set('_routeScope', [ 'api' ]), $page ];
        yield 'no route scope' => [ static fn (Request $r) => $r->attributes->remove('_routeScope'), $page ];
        yield 'maintenance page' => [ static fn (Request $r) => $r->attributes->set('_route', 'frontend.maintenance.page'), $page ];
        yield 'maintenance single page' => [ static fn (Request $r) => $r->attributes->set('_route', 'frontend.maintenance.singlepage'), $page ];
        yield 'xhr' => [ static fn (Request $r) => $r->headers->set('X-Requested-With', 'XMLHttpRequest'), $page ];
        yield 'json' => [ $noop, static fn (): Response => new Response('{"a":"</body>"}', 200, [ 'Content-Type' => 'application/json' ]) ];
        yield 'redirect' => [ $noop, static fn (): Response => new RedirectResponse('/maintenance') ];
        yield 'streamed' => [ $noop, static fn (): Response => new StreamedResponse(static function (): void {}) ];
        yield 'binary file' => [ $noop, static fn (): Response => new BinaryFileResponse(__FILE__) ];
        yield 'compressed' => [ $noop, static fn (): Response => new Response(self::PAGE, 200, [ 'Content-Encoding' => 'gzip' ]) ];
        yield 'no closing body' => [ $noop, static fn (): Response => new Response('<div>fragment</div>') ];
    }

    /**
     * @param \Closure(Request): void $prepare
     * @param \Closure(): Response    $createResponse
     */
    #[DataProvider('notApplicableProvider')]
    public function testLeavesResponseAlone(\Closure $prepare, \Closure $createResponse): void
    {
        $request = $this->request();
        $prepare($request);
        $response = $createResponse();
        $before = $response->getContent();
        $cacheControl = $response->headers->get('Cache-Control');

        $this->dispatch($request, $response);

        static::assertSame($before, $response->getContent());
        static::assertSame($cacheControl, $response->headers->get('Cache-Control'));
    }

    public function testIgnoresSubRequests(): void
    {
        $response = new Response(self::PAGE);
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $this->request(), HttpKernelInterface::SUB_REQUEST, $response);

        (new MaintenanceBadgeSubscriber())->onResponse($event);

        static::assertSame(self::PAGE, $response->getContent());
    }

    private function request(): Request
    {
        $request = Request::create('https://shop.example.net/');
        $request->attributes->set('sw-maintenance', true);
        $request->attributes->set('_routeScope', [ 'storefront' ]);
        $request->attributes->set('_route', 'frontend.home.page');

        return $request;
    }

    private function dispatch(Request $request, Response $response, MaintenanceBadgeSubscriber $subscriber = new MaintenanceBadgeSubscriber()): Response
    {
        $subscriber->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
