<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
use OC\App\DependencyAnalyzer;
use OC\App\Platform;
use OC\AppFramework\Bootstrap\Coordinator;
use OC\Config\ConfigManager;
use OC\DB\MigrationService;
use OC\Installer;
use OC\Repair;
use OC\Repair\Events\RepairErrorEvent;
use OCP\App\Events\AppUpdateEvent;
use OCP\App\IAppManager;
use OCP\App\ManagerEvent;
use OCP\Authentication\IAlternativeLogin;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;
use function OCP\Log\logger;

/**
 * This class manages the apps. It allows them to register and integrate in the
 * Nextcloud ecosystem. Furthermore, this class is responsible for installing,
 * upgrading and removing apps.
 */
class OC_App {
	private static $altLogin = [];
	private static $alreadyRegistered = [];
	public const supportedApp = 300;
	public const officialApp = 200;

	/**
	 * clean the appId
	 *
	 * @psalm-taint-escape file
	 * @psalm-taint-escape include
	 * @psalm-taint-escape html
	 * @psalm-taint-escape has_quotes
	 *
	 * @deprecated 31.0.0 use IAppManager::cleanAppId
	 */
	public static function cleanAppId(string $app): string {
		return str_replace(['<', '>', '"', "'", '\0', '/', '\\', '..'], '', $app);
	}

	/**
	 * Check if an app is loaded
	 *
	 * @param string $app
	 * @return bool
	 * @deprecated 27.0.0 use IAppManager::isAppLoaded
	 */
	public static function isAppLoaded(string $app): bool {
		return \OC::$server->get(IAppManager::class)->isAppLoaded($app);
	}

	/**
	 * loads all apps
	 *
	 * @param string[] $types
	 * @return bool
	 *
	 * This function walks through the Nextcloud directory and loads all apps
	 * it can find. A directory contains an app if the file /appinfo/info.xml
	 * exists.
	 *
	 * if $types is set to non-empty array, only apps of those types will be loaded
	 *
	 * @deprecated 29.0.0 use IAppManager::loadApps instead
	 */
	public static function loadApps(array $types = []): bool {
		if (!\OC::$server->getSystemConfig()->getValue('installed', false)) {
			// This should be done before calling this method so that appmanager can be used
			return false;
		}
		return \OC::$server->get(IAppManager::class)->loadApps($types);
	}

	/**
	 * load a single app
	 *
	 * @param string $app
	 * @throws Exception
	 * @deprecated 27.0.0 use IAppManager::loadApp
	 */
	public static function loadApp(string $app): void {
		\OC::$server->get(IAppManager::class)->loadApp($app);
	}

	/**
	 * @internal
	 * @param string $app
	 * @param string $path
	 * @param bool $force
	 */
	public static function registerAutoloading(string $app, string $path, bool $force = false) {
		$key = $app . '-' . $path;
		if (!$force && isset(self::$alreadyRegistered[$key])) {
			return;
		}

		self::$alreadyRegistered[$key] = true;

		// Register on PSR-4 composer autoloader
		$appNamespace = \OC\AppFramework\App::buildAppNamespace($app);
		\OC::$server->registerNamespace($app, $appNamespace);

		if (file_exists($path . '/composer/autoload.php')) {
			require_once $path . '/composer/autoload.php';
		} else {
			\OC::$composerAutoloader->addPsr4($appNamespace . '\\', $path . '/lib/', true);
		}

		// Register Test namespace only when testing
		if (defined('PHPUNIT_RUN') || defined('CLI_TEST_RUN')) {
			\OC::$composerAutoloader->addPsr4($appNamespace . '\\Tests\\', $path . '/tests/', true);
		}
	}

	/**
	 * check if an app is of a specific type
	 *
	 * @param string $app
	 * @param array $types
	 * @return bool
	 * @deprecated 27.0.0 use IAppManager::isType
	 */
	public static function isType(string $app, array $types): bool {
		return \OC::$server->get(IAppManager::class)->isType($app, $types);
	}

	/**
	 * read app types from info.xml and cache them in the database
	 */
	public static function setAppTypes(string $app) {
		$appManager = \OC::$server->getAppManager();
		$appData = $appManager->getAppInfo($app);
		if (!is_array($appData)) {
			return;
		}

		if (isset($appData['types'])) {
			$appTypes = implode(',', $appData['types']);
		} else {
			$appTypes = '';
			$appData['types'] = [];
		}

		$config = \OC::$server->getConfig();
		$config->setAppValue($app, 'types', $appTypes);

		if ($appManager->hasProtectedAppType($appData['types'])) {
			$enabled = $config->getAppValue($app, 'enabled', 'yes');
			if ($enabled !== 'yes' && $enabled !== 'no') {
				$config->setAppValue($app, 'enabled', 'yes');
			}
		}
	}

	/**
	 * Returns apps enabled for the current user.
	 *
	 * @param bool $forceRefresh whether to refresh the cache
	 * @param bool $all whether to return apps for all users, not only the
	 *                  currently logged in one
	 * @return list<string>
	 */
	public static function getEnabledApps(bool $forceRefresh = false, bool $all = false): array {
		if (!\OC::$server->getSystemConfig()->getValue('installed', false)) {
			return [];
		}
		// in incognito mode or when logged out, $user will be false,
		// which is also the case during an upgrade
		$appManager = \OC::$server->getAppManager();
		if ($all) {
			$user = null;
		} else {
			$user = \OC::$server->getUserSession()->getUser();
		}

		if (is_null($user)) {
			$apps = $appManager->getEnabledApps();
		} else {
			$apps = $appManager->getEnabledAppsForUser($user);
		}
		$apps = array_filter($apps, function ($app) {
			return $app !== 'files';//we add this manually
		});
		sort($apps);
		array_unshift($apps, 'files');
		return $apps;
	}

	/**
	 * enables an app
	 *
	 * @param string $appId
	 * @param array $groups (optional) when set, only these groups will have access to the app
	 * @throws \Exception
	 * @return void
	 *
	 * This function set an app as enabled in appconfig.
	 */
	public function enable(string $appId,
		array $groups = []) {
		// Check if app is already downloaded
		/** @var Installer $installer */
		$installer = Server::get(Installer::class);
		$isDownloaded = $installer->isDownloaded($appId);

		if (!$isDownloaded) {
			$installer->downloadApp($appId);
		}

		$installer->installApp($appId);

		$appManager = \OC::$server->getAppManager();
		if ($groups !== []) {
			$groupManager = \OC::$server->getGroupManager();
			$groupsList = [];
			foreach ($groups as $group) {
				$groupItem = $groupManager->get($group);
				if ($groupItem instanceof \OCP\IGroup) {
					$groupsList[] = $groupManager->get($group);
				}
			}
			$appManager->enableAppForGroups($appId, $groupsList);
		} else {
			$appManager->enableApp($appId);
		}
	}

	/**
	 * Find the apps root for an app id.
	 *
	 * If multiple copies are found, the apps root the latest version is returned.
	 *
	 * @param string $appId
	 * @param bool $ignoreCache ignore cache and rebuild it
	 * @return false|array{path: string, url: string} the apps root shape
	 */
	public static function findAppInDirectories(string $appId, bool $ignoreCache = false) {
		$sanitizedAppId = self::cleanAppId($appId);
		if ($sanitizedAppId !== $appId) {
			return false;
		}
		static $app_dir = [];

		if (isset($app_dir[$appId]) && !$ignoreCache) {
			return $app_dir[$appId];
		}

		$possibleApps = [];
		foreach (OC::$APPSROOTS as $dir) {
			if (file_exists($dir['path'] . '/' . $appId)) {
				$possibleApps[] = $dir;
			}
		}

		if (empty($possibleApps)) {
			return false;
		} elseif (count($possibleApps) === 1) {
			$dir = array_shift($possibleApps);
			$app_dir[$appId] = $dir;
			return $dir;
		} else {
			$versionToLoad = [];
			foreach ($possibleApps as $possibleApp) {
				$version = self::getAppVersionByPath($possibleApp['path'] . '/' . $appId);
				if (empty($versionToLoad) || version_compare($version, $versionToLoad['version'], '>')) {
					$versionToLoad = [
						'dir' => $possibleApp,
						'version' => $version,
					];
				}
			}
			$app_dir[$appId] = $versionToLoad['dir'];
			return $versionToLoad['dir'];
			//TODO - write test
		}
	}

	/**
	 * Get the directory for the given app.
	 * If the app is defined in multiple directories, the first one is taken. (false if not found)
	 *
	 * @psalm-taint-specialize
	 *
	 * @param string $appId
	 * @param bool $refreshAppPath should be set to true only during install/upgrade
	 * @return string|false
	 * @deprecated 11.0.0 use Server::get(IAppManager)->getAppPath()
	 */
	public static function getAppPath(string $appId, bool $refreshAppPath = false) {
		$appId = self::cleanAppId($appId);
		if ($appId === '') {
			return false;
		} elseif ($appId === 'core') {
			return __DIR__ . '/../../../core';
		}

		if (($dir = self::findAppInDirectories($appId, $refreshAppPath)) != false) {
			return $dir['path'] . '/' . $appId;
		}
		return false;
	}

	/**
	 * Get the path for the given app on the access
	 * If the app is defined in multiple directories, the first one is taken. (false if not found)
	 *
	 * @param string $appId
	 * @return string|false
	 * @deprecated 18.0.0 use \OC::$server->getAppManager()->getAppWebPath()
	 */
	public static function getAppWebPath(string $appId) {
		if (($dir = self::findAppInDirectories($appId)) != false) {
			return OC::$WEBROOT . $dir['url'] . '/' . $appId;
		}
		return false;
	}

	/**
	 * get app's version based on it's path
	 *
	 * @param string $path
	 * @return string
	 */
	public static function getAppVersionByPath(string $path): string {
		$infoFile = $path . '/appinfo/info.xml';
		$appData = Server::get(IAppManager::class)->getAppInfoByPath($infoFile);
		return $appData['version'] ?? '';
	}

	/**
	 * get the id of loaded app
	 *
	 * @return string
	 */
	public static function getCurrentApp(): string {
		if (\OC::$CLI) {
			return '';
		}

		$request = \OC::$server->getRequest();
		$script = substr($request->getScriptName(), strlen(OC::$WEBROOT) + 1);
		$topFolder = substr($script, 0, strpos($script, '/') ?: 0);
		if (empty($topFolder)) {
			try {
				$path_info = $request->getPathInfo();
			} catch (Exception $e) {
				// Can happen from unit tests because the script name is `./vendor/bin/phpunit` or something a like then.
				\OC::$server->get(LoggerInterface::class)->error('Failed to detect current app from script path', ['exception' => $e]);
				return '';
			}
			if ($path_info) {
				$topFolder = substr($path_info, 1, strpos($path_info, '/', 1) - 1);
			}
		}
		if ($topFolder == 'apps') {
			$length = strlen($topFolder);
			return substr($script, $length + 1, strpos($script, '/', $length + 1) - $length - 1) ?: '';
		} else {
			return $topFolder;
		}
	}

	/**
	 * @param array $entry
	 * @deprecated 20.0.0 Please register your alternative login option using the registerAlternativeLogin() on the RegistrationContext in your Application class implementing the OCP\Authentication\IAlternativeLogin interface
	 */
	public static function registerLogIn(array $entry) {
		Server::get(LoggerInterface::class)->debug('OC_App::registerLogIn() is deprecated, please register your alternative login option using the registerAlternativeLogin() on the RegistrationContext in your Application class implementing the OCP\Authentication\IAlternativeLogin interface');
		self::$altLogin[] = $entry;
	}

	/**
	 * @return array
	 */
	public static function getAlternativeLogIns(): array {
		/** @var Coordinator $bootstrapCoordinator */
		$bootstrapCoordinator = Server::get(Coordinator::class);

		foreach ($bootstrapCoordinator->getRegistrationContext()->getAlternativeLogins() as $registration) {
			if (!in_array(IAlternativeLogin::class, class_implements($registration->getService()), true)) {
				Server::get(LoggerInterface::class)->error('Alternative login option {option} does not implement {interface} and is therefore ignored.', [
					'option' => $registration->getService(),
					'interface' => IAlternativeLogin::class,
					'app' => $registration->getAppId(),
				]);
				continue;
			}

			try {
				/** @var IAlternativeLogin $provider */
				$provider = Server::get($registration->getService());
			} catch (ContainerExceptionInterface $e) {
				Server::get(LoggerInterface::class)->error('Alternative login option {option} can not be initialized.',
					[
						'exception' => $e,
						'option' => $registration->getService(),
						'app' => $registration->getAppId(),
					]);
			}

			try {
				$provider->load();

				self::$altLogin[] = [
					'name' => $provider->getLabel(),
					'href' => $provider->getLink(),
					'class' => $provider->getClass(),
				];
			} catch (Throwable $e) {
				Server::get(LoggerInterface::class)->error('Alternative login option {option} had an error while loading.',
					[
						'exception' => $e,
						'option' => $registration->getService(),
						'app' => $registration->getAppId(),
					]);
			}
		}

		return self::$altLogin;
	}

	/**
	 * get a list of all apps in the apps folder
	 *
	 * @return string[] an array of app names (string IDs)
	 * @deprecated 31.0.0 Use IAppManager::getAllAppsInAppsFolders instead
	 */
	public static function getAllApps(): array {
		return Server::get(IAppManager::class)->getAllAppsInAppsFolders();
	}

	/**
	 * List all supported apps
	 *
	 * @deprecated 32.0.0 Use \OCP\Support\Subscription\IRegistry::delegateGetSupportedApps instead
	 */
	public function getSupportedApps(): array {
		$subscriptionRegistry = Server::get(\OCP\Support\Subscription\IRegistry::class);
		$supportedApps = $subscriptionRegistry->delegateGetSupportedApps();
		return $supportedApps;
	}

	/**
	 * List all apps, this is used in apps.php
	 *
	 * @return array
	 */
	public function listAllApps(): array {
		$appManager = \OC::$server->getAppManager();

		$installedApps = $appManager->getAllAppsInAppsFolders();
		//we don't want to show configuration for these
		$blacklist = $appManager->getAlwaysEnabledApps();
		$appList = [];
		$langCode = \OC::$server->getL10N('core')->getLanguageCode();
		$urlGenerator = \OC::$server->getURLGenerator();
		$supportedApps = $this->getSupportedApps();

		foreach ($installedApps as $app) {
			if (!in_array($app, $blacklist)) {
				$info = $appManager->getAppInfo($app, false, $langCode);
				if (!is_array($info)) {
					Server::get(LoggerInterface::class)->error('Could not read app info file for app "' . $app . '"', ['app' => 'core']);
					continue;
				}

				if (!isset($info['name'])) {
					Server::get(LoggerInterface::class)->error('App id "' . $app . '" has no name in appinfo', ['app' => 'core']);
					continue;
				}

				$enabled = \OC::$server->getConfig()->getAppValue($app, 'enabled', 'no');
				$info['groups'] = null;
				if ($enabled === 'yes') {
					$active = true;
				} elseif ($enabled === 'no') {
					$active = false;
				} else {
					$active = true;
					$info['groups'] = $enabled;
				}

				$info['active'] = $active;

				if ($appManager->isShipped($app)) {
					$info['internal'] = true;
					$info['level'] = self::officialApp;
					$info['removable'] = false;
				} else {
					$info['internal'] = false;
					$info['removable'] = true;
				}

				if (in_array($app, $supportedApps)) {
					$info['level'] = self::supportedApp;
				}

				$appPath = self::getAppPath($app);
				if ($appPath !== false) {
					$appIcon = $appPath . '/img/' . $app . '.svg';
					if (file_exists($appIcon)) {
						$info['preview'] = $urlGenerator->imagePath($app, $app . '.svg');
						$info['previewAsIcon'] = true;
					} else {
						$appIcon = $appPath . '/img/app.svg';
						if (file_exists($appIcon)) {
							$info['preview'] = $urlGenerator->imagePath($app, 'app.svg');
							$info['previewAsIcon'] = true;
						}
					}
				}
				// fix documentation
				if (isset($info['documentation']) && is_array($info['documentation'])) {
					foreach ($info['documentation'] as $key => $url) {
						// If it is not an absolute URL we assume it is a key
						// i.e. admin-ldap will get converted to go.php?to=admin-ldap
						if (stripos($url, 'https://') !== 0 && stripos($url, 'http://') !== 0) {
							$url = $urlGenerator->linkToDocs($url);
						}

						$info['documentation'][$key] = $url;
					}
				}

				$info['version'] = $appManager->getAppVersion($app);
				$appList[] = $info;
			}
		}

		return $appList;
	}

	public static function shouldUpgrade(string $app): bool {
		$versions = self::getAppVersions();
		$currentVersion = Server::get(\OCP\App\IAppManager::class)->getAppVersion($app);
		if ($currentVersion && isset($versions[$app])) {
			$installedVersion = $versions[$app];
			if (!version_compare($currentVersion, $installedVersion, '=')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adjust the number of version parts of $version1 to match
	 * the number of version parts of $version2.
	 *
	 * @param string $version1 version to adjust
	 * @param string $version2 version to take the number of parts from
	 * @return string shortened $version1
	 */
	private static function adjustVersionParts(string $version1, string $version2): string {
		$version1 = explode('.', $version1);
		$version2 = explode('.', $version2);
		// reduce $version1 to match the number of parts in $version2
		while (count($version1) > count($version2)) {
			array_pop($version1);
		}
		// if $version1 does not have enough parts, add some
		while (count($version1) < count($version2)) {
			$version1[] = '0';
		}
		return implode('.', $version1);
	}

	/**
	 * Check whether the current Nextcloud version matches the given
	 * application's version requirements.
	 *
	 * The comparison is made based on the number of parts that the
	 * app info version has. For example for ownCloud 6.0.3 if the
	 * app info version is expecting version 6.0, the comparison is
	 * made on the first two parts of the ownCloud version.
	 * This means that it's possible to specify "requiremin" => 6
	 * and "requiremax" => 6 and it will still match ownCloud 6.0.3.
	 *
	 * @param string $ocVersion Nextcloud version to check against
	 * @param array $appInfo app info (from xml)
	 *
	 * @return boolean true if compatible, otherwise false
	 */
	public static function isAppCompatible(string $ocVersion, array $appInfo, bool $ignoreMax = false): bool {
		$requireMin = '';
		$requireMax = '';
		if (isset($appInfo['dependencies']['nextcloud']['@attributes']['min-version'])) {
			$requireMin = $appInfo['dependencies']['nextcloud']['@attributes']['min-version'];
		} elseif (isset($appInfo['dependencies']['owncloud']['@attributes']['min-version'])) {
			$requireMin = $appInfo['dependencies']['owncloud']['@attributes']['min-version'];
		} elseif (isset($appInfo['requiremin'])) {
			$requireMin = $appInfo['requiremin'];
		} elseif (isset($appInfo['require'])) {
			$requireMin = $appInfo['require'];
		}

		if (isset($appInfo['dependencies']['nextcloud']['@attributes']['max-version'])) {
			$requireMax = $appInfo['dependencies']['nextcloud']['@attributes']['max-version'];
		} elseif (isset($appInfo['dependencies']['owncloud']['@attributes']['max-version'])) {
			$requireMax = $appInfo['dependencies']['owncloud']['@attributes']['max-version'];
		} elseif (isset($appInfo['requiremax'])) {
			$requireMax = $appInfo['requiremax'];
		}

		if (!empty($requireMin)
			&& version_compare(self::adjustVersionParts($ocVersion, $requireMin), $requireMin, '<')
		) {
			return false;
		}

		if (!$ignoreMax && !empty($requireMax)
			&& version_compare(self::adjustVersionParts($ocVersion, $requireMax), $requireMax, '>')
		) {
			return false;
		}

		return true;
	}

	/**
	 * get the installed version of all apps
	 * @deprecated 32.0.0 Use IAppManager::getAppInstalledVersions or IAppConfig::getAppInstalledVersions instead
	 */
	public static function getAppVersions(): array {
		return Server::get(IAppConfig::class)->getAppInstalledVersions();
	}

	/**
	 * update the database for the app and call the update script
	 *
	 * @param string $appId
	 * @return bool
	 */
	public static function updateApp(string $appId): bool {
		// for apps distributed with core, we refresh app path in case the downloaded version
		// have been installed in custom apps and not in the default path
		$appPath = self::getAppPath($appId, true);
		if ($appPath === false) {
			return false;
		}

		if (is_file($appPath . '/appinfo/database.xml')) {
			Server::get(LoggerInterface::class)->error('The appinfo/database.xml file is not longer supported. Used in ' . $appId);
			return false;
		}

		\OC::$server->getAppManager()->clearAppsCache();
		$l = \OC::$server->getL10N('core');
		$appData = Server::get(\OCP\App\IAppManager::class)->getAppInfo($appId, false, $l->getLanguageCode());

		$ignoreMaxApps = \OC::$server->getConfig()->getSystemValue('app_install_overwrite', []);
		$ignoreMax = in_array($appId, $ignoreMaxApps, true);
		\OC_App::checkAppDependencies(
			\OC::$server->getConfig(),
			$l,
			$appData,
			$ignoreMax
		);

		self::registerAutoloading($appId, $appPath, true);
		self::executeRepairSteps($appId, $appData['repair-steps']['pre-migration']);

		$ms = new MigrationService($appId, \OC::$server->get(\OC\DB\Connection::class));
		$ms->migrate();

		self::executeRepairSteps($appId, $appData['repair-steps']['post-migration']);
		self::setupLiveMigrations($appId, $appData['repair-steps']['live-migration']);
		// update appversion in app manager
		\OC::$server->getAppManager()->clearAppsCache();
		\OC::$server->getAppManager()->getAppVersion($appId, false);

		self::setupBackgroundJobs($appData['background-jobs']);

		//set remote/public handlers
		if (array_key_exists('ocsid', $appData)) {
			\OC::$server->getConfig()->setAppValue($appId, 'ocsid', $appData['ocsid']);
		} elseif (\OC::$server->getConfig()->getAppValue($appId, 'ocsid', null) !== null) {
			\OC::$server->getConfig()->deleteAppValue($appId, 'ocsid');
		}
		foreach ($appData['remote'] as $name => $path) {
			\OC::$server->getConfig()->setAppValue('core', 'remote_' . $name, $appId . '/' . $path);
		}
		foreach ($appData['public'] as $name => $path) {
			\OC::$server->getConfig()->setAppValue('core', 'public_' . $name, $appId . '/' . $path);
		}

		self::setAppTypes($appId);

		$version = Server::get(\OCP\App\IAppManager::class)->getAppVersion($appId);
		\OC::$server->getConfig()->setAppValue($appId, 'installed_version', $version);

		// migrate eventual new config keys in the process
		/** @psalm-suppress InternalMethod */
		Server::get(ConfigManager::class)->migrateConfigLexiconKeys($appId);

		\OC::$server->get(IEventDispatcher::class)->dispatchTyped(new AppUpdateEvent($appId));
		\OC::$server->get(IEventDispatcher::class)->dispatch(ManagerEvent::EVENT_APP_UPDATE, new ManagerEvent(
			ManagerEvent::EVENT_APP_UPDATE, $appId
		));

		return true;
	}

	/**
	 * @param string $appId
	 * @param string[] $steps
	 * @throws \OC\NeedsUpdateException
	 */
	public static function executeRepairSteps(string $appId, array $steps) {
		if (empty($steps)) {
			return;
		}
		// load the app
		self::loadApp($appId);

		$dispatcher = Server::get(IEventDispatcher::class);

		// load the steps
		$r = Server::get(Repair::class);
		foreach ($steps as $step) {
			try {
				$r->addStep($step);
			} catch (Exception $ex) {
				$dispatcher->dispatchTyped(new RepairErrorEvent($ex->getMessage()));
				logger('core')->error('Failed to add app migration step ' . $step, ['exception' => $ex]);
			}
		}
		// run the steps
		$r->run();
	}

	public static function setupBackgroundJobs(array $jobs) {
		$queue = \OC::$server->getJobList();
		foreach ($jobs as $job) {
			$queue->add($job);
		}
	}

	/**
	 * @param string $appId
	 * @param string[] $steps
	 */
	private static function setupLiveMigrations(string $appId, array $steps) {
		$queue = \OC::$server->getJobList();
		foreach ($steps as $step) {
			$queue->add('OC\Migration\BackgroundRepair', [
				'app' => $appId,
				'step' => $step]);
		}
	}

	/**
	 * @param \OCP\IConfig $config
	 * @param \OCP\IL10N $l
	 * @param array $info
	 * @throws \Exception
	 */
	public static function checkAppDependencies(\OCP\IConfig $config, \OCP\IL10N $l, array $info, bool $ignoreMax) {
		$dependencyAnalyzer = new DependencyAnalyzer(new Platform($config), $l);
		$missing = $dependencyAnalyzer->analyze($info, $ignoreMax);
		if (!empty($missing)) {
			$missingMsg = implode(PHP_EOL, $missing);
			throw new \Exception(
				$l->t('App "%1$s" cannot be installed because the following dependencies are not fulfilled: %2$s',
					[$info['name'], $missingMsg]
				)
			);
		}
	}
}
