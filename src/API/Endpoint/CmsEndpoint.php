<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Endpoint;


use Nette\Http\Response;
use Nette\Security\Identity;
use Nette\Security\User;

final class CmsEndpoint implements ApiEndpoint
{

	/**
	 * @var User
	 */
	private $user;

	/**
	 * @var Response
	 */
	private $response;

	/**
	 * @param User $user
	 * @param Response $response
	 */
	public function __construct(User $user, Response $response)
	{
		$this->user = $user;
		$this->response = $response;
	}

	public function actionDefault(string $username, string $password, bool $remember = false): array
	{
		$identity = new Identity($username, ['admin'], [
			'username' => $username,
			'password' => '1234',
			'mail' => 'jan@barasek.com',
		]);

		bdump($identity);

		$this->user->getStorage()
			->setAuthenticated(true)
			->setExpiration($remember === true ? '14 days' : '2 hours')
			->setIdentity($identity);

		Redirect::link('Homepage:default');

		return [
			'message' => 'Uživatelské jméno nebo heslo není správné.',
		];
	}

	public function actionJs(): void
	{
		$this->response->setHeader('Content-Type', 'application/json');
		echo file_get_contents(__DIR__ . '/../../assets/app.js');
		die;
	}

	public function actionCss(): void
	{
		$this->response->setHeader('Content-Type', 'text/css');
		echo file_get_contents(__DIR__ . '/../../assets/app.css');
		die;
	}

	public function actionLogo(): void
	{
		$this->response->setHeader('Content-Type', 'image/svg+xml');
		echo file_get_contents(__DIR__ . '/../../assets/cms-logo.svg');
		die;
	}

	public function actionAutocomplete(string $query): array
	{
		return [
			'query' => $query,
			'results' => [
				[
					'title' => $query,
				],
				[
					'title' => 'Pokus 2',
				],
				[
					'title' => 'Pokus 3',
				],
			],
		];
	}

}