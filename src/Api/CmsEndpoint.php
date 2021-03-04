<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Helpers;
use Baraja\Cms\Settings;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserResetPasswordRequest;
use Baraja\Cms\User\UserManager;
use Baraja\Doctrine\EntityManager;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Security\AuthenticationException;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @public
 */
final class CmsEndpoint extends BaseEndpoint
{
	public function __construct(
		private UserManager $userManager,
		private CloudManager $cloudManager,
		private Settings $settings,
		private EntityManager $entityManager
	) {
	}


	public function postSign(string $locale, string $username, string $password, bool $remember = false): void
	{
		if ($username === '' || $password === '') {
			$this->sendError('Empty username or password.');
		}
		try {
			$user = $this->userManager->authenticate($username, $password, $remember);
		} catch (AuthenticationException) {
			$this->sendError('Wrong username or password.');

			return;
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::CRITICAL);
			$this->sendError('Internal authentication error. Your account has been broken. Please contact your administrator or Baraja support team.');

			return;
		}

		$needOauth = false;
		if ($user instanceof CmsUser && $user->getOtpCode() !== null) { // need OTP authentication
			$this->userManager->logout();
			$needOauth = $user->getOtpCode() !== null;
		}

		$this->sendOk([
			'loginStatus' => true,
			'needOauth' => $needOauth,
		]);
	}


	public function postCheckOauthCode(
		string $locale,
		string $code,
		string $username,
		string $password,
		bool $remember = false
	): void {
		if (($userEntity = $this->getUserEntity()) === null) {
			$this->sendError('User is not logged in.');

			return;
		}
		try {
			$user = $this->userManager->getUserById($userEntity->getId());
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $userEntity->getId() . '" does not exist.');

			return;
		}
		if (($otpCode = $user->getOtpCode()) === null) {
			$this->sendError('OTP code for user "' . $userEntity->getId() . '" does not exist.');

			return;
		}
		if (Helpers::checkAuthenticatorOtpCodeManually($otpCode, (int) $code) === true) {
			$this->userManager->authenticate($username, $password, $remember);
			$this->sendOk();
		} else {
			$this->sendError('OTP code is invalid. Please try again.');
		}
	}


	public function postForgotPassword(string $locale, string $username): void
	{
		try {
			/** @var CmsUser $user */
			$user = $this->entityManager->getRepository($this->userManager->getDefaultEntity())
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

				return;
			}

			$this->entityManager->persist($request = new UserResetPasswordRequest($user, '3 hours'));
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
		}

		$this->sendOk();
	}


	public function postForgotUsername(string $locale, string $realName): void
	{
		if (preg_match('/^(\S+)\s+(\S+)$/', trim($realName), $parser)) {
			try {
				/** @var CmsUser $user */
				$user = $this->entityManager->getRepository($this->userManager->getDefaultEntity())
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
					'loginUrl' => Url::get()->getBaseUrl() . '/admin',
				]);
			} catch (NoResultException | NonUniqueResultException) {
			}
		} else {
			$this->sendError('Invalid name "' . $realName . '".');
		}

		$this->sendOk();
	}


	public function postReportProblem(string $locale, string $description, string $username): void
	{
		if (($adminEmail = $this->settings->getAdminEmail()) === null) {
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
		try {
			/** @var UserResetPasswordRequest $request */
			$request = $this->entityManager->getRepository(UserResetPasswordRequest::class)
				->createQueryBuilder('resetRequest')
				->select('resetRequest, user')
				->leftJoin('resetRequest.user', 'user')
				->where('resetRequest.token = :token')
				->setParameter('token', $token)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			if ($request->isExpired() === true) {
				$this->sendError('Token has been expired.');
			}

			$request->getUser()->setPassword($password);
			$request->setExpired();

			$this->entityManager->flush([$request, $request->getUser()]);

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


	public function postSetUserPassword(string $locale, string $userId, string $password): void
	{
		try {
			/** @var CmsUser $user */
			$user = $this->entityManager->getRepository($this->userManager->getDefaultEntity())
				->createQueryBuilder('user')
				->where('user.id = :userId')
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException) {
			$this->sendError('User "' . $userId . '" does not exist.');

			return;
		}

		$user->setPassword($password);
		$this->entityManager->flush();
		$this->sendOk();
	}
}
