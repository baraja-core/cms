<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\Component\ErrorComponent;
use Baraja\Cms\MiddleWare\Bridge\AdminBarBridge;
use Baraja\Cms\Plugin\CommonSettingsPlugin;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Plugin\HomepagePlugin;
use Baraja\Cms\Plugin\UserPlugin;
use Baraja\Cms\Proxy\GlobalAsset\CmsEditorAsset;
use Baraja\Cms\Proxy\GlobalAsset\CmsSimpleStaticAsset;
use Baraja\Cms\Proxy\GlobalAsset\CustomGlobalAssetManager;
use Baraja\Cms\Proxy\GlobalAsset\CustomGlobalAssetManagerAccessor;
use Baraja\Cms\Support\Support;
use Baraja\Cms\Translator\TranslatorFilter;
use Baraja\Cms\User\AdminBarUser;
use Baraja\Cms\User\UserManager;
use Baraja\Cms\User\UserManagerAccessor;
use Baraja\Cms\User\UserMetaManager;
use Baraja\Doctrine\ORM\DI\OrmAnnotationsExtension;
use Baraja\Plugin\Component\VueComponent;
use Baraja\Plugin\PluginComponentExtension;
use Baraja\Plugin\PluginLinkGenerator;
use Baraja\Plugin\PluginManager;
use Baraja\Url\Url;
use Nette\Application\Application;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\MissingServiceException;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Nette\Security\User;
use Nette\Utils\FileSystem;
use Nette\Utils\Validators;

final class CmsExtension extends CompilerExtension
{
	/**
	 * @return array<int, string>
	 */
	public static function mustBeDefinedBefore(): array
	{
		return [OrmAnnotationsExtension::class];
	}


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'assets' => Expect::arrayOf(Expect::string()),
			'globalAssets' => Expect::arrayOf(Expect::anyOf(
				Expect::string()->required(),
				Expect::structure([
					'format' => Expect::anyOf('css', 'js')->required(),
					'url' => Expect::string()->required(),
				])->castTo('array'),
			)),
		]);
	}


	public function beforeCompile(): void
	{
		PluginComponentExtension::defineBasicServices($builder = $this->getContainerBuilder());
		OrmAnnotationsExtension::addAnnotationPathToManager($builder, 'Baraja\Cms\User\Entity', __DIR__ . '/User/Entity');
		OrmAnnotationsExtension::addAnnotationPathToManager($builder, 'Baraja\Cms\Announcement\Entity', __DIR__ . '/Announcement');
		OrmAnnotationsExtension::addAnnotationPathToManager($builder, 'Baraja\DoctrineConfiguration', __DIR__ . '/Settings/Entity');

		// linkGenerator
		$builder->addDefinition($this->prefix('linkGenerator'))
			->setFactory(LinkGenerator::class);

		try {
			/** @var ServiceDefinition $pluginContext */
			$pluginContext = $builder->getDefinitionByType(\Baraja\Plugin\Context::class);
			$pluginContext->addSetup('?->setLinkGenerator(?)', ['@self', '@' . PluginLinkGenerator::class]);
		} catch (MissingServiceException $e) {
			throw new \RuntimeException('Can not compile CMS extension, because service "' . \Baraja\Plugin\Context::class . '" (from package baraja-core/plugin-system) does not exist. Did you register Plugin system extension before CMS?', 500, $e);
		}

		// admin
		$builder->addDefinition($this->prefix('admin'))
			->setFactory(Admin::class);

		// context
		$context = $builder->addDefinition($this->prefix('context'))
			->setFactory(Context::class);

		$builder->addAccessorDefinition($this->prefix('contextAccessor'))
			->setImplement(ContextAccessor::class);

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

		// bridge
		$builder->addDefinition($this->prefix('adminBarBridge'))
			->setFactory(AdminBarBridge::class);

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
		$globalAssetManager = $builder->addDefinition($this->prefix('customGlobalAssetManager'))
			->setFactory(CustomGlobalAssetManager::class);

		if (isset($this->config->globalAssets)) {
			$this->registerGlobalAssets($globalAssetManager, $this->config->globalAssets);
		}

		$builder->addAccessorDefinition($this->prefix('customGlobalAssetManagerAccessor'))
			->setImplement(CustomGlobalAssetManagerAccessor::class);

		// user
		$builder->addDefinition($this->prefix('userManager'))
			->setFactory(UserManager::class);

		$builder->addDefinition($this->prefix('userMetaManager'))
			->setFactory(UserMetaManager::class);

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

		/** @var ServiceDefinition $netteUser */
		$netteUser = $builder->getDefinitionByType(User::class);

		/** @var ServiceDefinition $adminBarBridge */
		$adminBarBridge = $builder->getDefinitionByType(AdminBarBridge::class);

		/** @var FactoryDefinition $latte */
		$latte = $builder->getDefinitionByType(LatteFactory::class);
		$latte->getResultDefinition()->addSetup('addFilter(?, ?)', [
			'translate', '@' . TranslatorFilter::class,
		]);

		if (PHP_SAPI === 'cli') {
			return;
		}
		$class->getMethod('initialize')->addBody(
			'// admin (cms).' . "\n"
			. '(function (): void {' . "\n"
			. "\t" . 'if (' . Admin::class . '::isAdminRequest()) {' . "\n"
			. "\t\t" . '$this->getService(?)->onStartup[] = function(' . Application::class . ' $a): void {' . "\n"
			. "\t\t\t" . '$this->getService(?)->run();' . "\n"
			. "\t\t" . '};' . "\n"
			. "\t" . '}' . "\n"
			. "\t" . AdminBar::class . '::getBar()->setUser(new ' . AdminBarUser::class . '($this->getService(?)));' . "\n"
			. "\t" . '$this->getService(?)->setup();' . "\n"
			. '})();',
			[
				$application->getName(),
				$admin->getName(),
				$netteUser->getName(),
				$adminBarBridge->getName(),
			],
		);
	}


	/**
	 * @param array<int, string|array<string, string>> $assets
	 */
	private function registerGlobalAssets(ServiceDefinition $globalAssetManager, array $assets): void
	{
		foreach ($assets as $asset) {
			$url = null;
			$format = null;
			if (is_string($asset)) {
				if (preg_match('/^(?<name>.+)\.(?<format>[a-zA-Z0-9]+)(?:\?.*)?$/', $asset, $formatParser) === 1) {
					$format = $formatParser['format'] ?? null;
				} else {
					throw new \RuntimeException('Invalid asset filename "' . $asset . '". Did you mean "' . $asset . '.js"?');
				}
				$url = $asset;
			} else {
				$url = $asset['url'] ?? null;
				$format = $asset['format'] ?? null;
			}
			if ($url === null || $format === null) {
				throw new \RuntimeException(
					'Custom asset is invalid, because URL "' . $url . '" and format "' . $format . '" given. '
					. 'Did you set "url" and "format"?',
				);
			}
			$finalUrl = null;
			if (Validators::isUrl($url)) {
				$finalUrl = $url;
			} elseif (is_file($url)) {
				if (PHP_SAPI === 'cli') {
					continue;
				}
				if (FileSystem::isAbsolute($url) === false) {
					throw new \InvalidArgumentException('Asset disk path "' . $url . '" must be absolute.');
				}
				$finalUrl = $this->createUrlFromAssetPath($globalAssetManager, $url, $format);
			} else {
				throw new \InvalidArgumentException('URL "' . $url . '" is in invalid format.');
			}
			$globalAssetManager->addSetup('?->addAsset(new ' . CmsSimpleStaticAsset::class . '(?, ?))', [
				'@self',
				$format,
				$finalUrl,
			]);
		}
		$globalAssetManager->addSetup('?->addAsset(new ' . CmsEditorAsset::class . ')', [
			'@self',
		]);
	}


	private function createUrlFromAssetPath(
		ServiceDefinition $globalAssetManager,
		string $path,
		string $format
	): string {
		$hash = md5($path . '.' . $format);
		$globalAssetManager->addSetup('?->addDiskPath(?, ?)', [
			'@self',
			$hash,
			$path,
		]);

		return Url::get()->getBaseUrl() . '/admin/assets/static-file-proxy/' . $hash . '.' . $format;
	}
}
