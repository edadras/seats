<?php

namespace App\Providers;

use App\Domain\SeatMaps\SeatMapValidator;
use App\Models\PersonalAccessToken;
use App\Support\Signing\PriceSigner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant binding per request/job. Everything scoped resolves through this instance.
        $this->app->singleton(TenantContext::class);

        /*
         * Both registries cache what the enabled modules contribute, keyed by tenant, and both
         * rebuild when the tenant changes. That only works if everybody shares one — two copies
         * would each build their own set, and a module enabled halfway through a request would be
         * visible to one and not the other.
         */
        $this->app->singleton(\App\Domain\Sites\Payments\GatewayRegistry::class);
        $this->app->singleton(\App\Domain\Messaging\ChannelRegistry::class);

        $this->app->singleton(PriceSigner::class, function () {
            $key = (string) config('seatmap.signing_key');

            if ($key === '') {
                // Failing at boot beats signing every price snapshot with an empty key and only
                // finding out when someone forges one.
                throw new \RuntimeException(
                    'SEATMAP_SIGNING_KEY is not set. Generate one with: php artisan seatmap:generate-signing-key'
                );
            }

            return new PriceSigner($key);
        });

        $this->app->singleton(SeatMapValidator::class, fn () => SeatMapValidator::make());
    }

    public function boot(): void
    {
        // Resolves token holders without tenant scoping — see PersonalAccessToken for why device
        // authentication cannot work otherwise.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Catching an accidental lazy load in development is cheaper than discovering an N+1 in
        // an availability query over 20,000 seats in production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
    }
}
