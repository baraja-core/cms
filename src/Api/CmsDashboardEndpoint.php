<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\Cms\Announcement\Entity\Announcement;
use Baraja\Cms\Announcement\Entity\AnnouncementRepository;
use Baraja\Cms\User\Entity\User;
use Baraja\Cms\User\UserManager;
use Baraja\Localization\Localization;
use Baraja\StructuredApi\BaseEndpoint;
use Doctrine\ORM\EntityManagerInterface;

final class CmsDashboardEndpoint extends BaseEndpoint
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private UserManager $userManager,
		private Localization $localization,
	) {
	}


	public function actionFeed(): void
	{
		/** @var AnnouncementRepository $repository */
		$repository = $this->entityManager->getRepository(AnnouncementRepository::class);

		$this->sendJson(
			[
				'feed' => $repository->getFeed(),
			],
		);
	}


	public function postPostTopic(string $message): void
	{
		try {
			$topic = new Announcement(
				user: $this->getUserIdentity(),
				locale: $this->localization->getLocale(),
				message: $message,
			);
		} catch (\InvalidArgumentException $e) {
			$this->flashMessage($e->getMessage());
			$this->sendError($e->getMessage());
		}

		$topic->setActive();
		$this->entityManager->persist($topic);
		$this->entityManager->flush();
		$this->sendOk();
	}


	private function getUserIdentity(): User
	{
		$identity = $this->userManager->getIdentity();
		assert($identity instanceof User);

		return $identity;
	}
}
