<?php
namespace Drush\Preflight;

use Composer\Autoload\ClassLoader;
use Drush\Drush;
use Drush\Config\Environment;
use Drush\Config\ConfigLocator;
use Drush\Config\EnvironmentConfigLoader;
use Drush\SiteAlias\SiteAliasManager;
use DrupalFinder\DrupalFinder;

use Consolidation\AnnotatedCommand\CommandFileDiscovery;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

use Webmozart\PathUtil\Path;

/**
 * Prepare to bootstrap Drupal
 *
 * - Determine the site to use
 * - Set up the DI container
 * - Start the bootstrap process
 */
class Preflight
{
    /**
     * @var Environment $environment
     */
    protected $environment;

    /**
     * @var PreflightVerify
     */
    protected $verify;

    /**
     * @var ConfigLocator
     */
    protected $configLocator;

    /**
     * @var DrupalFinder
     */
    protected $drupalFinder;

    public function __construct(Environment $environment, $verify = null, $configLocator = null)
    {
        $this->environment = $environment;
        $this->verify = $verify ?: new PreflightVerify();
        $this->configLocator = $configLocator ?: new ConfigLocator();
        $this->drupalFinder = new DrupalFinder();
    }

    public function init(PreflightArgs $preflightArgs)
    {
        // Define legacy constants, and include legacy files that Drush still needs
        LegacyPreflight::includeCode($this->environment->drushBasePath());
        LegacyPreflight::defineConstants($this->environment, $preflightArgs->applicationPath());
        LegacyPreflight::setContexts($this->environment);

        // TODO: Inject a termination handler into this class, so that we don't
        // need to add these e.g. when testing.
        $this->setTerminationHandlers();
    }

    /**
     * Preprocess the args, removing any @sitealias that may be present.
     * Arguments and options not used during preflight will be processed
     * with an ArgvInput.
     */
    public function preflightArgs($argv)
    {
        $argProcessor = new ArgsPreprocessor();
        $preflightArgs = new PreflightArgs();
        $argProcessor->parse($argv, $preflightArgs);

        return $preflightArgs;
    }

    public function prepareConfig(PreflightArgs $preflightArgs, Environment $environment)
    {
        // Load configuration and aliases from defined global locations
        // where such things are found.
        $configLocator = new ConfigLocator();
        $configLocator->setLocal($preflightArgs->isLocal());
        $configLocator->addUserConfig($preflightArgs->configPath(), $environment->systemConfigPath(), $environment->userConfigPath());
        $configLocator->addDrushConfig($environment->drushBasePath());

        // Make our environment settings available as configuration items
        $configLocator->addEnvironment($environment);

        return $configLocator;
    }

    /**
     * Start code coverage collection
     *
     * @param PreflightArgs $preflightArgs
     */
    public function startCoverage(PreflightArgs $preflightArgs)
    {
        if ($coverage_file = $preflightArgs->coverageFile()) {
            // TODO: modernize code coverage handling
            drush_set_context('DRUSH_CODE_COVERAGE', $coverage_file);
            xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
            register_shutdown_function('drush_coverage_shutdown');
        }
    }

    /**
     * Run the application, catching any errors that may be thrown.
     * Typically, this will happen only for code that fails fast during
     * preflight. Later code should catch and handle its own exceptions.
     */
    public function run($argv)
    {
        $status = 0;
        try {
            $status = $this->doRun($argv);
        } catch (\Exception $e) {
            $status = $e->getCode();
            $message = $e->getMessage();
            // Uncaught exceptions could happen early, before our logger
            // and other classes are initialized. Print them and exit.
            fwrite(STDERR, "$message\n");
        }
        return $status;
    }

    protected function doRun($argv)
    {
        // Fail fast if there is anything in our environment that does not check out
        $this->verify->verify($this->environment);

        // Get the preflight args and begin collecting configuration files.
        $preflightArgs = $this->preflightArgs($argv);
        $configLocator = $this->prepareConfig($preflightArgs, $this->environment);

        // Do legacy initialization (load static includes, define old constants, etc.)
        $this->init($preflightArgs);

        // Start code coverage
        $this->startCoverage($preflightArgs);

        // TODO: Should we allow config to set values defined by preflightArgs?
        // (e.g. --root and --uri).
        // Maybe preflight args should be one of the config layers, and we
        // should fetch 'root' et. al. from config rather than preflight args.
        $config = $configLocator->config();

        // Determine the local site targeted, if any.
        // Extend configuration and alias files to include files in
        // target site.
        $root = $this->findSelectedSite($preflightArgs);
        $configLocator->addSitewideConfig($root);

        // Handle aliases. Note that this might change the root. If it does,
        // extend the configuration again for the new alias record.
        $aliasManager = (new SiteAliasManager())
            ->addSearchLocation($preflightArgs->aliasPath())
            ->addSearchLocation($this->environment->systemConfigPath())
            ->addSearchLocation($this->environment->userConfigPath());
        $selfAliasRecord = $aliasManager->findSelf($preflightArgs->alias(), $root, $preflightArgs->uri());
        $root = $this->setSelectedSite($selfAliasRecord->localRoot());
        $configLocator->addSitewideConfig($root);

        // Require the Composer autoloader for Drupal (if different)
        $loader = $this->environment->loadSiteAutoloader($root);

        // Create the Symfony Application et. al.
        $input = new ArgvInput($preflightArgs->args());
        $output = new \Symfony\Component\Console\Output\ConsoleOutput();
        $application = new \Drush\Application('Drush Commandline Tool', Drush::getVersion());

        // Set up the DI container
        $container = DependencyInjection::initContainer($application, $config, $input, $output, $loader);

        // We need to check the php minimum version again, in case anyone
        // has set it to something higher in one of the config files we loaded.
        $this->verify->confirmPhpVersion($config->get('drush.php.minimum-version'));

        // Find all of the available commandfiles, save for those that are
        // provided by modules in the selected site; those will be added
        // during bootstrap.
        $searchpath = $this->findCommandFileSearchPath($preflightArgs, $root);
        $discovery = $this->commandDiscovery();
        $commandClasses = $discovery->discover($searchpath, '\Drush');

        // For now: use Symfony's built-in help, as Drush's version
        // assumes we are using the legacy Drush dispatcher.
        unset($commandClasses[dirname(__DIR__) . '/Commands/help/HelpCommands.php']);
        unset($commandClasses[dirname(__DIR__) . '/Commands/help/ListCommands.php']);

        // Use the robo runner to register commands with Symfony application.
        $runner = new \Robo\Runner();
        $runner->registerCommandClasses($application, $commandClasses);

        // TODO: Consider alternatives for injecting DrupalFinder into the bootstrap manager.
        $bootstrapManager = $container->get('bootstrap.manager');
        $bootstrapManager->setDrupalFinder($this->drupalFinder);

        // Run the Symfony Application
        // Predispatch: call a remote Drush command if applicable (via a 'pre-init' hook)
        // Bootstrap: bootstrap site to the level requested by the command (via a 'post-init' hook)
        $status = $application->run($input, $output);

        // Placate the Drush shutdown handler.
        // TODO: use a more modern termination management strategy
        drush_set_context('DRUSH_EXECUTION_COMPLETED', true);

        return $status;
    }

    /**
     * Find the site the user selected based on --root or cwd.
     */
    protected function findSelectedSite(PreflightArgs $preflightArgs)
    {
        $selectedRoot = $preflightArgs->selectedSite($this->environment->cwd());
        return $this->setSelectedSite($selectedRoot);
    }

    protected function setSelectedSite($selectedRoot)
    {
        $this->drupalFinder->locateRoot($selectedRoot);
        return $this->drupalFinder->getDrupalRoot();
    }

    /**
     * Create a command file discovery object
     */
    protected function commandDiscovery()
    {
        $discovery = new CommandFileDiscovery();
        $discovery
            ->setIncludeFilesAtBase(false)
            ->setSearchLocations(['Commands'])
            ->setSearchPattern('#.*Commands.php$#');
        return $discovery;
    }

    /**
     * Return the search path containing all of the locations where Drush
     * commands are found.
     */
    protected function findCommandFileSearchPath(PreflightArgs $preflightArgs)
    {
        // Start with the built-in commands
        $searchpath = [ dirname(__DIR__) ];

        // Commands specified by 'include' option
        $commandPath = $preflightArgs->commandPath();
        if (is_dir($commandPath)) {
            $searchpath[] = $commandPath;
        }

        if (!$preflightArgs->isLocal()) {
            // System commands, residing in $SHARE_PREFIX/share/drush/commands
            $share_path = $this->environment->systemCommandFilePath();
            if (is_dir($share_path)) {
                $searchpath[] = $share_path;
            }

            // User commands, residing in ~/.drush
            $per_user_config_dir = $this->environment->userConfigPath();
            if (is_dir($per_user_config_dir)) {
                $searchpath[] = $per_user_config_dir;
            }
        }

        $siteCommands = "$root/drupal";
        if (is_dir($siteCommands)) {
            $searchpath[] = $siteCommands;
        }

        return $searchpath;
    }

    /**
     * Make sure we are notified on exit, and when bad things happen.
     */
    protected function setTerminationHandlers()
    {
        // Set an error handler and a shutdown function
        // TODO: move these to a class somewhere
        set_error_handler('drush_error_handler');
        register_shutdown_function('drush_shutdown');
    }
}