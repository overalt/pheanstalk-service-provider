<?php

namespace Sergiors\Silex\Provider;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Pheanstalk\PheanstalkInterface;
use Pheanstalk\Pheanstalk;
use Leezy\PheanstalkBundle\PheanstalkLocator;
use Leezy\PheanstalkBundle\Proxy\PheanstalkProxy;
use Leezy\PheanstalkBundle\Listener\PheanstalkLogListener;
use Leezy\PheanstalkBundle\Command\DeleteJobCommand;
use Leezy\PheanstalkBundle\Command\FlushTubeCommand;
use Leezy\PheanstalkBundle\Command\KickCommand;
use Leezy\PheanstalkBundle\Command\KickJobCommand;
use Leezy\PheanstalkBundle\Command\ListTubeCommand;
use Leezy\PheanstalkBundle\Command\NextReadyCommand;
use Leezy\PheanstalkBundle\Command\PauseTubeCommand;
use Leezy\PheanstalkBundle\Command\PeekCommand;
use Leezy\PheanstalkBundle\Command\PeekTubeCommand;
use Leezy\PheanstalkBundle\Command\PutCommand;
use Leezy\PheanstalkBundle\Command\StatsCommand;
use Leezy\PheanstalkBundle\Command\StatsJobCommand;
use Leezy\PheanstalkBundle\Command\StatsTubeCommand;

/**
 * @author Sérgio Rafael Siqueira <sergio@gmail.com>
 */
class PheanstalkServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['pheanstalks.options.initializer'] = $app->protect(function () use ($app) {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($app['pheanstalks.options'])) {
                $app['pheanstalks.options'] = [
                    'default' => isset($app['pheanstalk.options']) ? $app['pheanstalk.options'] : [],
                ];
            }

            $tmp = $app['pheanstalks.options'];
            array_walk($tmp, function (&$options, $name) use ($app) {
                $options = array_replace($app['pheanstalk.default_options'], $options);

                if (isset($app['pheanstalks.default'])) {
                    return;
                }

                $app['pheanstalks.default'] = $name;
            });

            $app['pheanstalks.options'] = $tmp;
        });

        $app['pheanstalk.listener.log'] = $app->share(function (Application $app) {
            $listener = new PheanstalkLogListener();
            $listener->setLogger($app['logger']);

            return $listener;
        });

        $app['pheanstalk.proxy.factory'] = $app->protect(
            function ($name, PheanstalkInterface $pheanstalk) use ($app) {
                $proxy = new PheanstalkProxy();
                $proxy->setName($name);
                $proxy->setPheanstalk($pheanstalk);
                $proxy->setDispatcher($app['dispatcher']);

                return $proxy;
            }
        );

        $app['pheanstalks'] = $app->share(function (Application $app) {
            $app['pheanstalks.options.initializer']();

            $locator = new PheanstalkLocator();
            $options = $app['pheanstalks.options'];

            array_walk($options, function (array $options, $name) use ($app, $locator) {
                $pheanstalk = new Pheanstalk(
                    $options['server'],
                    $options['port'],
                    $options['timeout']
                );

                $locator->addPheanstalk(
                    $name,
                    $app['pheanstalk.proxy.factory']($name, $pheanstalk),
                    $app['pheanstalks.default'] === $name
                );
            });

            return $locator;
        });

        $app['pheanstalk.commands'] = $app->protect(function () use ($app) {
            $locator = $app['pheanstalks'];

            return [
                new DeleteJobCommand($locator),
                new FlushTubeCommand($locator),
                new KickCommand($locator),
                new KickJobCommand($locator),
                new ListTubeCommand($locator),
                new NextReadyCommand($locator),
                new PauseTubeCommand($locator),
                new PeekCommand($locator),
                new PeekTubeCommand($locator),
                new PutCommand($locator),
                new StatsCommand($locator),
                new StatsJobCommand($locator),
                new StatsTubeCommand($locator)
            ];
        });

        // shortcuts for the "first" pheanstalk
        $app['pheanstalk'] = $app->share(function (Application $app) {
            $pheanstalks = $app['pheanstalks'];

            return $pheanstalks->getPheanstalk($app['pheanstalks.default']);
        });

        $app['pheanstalk.default_options'] = [
            'server' => '127.0.0.1',
            'port' => PheanstalkInterface::DEFAULT_PORT,
            'timeout' => PheanstalkInterface::DEFAULT_TTR,
        ];

        if (isset($app['console'])) {
            $app['console'] = $app->share(
                $app->extend('console', function ($console) use ($app) {
                    $commands = $app['pheanstalk.commands']();

                    return array_reduce($commands, function (ConsoleApplication $console, $command) {
                        $console->add($command);
                        return $console;
                    }, $console);
                })
            );
        }
    }

    public function boot(Application $app)
    {
        $app['dispatcher']->addSubscriber($app['pheanstalk.listener.log']);
    }
}
