<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\PuliPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Webmozart\Composer\PuliPlugin\RepositoryLoader\RepositoryLoader;
use Webmozart\Puli\Filesystem\PhpCacheRepository;
use Webmozart\Puli\ResourceRepository;

/**
 * A plugin for managing resources of Composer dependencies.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliPlugin implements PluginInterface, EventSubscriberInterface
{
    const VERSION = '@package_version@';

    const RELEASE_DATE = '@release_date@';

    private $firstRun = true;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'dumpResourceLocator',
            ScriptEvents::POST_UPDATE_CMD => 'dumpResourceLocator',
        );
    }

    /**
     * Apply plugin modifications to composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $composer->getEventDispatcher()->addSubscriber($this);
    }

    public function dumpResourceLocator(CommandEvent $event)
    {
        // This method is called twice. Run it only once.
        if (!$this->firstRun) {
            return;
        }

        $this->firstRun = false;

        $config = $event->getComposer()->getConfig();
        $installationManager = $event->getComposer()->getInstallationManager();
        $repositoryManager = $event->getComposer()->getRepositoryManager();
        $packages = $repositoryManager->getLocalRepository()->getPackages();

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $basePath = $filesystem->normalizePath(realpath(getcwd()));
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

        $event->getIO()->write('<info>Generating resource locator</info>');

        $repository = new ResourceRepository();
        $loader = new RepositoryLoader($repository);

        $loader->loadPackage($event->getComposer()->getPackage(), $basePath);

        foreach ($packages as $package) {
            /** @var \Composer\Package\PackageInterface $package */
            $loader->loadPackage($package, $installationManager->getInstallPath($package));
        }

        $loader->validateOverrides();
        $loader->applyOverrides();
        $loader->applyTags();

        $filesystem->ensureDirectoryExists($vendorPath.'/composer');

        PhpCacheRepository::dumpRepository($repository, $vendorPath.'/composer');

        $locatorCode = <<<LOCATOR
<?php

// resource-locator.php @generated by the Composer Puli plugin

use Webmozart\Puli\Locator\PhpCacheLocator;

return new PhpCacheLocator(__DIR__ . '/composer');

LOCATOR;

        file_put_contents($vendorPath.'/resource-locator.php', $locatorCode);
    }
}
