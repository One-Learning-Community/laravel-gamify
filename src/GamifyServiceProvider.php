<?php

namespace QCod\Gamify;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use QCod\Gamify\Listeners\SyncBadges;
use Illuminate\Support\ServiceProvider;
use QCod\Gamify\Console\MakeBadgeCommand;
use QCod\Gamify\Console\MakePointCommand;
use QCod\Gamify\Events\ReputationChanged;
use Symfony\Component\Finder\Finder;

class GamifyServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        // publish config
        $this->publishes([
            __DIR__ . '/config/gamify.php' => config_path('gamify.php'),
        ], 'config');

        $this->mergeConfigFrom(__DIR__ . '/config/gamify.php', 'gamify');

        // publish migration
        if (!class_exists('CreateGamifyTables')) {
            $timestamp = date('Y_m_d_His', time());
            $this->publishes([
                __DIR__ . '/migrations/create_gamify_tables.php.stub' => database_path("/migrations/{$timestamp}_create_gamify_tables.php"),
                __DIR__ . '/migrations/add_reputation_on_user_table.php.stub' => database_path("/migrations/{$timestamp}_add_reputation_field_on_user_table.php"),
            ], 'migrations');
        }

        // register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakePointCommand::class,
                MakeBadgeCommand::class,
            ]);
        }

        // register event listener
        Event::listen(ReputationChanged::class, SyncBadges::class);
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('badges', function () {
            return cache()->rememberForever('gamify.badges.all', function () {
                return $this->getBadges()->map(function ($badge) {
                    return resolve($badge);
                });
            });
        });
    }

    /**
     * Get all the badge inside app/Gamify/Badges folder
     *
     * @return Collection
     */
    protected function getBadges()
    {
        $badgeRootNamespace = config(
            'gamify.badge_namespace',
            $this->app->getNamespace() . 'Gamify\Badges'
        );

        $badges = [];

        $basePath = config('gamify.badge_class_path', app_path('/Gamify/Badges/'));
        $badgeClasses = Finder::create()
            ->in($basePath)
            ->name('*.php')
            ->files();

        foreach ($badgeClasses as $file) {
            $className = $badgeRootNamespace . str_replace('/', '\\', substr($file->getPath(), strlen($basePath))) . '\\' . $file->getBasename('.php');
            if (class_exists($className)) {
                $clazz = new \ReflectionClass($className);
                if (!$clazz->isAbstract()) {
                    $badges[] = $className;
                }
            }
        }

        $badges = collect($badges);

        if ($this->app->has('badges-resolver')) {
            $badges = $badges->concat($this->app->get('badges-resolver'));
        }

        return $badges;
    }
}
