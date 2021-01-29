<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\Component\ErrorComponent;
use Baraja\Cms\Plugin\CommonSettingsPlugin;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Plugin\HomepagePlugin;
use Baraja\Cms\Plugin\UserPlugin;
use Baraja\Cms\Proxy\GlobalAsset\CustomGlobalAssetManager;
use Baraja\Cms\Proxy\GlobalAsset\CustomGlobalAssetManagerAccessor;
use Baraja\Cms\Support\Support;
use Baraja\Cms\Translator\TranslatorFilter;
use Baraja\Cms\User\UserManager;
use Baraja\Cms\User\UserManagerAccessor;
use Baraja\Doctrine\ORM\DI\OrmAnnotationsExtension;
use Baraja\Plugin\Component\VueComponent;
use Baraja\Plugin\PluginComponentExtension;
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
	/**
	 * @return string[]
	 */
	public static function mustBeDefinedBefore(): array
	{
		return [OrmAnnotationsExtension::class];
	}


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'assets' => Expect::arrayOf(Expect::string()),
		]);
	}


	public function beforeCompile(): void
	{
		PluginComponentExtension::defineBasicServices($builder = $this->getContainerBuilder());
		OrmAnnotationsExtension::addAnnotationPathToManager($builder, 'Baraja\Cms\User\Entity', __DIR__ . '/User/Entity');
		OrmAnnotationsExtension::addAnnotationPathToManager($builder, 'Baraja\DoctrineConfiguration', __DIR__ . '/Settings/Entity');

		// linkGenerator
		$builder->addDefinition($this->prefix('linkGenerator'))
			->setFactory(LinkGenerator::class);

		try {
			/** @var ServiceDefinition $pluginContext */
			$pluginContext = $builder->getDefinitionByType(\Baraja\Plugin\Context::class);
			$pluginContext->addSetup('?->setLinkGenerator(?)', ['@self', '@' . PluginLinkGenerator::class]);
		} catch (MissingServiceException $e) {
			throw new \RuntimeException('Can not compile CMS extension, because service "' . \Baraja\Plugin\Context::class . '" (from package baraja-core/plugin-system) does not exist. Did you register Plugin system extension before CMS?', $e->getCode(), $e);
		}

		// admin
		$builder->addDefinition($this->prefix('admin'))
			->setFactory(Admin::class)
			->setArgument('cacheDir', $cacheDir = $builder->parameters['tempDir'] . '/cache/baraja.cms');
		FileSystem::createDir($cacheDir);

		// context
		$context = $builder->addDefinition($this->prefix('context'))
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

		$builder->addDefinition($this->prefix('support'))
			->setFactory(Support::class);

		// translator
		$builder->addDefinition($this->prefix('translatorFilter'))
			->setFactory(TranslatorFilter::class);

		// settings
		$builder->addDefinition($this->prefix('settings'))
			->setFactory(Settings::class);

		$builder->addDefinition($this->prefix('tokenStorage'))
			->setFactory(CmsConstantTokenStorage::class);

		// menuManager
		$builder->addDefinition($this->prefix('menuManager'))
			->setFactory(MenuManager::class);

		$builder->addDefinition($this->prefix('menuAuthorizator'))
			->setFactory(MenuAuthorizator::class);

		$builder->addAccessorDefinition($this->prefix('menuAuthorizatorAccessor'))
			->setImplement(MenuAuthorizatorAccessor::class);

		// global asset manager
		$builder->addDefinition($this->prefix('customGlobalAssetManager'))
			->setFactory(CustomGlobalAssetManager::class);

		$builder->addAccessorDefinition($this->prefix('customGlobalAssetManagerAccessor'))
			->setImplement(CustomGlobalAssetManagerAccessor::class);

		// user
		$builder->addDefinition($this->prefix('userManager'))
			->setFactory(UserManager::class);

		$builder->addAccessorDefinition($this->prefix('userManagerAccessor'))
			->setImplement(UserManagerAccessor::class);

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
			'position' => 80,
			'tab' => 'Security',
			'params' => ['id'],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'userPermissions',
			'name' => 'user-permissions',
			'implements' => UserPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'detail',
			'source' => __DIR__ . '/../template/user/permissions.js',
			'position' => 50,
			'tab' => 'Permissions',
			'params' => ['id'],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'userLoginHistory',
			'name' => 'user-login-history',
			'implements' => UserPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'detail',
			'source' => __DIR__ . '/../template/user/login-history.js',
			'position' => 20,
			'tab' => 'Login history',
			'params' => ['id'],
		]]);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'commonSettings',
			'name' => 'common-settings',
			'implements' => CommonSettingsPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'default',
			'source' => __DIR__ . '/../template/vue/common-settings.js',
			'position' => 100,
			'tab' => 'Common settings',
			'params' => [],
		]]);
	}


	public function afterCompile(ClassType $class): void
	{
		$builder = $this->getContainerBuilder();

		/** @var ServiceDefinition $application */
		$application = $builder->getDefinitionByType(Application::class);

		/** @var ServiceDefinition $admin */
		$admin = $builder->getDefinitionByType(Admin::class);

		/** @var FactoryDefinition $latte */
		$latte = $builder->getDefinitionByType(ILatteFactory::class);
		$latte->getResultDefinition()->addSetup('addFilter(?, ?)', [
			'translate', '@' . TranslatorFilter::class,
		]);

		if (PHP_SAPI === 'cli') {
			return;
		}
		$class->getMethod('initialize')->addBody(
			'// admin (cms).' . "\n"
			. '(function () {' . "\n"
			. "\t" . 'if (preg_match(?, $this->getService(\'http.request\')->getUrl()->getRelativeUrl(), $parser)) {' . "\n"
			. "\t\t" . '$this->getService(?)->onStartup[] = function(' . Application::class . ' $a) use ($parser): void {' . "\n"
			. "\t\t\t" . 'try {' . "\n"
			. "\t\t\t\t" . '$this->getService(?)->run($parser[\'locale\'] \?: null, $parser[\'path\']);' . "\n"
			. "\t\t\t" . '} catch (\Throwable $e) {' . "\n"
			. "\t\t\t\t" . Debugger::class . '::log($e, \'' . ILogger::DEBUG . '\'); ' . Helpers::class . '::brokenAdmin($e); die;' . "\n"
			. "\t\t\t" . '}' . "\n"
			. "\t\t" . '};' . "\n"
			. "\t" . '}' . "\n"
			. '})();',
			[
				'/^admin(?:\/+(?<locale>' . implode('|', Admin::SUPPORTED_LOCALES) . '))?(?<path>\/.*|\?.*|)$/',
				$application->getName(),
				$admin->getName(),
			],
		);
	}
}
