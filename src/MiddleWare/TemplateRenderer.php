<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\Configuration;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Cms\MenuManager;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Proxy\GlobalAsset\CmsSimpleStaticAsset;
use Baraja\Cms\Settings;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\UserResetPasswordRequest;
use Baraja\Cms\User\Entity\UserResetPasswordRequestRepository;
use Baraja\Plugin\BasePlugin;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\Component\PluginComponent;
use Baraja\Plugin\Plugin;
use Baraja\Plugin\SimpleComponent\SimpleComponent;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Latte\Engine;

final class TemplateRenderer
{
	public function __construct(
		private string $cacheDir,
		private Context $context,
		private CmsPluginPanel $panel,
		private MenuManager $menuManager,
		private Settings $settings,
	) {
	}


	public function renderTemplate(Plugin $plugin, string $pluginName, string $view): string
	{
		$components = $this->context->getComponents($plugin, $plugin instanceof ErrorPlugin ? 'default' : $view);
		$this->panel->setRenderedComponents($components);
		$baseUrl = Url::get()->getBaseUrl();
		$baseUrlPrefix = $baseUrl . '/' . Configuration::get()->getBaseUriEscaped();

		ob_start(static function () {
		});

		$args = [
			'isDebug' => AdminBar::getBar()->isDebugMode(),
			'basePath' => $baseUrl,
			'staticAssets' => array_merge($this->context->getCustomGlobalAssetPaths(), [
				new CmsSimpleStaticAsset('js', $baseUrlPrefix . '/cms-web-loader/' . $this->context->getPluginNameByType($plugin) . '.js'),
				new CmsSimpleStaticAsset('js', $baseUrlPrefix . '/assets/core.js'),
			]),
			'title' => $plugin instanceof BasePlugin ? $plugin->getTitle() : null,
			'content' => $this->renderContentCode($plugin, $pluginName, $view, $components),
			'locale' => $this->context->getLocale(),
			'menu' => [
				'dashboardLink' => $this->context->getContainer()->getLinkGenerator()->linkHomepage(),
				'isDashboard' => $pluginName === 'Homepage' && $view === 'default',
				'structure' => $this->menuManager->getItems(),
				'activeKey' => $this->context->getPluginKey($plugin),
			],
			'globalSettings' => [
				'startWeekday' => 0,
			],
			'settings' => $this->settings->getSystemInfo()->toArray(),
		];

		/** @phpstan-ignore-next-line */
		extract($args, EXTR_OVERWRITE);

		try {
			require __DIR__ . '/../../template/@layout.phtml';

			return (string) ob_get_clean();
		} catch (\Throwable $e) {
			ob_end_clean();
			throw new \RuntimeException($e->getMessage(), 500, $e);
		}
	}


	public function renderNeedOtpAuth(): string
	{
		return '<link type="text/css" rel="stylesheet" href="https://cdn.baraja.cz/bootstrap/bootstrap.min.css">
<div style="margin:6em auto;max-width:32em;padding:0 1em">
	<div class="row mb-4">
		<div class="col-sm-3"><img src="https://cdn.baraja.cz/icon/warning-triangle.png" alt="Warning"></div>
		<div class="col text-right"><h1>Permission denied</h1></div>
	</div>
	<p>To visit this page, you must first verify through 2-step verification.</p>
	<p class="text-secondary">That’s all we know.</p>
	<p><a href="' . $this->context->getContainer()->getLinkGenerator()->link('Cms:signOut') . '" class="btn btn-primary">Sign out</a></p>
</div>';
	}


	public function renderPermissionDenied(): string
	{
		return '<link type="text/css" rel="stylesheet" href="https://cdn.baraja.cz/bootstrap/bootstrap.min.css">
<div class="card" style="margin:6em auto;max-width:32em;padding:0 1em">
	<div class="card-body container-fluid">
		<div class="row mb-4">
			<div class="col-sm-2"><img src="https://cdn.baraja.cz/icon/warning-triangle.png" alt="Warning"></div>
			<div class="col text-right"><h1>Permission denied</h1></div>
		</div>
		<p>Open this page is not permitted for your account.</p>
		<p class="text-secondary">That’s all we know.</p>
		<p><a href="' . $this->context->getContainer()->getLinkGenerator()->link('Cms:signOut') . '" class="btn btn-primary">Sign out</a></p>
	</div>
</div>';
	}


	public function renderLoginTemplate(string $locale): string
	{
		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/login.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'availableLocales' => Configuration::get()->getSupportedLocales(),
					'projectName' => $this->context->getConfiguration()->get('name'),
					'locale' => $locale,
				],
			);
	}


	public function renderLoginOtpAuthTemplate(string $locale): string
	{
		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/loginOthAuth.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'availableLocales' => Configuration::get()->getSupportedLocales(),
					'projectName' => $this->context->getConfiguration()->get('name'),
					'locale' => $locale,
				],
			);
	}


	public function renderResetPasswordTemplate(string $token, string $locale): string
	{
		/** @var UserResetPasswordRequestRepository $repository */
		$repository = $this->context->getEntityManager()->getRepository(UserResetPasswordRequestRepository::class);

		try {
			$request = $repository->getByToken($token);
			if ($request->isExpired() === true) {
				throw new \InvalidArgumentException('Token has been expired.');
			}
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException) {
			return 'The password change token does not exist. Please request a new token again.';
		}

		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/reset-password.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'loginUrl' => $this->context->getContainer()->getLinkGenerator()->linkHomepage(),
					'locale' => $locale,
					'username' => $request->getUser()->getUsername(),
					'token' => $request->getToken(),
				],
			);
	}


	public function renderSetUserPasswordTemplate(string $userId, string $locale): string
	{
		try {
			/** @var CmsUser $user */
			$user = $this->context->getUserManager()->get()->getDefaultUserRepository()
				->createQueryBuilder('user')
				->where('user.id = :userId')
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException) {
			return 'This link does not work. For more information, please contact your project administrator.';
		}
		if ($user->getPassword() !== '---empty-password---') {
			return 'Settings link is invalid, because password was changed.';
		}

		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/set-user-password.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'loginUrl' => $this->context->getContainer()->getLinkGenerator()->linkHomepage(),
					'locale' => $locale,
					'userId' => $user->getId(),
					'username' => $user->getUsername(),
				],
			);
	}


	/**
	 * @param array<int, PluginComponent> $components
	 */
	private function renderContentCode(Plugin $plugin, string $pluginName, string $view, array $components): string
	{
		if (\count($components) === 0) {
			$return = null;
		} elseif ($view === 'detail') {
			$componentsData = [];

			$first = true;
			foreach ($components as $key => $component) {
				if ($this->context->checkPermission($pluginName, $component->getName())) {
					$active = $first === true;
					$componentsData[] = '<b-tab lazy @click="$emit(\'activeMe\')" '
						. 'id="cms-component-' . Helpers::escapeHtmlAttr(($key + 1) . '-' . $component->getName()) . '" '
						. 'title="' . Helpers::escapeHtmlAttr($component->getTab()) . '"'
						. ($active ? ' active' : '')
						. '>' . "\n" . $this->renderVueComponent($component, $plugin) . "\n" . '</b-tab>';
					$first = false;
				}
			}

			$componentParameters = '';
			if ($plugin instanceof BasePlugin) {
				$componentParameters = ' title="' . (($title = $plugin->getTitle()) !== null ? Helpers::escapeHtmlAttr($title) : $plugin->getName()) . '"'
					. (($subtitle = $plugin->getSubtitle()) !== null ? ' subtitle="' . Helpers::escapeHtmlAttr($subtitle) . '"' : '')
					. (($breadcrumb = $plugin->getBreadcrumb()) !== [] ? ' :breadcrumb="' . Helpers::escapeHtmlAttr($this->renderSimpleComponents($breadcrumb)) . '"' : '')
					. (($buttons = $plugin->getButtons()) !== [] ? ' :buttons="' . Helpers::escapeHtmlAttr($this->renderSimpleComponents($buttons)) . '"' : '')
					. (($contextMenu = $plugin->getContextMenu()) !== [] ? ' :context-menu="' . Helpers::escapeHtmlAttr($this->renderSimpleComponents($contextMenu)) . '"' : '')
					. (($linkBack = $plugin->getLinkBack()) !== null ? ' link-back="' . Helpers::escapeHtmlAttr($linkBack) . '"' : '')
					. ($plugin->isSaveAll() === true ? ' :save-all="true"' : '')
					. (($smartComponent = $plugin->getSmartControlComponentName()) !== null
						? ' smart-component="' . Helpers::escapeHtmlAttr($smartComponent) . '"'
						. ' :smart-component-params="' . Helpers::escapeHtmlAttr((string) json_encode($plugin->getSmartControlComponentParams())) . '"' : '');
			}

			$return = '<div class="px-4 py-2">' . "\n"
				. '<!-- Main content (tabs) -->' . "\n"
				. '<cms-detail' . $componentParameters . '>' . "\n"
				. '<b-tabs no-fade>' . "\n\n" . implode("\n", $componentsData) . "\n\n" . '</b-tabs>' . "\n"
				. '</cms-detail>' . "\n"
				. '</div>';
		} elseif (\count($components) === 1) {
			if ($this->context->checkPermission($pluginName, $components[0]->getName())) {
				$return = $this->renderVueComponent($components[0], $plugin);
			} else {
				$return = null;
			}
		} else {
			$return = null;
		}

		return $return ?? '<div class="px-4 py-2"><i>No&nbsp;components found.</i></div>';
	}


	/**
	 * Render given component entity to Vue component (HTML element with all parameters).
	 * For example: '<user-detail id="1234"></user-detail>'.
	 */
	private function renderVueComponent(PluginComponent $component, Plugin $plugin): string
	{
		try {
			return '<!-- component ' . Helpers::escapeHtmlComment($component->getKey()) . ' -->' . "\n"
				. $component->render($this->context->getRequest(), $plugin);
		} catch (\Throwable $e) {
			$this->context->getContainer()->getLogger()->critical($e->getMessage(), ['exception' => $e]);

			return '<!-- can not render component! -->'
				. '<div class="alert alert-danger">'
				. '<p class="mb-0">Can not render component <b>'
				. htmlspecialchars($component->getTab(), ENT_QUOTES)
				. '</b>.'
				. ($e instanceof \InvalidArgumentException
					? '<br>' . htmlspecialchars($e->getMessage(), ENT_QUOTES)
					: '')
				. '</p>'
				. '</div>';
		}
	}


	/**
	 * @param array<int, SimpleComponent> $simpleComponents
	 */
	private function renderSimpleComponents(array $simpleComponents): string
	{
		$return = [];
		foreach ($simpleComponents as $component) {
			$return[] = $component->toArray();
		}

		return json_encode($return, JSON_THROW_ON_ERROR);
	}
}
