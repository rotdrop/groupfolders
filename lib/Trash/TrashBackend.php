<?php
/**
 * @copyright Copyright (c) 2018 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\GroupFolders\Trash;

use OC\Files\Storage\Wrapper\Jail;
use OCA\Files_Trashbin\Expiration;
use OCA\Files_Trashbin\Trash\ITrashBackend;
use OCA\Files_Trashbin\Trash\ITrashItem;
use OCA\GroupFolders\ACL\ACLManagerFactory;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\GroupFolderStorage;
use OCA\GroupFolders\Mount\MountProvider;
use OCA\GroupFolders\Versions\VersionsBackend;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\IStorage;
use OCP\Files\IRootFolder;
use OCP\IUser;

class TrashBackend implements ITrashBackend {
	/** @var FolderManager */
	private $folderManager;

	/** @var TrashManager */
	private $trashManager;

	/** @var Folder */
	private $appFolder;

	/** @var MountProvider */
	private $mountProvider;

	/** @var ACLManagerFactory */
	private $aclManagerFactory;

	/** @var ?VersionsBackend */
	private $versionsBackend;

	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(
		FolderManager $folderManager,
		TrashManager $trashManager,
		Folder $appFolder,
		MountProvider $mountProvider,
		ACLManagerFactory $aclManagerFactory,
		IRootFolder $rootFolder
	) {
		$this->folderManager = $folderManager;
		$this->trashManager = $trashManager;
		$this->appFolder = $appFolder;
		$this->mountProvider = $mountProvider;
		$this->aclManagerFactory = $aclManagerFactory;
		$this->rootFolder = $rootFolder;
	}

	public function setVersionsBackend(VersionsBackend $versionsBackend): void {
		$this->versionsBackend = $versionsBackend;
	}

	public function listTrashRoot(IUser $user): array {
		$folders = $this->folderManager->getFoldersForUser($user);
		return $this->getTrashForFolders($user, $folders);
	}

	public function listTrashFolder(ITrashItem $trashItem): array {
		if (!$trashItem instanceof GroupTrashItem) {
			return [];
		}
		$user = $trashItem->getUser();
		$folder = $this->getNodeForTrashItem($user, $trashItem);
		if (!$folder instanceof Folder) {
			return [];
		}
		$content = $folder->getDirectoryListing();
		return array_map(function (Node $node) use ($trashItem, $user) {
			return new GroupTrashItem(
				$this,
				$trashItem->getOriginalLocation() . '/' . $node->getName(),
				$trashItem->getDeletedTime(),
				$trashItem->getTrashPath() . '/' . $node->getName(),
				$node,
				$user,
				$trashItem->getGroupFolderMountPoint()
			);
		}, $content);
	}

	/**
	 * @return void
	 */
	public function restoreItem(ITrashItem $item) {
		if (!($item instanceof GroupTrashItem)) {
			throw new \LogicException('Trying to restore normal trash item in group folder trash backend');
		}
		$user = $item->getUser();
		[, $folderId] = explode('/', $item->getTrashPath());
		$node = $this->getNodeForTrashItem($user, $item);
		if ($node === null) {
			throw new NotFoundException();
		}
		if (!$this->userHasAccessToPath($item->getUser(), $folderId . '/' . $item->getOriginalLocation(), Constants::PERMISSION_UPDATE)) {
			throw new NotPermittedException();
		}
		$folderPermissions = $this->folderManager->getFolderPermissionsForUser($item->getUser(), (int)$folderId);
		if (($folderPermissions & Constants::PERMISSION_UPDATE) !== Constants::PERMISSION_UPDATE) {
			throw new NotPermittedException();
		}

		$trashStorage = $node->getStorage();
		/** @var Folder $targetFolder */
		$targetFolder = $this->mountProvider->getFolder((int)$folderId);
		$originalLocation = $item->getOriginalLocation();
		$parent = dirname($originalLocation);
		if ($parent === '.') {
			$parent = '';
		}
		if ($parent !== '' && !$targetFolder->nodeExists($parent)) {
			$originalLocation = basename($originalLocation);
		}

		if ($targetFolder->nodeExists($originalLocation)) {
			$info = pathinfo($originalLocation);
			$i = 1;

			$gen = function ($info, int $i): string {
				$target = $info['dirname'];
				if ($target === '.') {
					$target = '';
				}

				$target .= $info['filename'];
				$target .= ' (' . $i . ')';

				if (isset($info['extension'])) {
					$target .= $info['extension'];
				}

				return $target;
			};

			do {
				$originalLocation = $gen($info, $i);
				$i++;
			} while ($targetFolder->nodeExists($originalLocation));
		}

		$targetLocation = $targetFolder->getInternalPath() . '/' . $originalLocation;
		$targetFolder->getStorage()->moveFromStorage($trashStorage, $node->getInternalPath(), $targetLocation);
		$targetFolder->getStorage()->getCache()->moveFromCache($trashStorage->getCache(), $node->getInternalPath(), $targetLocation);
		$this->trashManager->removeItem((int)$folderId, $item->getName(), $item->getDeletedTime());
	}

	/**
	 * @return void
	 */
	public function removeItem(ITrashItem $item) {
		if (!($item instanceof GroupTrashItem)) {
			throw new \LogicException('Trying to remove normal trash item in group folder trash backend');
		}
		$user = $item->getUser();
		[, $folderId] = explode('/', $item->getTrashPath());
		$node = $this->getNodeForTrashItem($user, $item);
		if ($node === null) {
			throw new NotFoundException();
		}
		if ($node->getStorage()->unlink($node->getInternalPath()) === false) {
			throw new \Exception('Failed to remove item from trashbin');
		}
		if (!$this->userHasAccessToPath($item->getUser(), $folderId . '/' . $item->getOriginalLocation(), Constants::PERMISSION_DELETE)) {
			throw new NotPermittedException();
		}

		$node->getStorage()->getCache()->remove($node->getInternalPath());
		if ($item->isRootItem()) {
			$this->trashManager->removeItem((int)$folderId, $item->getName(), $item->getDeletedTime());
		}
	}

	public function moveToTrash(IStorage $storage, string $internalPath): bool {
		if ($storage->instanceOfStorage(GroupFolderStorage::class) && $storage->isDeletable($internalPath)) {
			/** @var GroupFolderStorage|Jail $storage */
			$name = basename($internalPath);
			$fileEntry = $storage->getCache()->get($internalPath);
			$folderId = $storage->getFolderId();
			$trashFolder = $this->getTrashFolder($folderId);
			$trashStorage = $trashFolder->getStorage();
			$time = time();
			$trashName = $name . '.d' . $time;
			[$unJailedStorage, $unJailedInternalPath] = $this->unwrapJails($storage, $internalPath);
			$targetInternalPath = $trashFolder->getInternalPath() . '/' . $trashName;
			if ($trashStorage->moveFromStorage($unJailedStorage, $unJailedInternalPath, $targetInternalPath)) {
				$this->trashManager->addTrashItem($folderId, $name, $time, $internalPath, $fileEntry->getId());
				if ($trashStorage->getCache()->getId($targetInternalPath) !== $fileEntry->getId()) {
					$trashStorage->getCache()->moveFromCache($unJailedStorage->getCache(), $unJailedInternalPath, $targetInternalPath);
				}
			} else {
				throw new \Exception("Failed to move groupfolder item to trash");
			}
			return true;
		} else {
			return false;
		}
	}

	private function unwrapJails(IStorage $storage, string $internalPath): array {
		$unJailedInternalPath = $internalPath;
		$unJailedStorage = $storage;
		while ($unJailedStorage->instanceOfStorage(Jail::class)) {
			$unJailedStorage = $unJailedStorage->getWrapperStorage();
			if ($unJailedStorage instanceof Jail) {
				$unJailedInternalPath = $unJailedStorage->getUnjailedPath($unJailedInternalPath);
			}
		}
		return [$unJailedStorage, $unJailedInternalPath];
	}

	private function userHasAccessToFolder(IUser $user, int $folderId): bool {
		$folders = $this->folderManager->getFoldersForUser($user);
		$folderIds = array_map(function (array $folder) {
			return $folder['folder_id'];
		}, $folders);
		return in_array($folderId, $folderIds);
	}

	private function userHasAccessToPath(IUser $user, string $path, int $permission = Constants::PERMISSION_READ): bool {
		$activePermissions = $this->aclManagerFactory->getACLManager($user)
			->getACLPermissionsForPath('__groupfolders/' . ltrim($path, '/'));
		return (bool)($activePermissions & $permission);
	}

	private function getNodeForTrashItem(IUser $user, ITrashItem $trashItem): ?Node {
		[, $folderId, $path] = explode('/', $trashItem->getTrashPath(), 3);
		$folders = $this->folderManager->getFoldersForUser($user);
		foreach ($folders as $groupFolder) {
			if ($groupFolder['folder_id'] === (int)$folderId) {
				$trashRoot = $this->getTrashFolder((int)$folderId);
				try {
					$node = $trashRoot->get($path);
					if (!$this->userHasAccessToPath($user, $folderId . '/' . $trashItem->getOriginalLocation())) {
						return null;
					}
					return $node;
				} catch (NotFoundException $e) {
					return null;
				}
			}
		}
		return null;
	}

	private function getTrashFolder(int $folderId): Folder {
		try {
			return $this->appFolder->get('trash/' . $folderId);
		} catch (NotFoundException $e) {
			/** @var Folder $trashRoot */
			$trashRoot = $this->appFolder->nodeExists('trash') ? $this->appFolder->get('trash') : $this->appFolder->newFolder('trash');
			return $trashRoot->newFolder((string)$folderId);
		}
	}

	/**
	 * @return list<GroupTrashItem>
	 */
	private function getTrashForFolders(IUser $user, array $folders): array {
		$folderIds = array_map(function (array $folder) {
			return $folder['folder_id'];
		}, $folders);
		$rows = $this->trashManager->listTrashForFolders($folderIds);
		$indexedRows = [];
		foreach ($rows as $row) {
			$key = $row['folder_id'] . '/' . $row['name'] . '/' . $row['deleted_time'];
			$indexedRows[$key] = $row;
		}
		$items = [];
		foreach ($folders as $folder) {
			$folderId = $folder['folder_id'];
			$mountPoint = $folder['mount_point'];
			$trashFolder = $this->getTrashFolder($folderId);
			$content = $trashFolder->getDirectoryListing();
			foreach ($content as $item) {
				/** @var \OC\Files\Node\Node $item */
				$pathParts = pathinfo($item->getName());
				$timestamp = (int)substr($pathParts['extension'], 1);
				$name = $pathParts['filename'];
				$key = $folderId . '/' . $name . '/' . $timestamp;
				$originalLocation = isset($indexedRows[$key]) ? $indexedRows[$key]['original_location'] : '';
				if (!$this->userHasAccessToPath($user, $folderId . '/' . $originalLocation)) {
					continue;
				}
				$info = $item->getFileInfo();
				$info['name'] = $name;
				$items[] = new GroupTrashItem(
					$this,
					$originalLocation,
					$timestamp,
					'/' . $folderId . '/' . $item->getName(),
					$info,
					$user,
					$mountPoint
				);
			}
		}
		return $items;
	}

	public function getTrashNodeById(IUser $user, int $fileId): ?Node {
		try {
			/** @var Folder $trashFolder */
			$trashFolder = $this->appFolder->get('trash');
			$storage = $this->appFolder->getStorage();
			$path = $storage->getCache()->getPathById($fileId);
			if (!$path) {
				return null;
			}
			$absolutePath = $this->appFolder->getMountPoint()->getMountPoint() . $path;
			$relativePath = $trashFolder->getRelativePath($absolutePath);
			[, $folderId, $nameAndTime] = explode('/', $relativePath);
			$trashItem = $this->trashManager->getTrashItemByFileId($fileId);
			$originalPath = $folderId . '/' . ($trashItem ? $trashItem['original_location'] : '/');
			if ($this->userHasAccessToFolder($user, (int)$folderId) && $this->userHasAccessToPath($user, $originalPath)) {
				return $trashFolder->get($relativePath);
			} else {
				return null;
			}
		} catch (NotFoundException $e) {
			return null;
		}
	}

	public function cleanTrashFolder(int $folderid): void {
		$trashFolder = $this->getTrashFolder($folderid);

		if (!($trashFolder instanceof Folder)) {
			return;
		}

		foreach ($trashFolder->getDirectoryListing() as $node) {
			$node->delete();
		}

		$this->trashManager->emptyTrashbin($folderid);
	}

	public function expire(Expiration $expiration): array {
		$size = 0;
		$count = 0;
		$folders = $this->folderManager->getAllFoldersWithSize($this->rootFolder->getMountPoint()->getNumericStorageId());
		foreach ($folders as $folder) {
			$folderId = $folder['id'];
			$trashItems = $this->trashManager->listTrashForFolders([$folderId]);

			// calculate size of trash items
			$sizeInTrash = 0;
			$trashFolder = $this->getTrashFolder($folderId);
			$nodes = []; // cache
			foreach ($trashItems as $groupTrashItem) {
				$nodeName = $groupTrashItem['name'] . '.d' . $groupTrashItem['deleted_time'];
				$nodes[$nodeName] = $node = $trashFolder->get($nodeName);
				$sizeInTrash += $node->getSize();
			}
			foreach ($trashItems as $groupTrashItem) {
				if ($expiration->isExpired($groupTrashItem['deleted_time'], $folder['quota'] < ($folder['size'] + $sizeInTrash))) {
					try {
						$nodeName = $groupTrashItem['name'] . '.d' . $groupTrashItem['deleted_time'];
						$node = $nodes[$nodeName];
						$size += $node->getSize();
						$count += 1;
					} catch (NotFoundException $e) {
						$this->trashManager->removeItem($folderId, $groupTrashItem['name'], $groupTrashItem['deleted_time']);
						continue;
					}
					if ($node->getStorage()->unlink($node->getInternalPath()) === false) {
						throw new \Exception('Failed to remove item from trashbin');
					}
					$node->getStorage()->getCache()->remove($node->getInternalPath());
					$this->trashManager->removeItem($folderId, $groupTrashItem['name'], $groupTrashItem['deleted_time']);
					if (!is_null($groupTrashItem['file_id']) && !is_null($this->versionsBackend)) {
						$this->versionsBackend->deleteAllVersionsForFile($folderId, $groupTrashItem['file_id']);
					}
				} else {
					break;
				}
			}
		}
		return [$count, $size];
	}
}
