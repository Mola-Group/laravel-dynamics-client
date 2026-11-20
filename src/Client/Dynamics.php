<?php

declare(strict_types=1);

namespace JustBetter\DynamicsClient\Client;

use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use JustBetter\DynamicsClient\Contracts\Availability\ChecksAvailability;
use JustBetter\DynamicsClient\Contracts\BuildsRequest;
use JustBetter\DynamicsClient\Data\Connection;
use JustBetter\DynamicsClient\Data\Entity;
use JustBetter\DynamicsClient\Events\DynamicsResponseEvent;
use JustBetter\DynamicsClient\Events\DynamicsTimeoutEvent;
use JustBetter\DynamicsClient\Exceptions\DynamicsException;
use JustBetter\DynamicsClient\Exceptions\UnavailableException;

class Dynamics
{
    protected string $connection;

    /** @var array<string, string> */
    protected array $parameters = [];

    /** @var array<string, string> */
    protected array $headers = [];

    protected ?Entity $entity = null;

    protected ?string $entityEndpoint = null;

    public function __construct(
        protected BuildsRequest $request,
        protected ChecksAvailability $availability,
    ) {
        $this->connection = config()->string('dynamics.connection');
    }

    public function connection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    public function set(string $key, string $value): static
    {
        $this->parameters[$key] = $value;

        return $this;
    }

    /** @param array<string, string> $parameters */
    public function parameters(array $parameters): static
    {
        $this->parameters = array_merge($this->parameters, $parameters);

        return $this;
    }

    public function tenantId(string $tenantId): static
    {
        return $this->set('tenant_id', $tenantId);
    }

    public function environment(string $environment): static
    {
        return $this->set('environment', $environment);
    }

    public function api(string $api): static
    {
        return $this->set('api', $api);
    }

    public function companyId(string $companyId): static
    {
        return $this->set('company_id', $companyId);
    }

    public function company(string $name): static
    {
        return $this->companyId($this->getConfig()->company($name));
    }

    /** @return array<string, ?string> */
    public function getParameters(): array
    {
        return collect($this->getConfig()->parameters)
            ->merge($this->parameters)
            ->all();
    }

    public function parameter(string $key): ?string
    {
        $value = $this->getParameters()[$key] ?? null;

        return $value !== null && $value !== '' ? $value : null;
    }

    public function header(string $key, string $value): static
    {
        $this->headers[$key] = $value;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function headers(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function clearHeaders(): static
    {
        $this->headers = [];

        return $this;
    }

    public function entity(Entity $entity, ?string $endpoint = null): static
    {
        $this->entity = $entity;
        $this->entityEndpoint = $endpoint;

        return $this;
    }

    public function getEntity(): ?Entity
    {
        return $this->entity;
    }

    public function clearEntity(): static
    {
        $this->entity = null;
        $this->entityEndpoint = null;

        return $this;
    }

    public function reset(): static
    {
        $this->parameters = [];

        return $this->clearRequestState();
    }

    protected function clearRequestState(): static
    {
        return $this->clearHeaders()->clearEntity();
    }

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->send(function (PendingRequest $request) use ($path, $query): Response {
            $url = $this->getUrl($path);

            return $query === [] ? $request->get($url) : $request->get($url, $query);
        });
    }

    /** @param  array<string, mixed>  $data */
    public function post(string $path, array $data = []): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post($this->getUrl($path), $data));
    }

    /** @param  array<string, mixed>  $data */
    public function patch(string $path, array $data = []): Response
    {
        return $this->configureEntityTag()
            ->send(fn (PendingRequest $request): Response => $request->patch($this->getUrl($path), $data));
    }

    /** @param  array<string, mixed>  $data */
    public function put(string $path, array $data = []): Response
    {
        return $this->configureEntityTag()
            ->send(fn (PendingRequest $request): Response => $request->put($this->getUrl($path), $data));
    }

    /** @param  array<string, mixed>  $data */
    public function delete(?string $path = null, array $data = []): Response
    {
        $url = $path !== null ? $this->getUrl($path) : $this->scopedUrl();

        return $this->configureEntityTag()
            ->send(fn (PendingRequest $request): Response => $request->delete($url, $data));
    }

    /** @param  array<string, mixed>  $data */
    public function procedure(string $path, array $data = []): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request
            ->when($data === [], fn (PendingRequest $request): PendingRequest => $request->withBody('{}'))
            ->post($this->getUrl($path), $data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(array $data = []): Response
    {
        $url = $this->scopedUrl();

        if ($data === []) {
            /** @var Entity $entity */
            $entity = $this->entity;

            $data = $entity->dirty();

            throw_if($data === [], DynamicsException::class, 'The scoped entity has no changed attributes to update.');
        }

        return $this->patch($url, $data);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, Entity>
     */
    public function entities(string $endpoint, array $query = []): Collection
    {
        return Entity::collection($this->get($endpoint, $query)->throw(), ['endpoint' => $endpoint]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, Entity>
     */
    public function lazyEntities(string $endpoint, array $query = [], ?int $pageSize = null): LazyCollection
    {
        return $this->lazy($endpoint, $query, $pageSize)
            ->map(fn (array $attributes): Entity => Entity::from($attributes, ['endpoint' => $endpoint]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function lazy(string $path, array $query = [], ?int $pageSize = null): LazyCollection
    {
        $config = $this->getConfig();
        $pageSize ??= $config->page_size;

        throw_if($pageSize < 1, DynamicsException::class, 'Page size must be at least 1.');

        if ($config->isDataverse()) {
            throw_if($pageSize > 5000, DynamicsException::class, 'Dataverse page size cannot exceed 5000.');

            return LazyCollection::make(fn (): Generator => $this->lazyDataverse($path, $query, $pageSize));
        }

        return LazyCollection::make(function () use ($path, $query, $pageSize): Generator {
            $skip = 0;

            do {
                $query['$top'] = $pageSize;
                $query['$skip'] = $skip;

                /** @var Collection<int, array<string, mixed>> $records */
                $records = $this->get($path, $query)->throw()->collect('value');

                foreach ($records as $record) {
                    yield $record;
                }

                $skip += $pageSize;
            } while ($records->count() >= $pageSize);
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Generator<int, array<string, mixed>>
     */
    protected function lazyDataverse(string $path, array $query, int $pageSize): Generator
    {
        unset($query['$top'], $query['$skip']);

        $prefer = 'odata.maxpagesize='.$pageSize;
        $additionalPreferences = $this->getHeaders()['Prefer'] ?? null;
        if (filled($additionalPreferences)) {
            $prefer .= ', '.$additionalPreferences;
        }

        do {
            $response = $this->header('Prefer', $prefer)->get($path, $query)->throw();

            /** @var Collection<int, array<string, mixed>> $records */
            $records = $response->collect('value');
            foreach ($records as $record) {
                yield $record;
            }

            $nextLink = $response->collect()->get('@odata.nextLink');
            throw_if($nextLink !== null && ! is_string($nextLink), DynamicsException::class, 'Dataverse returned an invalid next link.');
            $path = $nextLink ?? '';
            $query = [];
        } while ($path !== '');
    }

    protected function scopedUrl(): string
    {
        throw_if(! $this->entity instanceof Entity, DynamicsException::class, 'No entity is scoped, call entity() first or pass a path.');

        return $this->getUrl($this->entity->url($this->entityEndpoint));
    }

    protected function configureEntityTag(): static
    {
        $headers = array_change_key_case($this->headers);

        if (isset($headers['if-match']) || (isset($headers['if-none-match']) && $this->getConfig()->isDataverse())) {
            return $this;
        }

        return $this->header(
            'If-Match',
            $this->entity?->etag() ?? '*'
        );
    }

    public function getUrl(string $path): string
    {
        throw_if(
            str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1,
            DynamicsException::class,
            'Invalid Dynamics request URL.'
        );

        $path = ltrim($path, '/');

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1) {
            $origin = $this->urlOrigin($path);
            throw_if($origin === null || $origin !== $this->urlOrigin($this->baseUrl()),
                DynamicsException::class, 'Dynamics request URL must use the configured API origin.');
        }

        if ($path !== '') {
            return $path;
        }

        // An absolute base URL is passed through as-is, since Laravel only treats it as
        // relative to the client's base URL - and thus prepends it again - when it isn't.
        $baseUrl = $this->baseUrl();

        return str_contains($baseUrl, '://') ? $baseUrl : '';
    }

    /** @return array{string, string, int}|null */
    protected function urlOrigin(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = $parts['scheme'];
        if (! in_array($scheme, ['https', 'http'], true)) {
            return null;
        }

        return [$scheme, strtolower($parts['host']), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    public function available(): bool
    {
        return $this->availability->check($this->connection);
    }

    protected function guardAvailability(): void
    {
        $throw = config()->boolean('dynamics.connections.'.$this->connection.'.availability.throw', false);

        if (! $throw || $this->available()) {
            return;
        }

        throw new UnavailableException('Connection "'.$this->connection.'" is currently unavailable.');
    }

    /** @param  Closure(PendingRequest): Response  $callback */
    protected function send(Closure $callback): Response
    {
        try {
            $this->guardAvailability();

            $response = $callback($this->request());
        } catch (ConnectionException $connectionException) {
            DynamicsTimeoutEvent::dispatch($this->getConnection());

            throw $connectionException;
        } finally {
            $this->clearRequestState();
        }

        return $this->handleResponse($response);
    }

    protected function request(): PendingRequest
    {
        return $this->request->build($this);
    }

    protected function handleResponse(Response $response): Response
    {
        DynamicsResponseEvent::dispatch($response, $this->getConnection());

        return $response;
    }

    public function baseUrl(): string
    {
        return $this->buildUrl($this->getConfig()->base_url);
    }

    public function tokenUrl(): string
    {
        return $this->buildUrl($this->getConfig()->token_url);
    }

    public function scope(): string
    {
        return $this->buildUrl($this->getConfig()->scope);
    }

    protected function buildUrl(string $template): string
    {
        $parameters = $this->getParameters();

        /** @var string $resolved */
        $resolved = preg_replace_callback(
            '/\{(\w+)\}/',
            /** @param array<int, string> $matches */
            function (array $matches) use ($parameters): string {
                $key = $matches[1];
                $value = $parameters[$key] ?? null;

                throw_if(blank($value), DynamicsException::class, 'Parameter "'.$key.'" has no value to resolve placeholder "{'.$key.'}".');

                return $value;
            },
            $template
        );

        return $resolved;
    }

    protected function getConfig(): Connection
    {
        return static::config($this->connection);
    }

    public static function config(string $connection): Connection
    {
        $config = config()->get('dynamics.connections.'.$connection);

        throw_if(! is_array($config), DynamicsException::class, 'Connection "'.$connection.'" not found in the configuration file.');

        return Connection::make($config)->validate();
    }

    public static function fake(): void
    {
        config()->set('dynamics.connection', 'default');
        config()->set('dynamics.connections.default', [
            'base_url' => 'dynamics/',
            'parameters' => [
                'tenant_id' => '::tenant-id::',
                'environment' => '::environment::',
                'api' => 'v2.0',
                'company_id' => '::company-id::',
            ],
            'companies' => [
                'default' => '::company-id::',
            ],
            'client_id' => '::client-id::',
            'client_secret' => '::client-secret::',
            'token_url' => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token',
            'scope' => 'https://api.businesscentral.dynamics.com/.default',
            'grant_type' => 'client_credentials',
            'page_size' => 1000,
            'timeout' => 30,
            'connect_timeout' => 10,
            'availability' => [
                'codes' => [502, 503, 504],
                'threshold' => 10,
                'timespan' => 10,
                'cooldown' => 2,
                'throw' => false,
            ],
        ]);

        Http::fake([
            'https://login.microsoftonline.com/::tenant-id::/oauth2/v2.0/token' => Http::response([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => '::access-token::',
            ]),
        ])->preventStrayRequests();
    }
}
