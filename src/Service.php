<?php

namespace PragmaRX\Health;

use Mail;
use Cache;
use Exception;
use Illuminate\Support\Str;
use PragmaRX\Health\Events\RaiseHealthIssue;

class Service
{
    /**
     * @var
     */
    private $resources;

    /**
     * @var
     */
    private $notified = [];

    /**
     * @var
     */
    private $checked;

    /**
     * @var
     */
    private $currentAction;

    /**
     * Service constructor.
     */
    public function __construct()
    {
        $this->loadResources();
    }

    /**
     * @param $name
     * @param $health
     * @param $resource
     * @return bool
     */
    private function canNotify($name, $health, $resource)
    {
        return ! $health['healthy'] &&
               $resource['notify'] &&
               ! isset($this->notified[$name]) &&
               config('health.notifications.enabled') &&
               config('health.notifications.notify_on.'.$this->currentAction)
        ;
    }

    /**
     * Check all resources.
     *
     * @return array
     */
    public function checkResources()
    {
        if ($this->checked) {
            return false;
        }

        $resourceChecker = function($allowGlobal) {
            $this->resources = $this->getResources()->map(function($item, $key) use ($allowGlobal) {
                if ($item['is_global'] == $allowGlobal)
                {
                    $item['health'] = $this->checkResource($key);
                }

                return $item;
            });
        };

        $checker = function() use ($resourceChecker) {
            $resourceChecker(false);

            $resourceChecker(true);

            return $this->getResources();
        };

        if (($minutes = config('health.cache.minutes')) !== false) {
            $this->resources = Cache::remember('health-resources', $minutes, $checker);
        } else {
            $this->resources = $checker();
        }

        $this->checked = true;
    }

    /**
     * @return mixed
     */
    public function isHealthy()
    {
        $this->checkResources();

        return $this->getResources()->reduce(function($carry, $item) {
            return $carry && $item['health'];
        }, true);
    }

    /**
     * @return mixed
     */
    public function health()
    {
        $this->checkResources();

        return $this->getResources();
    }

    /**
     * @param $name
     * @return bool
     */
    private function checkResource($name)
    {
        $resource = $this->getResource($name);

        try {
            $resourceChecker = $this->getResourceCheckerInstance($name, $resource);

            $resourceChecker->check($resource, $this->getResources());
        } catch (Exception $exception) {
            $resourceChecker->makeResult(false, 'Unknown error.');
        }

        $health = $resourceChecker->healthArray();

        if ($this->canNotify($name, $health, $resource)) {
            $resource['health'] = $health;

            $this->notify($resource);

            $this->notified[$name] = true;
        }

        return $health;
    }

    /**
     * @param $resource
     * @return static
     */
    private function notify($resource)
    {
        return collect(config('health.notifications.channels'))->filter(function($value, $channel) use ($resource) {
            try {
                event(new RaiseHealthIssue($resource, $channel));
            } catch (\Exception $exception) {}
        });
    }

    /**
     * @param $name
     * @return mixed
     */
    public function resource($name)
    {
        $this->checkResources();

        return $this->getResources()[$name];
    }

    /**
     * @param $action
     */
    public function setAction($action)
    {
        $this->currentAction = $action;
    }

    /**
     * @return mixed
     */
    public function string()
    {
        $this->health();

        return collect($this->getResources())->reduce(function($current, $item) {
            return $current .
                ($current ? config('health.string.glue') : '') .
                $this->makeString($item['abbreviation'], $this->checkResource($item['slug']));
        });
    }

    /**
     * @param $name
     * @return mixed
     */
    private function getResource($name)
    {
        return $this->getResources()[$name];
    }

    /**
     * @return mixed
     */
    private function getResources()
    {
        return $this->resources;
    }

    /**
     *
     */
    private function loadResources()
    {
        $this->resources = collect(config('health.resources'))->map(function($item, $key) {
            $item['slug'] = $key;

            $item['name'] = Str::studly($key);

            $item['is_global'] = (isset($item['is_global']) && $item['is_global']);

            return $item;
        });

        if ($sortBy = config('health.sort_by')) {
            $this->resources = $this->resources->sortBy(function ($item, $key) use ($sortBy) {
                return $item['is_global'] ? 0 : $item[$sortBy];
            });
        }
    }

    /**
     * @param $name
     * @return \Illuminate\Foundation\Application|mixed
     */
    public function getResourceCheckerInstance($name, $resource)
    {
        return app(
            $this->getResource($name)['checker'],
            [$resource, $this->getResources()]
        );
    }

    /**
     * @param $string
     * @param $checkSystem
     * @return string
     */
    private function makeString($string, $checkSystem)
    {
        return $string .
                ($checkSystem
                    ? config('health.string.ok')
                    : config('health.string.fail')
                )
        ;
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function panel()
    {
        return $this->health();
    }

    /**
     * @param $name
     * @return mixed
     */
    public function find($name)
    {
        foreach ($this->getResources() as $resource)
        {
            if ($resource['slug'] == $name) {
                return $resource;
            }
        }
    }
}
