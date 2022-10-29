<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\CAS\CasHelper;
use Baraja\CAS\Entity\OrganisationMember;
use Baraja\CAS\Entity\Role;
use Baraja\CAS\Entity\User;
use Baraja\CAS\Entity\UserEmail;
use Baraja\CAS\Entity\UserLogin;
use Baraja\CAS\Entity\UserMeta;
use Baraja\CAS\Repository\UserMetaRepository;
use Baraja\CAS\Service\MemberRoleManager;
use Baraja\CAS\Service\UserMetaManager;
use Baraja\Cms\Configuration;
use Baraja\Cms\Context;
use Baraja\Cms\Settings;
use Baraja\Doctrine\EntityManager;
use Baraja\Plugin\PluginManager;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Http\FileUpload;
use Nette\Http\Request;
use Nette\NotSupportedException;
use Nette\Utils\Callback;
use Nette\Utils\Paginator;
use Nette\Utils\Random;
use Nette\Utils\Strings;

final class UserEndpoint extends BaseEndpoint
{
	private Cache $cache;


	public function __construct(
		private UserMetaManager $userMetaManager,
		private EntityManager $entityManager,
		private CloudManager $cloudManager,
		private PluginManager $pluginManager,
		private MemberRoleManager $memberRoleManager,
		private Settings $settings,
		private Context $context,
		private Request $httpRequest,
		Storage $cacheStorage,
	) {
		$this->cache = new Cache($cacheStorage, 'user-endpoint');
	}


	/**
	 * Returns all users
	 *
	 * @param string|null $active String 'active' => show only active users, 'deleted' => show only deleted users, null => all users
	 */
	public function actionDefault(
		int $page = 1,
		int $limit = 32,
		?string $role = null,
		?string $query = null,
		?string $active = null,
	): void {
		$currentUserId = $this->user->getId();
		$currentUser = $this->user->getIdentityEntity();
		assert($currentUser !== null);
		$count = $this->user->getUserStorage()->getUserRepository()->getCountUsers();

		$selection = $this->entityManager
			->getRepository(User::class)
			->createQueryBuilder('user');

		if ($active !== null) {
			$selection->andWhere('user.active = ' . ($active === 'active' ? 'TRUE' : 'FALSE'));
		}
		$allRoles = ($role !== null || $count <= 5000)
			? $this->getAllRoles()
			: [];
		if ($role !== null) {
			if (isset($allRoles[$role]) === false) {
				$this->sendError('Role "' . $role . '" does not exist. Did you mean "' . implode('", "', $allRoles) . '"?');
			}
			$selection->andWhere('user.roles LIKE :role')
				->setParameter('role', '%"' . $role . '"%');
		}
		if ($query !== null) {
			if (is_numeric($query) && $query >= 1) {
				$selection->andWhere('(user.id = :userId OR user.id LIKE :userIdStart)')
					->setParameter('userId', $query)
					->setParameter('userIdStart', ((int) $query) . '%');
			}
			$orx = $selection->expr()->orX();
			$isMysql = ($this->entityManager->getConnection()->getParams()['driver'] ?? '') === 'pdo_mysql';
			$query = trim((string) preg_replace('/\s+/', ' ', $query));
			if (preg_match('/^(\S+)\s+(.+)$/', $query, $queryParser) === 1) {
				$orx->add('user.username LIKE :firstName');
				$orx->add('user.username LIKE :lastName');
				$orx->add('user.firstName LIKE :firstName');
				$orx->add('user.lastName LIKE :lastName');
				if ($isMysql) {
					$orx->add('user.emails LIKE :firstName');
					$orx->add('user.emails LIKE :lastName');
				}
				$selection->andWhere($orx)
					->setParameter('firstName', $queryParser[1] . '%')
					->setParameter('lastName', $queryParser[2] . '%');
			} else {
				$orx->add('user.username LIKE :query');
				$orx->add('user.firstName LIKE :query');
				$orx->add('user.lastName LIKE :query');
				$orx->add('user.phone LIKE :query');
				if ($isMysql) {
					$orx->add('user.emails LIKE :query');
				}
				$selection->andWhere($orx)
					->setParameter('query', '%' . $query . '%');
			}
		}

		/** @var array<int, array{
		 *     id: int,
		 *     firstName: string,
		 *     lastName: string,
		 *     password: string,
		 *     email: string,
		 *     phone: string|null,
		 *     roles: array<int, string>,
		 *     active: bool,
		 *     registerDate: \DateTime,
		 *     otpCode: string|null
		 * }> $users
		 */
		$users = $selection->select('PARTIAL user.{id, firstName, lastName, password, email, phone, roles, active, registerDate, otpCode}')
			->setMaxResults($limit)
			->setFirstResult(($page - 1) * $limit)
			->addOrderBy('user.active', 'DESC')
			->addOrderBy('user.createDate', 'DESC')
			->getQuery()
			->getArrayResult();

		$metaRepository = $this->entityManager->getRepository(UserMeta::class);
		assert($metaRepository instanceof UserMetaRepository);

		$metaToUser = $metaRepository->loadByUsersAndKeys(
			array_map(static fn(array $user): int => $user['id'], $users),
			['blocked', 'block-reason', 'last-activity'],
		);

		$return = [];
		foreach ($users as $user) {
			$return[] = [
				'id' => $user['id'],
				'name' => (static function () use ($user): ?string {
					$return = trim($user['firstName'] . ' ' . $user['lastName']);
					if ($return === '') {
						$return = explode('@', $user['email'])[0] ?? '';
					}
					$return = Strings::firstUpper($return);

					return $return !== '' ? $return : null;
				})(),
				'email' => $user['email'],
				'roles' => $user['roles'],
				'phone' => $user['phone'],
				'avatarUrl' => 'https://cdn.baraja.cz/avatar/' . md5($user['email']) . '.png',
				'registerDate' => $user['registerDate'],
				'options' => [
					'title' => null,
					'active' => $user['active'],
					'2fa' => $user['otpCode'] !== null,
					'verifying' => $user['password'] === '---empty-password---',
					'blocked' => ($metaToUser[$user['id']]['blocked'] ?? '') === 'true',
					'blockedReason' => $metaToUser[$user['id']]['block-reason'] ?? null,
					'online' => (static fn(string $lastActivity): bool => (new \DateTime($lastActivity))->getTimestamp() + 30 >= time())($metaToUser[$user['id']]['last-activity'] ?? 'yesterday'),
				],
			];
		}
		if ($count <= 5000) {
			$allUsers = $this->getAllUsers();
			$activeCount = \count(array_filter($allUsers, static fn(array $item): bool => $item['active'] === true));
			$deletedCount = \count(array_filter($allUsers, static fn(array $item): bool => $item['active'] === false));
		} else {
			$activeCount = 'many';
			$deletedCount = 'many';
		}

		$this->sendJson([
			'list' => $return,
			'roles' => $this->formatBootstrapSelectArray($allRoles),
			'isCurrentUserUsing2fa' => $currentUser->getOtpCode() !== null,
			'currentUserId' => $currentUserId,
			'statusCount' => [
				'all' => $count,
				'active' => $activeCount,
				'deleted' => $deletedCount,
			],
			'paginator' => (new Paginator)
				->setItemCount($count)
				->setItemsPerPage($limit)
				->setPage($page),
		]);
	}


	public function createDefault(
		string $fullName,
		string $email,
		?string $phone = null,
		?string $password = null,
	): void {
		if ($this->user->getUserStorage()->userExist($email)) {
			$this->sendError(sprintf('User "%s" already exist.', $email));
		}
		try {
			$user = $this->user->createUser(
				email: $email,
				password: $password,
				phone: $phone,
			);
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());
		} catch (\Throwable $e) {
			$this->context->getContainer()->getLogger()->critical($e->getMessage(), ['exception' => $e]);
			$this->sendError('Can not create user because user storage is broken.');
		}

		$this->setRealUserName($user, $fullName);
		$this->entityManager->flush();

		$this->cloudManager->callRequest('cloud/confirm-user-registration', [
			'domain' => Url::get()->getNetteUrl()->getDomain(3),
			'locale' => 'cs',
			'email' => $email,
			'setPasswordUrl' => $password === null || $password === ''
				? null
				: Url::get()->getBaseUrl()
				. '/' . Configuration::get()->getBaseUri()
				. '/set-user-password?userId=' . $user->getId(),
			'loginUrl' => Url::get()->getBaseUrl() . '/' . Configuration::get()->getBaseUri(),
		], 'POST');

		$this->sendOk([
			'id' => $user->getId(),
		]);
	}


	public function deleteDefault(int $id): void
	{
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$member->getUser()->setActive(false);
		$this->entityManager->flush();
		$this->flashMessage('User was marked as deleted.', 'success');
		$this->sendOk();
	}


	public function actionRevertUser(int $id): void
	{
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$member->getUser()->setActive(true);
		$this->entityManager->flush();
		$this->flashMessage('User was reverted to active state.', 'success');
		$this->sendOk();
	}


	/**
	 * Check if user already exist.
	 */
	public function actionValidateUser(string $email): void
	{
		$this->sendJson([
			'exist' => $this->user->getUserStorage()->userExist($email),
		]);
	}


	public function actionOverview(int $id): void
	{
		try {
			$user = $this->user->getUserStorage()->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$this->sendJson([
			'form' => [
				'username' => $user->getUsername(),
				'fullName' => $user->getName(),
				'email' => $user->getEmail(),
				'phone' => (static function (?string $phone): array {
					if ($phone === null) {
						return [
							'original' => '',
							'region' => 420,
							'phone' => '',
						];
					}
					if (preg_match('/^\+(?<region>\d+)\s*(?<phone>.+)$/', $phone, $parser) === 1) {
						return [
							'original' => $phone,
							'region' => (int) $parser['region'],
							'phone' => $parser['phone'],
						];
					}

					return [
						'original' => $phone,
						'region' => 420,
						'phone' => $phone,
					];
				})($user->getPhone()),
				'avatarUrl' => $user->getAvatarUrl(),
			],
			'avatarUrl' => $user->getAvatarUrl(),
			'created' => $user->getCreateDate(),
			'meta' => $user->getMetaData(),
		]);
	}


	public function postOverview(int $id, string $username, string $email, string $name): void
	{
		try {
			$user = $this->getMemberById($id)->getUser();
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$this->setRealUserName($user, $name);
		$user->setUsername($username);

		if ($user->getEmail() !== $email) {
			try {
				$emailEntity = new UserEmail($user, $email);
				$this->entityManager->persist($emailEntity);
				$user->addEmail($emailEntity);
			} catch (\InvalidArgumentException $e) {
				$this->sendError($e->getMessage());
			}
		}

		$this->entityManager->flush();
		$this->flashMessage('User settings was saved.', 'success');
		$this->sendOk();
	}


	public function actionRandomPassword(): void
	{
		$this->sendJson([
			'numbers' => Random::generate(12, '0-9'),
			'simple' => Random::generate(8),
			'normal' => Random::generate(12, '0-9a-zA-Z'),
			'advance' => Random::generate(6, '0-9a-zA-Z') . '-' . Random::generate(6, '0-9a-zA-Z') . '-' . Random::generate(6, '0-9a-zA-Z'),
		]);
	}


	public function postSetPassword(int $id, string $password): void
	{
		try {
			$user = $this->getMemberById($id)->getUser();
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}
		try {
			$user->setPassword($password);
		} catch (\InvalidArgumentException $e) {
			$this->sendError('Password can not be changed. Reason: ' . $e->getMessage());
		}
		$this->entityManager->flush();
		$this->sendOk();
	}


	/**
	 * Return info about user's password
	 */
	public function actionSecurity(int $id): void
	{
		try {
			$user = $this->getMemberById($id)->getUser();
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$this->userMetaManager->loadAll($id);
		$lastChangedPassword = $this->userMetaManager->get($id, 'password-last-changed-date');
		if ($lastChangedPassword === null) {
			$lastChangedPassword = $user->getRegisterDate()->format('Y-m-d');
			$this->userMetaManager->set($id, 'password-last-changed-date', $lastChangedPassword);
		}
		$isBlocked = $this->userMetaManager->get($id, 'blocked') === 'true';

		$this->sendJson([
			'lastChangedPassword' => (new \DateTimeImmutable($lastChangedPassword))->format('F j, Y'),
			'twoFactorAuth' => $user->getOtpCode() !== null,
			'options' => [
				'canBan' => $isBlocked === false
					&& $this->user->isAdmin()
					&& $this->getUser()->getId() !== $id,
				'isBlocked' => $isBlocked,
				'blockedReason' => $this->userMetaManager->get($id, 'block-reason'),
			],
		]);
	}


	public function postCancelOauth(int $id): void
	{
		try {
			$user = $this->getMemberById($id)->getUser();
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$user->setOtpCode(null);
		$this->entityManager->flush();
		$this->sendOk();
	}


	public function postBlockUser(int $id, string $reason): void
	{
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$this->user->getUserStorage()->disableMember($member, $reason);

		$this->flashMessage('User has been blocked.', 'success');
		$this->sendOk();
	}


	public function postBlockUserCancel(int $id): void
	{
		if ($this->user->isAdmin() === false) {
			$this->sendError('You are not admin.');
		}
		try {
			$this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$this->userMetaManager->loadAll($id);
		$this->userMetaManager->set($id, 'blocked', null);
		$this->userMetaManager->set($id, 'block-reason', null);
		$this->flashMessage('Account blocking has been lifted.', 'success');
		$this->sendOk();
	}


	public function actionGenerateOauth(int $id): void
	{
		try {
			$user = $this->getMemberById($id)->getUser();
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}
		if ($user->getOtpCode() !== null) {
			$this->sendError('OTP code already exist.');
		}

		$otpCode = CasHelper::generateOtpCode();
		$opCodeHuman = CasHelper::otpBase32Encode($otpCode);
		$otpCodeHash = md5($opCodeHuman);

		$this->cache->save($otpCodeHash, $otpCode, [
			Cache::EXPIRE => '10 minutes',
		]);

		$this->sendJson([
			'account' => $user->getUsername(),
			'otpCode' => [
				'hash' => $otpCodeHash,
				'human' => $opCodeHuman,
			],
			'qrCodeUrl' => CasHelper::getOtpQrUrl(
				Url::get()->getNetteUrl()->getDomain(3) . ' | ' . ($this->settings->getProjectName() ?? 'Baraja CMS'),
				$user->getUsername(),
				$otpCode,
			),
		]);
	}


	public function postSetAuth(int $id, string $hash, string $code): void
	{
		/** @var string|null $otpCode */
		$otpCode = $this->cache->load($hash);

		if ($otpCode === null) {
			$this->sendError('Hash is invalid or already expired.');
		}
		if (CasHelper::checkAuthenticatorOtpCodeManually($otpCode, (int) $code) === true) {
			try {
				$user = $this->getMemberById($id)->getUser();
			} catch (NoResultException | NonUniqueResultException) {
				$this->sendError(sprintf('User "%s" does not exist.', $id));
			}

			$user->setOtpCode($otpCode);
			$this->entityManager->flush();
			$this->sendOk();
		}

		$this->sendError('OTP code does not work.');
	}


	public function actionPermissions(int $id): void
	{
		$userId = $this->user->getId();
		$currentMember = $this->getMemberById($userId);
		if ($currentMember->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$plugins = [];
		foreach ($this->pluginManager->getPluginInfoEntities() as $plugin) {
			$components = [];
			foreach ($this->pluginManager->getComponents($plugin, null) as $component) {
				$components[] = [
					'active' => $member->hasRole('component-' . $component->getName()),
					'tab' => $component->getTab(),
					'name' => $component->getName(),
				];
			}
			$plugins[] = [
				'active' => $member->hasRole('plugin-' . $plugin->getSanitizedName()),
				'name' => $plugin->getSanitizedName(),
				'type' => $plugin->getType(),
				'realName' => $plugin->getRealName(),
				'components' => $components,
			];
		}

		$this->sendJson([
			'isAdmin' => $member->isAdmin(),
			'roles' => $member->getRoles(),
			'items' => $plugins,
		]);
	}


	/**
	 * @param array<int, string> $roles
	 * @param array<int, array{
	 *     active: bool,
	 *     name: string,
	 *     type: string,
	 *     realName: string,
	 *     components: array<int, array{
	 *         active: bool,
	 *         name: string
	 *     }>
	 * }> $permissions
	 */
	public function postSavePermissions(int $id, array $roles, array $permissions = []): void
	{
		$userId = $this->user->getId();
		$currentMember = $this->getMemberById($userId);
		if ($currentMember->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}

		$roles = array_map(static fn(string $role): string => strtolower($role), $roles);
		if ($member->isAdmin() === false && \in_array('admin', $roles, true) === true) {
			$this->sendError('You cannot set the administrator role manually. To maintain security, use the "Set as admin" button.');
		}

		// TODO: Privilege and role engine is temporally disabled.

		/*
		$privileges = [];
		foreach ($permissions as $plugin) {
			if ($plugin['active'] === false) {
				continue;
			}
			$pluginEntity = $this->pluginManager->getPluginByName(Helpers::formatPresenterNameByUri($plugin['name']));
			$pluginComponents = $this->pluginManager->getComponents($pluginEntity, null);
			$privileges[] = 'plugin-' . $plugin['name'];
			foreach ($plugin['components'] as $component) {
				if ($component['active'] === false) {
					continue;
				}
				foreach ($pluginComponents as $componentEntity) {
					if ($componentEntity->getName() === $component['name']) {
						$privileges[] = 'component-' . $componentEntity->getName();
						break;
					}
				}
			}
		}

		$member->resetPrivileges();
		foreach ($privileges as $privilege) {
			$member->addPrivilege($privilege);
		}

		$member->resetRoles();
		foreach ($roles as $role) {
			$member->addRole($role);
		}
		*/

		$this->entityManager->flush();
		$this->flashMessage('User permissions has been set.', 'success');
		$this->sendOk();
	}


	public function postMarkUserAsAdmin(int $id, string $password): void
	{
		$currentMember = $this->getMemberById($this->user->getId());
		if ($currentMember->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}
		if ($currentMember->getUser()->passwordVerify($password) === false) {
			$this->sendError('Admin password is incorrect.');
		}

		$this->memberRoleManager->addRole($member, Role::RoleAdmin);
		$this->sendOk();
	}


	/**
	 * Return info about user's login history.
	 */
	public function actionLoginHistory(int $id, int $page = 1, int $limit = 32): void
	{
		$permitted = $this->user->isAdmin() || $this->user->getId() === $id;
		$count = 0;
		if ($permitted === true) {
			/** @var array<int, array{id: int, ip: string, hostname: string, userAgent: string, loginDatetime: \DateTime}> $logins */
			$logins = $this->entityManager->getRepository(UserLogin::class)
				->createQueryBuilder('userLogin')
				->select('PARTIAL userLogin.{id, ip, hostname, userAgent, loginDatetime}')
				->where('userLogin.user = :userId')
				->setParameter('userId', $id)
				->setMaxResults($limit)
				->setFirstResult(($page - 1) * $limit)
				->orderBy('userLogin.loginDatetime', 'DESC')
				->getQuery()
				->getArrayResult();

			if ($page !== 1 || $logins !== []) {
				try {
					$dbCount = $this->entityManager->getRepository(UserLogin::class)
						->createQueryBuilder('userLogin')
						->select('COUNT(userLogin.id)')
						->getQuery()
						->getSingleScalarResult();
					if (is_numeric($dbCount)) {
						$count = (int) $dbCount;
					}
				} catch (NoResultException | NonUniqueResultException) {
				}
			}

			$userAgentIterator = 1;
			$userAgents = [];
			$userAgentIdToHaystack = [];
			$return = [];
			foreach ($logins as $login) {
				$hash = md5($login['userAgent']);
				if (isset($userAgents[$hash]) === false) {
					$userAgents[$hash] = $userAgentIterator++;
					$userAgentIdToHaystack[$userAgents[$hash]] = $login['userAgent'];
				}

				$return[] = [
					'ip' => $login['ip'],
					'hostname' => $login['hostname'],
					'userAgent' => $userAgents[$hash],
					'loginDatetime' => $login['loginDatetime'],
				];
			}
		} else {
			$return = [];
			$userAgentIdToHaystack = [];
			$userAgents = [];
		}

		$this->sendJson([
			'permitted' => $permitted,
			'items' => $return,
			'userAgents' => $userAgentIdToHaystack,
			'count' => [
				'items' => \count($return),
				'allItems' => $count,
				'userAgents' => \count($userAgents),
			],
			'paginator' => (new Paginator)
				->setItemCount($count)
				->setItemsPerPage($limit)
				->setPage($page),
		]);
	}


	public function actionSaveMeta(int $id, string $key, ?string $value = null): void
	{
		$this->userMetaManager->set($id, $key, $value);
		$this->sendOk();
	}


	public function postUploadAvatar(): void
	{
		$userIdParam = $this->httpRequest->getPost('userId');
		$userId = is_numeric($userIdParam) ? (int) $userIdParam : 0;

		try {
			$member = $this->getMemberById($userId);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $userId));
		}
		if ($this->user->getId() !== $userId && !$this->getUser()->isInRole('admin')) {
			$this->sendError('Upload avatar is not permitted.');
		}

		$file = $this->httpRequest->getFile('avatar');
		if ($file === null) {
			$this->sendError('Please select avatar image to upload.');
		}
		if ($file->isImage() === false) {
			$this->sendError('Uploaded avatar file must be a image.');
		}

		try {
			$apiResponse = Callback::invokeSafe(
				function: 'file_get_contents',
				args: [
					'https://cdn.baraja.cz/avatar/upload',
					false,
					stream_context_create([
						'http' => [
							'method' => 'POST',
							'header' => 'Content-Type: application/x-www-form-urlencoded',
							'user_agent' => 'BarajaBot in PHP',
							'content' => http_build_query([
								'email' => $member->getUser()->getEmail(),
								'blob' => base64_encode((static function (FileUpload $file): string {
									try { // try to compress
										return $file->toImage()->toString(IMAGETYPE_PNG);
									} catch (NotSupportedException) { // fallback - send whole data
										return (string) $file->getContents();
									}
								})($file)),
							]),
						],
					]),
				],
				onError: static function (string $message): void {
					throw new \RuntimeException($message);
				},
			);
		} catch (\RuntimeException $e) {
			$this->context->getContainer()->getLogger()->critical($e->getMessage(), ['exception' => $e]);
			$this->sendError('Can not upload avatar to CDN server: ' . $e->getMessage());
		}
		assert(is_string($apiResponse));
		if (str_starts_with($apiResponse, '{')) {
			/** @var array{error?: bool, message: string|null} $response */
			$response = json_decode($apiResponse, true, 512, JSON_THROW_ON_ERROR);
			if (isset($response['error']) && $response['error'] === true) {
				$this->sendError($response['message'] ?? 'CDN error');
			}
		}

		$this->sendOk();
	}


	public function actionSavePhone(int $id, string $phone, int $region = 420): void
	{
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');
		}
		try {
			$member->getUser()->setPhone($phone, $region);
		} catch (\Throwable $e) {
			$this->sendError($e->getMessage());
		}

		$this->entityManager->flush();
		$this->flashMessage('User phone has been changed.', 'success');
		$this->sendOk();
	}


	public function postSetUserPassword(int $id, string $password): void
	{
		try {
			$userId = $this->user->getId();
			$currentUser = $this->getMemberById($userId);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User is not logged in.');
		}
		try {
			$member = $this->getMemberById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError(sprintf('User "%s" does not exist.', $id));
		}
		if ($currentUser->getId() !== $member->getId()
			&& in_array('admin', $currentUser->getRoleCodes(), true) === false
		) {
			$this->sendError('Current user must be admin for change password.');
		}
		try {
			$member->getUser()->setPassword($password);
		} catch (\InvalidArgumentException $e) {
			$this->sendError('Password can not be changed. Reason: ' . $e->getMessage());
		}
		$this->userMetaManager->set($member->getId(), 'password-last-changed-date', date('Y-m-d'));
		$this->entityManager->flush();
		$this->flashMessage('User password has been changed.', 'success');

		$this->sendOk();
	}


	/**
	 * @return array<string, string>
	 */
	private function getAllRoles(): array
	{
		$roles = [];
		$countTypes = [];
		foreach ($this->getAllUsers() as $user) {
			foreach ($user['roles'] as $role) {
				$roles[$role] = true;
				if (isset($countTypes[$role]) === false) {
					$countTypes[$role] = 1;
				} else {
					$countTypes[$role]++;
				}
			}
		}

		$roles = array_keys($roles);
		sort($roles);

		$return = [];
		foreach ($roles as $role) {
			$return[$role] = Strings::firstUpper($role) . ' (' . $countTypes[$role] . ')';
		}

		return $return;
	}


	private function setRealUserName(User $user, string $name): void
	{
		$nameParser = CasHelper::nameParser($name);
		$user->setFirstName($nameParser['firstName']);
		$user->setLastName($nameParser['lastName']);
		$this->userMetaManager->set($user->getId(), 'name--degree-before', $nameParser['degreeBefore']);
		$this->userMetaManager->set($user->getId(), 'name--degree-after', $nameParser['degreeAfter']);
		$this->entityManager->flush();
	}


	/**
	 * @return array<int, array{id: int, roles: array<int, string>, active: bool}>
	 */
	private function getAllUsers(): array
	{
		static $cache;

		return $cache ?? $cache = $this->user->getUserStorage()->getUserRepository()
				->createQueryBuilder('user')
				->select('PARTIAL user.{id, roles, active}')
				->getQuery()
				->getArrayResult();
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	private function getMemberById(int $id): OrganisationMember
	{
		return $this->user->getUserStorage()->getMemberByUser($id);
	}
}
