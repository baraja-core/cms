<?php

declare(strict_types=1);

namespace Baraja\Cms\Settings;


use Baraja\Cms\Session;
use Baraja\Cms\User\UserManagerAccessor;
use Baraja\Doctrine\EntityManager;
use Baraja\DynamicConfiguration\ConfigurationSection;

final class SystemInfo
{
	/** @var array<int, string> */
	private array $userKeys = [
		'theme',
	];


	public function __construct(
		private EntityManager $entityManager,
		private ConfigurationSection $config,
		private UserManagerAccessor $userManager,
	) {
	}


	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$return = Session::get(Session::WORKFLOW_SETTINGS);
		if ($return === null) {
			$return = $this->generateStructure();
			Session::set(Session::WORKFLOW_SETTINGS, $return);
		}

		return $return;
	}


	public function reload(): void
	{
		Session::remove(Session::WORKFLOW_SETTINGS);
	}


	/**
	 * @return array<string, mixed>
	 */
	private function generateStructure(): array
	{
		return [
			'user' => $this->getUserSettings(),
		];
	}


	/**
	 * @return array<string, string|int|null>
	 */
	private function getUserSettings(): array
	{
		$identity = $this->userManager->get()->getIdentity();
		if ($identity === null) {
			throw new \LogicException('User is not logged in.');
		}
		$id = (int) $identity->getId();
		$metaManager = $this->userManager->get()->getUserMetaManager();
		$metaManager->loadAll($id);

		$return = [];
		foreach ($this->userKeys as $key) {
			$return[$key] = $metaManager->get($id, $key);
		}

		return $return;
	}
}
