<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Endpoint;


use Baraja\Cms\UserManager;
use Baraja\Cms\UserManagerException;
use Nette\DI\MissingServiceException;
use Nette\Http\Response;
use Nette\Mail\IMailer;
use Nette\Mail\Mailer;
use Nette\Mail\Message;
use Nette\Security\User;

final class CmsEndpoint extends BaseApiEndpoint
{

	/**
	 * @var User
	 * @inject
	 */
	public $user;

	/**
	 * @var UserManager
	 * @inject
	 */
	public $userManager;

	/**
	 * @var Response
	 * @inject
	 */
	public $response;

	/**
	 * @param string $username
	 * @param string $password
	 * @param bool $remember
	 * @return string[]
	 * @throws Redirect
	 */
	public function actionDefault(string $username, string $password, bool $remember = false): array
	{
		try {
			$this->userManager->authenticate($username, $password, $remember);
			Redirect::link('Homepage:default');

			return null;
		} catch (UserManagerException $e) {
			return [
				'message' => 'Uživatelské jméno nebo heslo není správné.',
			];
		}
	}

	public function actionForgotPassword(string $username): ?array
	{
		try {
			/** @var Mailer $mailer */
			$mailer = $this->getByType(IMailer::class);
		} catch (MissingServiceException $e) {
			return [
				'error' => $e->getMessage(),
			];
		}

		try {
			$link = $this->link(':Admin:Homepage:forgotPassword');

			$mailer->send(
				(new Message)
					->setFrom('admin@baraja.cz', 'Admin')
					->addTo($this->userManager->getEmail($this->userManager->getUserByUsername($username)->getId()))
					->setSubject('Obnova hesla do administrace')
					->setHtmlBody(
						'<p>Dobrý den,</p>'
						. '<p>zaznamenali jsme požadavek pro změnu hesla do administrace.</p>'
						. '<p>Nové heslo si vygenerujte kliknutím na odkaz:</p>'
						. '<a href="' . $link . '">Změnit heslo</a>'
						. '<hr><p>Pokud máte jakékoli dotazy, kontaktujte Vašeho správce.</p>'
					)
			);
		} catch (\Exception $e) {
			return [
				'message' => 'Požadavek na změnu hesla se nepodařilo vyřídit z důvodu chyby serveru: ' . $e->getMessage(),
				'ok' => false,
			];
		}

		return [
			'message' => 'Požadavek na změnu hesla byl úspěšně odeslán. Zkontrolujte svoji e-mailovou schránku.',
			'ok' => true,
		];
	}

	/**
	 * @param string $username
	 * @param string $token
	 * @param string $password
	 * @return mixed[]|null
	 */
	public function actionForgotPasswordSetNew(string $username, string $token, string $password): ?array
	{
		$user = $this->userManager->getUserByUsername($username);

		if ($this->userManager->verifyToken($user, $token) === false) {
			return [
				'ok' => false,
			];
		}

		$this->userManager->changePassword($user, $password);

		return [
			'ok' => true,
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