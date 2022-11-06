<?php

declare(strict_types=1);

namespace Baraja\Cms\Settings;


use Baraja\CAS\Service\UserMetaManager;
use Baraja\CAS\User;
use Baraja\Cms\Session;

final class SystemInfo
{
	/** @var array<int, string> */
	private array $userKeys = [
		'theme',
	];


	public function __construct(
		private User $user,
		private UserMetaManager $userMetaManager,
	) {
	}


	/**
	 * @return array{user: array<string, string|null>}
	 */
	public function toArray(): array
	{
		$return = Session::get(Session::WORKFLOW_SETTINGS);
		if ($return === null) {
			$return = $this->generateStructure();
			Session::set(Session::WORKFLOW_SETTINGS, $return);
		}

		/** @phpstan-ignore-next-line */
		return $return;
	}


	public function reload(): void
	{
		Session::remove(Session::WORKFLOW_SETTINGS);
	}


	/**
	 * @return array{user: array<string, string|null>}
	 */
	private function generateStructure(): array
	{
		return [
			'user' => $this->getUserSettings(),
		];
	}


	/**
	 * @return array<string, string|null>
	 */
	private function getUserSettings(): array
	{
		$identity = $this->user->getIdentity();
		if ($identity === null) {
			throw new \LogicException('User is not logged in.');
		}
		$id = $identity->getId();
		$this->userMetaManager->loadAll($id);

		$return = [];
		foreach ($this->userKeys as $key) {
			$return[$key] = $this->userMetaManager->get($id, $key);
		}

		return $return;
	}
}
