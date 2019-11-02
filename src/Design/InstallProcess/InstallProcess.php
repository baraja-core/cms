<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Latte\Engine;
use Nette\Security\Passwords;
use Nette\Utils\FileSystem;

class InstallProcess
{

	/**
	 * @var UserStorage
	 */
	private $userStorage;

	/**
	 * @param UserStorage $userStorage
	 */
	public function __construct(UserStorage $userStorage)
	{
		$this->userStorage = $userStorage;
	}

	public function run(): void
	{
		$this->processApi();

		$url = Helpers::getCurrentUrl();

		echo (new Engine)->renderToString(__DIR__ . '/default.latte', [
			'basePath' => Helpers::getBaseUrl(),
			'isLocalhost' => strpos($url, 'localhost') !== false,
			'isBarajaCz' => strpos($url, 'baraja.cz') !== false,
		]);
		die;
	}

	/**
	 * Processing URL /api/install
	 */
	private function processApi(): void
	{
		if (trim(str_replace(Helpers::getBaseUrl(), '', Helpers::getCurrentUrl()), '/') !== 'api/install') {
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$errors = [];
		$message = null;

		if (($title = trim($input['title'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte titulek (název) webu.';
		}

		if (($username = trim($input['username'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte uživatelské jméno správce.';
		} elseif (preg_match('/^[a-z0-9]+$/', (string) $username) === 0) {
			$errors[] = 'Uživatelské jméno se může skládat pouze z malých písmen anglické abecedy a číslic.';
		}

		if (($firstName = trim($input['firstName'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte jméno správce.';
		}

		if (($lastName = trim($input['lastName'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte příjmení správce.';
		}

		if (($mail = trim($input['mail'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte e-mail.';
		} elseif ($this->isEmail((string) $mail) === false) {
			$errors[] = 'Zadaný e-mail neexistuje.';
		}

		if (($password = trim($input['password'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte heslo.';
		}

		if (($passwordVerify = trim($input['passwordVerify'] ?? '') ? : null) === null) {
			$errors[] = 'Zadejte heslo znovu.';
		}

		if (Helpers::length((string) $password) < 6) {
			$errors[] = 'Heslo musí mít aspoň 6 znaků.';
		}

		if ($password !== $passwordVerify && Helpers::length((string) $password) >= 1) {
			$errors[] = 'Hesla se musí shodovat.';
		}

		if (($vop = trim($input['vop'] ?? '') ? : null) === null || $vop !== 'yes') {
			$errors[] = 'Musíte souhlasit s podmínkami služby.';
		}

		if ($errors === []) {
			try { // 1. Create admin user profile
				$user = $this->userStorage->create('admin', [
					'username' => $username,
					'password' => (new Passwords)->hash((string) $password),
					'firstName' => Helpers::firstUpper((string) $firstName),
					'lastName' => Helpers::firstUpper((string) $lastName),
					'mail' => $mail,
				]);
			} catch (UserManagerException $e) {
				$errors[] = 'Nepodařilo se vytvořit uživatelský účet.';
				$message = 'Systémová chyba: ' . $e->getMessage();
				$user = null;
			}

			if ($user !== null) {
				// 2. Write whole application config
				FileSystem::write(
					__DIR__ . '/../../../../../../data/config.json',
					json_encode([
						'title' => $title,
						'primaryMail' => $mail,
						'admin' => $username,
						'adminId' => $user->getId(),
					], JSON_PRETTY_PRINT)
				);

				// 3. Clear cache => remove temp + create new cache dir
				FileSystem::delete($tempPath = __DIR__ . '/../../../../../../temp');
				FileSystem::createDir($tempPath . '/cache');
			}
		}

		header('Content-Type: application/json');
		echo json_encode([
			'ok' => $errors === [],
			'message' => $errors !== [] ? 'Některá pole nebyla vyplněna správně.' : $message,
			'errors' => $errors,
		]);
		die;
	}

	/**
	 * Moved from nette/utils
	 *
	 * @param string $value
	 * @return bool
	 */
	private function isEmail(string $value): bool
	{
		$atom = "[-a-z0-9!#$%&'*+/=?^_`{|}~]"; // RFC 5322 unquoted characters in local-part
		$alpha = "a-z\x80-\xFF"; // superset of IDN

		return (bool) preg_match("(^
			(\"([ !#-[\\]-~]*|\\\\[ -~])+\"|$atom+(\\.$atom+)*)  # quoted or unquoted
			@
			([0-9$alpha]([-0-9$alpha]{0,61}[0-9$alpha])?\\.)+    # domain - RFC 1034
			[$alpha]([-0-9$alpha]{0,17}[$alpha])?                # top domain
		$)Dix", $value);
	}

}