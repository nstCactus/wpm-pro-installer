<?php namespace IgniteOnline\WPMProInstaller;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;
use Dotenv\Dotenv;
use IgniteOnline\WPMProInstaller\Exceptions\MissingKeyException;

/**
 * A composer plugin that makes installing ACF PRO possible
 *
 * The WordPress plugin Advanced Custom Fields PRO (ACF PRO) does not
 * offer a way to install it via composer natively.
 *
 * This plugin uses a 'package' repository (user supplied) that downloads the
 * correct version from the ACF site using the version number from
 * that repository and a license key from the ENVIRONMENT or an .env file.
 *
 * With this plugin user no longer need to expose their license key in
 * composer.json.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The name of the environment variable
     * where the ACF PRO key should be stored.
     */
    const KEY_ENV_VARIABLE = 'WPM_PRO_KEY';

    /**
     * The URL or the project
     */
    const URL_ENV_VARIABLE = 'WP_HOME';

    /**
     * The name of the WPM PRO package
     */

    public static function WPM_PRO_PACKAGE_NAMES()
    {
        return [
            'deliciousbrains/wp-migrate-db-pro',
            'deliciousbrains/wp-migrate-db-pro-media-files',
        ];
    }

    /**
     * The url where WPM PRO can be downloaded (without version and key)
     */

    public static function WPM_PRO_PACKAGE_URLS()
    {
        return [
            'deliciousbrains/wp-migrate-db-pro' => 'https://deliciousbrains.com/dl/wp-migrate-db-pro-latest.zip?',
            'deliciousbrains/wp-migrate-db-pro-media-files' => 'https://deliciousbrains.com/dl/wp-migrate-db-pro-media-files-latest.zip?',

        ];
    }

    /**
     * @access protected
     * @var Composer
     */
    protected $composer;

    /**
     * @access protected
     * @var IOInterface
     */
    protected $io;

    /**
     * The function that is called when the plugin is activated
     *
     * Makes composer and io available because they are needed
     * in the addKey method.
     *
     * @access public
     * @param Composer $composer The composer object
     * @param IOInterface $io Not used
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Subscribe this Plugin to relevant Events
     *
     * Pre Install/Update: The version needs to be added to the url
     *                     (will show up in composer.lock)
     * Pre Download: The key needs to be added to the url
     *               (will not show up in composer.lock)
     *
     * @access public
     * @return array An array of events that the plugin subscribes to
     * @static
     */
    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::PRE_FILE_DOWNLOAD => 'addKey'
        ];
    }

    /**
     * Add the version to the package url
     *
     * The version needs to be added in the PRE_PACKAGE_INSTALL/UPDATE
     * event to make sure that different version save different urls
     * in composer.lock. Composer would load any available version from cache
     * although the version numbers might differ (because they have the same
     * url).
     *
     * @access public
     * @param PackageEvent $event The event that called the method
     * @throws UnexpectedValueException
     */
    public function addVersion(PackageEvent $event)
    {
        $package = $this->getPackageFromOperation($event->getOperation());

        if (in_array($package->getName(), self::WPM_PRO_PACKAGE_NAMES())) {
            $version = $this->validateVersion($package->getPrettyVersion(), $package->getName());
            $package->setDistUrl(
                $this->addParameterToUrl($package->getDistUrl(), 'v', $version)
            );
        }
    }


    /**
     * Add the key from the environment to the event url
     *
     * The key is not added to the package because it would show up in the
     * composer.lock file in this case. A custom file system is used to
     * swap out the WPM PRO url with a url that contains the key.
     *
     * @access public
     * @param PreFileDownloadEvent $event The event that called this method
     * @throws MissingKeyException
     */
    public function addKey(PreFileDownloadEvent $event)
    {
        $processedUrl = $event->getProcessedUrl();

        if ($this->isWPMProPackageUrl($processedUrl)) {
            $processedUrl = $processedUrl . 'site_url=' . $this->getSiteUrlFromEnv();

            $rfs = $event->getRemoteFilesystem();
            $wpmRfs = new RemoteFilesystem(
                $this->addParameterToUrl(
                    $processedUrl,
                    'licence_key',
                    $this->getKeyFromEnv()
                ),
                $this->io,
                $this->composer->getConfig(),
                $rfs->getOptions(),
                $rfs->isTlsDisabled()
            );
            $event->setRemoteFilesystem($wpmRfs);
        }
    }

    /**
     * Get the package from a given operation
     *
     * Is needed because update operations don't have a getPackage method
     *
     * @access protected
     * @param OperationInterface $operation The operation
     * @return PackageInterface The package of the operation
     */
    protected function getPackageFromOperation(OperationInterface $operation)
    {
        if ($operation->getJobType() === 'update') {
            return $operation->getTargetPackage();
        }
        return $operation->getPackage();
    }

    /**
     * Validate that the version is an exact major.minor.patch version
     *
     * The url to download the code for the package only works with exact
     * version numbers with 3 digits: e.g. 1.2.3
     *
     * @access protected
     * @param string $version The version that should be validated
     * @return string The valid version
     * @throws UnexpectedValueException
     */
    protected function validateVersion($version, $package_name)
    {
        // \A = start of string, \Z = end of string
        // See: http://stackoverflow.com/a/34994075
        $major_minor_patch = '/\A\d\.\d\.\d\Z/';

        if (!preg_match($major_minor_patch, $version)) {
            throw new \UnexpectedValueException(
                'The version constraint of ' . $package_name .
                ' should be exact (with 3 digits). ' .
                'Invalid version string "' . $version . '"'
            );
        }

        return $version;
    }

    /**
     * Test if the given url is the WPM PRO download url
     *
     * @access protected
     * @param string The url that should be checked
     * @return bool
     */
    protected function isWPMProPackageUrl($url)
    {
        return in_array($url, self::WPM_PRO_PACKAGE_URLS()) !== false;
    }

    /**
     * Get the WPM PRO key from the environment
     *
     * Loads the .env file that is in the same directory as composer.json
     * and gets the key from the environment variable KEY_ENV_VARIABLE.
     * Already set variables will not be overwritten by the variables in .env
     * @link https://github.com/vlucas/phpdotenv#immutability
     *
     * @access protected
     * @return string The key from the environment
     * @throws IgniteOnline\WPMProInstaller\Exceptions\MissingKeyException
     */
    protected function getKeyFromEnv()
    {
        $this->loadDotEnv();
        $key = getenv(self::KEY_ENV_VARIABLE);

        if (!$key) {
            throw new MissingKeyException(self::KEY_ENV_VARIABLE);
        }

        return $key;
    }

    /**
     * Get the WPM PRO site url from the environment
     *
     * Loads the .env file that is in the same directory as composer.json
     * and gets the key from the environment variable KEY_ENV_VARIABLE.
     * Already set variables will not be overwritten by the variables in .env
     * @link https://github.com/vlucas/phpdotenv#immutability
     *
     * @access protected
     * @return string The key from the environment
     * @throws IgniteOnline\WPMProInstaller\Exceptions\MissingKeyException
     */
    protected function getSiteUrlFromEnv()
    {
        $this->loadDotEnv();
        $key = getenv(self::URL_ENV_VARIABLE);

        if (!$key) {
            throw new MissingKeyException(self::URL_ENV_VARIABLE);
        }

        return $key;
    }

    /**
     * Make environment variables in .env available if .env exists
     *
     * getcwd() returns the directory of composer.json.
     *
     * @access protected
     */
    protected function loadDotEnv()
    {
        if (file_exists(getcwd() . DIRECTORY_SEPARATOR . '.env')) {
            $dotenv = Dotenv::create(getcwd());
            $dotenv->load();
        }
    }

    /**
     * Add a parameter to the given url
     *
     * Adds the given parameter at the end of the given url. It only works with
     * urls that already have parameters (e.g. test.com?p=true) because it
     * uses & as a separation character.
     *
     * @access protected
     * @param string $url The url that should be appended
     * @param string $parameter The name of the parameter
     * @param string $value The value of the parameter
     * @return string The url appended with &parameter=value
     */
    protected function addParameterToUrl($url, $parameter, $value)
    {
        $cleanUrl = $this->removeParameterFromUrl($url, $parameter);
        $urlParameter = '&' . $parameter . '=' . urlencode($value);

        return $cleanUrl .= $urlParameter;
    }

    /**
     * Remove a given parameter from the given url
     *
     * Removes &parameter=value from the given url. Only works with urls that
     * have multiple parameters and the parameter that should be removed is
     * not the first (because of the & character).
     *
     * @access protected
     * @param string $url The url where the parameter should be removed
     * @param string $parameter The name of the parameter
     * @return string The url with the &parameter=value removed
     */
    protected function removeParameterFromUrl($url, $parameter)
    {
        // e.g. &t=1.2.3 in example.com?p=index.php&t=1.2.3&k=key
        $pattern = "/(&$parameter=[^&]*)/";
        return preg_replace($pattern, '', $url);
    }
}
