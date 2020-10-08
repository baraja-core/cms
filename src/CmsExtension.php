<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\Component\ErrorComponent;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Plugin\HomepagePlugin;
use Baraja\Cms\Plugin\UserPlugin;
use Baraja\Cms\Proxy\Proxy;
use Baraja\Cms\User\Authorizator;
use Baraja\Cms\User\UserManager;
use Baraja\Plugin\Component\VueComponent;
use Baraja\Plugin\PluginLinkGenerator;
use Baraja\Plugin\PluginManager;
use Nette\Application\Application;
use Nette\Bridges\ApplicationLatte\ILatteFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\MissingServiceException;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Utils\FileSystem;
use Tracy\Debugger;
use Tracy\ILogger;

final class CmsExtension extends CompilerExtension
{
	private const SERVICE_PREFIX = 'baraja.cms.';


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'assets' => Expect::arrayOf(Expect::string()),
		]);
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		// linkGenerator
		$builder->addDefinition(self::SERVICE_PREFIX . 'linkGenerator')
			->setFactory(LinkGenerator::class);

		try {
			/** @var ServiceDefinition $pluginContext */
			$pluginContext = $builder->getDefinitionByType(\Baraja\Plugin\Context::class);
			$pluginContext->addSetup('?->setLinkGenerator(?)', ['@self', '@' . PluginLinkGenerator::class]);
		} catch (MissingServiceException $e) {
			throw new \RuntimeException('Can not compile CMS extension, because service "' . \Baraja\Plugin\Context::class . '" (from package baraja-core/plugin-system) does not exist. Did you register Plugin system extension before CMS?', $e->getCode(), $e);
		}

		// proxy
		$builder->addDefinition(self::SERVICE_PREFIX . 'proxy')
			->setFactory(Proxy::class);

		// admin
		$builder->addDefinition(self::SERVICE_PREFIX . 'admin')
			->setFactory(Admin::class)
			->setArgument('cacheDir', $cacheDir = $builder->parameters['tempDir'] . '/cache/baraja.cms');
		FileSystem::createDir($cacheDir);

		// context
		$context = $builder->addDefinition(self::SERVICE_PREFIX . 'context')
			->setFactory(Context::class);

		if (isset($this->config->assets) === true) {
			foreach ($this->config->assets as $assetFormat => $assetPath) {
				if (\in_array($assetFormat, ['css', 'js'], true) === false) {
					throw new \RuntimeException('Asset format "' . $assetFormat . '" is not supported.');
				}
				if (\is_file($assetPath) === false) {
					throw new \RuntimeException('Asset file for format "' . $assetPath . '" does not exist, because path "' . $assetPath . '" given.');
				}
				$context->addSetup('?->setCustomAssetPath(?, ?)', ['@self', $assetFormat, $assetPath]);
			}
		}

		// translator
		$builder->addDefinition(self::SERVICE_PREFIX . 'translatorFilter')
			->setFactory(TranslatorFilter::class);

		// settings
		$builder->addDefinition(self::SERVICE_PREFIX . 'settings')
			->setFactory(Settings::class);

		$builder->addDefinition(self::SERVICE_PREFIX . 'tokenStorage')
			->setFactory(CmsConstantTokenStorage::class);

		// menuManager
		$builder->addDefinition(self::SERVICE_PREFIX . 'menuManager')
			->setFactory(MenuManager::class);

		// user
		$builder->addDefinition(self::SERVICE_PREFIX . 'userManager')
			->setFactory(UserManager::class);

		$builder->addDefinition(self::SERVICE_PREFIX . 'authorizator')
			->setFactory(Authorizator::class);

		/** @var ServiceDefinition $pluginManager */
		$pluginManager = $this->getContainerBuilder()->getDefinitionByType(PluginManager::class);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'homepageDashboardDefault',
			'name' => 'homepage-default',
			'implements' => HomepagePlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'default',
			'source' => __DIR__ . '/../template/vue/homepage-default.js',
			'position' => 100,
			'tab' => 'Dashboard',
			'params' => [],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'cmsError',
			'name' => 'cms-error',
			'implements' => ErrorPlugin::class,
			'componentClass' => ErrorComponent::class,
			'view' => 'default',
			'source' => __DIR__ . '/../template/vue/error.js',
			'position' => 100,
			'tab' => 'Error',
			'params' => [],
		]]);

		// User
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'userDefault',
			'name' => 'user-default',
			'implements' => UserPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'default',
			'source' => __DIR__ . '/../template/user/default.js',
			'position' => 100,
			'tab' => 'User manager',
			'params' => [],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'userOverview',
			'name' => 'user-overview',
			'implements' => UserPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'detail',
			'source' => __DIR__ . '/../template/user/overview.js',
			'position' => 100,
			'tab' => 'Profile',
			'params' => ['id'],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'userSecurity',
			'name' => 'user-security',
			'implements' => UserPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'detail',
			'source' => __DIR__ . '/../template/user/security.js',
			'position' => 100,
			'tab' => 'Security',
			'params' => ['id'],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'userLoginHistory',
			'name' => 'user-login-history',
			'implements' => UserPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'detail',
			'source' => __DIR__ . '/../template/user/login-history.js',
			'position' => 100,
			'tab' => 'Login history',
			'params' => ['id'],
		]]);
	}


	public function afterCompile(ClassType $class): void
	{
		$builder = $this->getContainerBuilder();

		/** @var ServiceDefinition $proxy */
		$proxy = $builder->getDefinitionByType(Proxy::class);

		/** @var ServiceDefinition $application */
		$application = $builder->getDefinitionByType(Application::class);

		/** @var ServiceDefinition $admin */
		$admin = $builder->getDefinitionByType(Admin::class);

		/** @var FactoryDefinition $latte */
		$latte = $builder->getDefinitionByType(ILatteFactory::class);
		$latte->getResultDefinition()->addSetup('addFilter(?, ?)', [
			'translate', '@' . TranslatorFilter::class,
		]);

		$class->getMethod('initialize')->addBody(
			'// admin (cms).' . "\n"
			. '(function () {' . "\n"
			. "\t" . '// Assets' . "\n"
			. "\t" . 'if (strncmp($assetLoader = ' . Helpers::class . '::processPath($this->getService(\'http.request\')), \'admin-assets/web-loader/\', 24) === 0) {' . "\n"
			. "\t\t" . '$this->getByType(' . Application::class . '::class)->onStartup[] = function(' . Application::class . ' $a) use ($assetLoader): void {' . "\n"
			. "\t\t\t" . '$this->getService(?)->run($assetLoader);' . "\n"
			. "\t\t" . '};' . "\n"
			. "\t" . '}' . "\n"
			. "\t" . '// Run admin' . "\n"
			. "\t" . 'if (preg_match(?, $this->getService(\'http.request\')->getUrl()->getRelativeUrl(), $parser)) {' . "\n"
			. "\t\t" . '$this->getService(?)->onStartup[] = function(' . Application::class . ' $a) use ($parser): void {' . "\n"
			. "\t\t\t" . 'try {' . "\n"
			. "\t\t\t\t" . '$this->getService(?)->run($parser[\'locale\'] \?: null, $parser[\'path\']);' . "\n"
			. "\t\t\t" . '} catch (\Throwable $e) {' . "\n"
			. "\t\t\t\t" . Debugger::class . '::log($e, \'' . ILogger::DEBUG . '\'); ' . Helpers::class . '::brokenAdmin($e); die;' . "\n"
			. "\t\t\t" . '}' . "\n"
			. "\t\t" . '};' . "\n"
			. "\t" . '}' . "\n"
			. '})();', [
				$proxy->getName(),
				'/^admin(?:\/+(?<locale>' . implode('|', Admin::SUPPORTED_LOCALES) . '))?(?<path>\/.*|\?.*|)$/',
				$application->getName(),
				$admin->getName(),
			]
		);
	}
}
