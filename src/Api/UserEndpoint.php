<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Helpers;
use Baraja\Cms\Settings;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\Entity\UserLogin;
use Baraja\Cms\User\Entity\UserMeta;
use Baraja\Cms\User\UserManager;
use Baraja\Cms\User\UserMetaManager;
use Baraja\Doctrine\EntityManager;
use Baraja\Plugin\PluginManager;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Caching\Cache;
use Nette\Http\FileUpload;
use Nette\Http\Request;
use Nette\NotSupportedException;
use Nette\Utils\Callback;
use Nette\Utils\DateTime;
use Nette\Utils\Paginator;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\ILogger;

final class UserEndpoint extends BaseEndpoint
{
	public function __construct(
		private UserManager $userManager,
		private UserMetaManager $userMetaManager,
		private EntityManager $entityManager,
		private CloudManager $cloudManager,
		private PluginManager $pluginManager,
		private Settings $settings,
	) {
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
		?string $active = null
	): void {
		$currentUserId = $this->getUser()->getId();
		/** @var CmsUser $currentUser */
		$currentUser = $this->entityManager->getRepository($this->userManager->getDefaultEntity())->find($currentUserId);
		$count = $this->getCountUsers();
		$selection = $this->entityManager->getRepository($this->userManager->getDefaultEntity())->createQueryBuilder('user');

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
			if (preg_match('/^(\S+)\s+(.+)$/', $query, $queryParser)) {
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

		$users = $selection->select('PARTIAL user.{id, firstName, lastName, password, email, phone, roles, active, registerDate, otpCode}')
			->setMaxResults($limit)
			->setFirstResult(($page - 1) * $limit)
			->addOrderBy('user.active', 'DESC')
			->addOrderBy('user.createDate', 'DESC')
			->getQuery()
			->getArrayResult();

		$metaToUser = $this->getMetaByUsers(
			array_map(static fn(array $user): int => $user['id'], $users),
			['blocked', 'block-reason'],
		);

		$return = [];
		foreach ($users as $user) {
			$return[] = [
				'id' => $user['id'],
				'name' => (static function () use ($user): ?string {
					if (($return = trim($user['firstName'] . ' ' . $user['lastName']) ?: null) === null) {
						$return = explode('@', $user['emails'][0] ?? '')[0] ?? null;
					}

					return Strings::firstUpper((string) $return) ?: null;
				})(),
				'email' => $user['email'],
				'roles' => $user['roles'],
				'phone' => $user['phone'],
				'avatarUrl' => 'https://cdn.baraja.cz/avatar/' . md5($user['email']) . '.png',
				'registerDate' => $user['registerDate'],
				'options' => [
					'active' => $user['active'],
					'2fa' => $user['otpCode'] !== null,
					'verifying' => $user['password'] === '---empty-password---',
					'blocked' => ($metaToUser[$user['id']]['blocked'] ?? '') === 'true',
					'blockedReason' => $metaToUser[$user['id']]['block-reason'] ?? null,
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
		string $role,
		?string $phone = null,
		?string $password = null
	): void {
		if ($this->userExist($email) === true) {
			$this->sendError('User "' . $email . '" already exist.');
		}
		try {
			try {
				/** @phpstan-ignore-next-line */
				$ref = new \ReflectionClass($this->userManager->getDefaultEntity());
				/** @var CmsUser $user */
				$user = $ref->newInstanceArgs([$email, $password ?: '', $email, CmsUser::ROLE_USER]);
			} catch (\Throwable $e) {
				if (class_exists(Debugger::class)) {
					Debugger::log($e, ILogger::CRITICAL);
				}
				$this->sendError('Can not create user because user storage is broken.');

				return;
			}
			$user->setPhone($phone);
			$user->addRole($role);
			$this->entityManager->persist($user)->flush();
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());

			return;
		}

		$this->setRealUserName($user, $fullName);
		$this->entityManager->flush();

		$this->cloudManager->callRequest('cloud/confirm-user-registration', [
			'domain' => Url::get()->getNetteUrl()->getDomain(3),
			'locale' => 'cs',
			'email' => $email,
			'setPasswordUrl' => $password ? null : Url::get()->getBaseUrl() . '/admin/set-user-password?userId=' . $user->getId(),
			'loginUrl' => Url::get()->getBaseUrl() . '/admin',
		], 'POST');

		$this->sendOk([
			'id' => $user->getId(),
		]);
	}


	public function deleteDefault(int $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setActive(false);
		$this->entityManager->flush();
		$this->flashMessage('User was marked as deleted.', 'success');
		$this->sendOk();
	}


	public function actionRevertUser(int $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setActive(true);
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
			'exist' => $this->userExist($email),
		]);
	}


	public function actionOverview(int $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
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
					if (preg_match('/^\+(?<region>\d+)\s*(?<phone>.+)$/', $phone, $parser)) {
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
			'meta' => (static function (array $data): array {
				$return = [];
				foreach ($data as $key => $value) {
					if (\is_scalar($value) === true) {
						$return[$key] = $value;
					}
				}

				return $return;
			})($user->getMetaData()),
		]);
	}


	public function postOverview(int $id, string $username, string $email, string $name): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$this->setRealUserName($user, $name);
		$user->setUsername($username);

		try {
			$user->addEmail($email);
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());
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
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		// TODO: if ($this->userManager->isSafePassword($password) === false) {
		// TODO:  $this->sendError('Password must be safe!');
		// TODO: }

		$user->setPassword($password);
		$this->entityManager->flush();
		$this->sendOk();
	}


	/**
	 * Return info about user's password
	 */
	public function actionSecurity(int $id): void
	{
		try {
			/** @var User $user */
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$this->userMetaManager->loadAll($id);
		$lastChangedPassword = $this->userMetaManager->get($id, 'password-last-changed-date');
		if ($lastChangedPassword === null) {
			$lastChangedPassword = $user->getRegisterDate()->format('Y-m-d');
			$this->userMetaManager->set($id, 'password-last-changed-date', $lastChangedPassword);
		}
		$isBlocked = $this->userMetaManager->get($id, 'blocked') === 'true';

		$this->sendJson([
			'lastChangedPassword' => DateTime::from($lastChangedPassword ?? 'now')->format('F j, Y'),
			'twoFactorAuth' => $user->getOtpCode() !== null,
			'options' => [
				'canBan' => $isBlocked === false
					&& $this->userManager->isAdmin()
					&& $this->getUser()->getId() !== $id,
				'isBlocked' => $isBlocked,
				'blockedReason' => $this->userMetaManager->get($id, 'block-reason'),
			],
		]);
	}


	public function postCancelOauth(int $id): void
	{
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$user->setOtpCode(null);
		$this->entityManager->flush();
		$this->sendOk();
	}


	public function postBlockUser(int $id, string $reason): void
	{
		if ($this->userManager->isAdmin() === false) {
			$this->sendError('You are not admin.');
		}
		if ($this->getUser()->getId() === $id) {
			$this->sendError('Your account can not be blocked.');
		}
		$reason = trim($reason);
		if ($reason === '') {
			$this->flashMessage('Block reason can not be empty.', 'error');
			$this->sendError('Block reason can not be empty.');
		}
		try {
			$user = $this->userManager->getUserById($id);
			$user->setActive(false);
			$user->resetPrivileges();
			$user->resetRoles();
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$this->userMetaManager->set($id, 'blocked', 'true');
		$this->userMetaManager->set($id, 'block-reason', $reason);
		$this->userMetaManager->set($id, 'block-admin', (string) $this->getUser()->getId());
		$this->flashMessage('User has been blocked.', 'success');
		$this->sendOk();
	}


	public function postBlockUserCancel(int $id): void
	{
		if ($this->userManager->isAdmin() === false) {
			$this->sendError('You are not admin.');
		}
		try {
			$this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
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
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		if ($user->getOtpCode() !== null) {
			$this->sendError('OTP code already exist.');
		}

		$otpCode = $this->userManager->generateOtpCode();
		$opCodeHuman = Helpers::otpBase32Encode($otpCode);
		$otpCodeHash = md5($opCodeHuman);

		$this->getCache('user-endpoint-otp')->save($otpCodeHash, $otpCode, [
			Cache::EXPIRE => '10 minutes',
		]);

		$this->sendJson([
			'account' => $user->getUsername(),
			'otpCode' => [
				'hash' => $otpCodeHash,
				'human' => $opCodeHuman,
			],
			'qrCodeUrl' => Helpers::getOtpQrUrl(
				Url::get()->getNetteUrl()->getDomain(3) . ' | ' . ($this->settings->getProjectName() ?? 'Baraja CMS'),
				$user->getUsername(),
				$otpCode,
			),
		]);
	}


	public function postSetAuth(int $id, string $hash, string $code): void
	{
		/** @var string|null $otpCode */
		$otpCode = $this->getCache('user-endpoint-otp')->load($hash);

		if ($otpCode === null) {
			$this->sendError('Hash is invalid or already expired.');

			return;
		}
		if (Helpers::checkAuthenticatorOtpCodeManually($otpCode, (int) $code) === true) {
			try {
				$user = $this->userManager->getUserById($id);
			} catch (NoResultException | NonUniqueResultException) {
				$this->sendError('User "' . $id . '" does not exist.');

				return;
			}

			$user->setOtpCode($otpCode);
			$this->entityManager->flush();
			$this->sendOk();
		}

		$this->sendError('OTP code does not work.');
	}


	public function actionPermissions(int $id): void
	{
		$currentUser = $this->userManager->getUserById($this->getUser()->getId());
		if ($currentUser->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}

		$plugins = [];
		foreach ($this->pluginManager->getPluginInfoEntities() as $plugin) {
			$components = [];
			foreach ($this->pluginManager->getComponents($plugin, null) as $component) {
				$components[] = [
					'active' => $user->containPrivilege('component-' . $component->getName()),
					'tab' => $component->getTab(),
					'name' => $component->getName(),
				];
			}
			$plugins[] = [
				'active' => $user->containPrivilege('plugin-' . $plugin->getSanitizedName()),
				'name' => $plugin->getSanitizedName(),
				'type' => $plugin->getType(),
				'realName' => $plugin->getRealName(),
				'components' => $components,
			];
		}

		$this->sendJson([
			'isAdmin' => $user->isAdmin(),
			'roles' => $user->getRoles(),
			'items' => $plugins,
		]);
	}


	/**
	 * @param string[] $roles
	 * @param mixed[] $permissions
	 */
	public function postSavePermissions(int $id, array $roles, array $permissions): void
	{
		$currentUser = $this->userManager->getUserById($this->getUser()->getId());
		if ($currentUser->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		$roles = array_map(fn(string $role): string => strtolower($role), $roles);
		if ($user->isAdmin() === false && \in_array('admin', $roles, true) === true) {
			$this->sendError('You cannot set the administrator role manually. To maintain security, use the "Set as admin" button.');
		}

		$privileges = [];
		foreach ($permissions as $plugin) {
			if (($plugin['active'] ?? false) === true) {
				$pluginName = (string) ($plugin['name'] ?? '');
				$pluginEntity = $this->pluginManager->getPluginByName(Helpers::formatPresenterNameByUri($pluginName));
				$pluginComponents = $this->pluginManager->getComponents($pluginEntity, null);
				$privileges[] = 'plugin-' . $pluginName;
				foreach ($plugin['components'] ?? [] as $component) {
					if (($component['active'] ?? false) === true) {
						foreach ($pluginComponents as $componentEntity) {
							if ($componentEntity->getName() === ($component['name'] ?? '')) {
								$privileges[] = 'component-' . $componentEntity->getName();
								break;
							}
						}
					}
				}
			}
		}

		$user->resetPrivileges();
		foreach ($privileges as $privilege) {
			$user->addPrivilege((string) $privilege);
		}

		$user->resetRoles();
		foreach ($roles as $role) {
			$user->addRole($role);
		}

		$this->entityManager->flush();
		$this->flashMessage('User permissions has been set.', 'success');
		$this->sendOk();
	}


	public function postMarkUserAsAdmin(int $id, string $password): void
	{
		$currentUser = $this->userManager->getUserById($this->getUser()->getId());
		if ($currentUser->isAdmin() === false) {
			$this->sendError('Permissions settings is available only for admin user.');
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		if ($currentUser->passwordVerify($password) === false) {
			$this->sendError('Admin password is incorrect.');
		}

		$user->addRole('admin');
		$this->entityManager->flush();
		$this->sendOk();
	}


	/**
	 * Return info about user's login history.
	 */
	public function actionLoginHistory(int $id, int $page = 1, int $limit = 32): void
	{
		$permitted = $this->userManager->isAdmin() || $this->getUser()->getId() === $id;
		if ($permitted === true) {
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

			if ($page === 1 && $logins === []) {
				$count = 0;
			} else {
				try {
					$count = (int) $this->entityManager->getRepository(UserLogin::class)
						->createQueryBuilder('userLogin')
						->select('COUNT(userLogin.id)')
						->getQuery()
						->getSingleScalarResult();
				} catch (NoResultException | NonUniqueResultException) {
					$count = 0;
				}
			}

			$userAgentIterator = 1;
			$userAgents = [];
			$userAgentIdToHaystack = [];
			$return = [];
			foreach ($logins as $login) {
				if (isset($userAgents[$hash = md5($login['userAgent'])]) === false) {
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
			$count = 0;
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
		/** @var Request $request */
		$request = $this->container->getByType(Request::class);
		$userId = (int) $request->getPost('userId');

		try {
			$user = $this->userManager->getUserById($userId);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $userId . '" does not exist.');

			return;
		}
		if (
			!$this->getUser()->isInRole('admin')
			&& $this->getUser()->getId() !== $userId
		) {
			$this->sendError('Upload avatar is not permitted.');
		}

		$file = $request->getFile('avatar');
		if ($file === null) {
			$this->sendError('Please select avatar image to upload.');

			return;
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
								'email' => $user->getEmail(),
								'blob' => base64_encode((static function(FileUpload $file): string {
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
				}
			);
		} catch (\RuntimeException $e) {
			Debugger::log($e, ILogger::CRITICAL);
			$this->sendError('Can not upload avatar to CDN server: ' . $e->getMessage());

			return;
		}
		if (str_starts_with($apiResponse, '{')) {
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
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		try {
			$user->setPhone($phone, $region);
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
			$currentUser = $this->userManager->getUserById((int) $this->getUser()->getId());
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User is not logged in.');

			return;
		}
		try {
			$user = $this->userManager->getUserById($id);
		} catch (NoResultException | NonUniqueResultException) {
			$this->sendError('User "' . $id . '" does not exist.');

			return;
		}
		if ($currentUser->getId() !== $user->getId() && \in_array('admin', $currentUser->getRoles(), true) === false) {
			$this->sendError('Current user must be admin for change password.');
		}

		$user->setPassword($password);
		$this->userMetaManager->set((int) $user->getId(), 'password-last-changed-date', date('Y-m-d'));
		$this->entityManager->flush();
		$this->flashMessage('User password has been changed.', 'success');

		$this->sendOk();
	}


	private function userExist(string $email): bool
	{
		try {
			$this->entityManager->getRepository($this->userManager->getDefaultEntity())
				->createQueryBuilder('user')
				->select('PARTIAL user.{id}')
				->where('user.username = :username')
				->setParameter('username', $email)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			return true;
		} catch (NoResultException | NonUniqueResultException) {
		}

		return false;
	}


	/**
	 * @return string[]
	 */
	private function getAllRoles(): array
	{
		$roles = [];
		$countTypes = [];
		foreach ($this->getAllUsers() as $user) {
			foreach ($user['roles'] ?? [] as $role) {
				$roles[$role = (string) $role] = true;
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
			$role = (string) $role;
			$return[$role] = Strings::firstUpper($role) . ' (' . $countTypes[$role] . ')';
		}

		return $return;
	}


	private function setRealUserName(CmsUser $user, string $name): void
	{
		$nameParser = Helpers::nameParser($name);
		$user->setFirstName($nameParser['firstName']);
		$user->setLastName($nameParser['lastName']);
		$this->userMetaManager->set((int) $user->getId(), 'name--degree-before', $nameParser['degreeBefore']);
		$this->userMetaManager->set((int) $user->getId(), 'name--degree-after', $nameParser['degreeAfter']);
		$this->entityManager->flush();
	}


	/**
	 * @return mixed[][]
	 */
	private function getAllUsers(): array
	{
		static $cache;

		return $cache ?? $cache = $this->entityManager->getRepository($this->userManager->getDefaultEntity())
				->createQueryBuilder('user')
				->select('PARTIAL user.{id, roles, active}')
				->getQuery()
				->getArrayResult();
	}


	private function getCountUsers(): int
	{
		try {
			return (int) $this->entityManager->getRepository($this->userManager->getDefaultEntity())
				->createQueryBuilder('user')
				->select('COUNT(user.id)')
				->getQuery()
				->getSingleScalarResult();
		} catch (\Throwable) {
			return -1;
		}
	}


	/**
	 * @param array<int, int> $ids
	 * @param array<int, string> $keys
	 * @return array<int, array<string, string>>
	 */
	private function getMetaByUsers(array $ids, array $keys = []): array
	{
		$selection = $this->entityManager->getRepository(UserMeta::class)
			->createQueryBuilder('meta')
			->select('PARTIAL meta.{id, user, key, value}')
			->addSelect('PARTIAL user.{id}')
			->leftJoin('meta.user', 'user')
			->where('user.id IN (:ids)')
			->setParameter('ids', $ids);

		if ($keys !== []) {
			$selection->andWhere('meta.key IN (:keys)')
				->setParameter('keys', $keys);
		}

		/** @var mixed[] $metas */
		$metas = $selection->getQuery()->getArrayResult();

		$return = [];
		foreach ($metas as $meta) {
			$id = (int) $meta['user']['id'];
			$key = $meta['key'] ?? '';
			$value = $meta['value'] ?? '';
			$return[$id][$key] = $value;
		}

		/** @phpstan-ignore-next-line */
		return $return;
	}
}
