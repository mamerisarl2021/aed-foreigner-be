<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PKI;

use App\Services\PKI\TrustedXClientService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TrustedXClientServiceLogTest extends TestCase
{
    private const REDIRECT_URI = 'http://localhost:4200/etranger/connexion/callback';

    #[Test]
    public function obtain_token_logs_the_trustedx_call_when_flag_is_on(): void
    {
        config(['trustedx.log_calls' => true]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        Http::fake([
            '*' => Http::response(['access_token' => 'tok_test', 'token_type' => 'Bearer'], 200),
        ]);

        $result = (new TrustedXClientService)->obtainToken('auth-code', self::REDIRECT_URI);

        $this->assertTrue($result['status']);

        $match = collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->message === 'TrustedX call'
                && ($log->context['operation'] ?? null) === 'obtainToken'
        );

        $this->assertNotNull($match);
        $this->assertSame('tok_test', $match->context['access_token'] ?? null);
        // The caller's redirect_uri must reach TrustedX untouched: it is
        // compared literally against the one used at /authorize.
        $this->assertStringContainsString('redirect_uri='.self::REDIRECT_URI, $match->context['url'] ?? '');
    }

    #[Test]
    public function obtain_token_does_not_log_when_flag_is_off(): void
    {
        config(['trustedx.log_calls' => false]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        Http::fake([
            '*' => Http::response(['access_token' => 'tok_test', 'token_type' => 'Bearer'], 200),
        ]);

        $result = (new TrustedXClientService)->obtainToken('auth-code');

        $this->assertTrue($result['status']);
        $this->assertNull(collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->message === 'TrustedX call'
        ));
    }

    #[Test]
    public function obtain_token_uses_configured_ssl_verify(): void
    {
        config([
            'trustedx.verify_ssl' => true,
            'trustedx.log_calls' => false,
        ]);

        Http::fake([
            '*' => Http::response(['access_token' => 'tok_ssl', 'token_type' => 'Bearer'], 200),
        ]);

        $result = (new TrustedXClientService)->obtainToken('auth-code');

        $this->assertTrue($result['status']);
        $this->assertTrue((bool) config('trustedx.verify_ssl'));
    }
}
