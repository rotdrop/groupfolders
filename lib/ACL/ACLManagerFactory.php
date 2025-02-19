<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupFolders\ACL;

use OCA\GroupFolders\Trash\TrashManager;
use OCP\IConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class ACLManagerFactory {
	private $rootFolderProvider;
	private $managers = [];

	public function __construct(
		private RuleManager $ruleManager,
		private TrashManager $trashManager,
		private IConfig $config,
		private LoggerInterface $logger,
		callable $rootFolderProvider,
	) {
		$this->rootFolderProvider = $rootFolderProvider;
	}

	public function getACLManager(IUser $user, ?int $rootStorageId = null): ACLManager {
		$userId = $user->getUID();
		if (empty($this->managers[$userId][$rootStorageId ?? 0])) {
			$this->managers[$userId][$rootStorageId ?? 0] = new ACLManager(
				$this->ruleManager,
				$this->trashManager,
				$this->logger,
				$user,
				$this->rootFolderProvider,
				$rootStorageId,
				$this->config->getAppValue('groupfolders', 'acl-inherit-per-user', 'false') === 'true',
			);
		}
		return $this->managers[$userId][$rootStorageId ?? 0];
	}
}
