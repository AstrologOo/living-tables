<?php

namespace App\Providers;

use App\Modules\Execution\Contracts\LlmCacheRepository;
use App\Modules\Execution\Contracts\ParentDataProvider;
use App\Modules\Execution\Contracts\RowStore;
use App\Modules\Execution\Contracts\UsageLimiter;
use App\Modules\Execution\ExecutionContext;
use App\Modules\Graph\Providers\GraphLlmCacheRepository;
use App\Modules\Graph\Providers\GraphParentDataProvider;
use App\Modules\Graph\Providers\GraphRowStore;
use App\Modules\Graph\Providers\GraphUsageLimiter;
use App\Modules\Intake\PreviewService;
use App\Modules\Shared\Adapters\Broadcast\ReverbRealtimeNotifier;
use App\Modules\Shared\Adapters\Llm\GeminiAdapter;
use App\Modules\Shared\Adapters\NullBlobStorage;
use App\Modules\Shared\Adapters\NullDocumentParser;
use App\Modules\Shared\Adapters\NullLlmProvider;
use App\Modules\Shared\Contracts\BlobStorage;
use App\Modules\Shared\Contracts\DocumentParser;
use App\Modules\Shared\Contracts\LlmProvider;
use App\Modules\Shared\Contracts\RealtimeNotifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Without an API key, the stub prevents real calls.
        $this->app->bind(
            LlmProvider::class,
            config('llm.gemini.api_key') ? GeminiAdapter::class : NullLlmProvider::class,
        );
        $this->app->bind(DocumentParser::class, NullDocumentParser::class);
        $this->app->bind(BlobStorage::class, NullBlobStorage::class);
        $this->app->bind(ParentDataProvider::class, GraphParentDataProvider::class);
        $this->app->bind(RowStore::class, GraphRowStore::class);
        $this->app->bind(LlmCacheRepository::class, GraphLlmCacheRepository::class);
        $this->app->bind(UsageLimiter::class, GraphUsageLimiter::class);
        $this->app->bind(RealtimeNotifier::class, ReverbRealtimeNotifier::class);

        // A user waits for a preview: one page of a paginated fetch and no batch endpoint.
        $this->app->bind(PreviewService::class, fn (Application $app) => new PreviewService(new ExecutionContext(
            $app->make(ParentDataProvider::class),
            $app->make(LlmProvider::class),
            $app->make(DocumentParser::class),
            $app->make(LlmCacheRepository::class),
            $app->make(UsageLimiter::class),
            $app->make(RowStore::class),
            isBackground: false,
        )));
    }

    public function boot(): void
    {
        // Per email and IP to slow password guessing without locking out the owner; per IP against trying many accounts.
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $limits = [Limit::perMinute(20)->by('ip:'.$request->ip())];

            if (is_string($email)) {
                $limits[] = Limit::perMinute(5)->by('pair:'.Str::lower(trim($email)).'|'.$request->ip());
            }

            return $limits;
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
