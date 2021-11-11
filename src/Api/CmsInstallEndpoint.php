<?php

declare(strict_types=1);

namespace Baraja\Cms\Api;


use Baraja\BarajaCloud\CloudManager;
use Baraja\Cms\Settings;
use Baraja\Cms\User\Entity\User;
use Baraja\Doctrine\EntityManager;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\DynamicConfiguration\ConfigurationSection;
use Baraja\StructuredApi\Attributes\PublicEndpoint;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Nette\Utils\Strings;
use Nette\Utils\Validators;

#[PublicEndpoint]
final class CmsInstallEndpoint extends BaseEndpoint
{
	private ConfigurationSection $config;


	public function __construct(
		private EntityManager $entityManager,
		private CloudManager $cloudManager,
		private Settings $settings,
		Configuration $configuration,
	) {
		$this->config = new ConfigurationSection($configuration, 'core');
	}


	public function postBasic(
		string $name,
		string $username,
		string $firstName,
		string $lastName,
		string $mail,
		string $password,
		string $passwordVerify,
		bool $vop = false
	): void {
		if ($this->settings->isBasicConfigurationOk() === true) {
			$this->sendError('Unauthorized request.');
		}

		$errors = [];

		$name = trim($name);
		$username = Strings::lower(trim($username));
		$firstName = Strings::firstUpper(trim($firstName));
		$lastName = Strings::firstUpper(trim($lastName));
		$mail = trim($mail);
		$password = trim($password);
		$passwordVerify = trim($passwordVerify);
		if ($name === '') {
			$errors[] = 'Zadejte titulek (název) webu.';
		}
		if ($username === '') {
			$errors[] = 'Zadejte uživatelské jméno správce.';
		} elseif (preg_match('/^[a-z0-9]+$/', $username) === 0) {
			$errors[] = 'Uživatelské jméno se může skládat pouze z malých písmen anglické abecedy a číslic.';
		}
		if ($firstName === '') {
			$errors[] = 'Zadejte jméno správce.';
		}
		if ($lastName === '') {
			$errors[] = 'Zadejte příjmení správce.';
		}
		if ($mail === '') {
			$errors[] = 'Zadejte e-mail.';
		} elseif (Validators::isEmail($mail) === false) {
			$errors[] = 'Zadaný e-mail neexistuje.';
		}
		if ($password === '') {
			$errors[] = 'Zadejte heslo.';
		}
		if ($passwordVerify === '') {
			$errors[] = 'Zadejte heslo znovu.';
		}
		if (mb_strlen($password, 'UTF-8') < 6) {
			$errors[] = 'Heslo musí mít aspoň 6 znaků.';
		} elseif (mb_strlen($password, 'UTF-8') > 2_048) {
			$errors[] = 'Maximální délka hesla je 2048 znaků.';
		}
		if ($password !== $passwordVerify && $password !== '') {
			$errors[] = 'Hesla se musí shodovat.';
		}
		if ($vop === false) {
			$errors[] = 'Musíte souhlasit s podmínkami služby.';
		}
		if ($errors !== []) {
			$this->sendJson(
				[
					'state' => 'error',
					'errors' => $errors,
				]
			);
		}

		$this->config->save('name', $name);
		$this->config->save('admin-email', $mail);

		$user = new User($username, $password, $mail, 'admin');
		$user->setFirstName($firstName);
		$user->setLastName($lastName);

		$this->entityManager->persist($user);
		$this->entityManager->flush();
		$this->sendOk();
	}


	public function postCloudCreateAccount(
		string $email,
		string $password,
		string $firstName,
		string $lastName,
		?string $phone = null
	): void {
		if ($this->settings->isCloudConnectionOk() === true) {
			$this->sendError('Unauthorized request.');
		}
		if ($email === '') {
			$this->sendError('Zadejte e-mail.');
		}
		if (Validators::isEmail($email) === false) {
			$this->sendError('E-mail nemá správný formát.');
		}
		if ($firstName === '' || $lastName === '') {
			$this->sendError('Zadejte vaše reálné jméno, které musí existovat.');
		}

		/** @var array{token?: string, message?: string} $response */
		$response = (array) json_decode(
			(string) @file_get_contents(
				CloudManager::ENDPOINT_URL . '/cloud-status/create-account?domain='
				. urlencode(Url::get()->getNetteUrl()->getDomain(3))
				. '&email=' . urlencode($email)
				. '&password=' . urlencode($password)
				. '&firstName=' . urlencode($firstName)
				. '&lastName=' . urlencode($lastName)
				. ($phone !== null ? '&phone=' . urlencode($phone) : '')
			),
			true
		);
		if (isset($response['token']) === false) {
			$this->sendError($response['message'] ?? 'Account with given e-mail or password does not exist.');
		}

		$this->cloudManager->setToken($response['token']);

		$this->sendOk();
	}


	public function postCloudLogin(string $email, string $password): void
	{
		if ($this->settings->isCloudConnectionOk() === true) {
			$this->sendError('Unauthorized request.');
		}
		if ($email === '') {
			$this->sendError('Zadejte e-mail.');
		}
		if (Validators::isEmail($email) === false) {
			$this->sendError('E-mail nemá správný formát.');
		}

		/** @var array{token?: string, message?: string} $response */
		$response = (array) json_decode(
			(string) @file_get_contents(
				CloudManager::ENDPOINT_URL . '/cloud-status/token-by-user?domain='
				. urlencode(Url::get()->getNetteUrl()->getDomain(3))
				. '&email=' . urlencode($email)
				. '&password=' . urlencode($password)
			),
			true
		);
		if (isset($response['token']) === false) {
			$this->sendError($response['message'] ?? 'Account with given e-mail or password does not exist.');
		}

		$this->cloudManager->setToken($response['token']);

		$this->sendOk();
	}
}
