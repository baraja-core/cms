<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Helpers;
use Baraja\Cms\Settings;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserResetPasswordRequest;
use Baraja\Cms\User\UserManager;
use Baraja\Doctrine\EntityManager;
use Baraja\StructuredApi\BaseEndpoint;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Http\Url;
use Nette\Security\AuthenticationException;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @public
 */
final class CmsEndpoint extends BaseEndpoint
{
	private UserManager $userManager;

	private CloudManager $cloudManager;

	private Settings $settings;

	private EntityManager $entityManager;


	public function __construct(UserManager $userManager, CloudManager $cloudManager, Settings $settings, EntityManager $entityManager)
	{
		$this->userManager = $userManager;
		$this->cloudManager = $cloudManager;
		$this->settings = $settings;
		$this->entityManager = $entityManager;
	}


	public function postSign(string $locale, string $username, string $password, bool $remember = false): void
	{
		if ($username === '' || $password === '') {
			$this->sendError('Empty username or password.');
		}
		try {
			$user = $this->userManager->login($username, $password, $remember);
		} catch (AuthenticationException $e) {
			$this->sendError('Wrong username or password.');

			return;
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::CRITICAL);
			$this->sendError('Internal authentication error. Your account has been broken. Please contact your administrator or Baraja support team.');

			return;
		}

		// TODO: Verify after refreshing the page
		$needOauth = false;
		if ($user instanceof User && $user->getOtpCode() !== null) { // need OTP authentication
			$this->getUser()->getStorage()->setAuthenticated(false);
			$needOauth = $user->getOtpCode() !== null;
		}

		$this->sendOk([
			'loginStatus' => true,
			'needOauth' => $needOauth,
		]);
	}


	public function postCheckOauthCode(string $locale, string $code): void
	{
		if (($userEntity = $this->getUserEntity()) === null) {
			$this->sendError('User is not logged in.');
		}
		try {
			$user = $this->userManager->getUserById($userEntity->getId());
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('User "' . $userEntity->getId() . '" does not exist.');

			return;
		}
		if (Helpers::checkAuthenticatorOtpCodeManually($user->getOtpCode(), (int) $code) === true) {
			$this->getUser()->getStorage()->setAuthenticated(true);
			$this->sendOk();
		} else {
			$this->sendError('OTP code is invalid. Please try again.');
		}
	}


	public function postForgotPassword(string $locale, string $username): void
	{
		try {
			/** @var \Baraja\Cms\User\Entity\User $user */
			$user = $this->entityManager->getRepository(\Baraja\Cms\User\Entity\User::class)
				->createQueryBuilder('user')
				->where('user.username = :username')
				->orWhere('user.email = :email')
				->setParameters([
					'username' => $username,
					'email' => $username,
				])
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			$this->entityManager->persist($request = new UserResetPasswordRequest($user, '3 hours'))->flush($request);

			$this->cloudManager->callRequest('cloud/forgot-password', [
				'domain' => (new Url(Helpers::getCurrentUrl()))->getDomain(3),
				'resetLink' => Helpers::getBaseUrl() . '/admin/reset-password?token=' . urlencode($request->getToken()),
				'locale' => $locale,
				'username' => $username,
				'email' => $user->getEmail(),
				'expireDate' => $request->getExpireDate()->format('d. m. Y, H:i:s'),
			]);
		} catch (NoResultException | NonUniqueResultException $e) {
		}

		$this->sendOk();
	}


	public function postForgotUsername(string $locale, string $realName): void
	{
		if (preg_match('/^(\S+)\s+(\S+)$/', trim($realName), $parser)) {
			try {
				/** @var \Baraja\Cms\User\Entity\User $user */
				$user = $this->entityManager->getRepository(\Baraja\Cms\User\Entity\User::class)
					->createQueryBuilder('user')
					->where('user.firstName = :firstName')
					->andWhere('user.lastName = :lastName')
					->setParameters([
						'firstName' => $parser[1],
						'lastName' => $parser[2],
					])
					->setMaxResults(1)
					->getQuery()
					->getSingleResult();

				$this->cloudManager->callRequest('cloud/forgot-username', [
					'domain' => (new Url(Helpers::getCurrentUrl()))->getDomain(3),
					'locale' => $locale,
					'username' => $user->getUsername(),
					'email' => $user->getEmail(),
					'loginUrl' => Helpers::getBaseUrl() . '/admin',
				]);
			} catch (NoResultException | NonUniqueResultException $e) {
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
			'domain' => (new Url(Helpers::getCurrentUrl()))->getDomain(3),
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
				'domain' => (new Url(Helpers::getCurrentUrl()))->getDomain(3),
				'locale' => $locale,
				'username' => $request->getUser()->getUsername(),
				'email' => $request->getUser()->getEmail(),
			]);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('The password change token does not exist. Please request a new token again.');
		}

		$this->sendOk();
	}


	public function postSetUserPassword(string $locale, string $userId, string $password): void
	{
		try {
			/** @var User $user */
			$user = $this->entityManager->getRepository(User::class)
				->createQueryBuilder('user')
				->where('user.id = :userId')
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException $e) {
			$this->sendError('User "' . $userId . '" does not exist.');

			return;
		}

		$user->setPassword($password);
		$this->entityManager->flush($user);

		$this->sendOk();
	}
}
