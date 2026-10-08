<?php

namespace Sammyjo20\LaravelHaystack\Builders;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Sammyjo20\LaravelHaystack\Models\Haystack;
use Sammyjo20\LaravelHaystack\Helpers\ClosureHelper;
use Sammyjo20\LaravelHaystack\Contracts\StackableJob;
use Sammyjo20\LaravelHaystack\Data\PendingHaystackBale;
use Sammyjo20\LaravelHaystack\Actions\CreatePendingHaystackBale;

class HaystackBuilder
{
    /**
     * Closure to run when the Haystack is finished.
     */
    protected ?Closure $onThen = null;

    /**
     * Closure to run when the Haystack has failed.
     */
    protected ?Closure $onCatch = null;

    /**
     * Closure to run when the Haystack has finished.
     */
    protected ?Closure $onFinally = null;

    /**
     * Closure to run when the Haystack has been paused.
     */
    protected ?Closure $onPaused = null;

    /**
     * The jobs to be added to the Haystack.
     */
    protected Collection $jobs;

    /**
     * Global delay in seconds.
     */
    protected int $globalDelayInSeconds = 0;

    /**
     * Global queue.
     */
    protected ?string $globalQueue = null;

    /**
     * Global connection.
     */
    protected ?string $globalConnection = null;

    /**
     * Global middleware.
     */
    protected ?Closure $globalMiddleware = null;

    /**
     * Should we return the data when the haystack finishes?
     */
    protected bool $returnDataOnFinish = true;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->jobs = new Collection;
    }

    /**
     * Provide a closure that will run when the haystack is complete.
     *
     * @return $this
     */
    public function then(Closure|callable $closure): static
    {
        $this->onThen = ClosureHelper::fromCallable($closure);

        return $this;
    }

    /**
     * Provide a closure that will run when the haystack fails.
     *
     * @return $this
     */
    public function catch(Closure|callable $closure): static
    {
        $this->onCatch = ClosureHelper::fromCallable($closure);

        return $this;
    }

    /**
     * Provide a closure that will run when the haystack finishes.
     *
     * @return $this
     */
    public function finally(Closure|callable $closure): static
    {
        $this->onFinally = ClosureHelper::fromCallable($closure);

        return $this;
    }

    /**
     * Provide a closure that will run when the haystack is paused.
     *
     * @return $this
     */
    public function paused(Closure|callable $closure): static
    {
        $this->onPaused = ClosureHelper::fromCallable($closure);

        return $this;
    }

    /**
     * Add a job to the haystack.
     *
     * @return $this
     */
    public function addJob(StackableJob $job, int $delayInSeconds = 0, ?string $queue = null, ?string $connection = null): static
    {
        $pendingHaystackRow = CreatePendingHaystackBale::execute($job, $delayInSeconds, $queue, $connection);

        $this->jobs->add($pendingHaystackRow);

        return $this;
    }

    /**
     * Add a bale onto the haystack. Yee-haw!
     *
     * @alias addJob()
     *
     * @return $this
     */
    public function addBale(StackableJob $job, int $delayInSeconds = 0, ?string $queue = null, ?string $connection = null): static
    {
        return $this->addJob($job, $delayInSeconds, $queue, $connection);
    }

    /**
     * Set a global delay on the jobs.
     *
     * @return $this
     */
    public function withDelay(int $seconds): static
    {
        $this->globalDelayInSeconds = $seconds;

        return $this;
    }

    /**
     * Set a global queue for the jobs.
     *
     * @return $this
     */
    public function onQueue(string $queue): static
    {
        $this->globalQueue = $queue;

        return $this;
    }

    /**
     * Set a global connection for the jobs.
     *
     * @return $this
     */
    public function onConnection(string $connection): static
    {
        $this->globalConnection = $connection;

        return $this;
    }

    /**
     * Set a global middleware closure to run.
     *
     * @return $this
     */
    public function withMiddleware(Closure|callable|array $value): static
    {
        if (is_array($value)) {
            $value = static fn () => $value;
        }

        $this->globalMiddleware = ClosureHelper::fromCallable($value);

        return $this;
    }

    /**
     * Create the Haystack
     */
    public function create(): Haystack
    {
        return DB::transaction(fn () => $this->createHaystack());
    }

    /**
     * Dispatch the Haystack.
     */
    public function dispatch(): Haystack
    {
        $haystack = $this->create();

        $haystack->start();

        return $haystack;
    }

    /**
     * Map the jobs to be ready for inserting.
     */
    protected function prepareJobsForInsert(Haystack $haystack): array
    {
        return $this->jobs->map(function (PendingHaystackBale $pendingJob) use ($haystack) {
            $hasDelay = isset($pendingJob->delayInSeconds) && $pendingJob->delayInSeconds > 0;

            return [
                'haystack_id' => $haystack->getKey(),
                'job' => serialize($pendingJob->job),
                'delay' => $hasDelay ? $pendingJob->delayInSeconds : $this->globalDelayInSeconds,
                'on_queue' => $pendingJob->queue ?? $this->globalQueue,
                'on_connection' => $pendingJob->connection ?? $this->globalConnection,
            ];
        })->toArray();
    }

    /**
     * Create the haystack.
     */
    protected function createHaystack(): Haystack
    {
        $haystack = new Haystack;
        $haystack->on_then = $this->onThen;
        $haystack->on_catch = $this->onCatch;
        $haystack->on_finally = $this->onFinally;
        $haystack->on_paused = $this->onPaused;
        $haystack->middleware = $this->globalMiddleware;
        $haystack->return_data = $this->returnDataOnFinish;
        $haystack->save();

        $haystack->bales()->insert($this->prepareJobsForInsert($haystack));

        return $haystack;
    }

    /**
     * Specify if you do not want haystack to return the data.
     *
     * @return $this
     */
    public function dontReturnData(): static
    {
        $this->returnDataOnFinish = false;

        return $this;
    }

    /**
     * Get all the jobs in the builder.
     */
    public function getJobs(): Collection
    {
        return $this->jobs;
    }

    /**
     * Get the closure for the "onThen".
     */
    public function getOnThen(): ?Closure
    {
        return $this->onThen;
    }

    /**
     * Get the closure for the "onCatch".
     */
    public function getOnCatch(): ?Closure
    {
        return $this->onCatch;
    }

    /**
     * Get the closure for the "onFinally".
     */
    public function getOnFinally(): ?Closure
    {
        return $this->onFinally;
    }

    /**
     * Get the time for the "withDelay".
     */
    public function getGlobalDelayInSeconds(): int
    {
        return $this->globalDelayInSeconds;
    }

    /**
     * Get the global queue
     */
    public function getGlobalQueue(): ?string
    {
        return $this->globalQueue;
    }

    /**
     * Get the global connection.
     */
    public function getGlobalConnection(): ?string
    {
        return $this->globalConnection;
    }

    /**
     * Get the closure for the global middleware.
     */
    public function getGlobalMiddleware(): ?Closure
    {
        return $this->globalMiddleware;
    }
}
