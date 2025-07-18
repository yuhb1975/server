<?php

/**
 * SPDX-FileCopyrightText: 2017-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Encryption;

use OC\Files\Storage\Storage;
use OC\Files\View;
use OCA\Encryption\Crypto\Crypt;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\PreConditionNotMetException;

class Util {
	private IUser|false $user;

	public function __construct(
		private View $files,
		private Crypt $crypt,
		IUserSession $userSession,
		private IConfig $config,
		private IUserManager $userManager,
	) {
		$this->user = $userSession->isLoggedIn() ? $userSession->getUser() : false;
	}

	/**
	 * check if recovery key is enabled for user
	 *
	 * @param string $uid
	 * @return bool
	 */
	public function isRecoveryEnabledForUser($uid) {
		$recoveryMode = $this->config->getUserValue($uid,
			'encryption',
			'recoveryEnabled',
			'0');

		return ($recoveryMode === '1');
	}

	/**
	 * check if the home storage should be encrypted
	 *
	 * @return bool
	 */
	public function shouldEncryptHomeStorage() {
		$encryptHomeStorage = $this->config->getAppValue(
			'encryption',
			'encryptHomeStorage',
			'1'
		);

		return ($encryptHomeStorage === '1');
	}

	/**
	 * set the home storage encryption on/off
	 *
	 * @param bool $encryptHomeStorage
	 */
	public function setEncryptHomeStorage($encryptHomeStorage) {
		$value = $encryptHomeStorage ? '1' : '0';
		$this->config->setAppValue(
			'encryption',
			'encryptHomeStorage',
			$value
		);
	}

	/**
	 * check if master key is enabled
	 */
	public function isMasterKeyEnabled(): bool {
		$userMasterKey = $this->config->getAppValue('encryption', 'useMasterKey', '1');
		return ($userMasterKey === '1');
	}

	/**
	 * @param $enabled
	 * @return bool
	 */
	public function setRecoveryForUser($enabled) {
		$value = $enabled ? '1' : '0';

		try {
			$this->config->setUserValue($this->user->getUID(),
				'encryption',
				'recoveryEnabled',
				$value);
			return true;
		} catch (PreConditionNotMetException $e) {
			return false;
		}
	}

	/**
	 * @param string $uid
	 * @return bool
	 */
	public function userHasFiles($uid) {
		return $this->files->file_exists($uid . '/files');
	}

	/**
	 * get owner from give path, path relative to data/ expected
	 *
	 * @param string $path relative to data/
	 * @return string
	 * @throws \BadMethodCallException
	 */
	public function getOwner($path) {
		$owner = '';
		$parts = explode('/', $path, 3);
		if (count($parts) > 1) {
			$owner = $parts[1];
			if ($this->userManager->userExists($owner) === false) {
				throw new \BadMethodCallException('Unknown user: '
				. 'method expects path to a user folder relative to the data folder');
			}
		}

		return $owner;
	}

	public function getStorage(string $path): ?IStorage {
		return $this->files->getMount($path)->getStorage();
	}

}
