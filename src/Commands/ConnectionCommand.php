<?php

declare(strict_types=1);

namespace JustBetter\DynamicsClient\Commands;

use Illuminate\Console\Command;
use JustBetter\DynamicsClient\Client\Dynamics;
use JustBetter\DynamicsClient\Exceptions\DynamicsException;
use Throwable;

class ConnectionCommand extends Command
{
    protected $signature = 'dynamics:connect {connection?}';

    protected $description = 'Test the connection to Dynamics';

    public function handle(Dynamics $dynamics): int
    {
        /** @var ?string $connection */
        $connection = $this->argument('connection');

        $connection ??= config()->string('dynamics.connection');

        try {
            $isDataverse = Dynamics::config($connection)->isDataverse();
            $response = $dynamics->connection($connection)->get($isDataverse ? 'WhoAmI' : '')->throw();

            if ($isDataverse) {
                throw_unless(filled($response->json('UserId')), DynamicsException::class, 'Dataverse did not return a user ID.');
                $this->info('Successfully connected to Dataverse.');

                return static::SUCCESS;
            }
        } catch (Throwable $throwable) {
            $this->error('Could not connect to "'.$connection.'": '.$throwable->getMessage());

            return static::FAILURE;
        }

        /** @var ?string $name */
        $name = $response->json('name');

        $this->info('Successfully connected to company "'.$name.'"');

        return static::SUCCESS;
    }
}
