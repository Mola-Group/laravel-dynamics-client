<?php

declare(strict_types=1);

namespace JustBetter\DynamicsClient\Tests\Client;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use JustBetter\DynamicsClient\Client\Dynamics;
use JustBetter\DynamicsClient\Commands\ConnectionCommand;
use JustBetter\DynamicsClient\Exceptions\DynamicsException;
use JustBetter\DynamicsClient\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DataverseTest extends TestCase
{
    private const string Origin = 'https://sales.example.test';

    private const string Api = self::Origin.'/api/data/v9.2';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dynamics.connections.dataverse.parameters', [
            'organization_url' => self::Origin,
            'tenant_id' => 'sales-tenant',
        ]);
        config()->set('dynamics.connections.dataverse.client_id', 'sales-client');
        config()->set('dynamics.connections.dataverse.client_secret', 'sales-secret');

        Http::fake([
            'https://login.microsoftonline.com/sales-tenant/oauth2/v2.0/token' => Http::response([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'sales-token',
            ]),
        ])->preventStrayRequests();
    }

    #[Test]
    public function it_checks_the_sales_connection_with_who_am_i(): void
    {
        Http::fake([
            self::Api.'/WhoAmI' => Http::response(['UserId' => 'application-user']),
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan(ConnectionCommand::class, ['connection' => 'dataverse']);
        $command->expectsOutput('Successfully connected to Dataverse.')->assertSuccessful()->run();

        Http::assertSent(fn (Request $request): bool => $request->isForm()
            && $request['scope'] === self::Origin.'/.default'
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::Api.'/WhoAmI'
            && $request->hasHeader('Authorization', 'Bearer sales-token')
            && $request->hasHeader('OData-MaxVersion', '4.0'));
    }

    #[Test]
    public function it_follows_next_links_without_rebuilding_their_queries(): void
    {
        $next = self::Api.'/leads?$skiptoken=%3Ccookie%2520%2B%3E';
        Http::fake([
            self::Api.'/leads?%24select=leadid' => Http::response([
                'value' => [['leadid' => 'first']],
                '@odata.nextLink' => $next,
            ]),
            $next => Http::response(['value' => [['leadid' => 'second']]]),
        ]);

        $rows = app(Dynamics::class)->connection('dataverse')->lazy('leads', ['$select' => 'leadid'], 100);

        $this->assertSame(['first', 'second'], $rows->pluck('leadid')->all());
        Http::assertSent(fn (Request $request): bool => $request->url() === $next
            && $request->hasHeader('Prefer', 'odata.maxpagesize=100'));
        Http::assertSentCount(3);
    }

    #[Test]
    public function it_rejects_urls_outside_the_api_origin(): void
    {
        $client = app(Dynamics::class)->connection('dataverse');

        foreach (['https://different.example.test/leads', '/https://different.example.test/leads',
            'https://sales.example.test:8443/leads', 'http://sales.example.test/leads'] as $url) {
            $this->assertThrows(fn () => $client->get($url), DynamicsException::class);
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_reports_errors_when_loading_entities(): void
    {
        Http::fake([self::Api.'/leads' => Http::response(['error' => ['message' => 'Forbidden']], 403)]);

        $this->expectException(RequestException::class);

        app(Dynamics::class)->connection('dataverse')->entities('leads');
    }

    #[Test]
    public function it_preserves_a_create_only_condition_on_a_lead_upsert(): void
    {
        Http::fake([self::Api.'/leads(*)' => Http::response(status: 204)]);

        app(Dynamics::class)->connection('dataverse')
            ->header('If-None-Match', '*')
            ->patch("leads(storekey='store1-123')", ['subject' => 'Store registration'])
            ->throw();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->hasHeader('If-None-Match', '*')
            && ! $request->hasHeader('If-Match'));
    }
}
