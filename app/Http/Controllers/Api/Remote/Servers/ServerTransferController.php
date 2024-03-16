<?php

namespace App\Http\Controllers\Api\Remote\Servers;

use App\Models\Server;
use App\Repositories\Daemon\DaemonRepository;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Models\Allocation;
use Illuminate\Support\Facades\Log;
use App\Models\ServerTransfer;
use Illuminate\Database\ConnectionInterface;
use App\Http\Controllers\Controller;
use App\Repositories\Eloquent\ServerRepository;
use Lcobucci\JWT\Token\Plain;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use App\Exceptions\Http\Connection\DaemonConnectionException;

class ServerTransferController extends Controller
{
    /**
     * ServerTransferController constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private ServerRepository $repository,
        private DaemonRepository $daemonRepository,
    ) {
    }

    private function notify(DaemonRepository $repository, Server $server, Plain $token): void
    {
        try {
            $repository->getHttpClient()->post('/api/transfer', [
                'json' => [
                    'server_id' => $server->uuid,
                    'url' => $server->node->getConnectionAddress() . "/api/servers/$server->uuid/archive",
                    'token' => 'Bearer ' . $token->toString(),
                    'server' => [
                        'uuid' => $server->uuid,
                        'start_on_completion' => false,
                    ],
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new DaemonConnectionException($exception);
        }
    }

    /**
     * The daemon notifies us about a transfer failure.
     *
     * @throws \Throwable
     */
    public function failure(string $uuid): JsonResponse
    {
        $server = $this->repository->getByUuid($uuid);
        $transfer = $server->transfer;
        if (is_null($transfer)) {
            throw new ConflictHttpException('Server is not being transferred.');
        }

        return $this->processFailedTransfer($transfer);
    }

    /**
     * The daemon notifies us about a transfer success.
     *
     * @throws \Throwable
     */
    public function success(string $uuid): JsonResponse
    {
        $server = $this->repository->getByUuid($uuid);
        $transfer = $server->transfer;
        if (is_null($transfer)) {
            throw new ConflictHttpException('Server is not being transferred.');
        }

        /** @var \App\Models\Server $server */
        $server = $this->connection->transaction(function () use ($server, $transfer) {
            $allocations = array_merge([$transfer->old_allocation], $transfer->old_additional_allocations);

            // Remove the old allocations for the server and re-assign the server to the new
            // primary allocation and node.
            Allocation::query()->whereIn('id', $allocations)->update(['server_id' => null]);
            $server->update([
                'allocation_id' => $transfer->new_allocation,
                'node_id' => $transfer->new_node,
            ]);

            $server = $server->fresh();
            $server->transfer->update(['successful' => true]);

            return $server;
        });

        // Delete the server from the old node making sure to point it to the old node so
        // that we do not delete it from the new node the server was transferred to.
        try {
            $this->daemonServerRepository
                ->setServer($server)
                ->setNode($transfer->oldNode)
                ->delete();
        } catch (DaemonConnectionException $exception) {
            Log::warning($exception, ['transfer_id' => $server->transfer->id]);
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Release all the reserved allocations for this transfer and mark it as failed in
     * the database.
     *
     * @throws \Throwable
     */
    protected function processFailedTransfer(ServerTransfer $transfer): JsonResponse
    {
        $this->connection->transaction(function () use (&$transfer) {
            $transfer->forceFill(['successful' => false])->saveOrFail();

            $allocations = array_merge([$transfer->new_allocation], $transfer->new_additional_allocations);
            Allocation::query()->whereIn('id', $allocations)->update(['server_id' => null]);
        });

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }
}
