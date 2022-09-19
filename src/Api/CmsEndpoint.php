<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\AdminBar\AdminBar;
use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Api\DTO\CmsGlobalSettingsResponse;
use Baraja\Cms\Api\DTO\CmsPluginResponse;
use Baraja\Cms\Api\DTO\CmsSettingsResponse;
use Baraja\Cms\Configuration;
use Baraja\Cms\ContextAccessor;
use Baraja\Cms\Helpers;
use Baraja\Cms\MenuManager;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Proxy\GlobalAsset\CmsSimpleStaticAsset;
use Baraja\Cms\Session;
use Baraja\Cms\Settings;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserResetPasswordRequest;
use Baraja\Cms\User\Entity\UserResetPasswordRequestRepository;
use Baraja\Cms\User\UserManager;
use Baraja\Markdown\CommonMarkRenderer;
use Baraja\Plugin\BasePlugin;
use Baraja\StructuredApi\Attributes\PublicEndpoint;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;

#[PublicEndpoint]
final class CmsEndpoint extends BaseEndpoint
{
	public function __construct(
		private UserManager $userManager,
		private CloudManager $cloudManager,
		private Settings $settings,
		private MenuManager $menuManager,
		private EntityManagerInterface $entityManager,
		private ContextAccessor $contextAccessor,
		private CommonMarkRenderer $commonMarkRenderer,
	) {
	}


	/**
	 * This method is called automatically at regular intervals by ajax.
	 * It maintains a connection with the user session and passes basic system states
	 * from the backend to the thin javascript client on the user's side.
	 */
	public function actionKeepConnection(): void
	{
		$this->sendJson([
			'login' => $this->contextAccessor
				->get()
				->getIntegrityWorkflow()
				->run(true),
		]);
	}


	public function actionSettings(): CmsSettingsResponse
	{
		$context = $this->contextAccessor->get();

		return new CmsSettingsResponse(
			isDebug: AdminBar::getBar()->isDebugMode(),
			basePath: Url::get()->getBaseUrl(),
			staticAssets: $context->getCustomGlobalAssetPaths(),
			projectName: 'Project name',
			locale: $context->getLocale(),
			menu: $this->menuManager->getItems(),
			globalSettings: new CmsGlobalSettingsResponse(startWeekday: 0),
			settings: $this->settings->getSystemInfo()->toArray(),
			currentVersion: $this->settings->getCurrentVersion(),
			installationHash: substr(md5(sprintf('%s|%s', __FILE__, $this->settings->getCurrentVersion())), 0, 8),
		);
	}


	public function actionPlugin(string $name, ?string $locale = null): CmsPluginResponse
	{
		$context = $this->contextAccessor->get();
		if ($locale !== null) {
			$context->setLocale($locale);
		}
		try {
			$plugin = $context->getPluginByName($name);
			if ($context->checkPermission($name) === false) {
				$this->sendError('Permission denied.');
			}
		} catch (\RuntimeException | \InvalidArgumentException $e) {
			if ($e->getCode() !== 404) {
				$context->getContainer()->getLogger()->warning($e->getMessage(), ['exception' => $e]);
			}
			$plugin = $context->getPluginByType(ErrorPlugin::class);
		}
		$baseUrl = Url::get()->getBaseUrl();
		$baseUrlPrefix = $baseUrl . '/' . Configuration::get()->getBaseUriEscaped();
		$components = $context->getComponents($plugin, $plugin instanceof ErrorPlugin ? 'default' : null);

		return new CmsPluginResponse(
			staticAssets: [
				new CmsSimpleStaticAsset('js', $baseUrlPrefix . '/cms-web-loader/' . $context->getPluginNameByType($plugin) . '.js'),
				new CmsSimpleStaticAsset('js', $baseUrlPrefix . '/assets/core.js'),
			],
			title: $plugin instanceof BasePlugin ? $plugin->getTitle() : null,
			activeKey: $context->getPluginKey($plugin),
			components: $components,
		);
	}


	public function postSign(string $locale, string $username, string $password, bool $remember = false): void
	{
		if ($username === '' || $password === '') {
			$this->sendError('Empty username or password.');
		}
		try {
			$this->userManager->authenticate($username, $password, $remember);
		} catch (AuthenticationException $e) {
			$code = $e->getCode();
			if (in_array($code, [Authenticator::IDENTITY_NOT_FOUND, Authenticator::INVALID_CREDENTIAL, Authenticator::FAILURE], true)) {
				$this->sendError($e->getMessage());
			} elseif ($code === Authenticator::NOT_APPROVED) {
				$reason = $e->getMessage();
				$this->sendError(
					'The user has been assigned a permanent block. Please contact your administrator.'
					. ($reason !== '' ? ' Block reason: ' . $reason : ''),
				);
			} else {
				$this->sendError('Wrong username or password.');
			}
		} catch (\Throwable $e) {
			$this->contextAccessor->get()->getContainer()->getLogger()->critical($e->getMessage(), ['exception' => $e]);
			$this->sendError('Internal authentication error. Your account has been broken. Please contact your administrator or Baraja support team.');
		}

		$this->sendOk([
			'loginStatus' => true,
		]);
	}


	public function postCheckOtpCode(string $locale, string $code): void
	{
		$userEntity = $this->getUserEntity();
		if ($userEntity === null) {
			$this->sendError('User is not logged in.');
		}
		$id = $userEntity->getId();
		assert(is_numeric($id));
		$id = (int) $id;
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%d" does not exist.', $id));
		}
		$otpCode = $user->getOtpCode();
		if ($otpCode === null) {
			$this->sendError(sprintf('OTP code for user "%d" does not exist.', $user->getId()));
		}
		if (Helpers::checkAuthenticatorOtpCodeManually($otpCode, (int) $code) === true) {
			Session::remove(Session::WORKFLOW_NEED_OTP_AUTH);
			$this->sendOk();
		}
		$this->sendError('OTP code is invalid. Please try again.');
	}


	public function postForgotPassword(string $locale, string $username): void
	{
		try {
			/** @var CmsUser $user */
			$user = $this->userManager->getDefaultUserRepository()
				->createQueryBuilder('user')
				->where('user.username = :username')
				->orWhere('user.email = :email')
				->setParameter('username', $username)
				->setParameter('email', $username)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			if (!$user instanceof User) {
				$this->sendError('Reset password is available only for system CMS Users. Please contact your administrator');
			}

			$request = new UserResetPasswordRequest($user, '3 hours');
			$this->entityManager->persist($request);
			$this->entityManager->flush();

			$this->cloudManager->callRequest('cloud/forgot-password', [
				'domain' => Url::get()->getNetteUrl()->getDomain(3),
				'resetLink' => Url::get()->getBaseUrl() . '/admin/reset-password?token=' . urlencode($request->getToken()),
				'locale' => $locale,
				'username' => $username,
				'email' => $user->getEmail(),
				'expireDate' => $request->getExpireDate()->format('d. m. Y, H:i:s'),
			]);
		} catch (NoResultException | NonUniqueResultException) {
			// Silence is golden.
		}

		$this->sendOk();
	}


	public function postForgotUsername(string $locale, string $realName): void
	{
		if (preg_match('/^(\S+)\s+(\S+)$/', trim($realName), $parser) === 1) {
			try {
				/** @var CmsUser $user */
				$user = $this->userManager->getDefaultUserRepository()
					->createQueryBuilder('user')
					->where('user.firstName = :firstName')
					->andWhere('user.lastName = :lastName')
					->setParameter('firstName', $parser[1])
					->setParameter('lastName', $parser[2])
					->setMaxResults(1)
					->getQuery()
					->getSingleResult();

				$this->cloudManager->callRequest('cloud/forgot-username', [
					'domain' => Url::get()->getNetteUrl()->getDomain(3),
					'locale' => $locale,
					'username' => $user->getUsername(),
					'email' => $user->getEmail(),
					'loginUrl' => Url::get()->getBaseUrl() . '/' . Configuration::get()->getBaseUri(),
				]);
			} catch (NoResultException | NonUniqueResultException) {
				// Silence is golden.
			}
		} else {
			$this->sendError('Invalid name "' . $realName . '".');
		}

		$this->sendOk();
	}


	public function postReportProblem(string $locale, string $description, string $username): void
	{
		$adminEmail = $this->settings->getAdminEmail();
		if ($adminEmail === null) {
			$this->sendError('Admin e-mail does not exist. Can not report your problem right now.');
		}

		$this->cloudManager->callRequest('cloud/report-problem', [
			'domain' => Url::get()->getNetteUrl()->getDomain(3),
			'locale' => $locale,
			'adminEmail' => $adminEmail,
			'description' => $description,
			'username' => $username,
		]);

		$this->sendOk();
	}


	public function postForgotPasswordSetNew(string $token, string $locale, string $password): void
	{
		/** @var UserResetPasswordRequestRepository $repository */
		$repository = $this->entityManager->getRepository(UserResetPasswordRequest::class);

		try {
			$request = $repository->getByToken($token);
			if ($request->isExpired() === true) {
				$this->sendError('Token has been expired.');
			}
			try {
				$request->getUser()->setPassword($password);
			} catch (\InvalidArgumentException $e) {
				$this->sendError('Password can not be changed. Reason: ' . $e->getMessage());
			}
			$request->setExpired();
			$this->entityManager->flush();

			$this->cloudManager->callRequest('cloud/forgot-password-has-been-changed', [
				'domain' => Url::get()->getNetteUrl()->getDomain(3),
				'locale' => $locale,
				'username' => $request->getUser()->getUsername(),
				'email' => $request->getUser()->getEmail(),
			]);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('The password change token does not exist. Please request a new token again.');
		}

		$this->sendOk();
	}


	public function postSetUserPassword(string $locale, int $userId, string $password): void
	{
		try {
			/** @var CmsUser $user */
			$user = $this->userManager->getDefaultUserRepository()
				->createQueryBuilder('user')
				->where('user.id = :userId')
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException) {
			$this->sendError('User "' . $userId . '" does not exist.');
		}
		try {
			$user->setPassword($password);
		} catch (\InvalidArgumentException $e) {
			$this->sendError('Password can not be changed. Reason: ' . $e->getMessage());
		}
		$this->entityManager->flush();
		$this->sendOk();
	}


	public function postRenderEditorPreview(string $haystack): void
	{
		$this->sendJson(
			[
				'html' => $this->commonMarkRenderer->render($haystack),
			],
		);
	}
}
