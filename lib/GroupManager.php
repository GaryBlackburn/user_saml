<?php

namespace OCA\User_SAML;

use OC\BackgroundJob\JobList;
use OC\Hooks\PublicEmitter;
use OCA\User_SAML\Jobs\MigrateGroups;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

class GroupManager
{
	const LOCAL_GROUPS_CHECK_FOR_MIGRATION = 'localGroupsCheckForMigration';

	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	/**
	 * @var GroupDuplicateChecker
	 */
	protected $duplicateChecker;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IUserManager */
	private $userManager;
	/** @var GroupBackend */
	private $ownGroupBackend;
	/** @var IConfig */
	private $config;
	/** @var JobList */
	private $jobList;


	public function __construct(
		IDBConnection $db,
		GroupDuplicateChecker $duplicateChecker,
		IGroupManager $groupManager,
		IUserManager $userManager,
		GroupBackend $ownGroupBackend,
		IConfig $config,
		JobList $jobList
	) {
		$this->db = $db;
		$this->duplicateChecker = $duplicateChecker;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->ownGroupBackend = $ownGroupBackend;
		$this->config = $config;
		$this->jobList = $jobList;
	}

	public function replaceGroups($uid, $saml) {
		$user = $this->userManager->get($uid);
		if($user === null) {
			return;
		}
		$assigned = $this->groupManager->getUserGroups($uid);
		$this->removeGroups($user, array_diff($assigned, $saml));
		$this->addGroups($uid, array_diff($saml, $assigned));
	}

	public function removeGroups(IUser $user, array $groupIds) {
		foreach ($groupIds as $gid) {
			$this->removeGroup($user, $gid);
		}
	}

	public function removeGroup(IUser $user, string $gid) {
		$group = $this->groupManager->get($gid);
		if($group === null) {
			return;
		}
		$group->removeUser($user);
	}

	public function addGroups(IUser $user, $groupIds) {
		foreach ($groupIds as $gid) {
			$this->addGroup($user, $gid);
		}
	}

	public function addGroup(IUser $user, $gid) {
		$group = $this->groupManager->get($gid);
		if($group === null) {
			if($this->groupManager instanceof PublicEmitter) {
				$this->groupManager->emit('\OC\Group', 'preCreate', array($gid));
			}
			if(!$this->ownGroupBackend->createGroup($gid)) {
				return;
			}

			$group = $this->groupManager->get($gid);
			if($this->groupManager instanceof PublicEmitter) {
				$this->groupManager->emit('\OC\Group', 'postCreate', array($group));
			}
		}
		$group->addUser($user);
	}

	public function evaluateGroupMigrations(array $groups) {
		$candidateInfo = $this->config->getAppValue('user_saml', self::LOCAL_GROUPS_CHECK_FOR_MIGRATION, null);
		if($candidateInfo === null) {
			return;
		}
		$candidateInfo = \json_decode($candidateInfo, true);
		if(!isset($candidateInfo['dropAfter']) || $candidateInfo['dropAfter'] < time()) {
			$this->config->deleteAppValue('user_saml', self::LOCAL_GROUPS_CHECK_FOR_MIGRATION);
			return;
		}

		$this->jobList->add(MigrateGroups::class, ['gids' => $groups]);
	}
}
