<?php

declare(strict_types=1);


/**
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2021
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


namespace OCA\Circles\Command;


use daita\MySmallPhpTools\Exceptions\InvalidItemException;
use daita\MySmallPhpTools\Exceptions\ItemNotFoundException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use daita\MySmallPhpTools\Traits\TStringTools;
use Exception;
use OC\Core\Command\Base;
use OCA\Circles\AppInfo\Application;
use OCA\Circles\Db\CoreQueryBuilder;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\Member;
use OCA\Circles\Service\ConfigService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;


/**
 * Class CirclesTest
 *
 * @package OCA\Circles\Command
 */
class CirclesTest extends Base {


	use TArrayTools;
	use TStringTools;


	static $INSTANCES = [
		'global-scale-1',
		'global-scale-2',
		'global-scale-3',
		'passive',
		'external',
		'trusted'
	];


	/** @var CoreQueryBuilder */
	private $coreQueryBuilder;

	/** @var ConfigService */
	private $configService;


	/** @var InputInterface */
	private $input;

	/** @var OutputInterface */
	private $output;

	/** @var array */
	private $config = [];

	/** @var string */
	private $local = '';

	/** @var bool */
	private $pOn = false;

	/** @var array */
	private $circles = [];

	/** @var array */
	private $members = [];

	/**
	 * CirclesTest constructor.
	 *
	 * @param CoreQueryBuilder $coreQueryBuilder
	 * @param ConfigService $configService
	 */
	public function __construct(CoreQueryBuilder $coreQueryBuilder, ConfigService $configService) {
		parent::__construct();

		$this->coreQueryBuilder = $coreQueryBuilder;
		$this->configService = $configService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('circles:test')
			 ->setDescription('testing some features')
			 ->addArgument('deprecated', InputArgument::OPTIONAL, '')
			 ->addOption(
				 'are-you-aware-this-will-delete-all-my-data', '', InputOption::VALUE_REQUIRED,
				 'Well, are you ?', ''
			 )
			 ->addOption('bypass-init', '', InputOption::VALUE_NONE, 'Bypass Initialisation');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 * @throws ItemNotFoundException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->input = $input;
		$this->output = $output;

		if ($input->getOption('are-you-aware-this-will-delete-all-my-data') === 'yes-i-am') {
			try {
				$this->testCirclesApp();
			} catch (Exception $e) {
				if ($this->pOn) {
					$this->output->writeln('<error>' . $e->getMessage() . '</error>');
				} else {
					throw $e;
				}
			}

			return 0;
		}

		$output->writeln('');
		$output->writeln(
			'<error>Since Nextcloud 22, this command have changed, please read the message below:</error>'
		);
		$output->writeln('<error>This new command is to test the integrity of the Circles App.</error>');
		$output->writeln(
			'<error>Running this command will REMOVE all your current configuration and all your current Circles.</error>'
		);
		$output->writeln('<error>There is a huge probability that you do not want to do that.</error>');
		$output->writeln('');
		$output->writeln(
			'<error>The old testing command you might looking for have moved to "./occ circles:check"</error>'
		);
		$output->writeln('');

		return 0;
	}


	/**
	 * @throws ItemNotFoundException
	 */
	private function testCirclesApp() {
		$this->t('Bootup');
		$this->loadConfiguration();

		if (!$this->input->getOption('bypass-init')) {
			$this->t('Initialisation');
			$this->initEnvironment();
			$this->reloadCirclesApp();
			$this->configureCirclesApp();
			$this->confirmVersion();
			$this->confirmEmptyCircles();
			$this->syncCircles();
		}

		$this->t('Fresh installation status');
		$this->statusFreshInstances();
		$this->createRemoteLink();
	}


	/**
	 * @throws ItemNotFoundException
	 */
	private function loadConfiguration() {
		$this->p('Loading configuration');
		$configuration = file_get_contents(__DIR__ . '/../../testConfiguration.json');
		$this->config = json_decode($configuration, true);
		$this->r(true, 'testConfiguration.json');

		$this->p('Checking configuration');
		foreach (self::$INSTANCES as $instance) {
			$cloudId = $this->getConfig($instance, 'config.frontal_cloud_id');
			if ($this->configService->isLocalInstance($cloudId)) {
				$this->local = $instance;
			}
		}
		$this->r();

		$this->p('Checking local');
		if ($this->local === '') {
			throw new ItemNotFoundException('local not defined');
		}
		$this->r(true, $this->local);
	}


	/**
	 * @throws ItemNotFoundException
	 */
	private function initEnvironment() {
		foreach ($this->getInstances() as $instance) {
			$this->p('Creating users on ' . $instance);
			foreach ($this->getConfigArray($instance, 'users') as $userId) {
				$this->pm($userId);
				$this->occ($instance, 'user:add ' . $userId, false, false);
			}
			$this->r();

			foreach ($this->getConfigArray($instance, 'groups') as $groupId => $users) {
				$this->p('Creating group <info>' . $groupId . '</info> on <info>' . $instance . '</info>');
				$this->occ($instance, 'group:add ' . $groupId, false, false);
				foreach ($users as $userId) {
					$this->pm($userId);
					$this->occ($instance, 'group:adduser ' . $groupId . ' ' . $userId, true, false);
				}
				$this->r();
			}

		}
	}


	/**
	 * @throws ItemNotFoundException
	 */
	private function reloadCirclesApp() {
		$this->p('Reload Circles App');
		foreach ($this->getInstances(false) as $instance) {
			$this->pm($instance);
			$this->occ($instance, 'circles:clean --uninstall', false, false);
			$this->occ($instance, 'app:enable circles', true, false);
		}
		$this->r();

		$this->p('Empty Circles database on local');
		$this->coreQueryBuilder->cleanDatabase();
		$this->r();
	}


	/**
	 * @throws ItemNotFoundException
	 */
	private function configureCirclesApp() {
		$this->p('Configure Circles App');
		foreach ($this->getInstances(true) as $instance) {
			$this->pm($instance);
			foreach ($this->getConfigArray($instance, 'config') as $k => $v) {
				$this->occ($instance, 'config:app:set --value ' . $v . ' circles ' . $k, true, false);
			}
		}
		$this->r();
	}


	/**
	 * @throws ItemNotFoundException
	 * @throws Exception
	 */
	private function confirmVersion() {
		$version = $this->configService->getAppValue('installed_version');
		$this->p('Confirming version <info>' . $version . '</info>');
		foreach ($this->getInstances(false) as $instance) {
			$this->pm($instance);
			$capabilities = $this->occ($instance, 'circles:check --capabilities');
			$v = $this->get('version', $capabilities);
			if ($v !== $version) {
				throw new Exception($v);
			}
		}
		$this->r();
	}


	/**
	 * @throws ItemNotFoundException
	 * @throws Exception
	 */
	private function confirmEmptyCircles() {
		$this->p('Confirming empty database');
		foreach ($this->getInstances() as $instance) {
			$this->pm($instance);
			$result = $this->occ($instance, 'circles:manage:list --all');
			if (!is_array($result) || !empty($result)) {
				throw new Exception('no');
			}
		}
		$this->r();
	}


	/**
	 * @throws ItemNotFoundException
	 * @throws Exception
	 */
	private function syncCircles() {
		$this->p('Running Circles Sync');
		foreach ($this->getInstances() as $instance) {
			$this->pm($instance);
			$this->occ($instance, 'circles:sync');
		}
		$this->r();
	}


	/**
	 * @throws CircleNotFoundException
	 * @throws ItemNotFoundException
	 * @throws InvalidItemException
	 */
	private function statusFreshInstances() {
		foreach ($this->getInstances() as $instanceId) {
			$this->p('Circles on ' . $instanceId);
			$result = $this->occ($instanceId, 'circles:manage:list --all');
			$expectedSize = sizeof($this->getConfigArray($instanceId, 'groups'))
							+ sizeof($this->getConfigArray($instanceId, 'users'))
							+ 1;
			$this->r((sizeof($result) === $expectedSize), sizeof($result) . ' circles');

			$_members = $_groups = [];
			foreach ($result as $item) {
				$circle = new Circle();
				$circle->import($item);
				if ($circle->isConfig(Circle::CFG_SINGLE)) {
					$_members[] = $circle;
				}

				if ($circle->getSource() === Member::TYPE_GROUP) {
					$_groups[] = $circle;
				}
			}

			$instance = $this->getConfig($instanceId, 'config.frontal_cloud_id');

			foreach ($this->getConfigArray($instanceId, 'users') as $userId) {
				$this->p('Checking Single Circle for <comment>' . $userId . '@' . $instance . '</comment>');
				$circle = $this->getSingleCircleForMember($_members, $userId, $instance);

				$compareToOwnerBasedOn = new Circle();
				$compareToOwnerBasedOn->setConfig(Circle::CFG_SINGLE)
									  ->setName('user:' . $userId . ':{CIRCLEID}')
									  ->setDisplayName('user:' . $userId . ':{CIRCLEID}');

				$compareToOwner = new Member();
				$compareToOwner->setUserId($userId)
							   ->setUserType(Member::TYPE_USER)
							   ->setInstance($instance)
							   ->setDisplayName($userId)
							   ->setId('{CIRCLEID}')
							   ->setCircleId('{CIRCLEID}')
							   ->setSingleId('{CIRCLEID}')
							   ->setStatus(Member::STATUS_MEMBER)
							   ->setLevel(Member::LEVEL_OWNER)
							   ->setBasedOn($compareToOwnerBasedOn);

				$compareTo = new Circle();
				$compareTo->setOwner($compareToOwner)
						  ->setConfig(Circle::CFG_SINGLE)
						  ->setName('user:' . $userId . ':{CIRCLEID}')
						  ->setDisplayName('user:' . $userId . ':{CIRCLEID}');

				$this->confirmCircleData($circle, $compareTo);
				$this->r(true, $circle->getId());
			}

			$this->p('Checking Single Circle for <comment>Circles App</comment>');
			$circle = $this->getSingleCircleForMember($_members, 'circles', $instance);

			$compareToOwnerBasedOn = new Circle();
			$compareToOwnerBasedOn->setConfig(Circle::CFG_SINGLE | Circle::CFG_ROOT)
								  ->setName('app:circles:{CIRCLEID}')
								  ->setDisplayName('app:circles:{CIRCLEID}');

			$compareToOwner = new Member();
			$compareToOwner->setUserId(Application::APP_ID)
						   ->setUserType(Member::TYPE_APP)
						   ->setInstance($instance)
						   ->setDisplayName(Application::APP_ID)
						   ->setId('{CIRCLEID}')
						   ->setCircleId('{CIRCLEID}')
						   ->setSingleId('{CIRCLEID}')
						   ->setStatus(Member::STATUS_MEMBER)
						   ->setLevel(Member::LEVEL_ADMIN)
						   ->setBasedOn($compareToOwnerBasedOn);

			$compareTo = new Circle();
			$compareTo->setOwner($compareToOwner)
					  ->setConfig(Circle::CFG_SINGLE | Circle::CFG_ROOT)
					  ->setName('app:circles:{CIRCLEID}')
					  ->setDisplayName('app:circles:{CIRCLEID}');

			$this->confirmCircleData($circle, $compareTo);
			$this->r(true, $circle->getId());

			foreach ($this->getConfigArray($instanceId, 'groups') as $groupId => $members) {
				$this->p('Checking Circle for <comment>' . $groupId . '@' . $instance . '</comment>');
				$circle = $this->getCircleByName($_groups, 'group:' . $groupId);

				$appCircle = $this->getSingleCircleForMember($_members, 'circles', $instance);
				$appOwner = $appCircle->getOwner();

				$compareToOwnerBasedOn = new Circle();
				$compareToOwnerBasedOn->setConfig(Circle::CFG_SINGLE | Circle::CFG_ROOT)
									  ->setName($appCircle->getName())
									  ->setDisplayName($appCircle->getDisplayName());

				$compareToOwner = new Member();
				$compareToOwner->setUserId($appOwner->getUserId())
							   ->setUserType($appOwner->getUserType())
							   ->setInstance($appOwner->getInstance())
							   ->setDisplayName($appOwner->getDisplayName())
							   ->setCircleId('{CIRCLEID}')
							   ->setSingleId($appOwner->getSingleId())
							   ->setStatus($appOwner->getStatus())
							   ->setLevel($appOwner->getLevel())
							   ->setBasedOn($compareToOwnerBasedOn);

				$compareTo = new Circle();
				$compareTo->setOwner($compareToOwner)
						  ->setConfig(Circle::CFG_SYSTEM | Circle::CFG_NO_OWNER | Circle::CFG_HIDDEN)
						  ->setName('group:' . $groupId)
						  ->setDisplayName($groupId);

				$this->confirmCircleData($circle, $compareTo);
				$this->r(true, $circle->getId());
			}

			$this->output->writeln('');
		}
	}


	private function createRemoteLink() {
		foreach ($this->getInstances() as $instanceId) {
			$this->p('Init remote link from ' . $instanceId);
			$links = $this->getConfigArray($instanceId, 'remote');
			foreach ($links as $link => $type) {
				$remote = $this->getConfig($link, 'config.frontal_cloud_id');
				$this->pm($remote . '(' . $type . ')');
				$this->occ($instanceId, 'circles:remote ' . $remote . ' --type ' . $type . ' --yes');
			}
			$this->r();
		}
	}


	/**
	 * @param array $circles
	 * @param string $userId
	 * @param string $instance
	 *
	 * @return Circle
	 * @throws CircleNotFoundException
	 */
	private function getSingleCircleForMember(array $circles, string $userId, string $instance): Circle {
		foreach ($circles as $circle) {
			$owner = $circle->getOwner();
			if ($owner->getUserId() === $userId && $owner->getInstance() === $instance) {
				return $circle;
			}
		}

		throw new CircleNotFoundException('cannot find ' . $userId . ' in the list of Single Circle');
	}


	/**
	 * @param array $circles
	 * @param string $name
	 *
	 * @return Circle
	 * @throws CircleNotFoundException
	 */
	private function getCircleByName(array $circles, string $name): Circle {
		foreach ($circles as $circle) {
			if ($circle->getName() === $name) {
				return $circle;
			}
		}

		throw new CircleNotFoundException('cannot find \'' . $name . '\' in the list of Circles');
	}

	/**
	 * @param Circle $circle
	 * @param Circle $compareTo
	 *
	 * @throws Exception
	 */
	private function confirmCircleData(Circle $circle, Circle $compareTo) {
		$params = [
			'CIRCLEID' => $circle->getId()
		];


		if ($compareTo->getName() !== ''
			&& $this->feedStringWithParams($compareTo->getName(), $params) !== $circle->getName()) {
			throw new Exception('wrong circle.name');
		}
		if ($compareTo->getDisplayName() !== ''
			&& $this->feedStringWithParams($compareTo->getDisplayName(), $params)
			   !== $circle->getDisplayName()) {
			throw new Exception('wrong circle.displayName: ' . $circle->getDisplayName());
		}
		if ($compareTo->getConfig() > 0
			&& $compareTo->getConfig() !== $circle->getConfig()) {
			throw new Exception('wrong circle.config: ' . $circle->getConfig());
		}

		$compareToOwner = $compareTo->getOwner();
		if ($compareToOwner !== null) {
			$owner = $circle->getOwner();
			if ($owner === null) {
				throw new Exception('empty owner');
			}
			if ($owner->getCircleId() !== $circle->getId()) {
				throw new Exception('owner.circleId is different than circle.id');
			}
			if ($compareToOwner->getId() !== ''
				&& $this->feedStringWithParams($compareToOwner->getId(), $params) !== $owner->getId()) {
				throw new Exception('wrong owner.memberId');
			}
			if ($compareToOwner->getCircleId() !== ''
				&& $this->feedStringWithParams($compareToOwner->getCircleId(), $params)
				   !== $owner->getCircleId()) {
				throw new Exception('wrong owner.circleId');
			}
			if ($compareToOwner->getSingleId() !== ''
				&& $this->feedStringWithParams($compareToOwner->getSingleId(), $params)
				   !== $owner->getSingleId()) {
				throw new Exception('wrong owner.singleId');
			}
			if ($compareToOwner->getUserId() !== ''
				&& $this->feedStringWithParams($compareToOwner->getUserId(), $params) !== $owner->getUserId(
				)) {
				throw new Exception('wrong owner.userId');
			}
			if ($compareToOwner->getInstance() !== ''
				&& $this->feedStringWithParams($compareToOwner->getInstance(), $params)
				   !== $owner->getInstance()) {
				throw new Exception('wrong owner.instance');
			}
			if ($compareToOwner->getUserType() > 0
				&& $compareToOwner->getUserType() !== $owner->getUserType()) {
				throw new Exception('wrong owner.userType');
			}

			$compareToOwnerBasedOn = $compareToOwner->getBasedOn();
			if ($compareToOwnerBasedOn !== null) {
				$basedOn = $owner->getBasedOn();
				if ($basedOn === null) {
					throw new Exception('empty basedOn');
				}
				if ($compareToOwnerBasedOn->getName() !== ''
					&& $this->feedStringWithParams($compareToOwnerBasedOn->getName(), $params)
					   !== $basedOn->getName()) {
					throw new Exception('wrong basedOn.name');
				}
				if ($compareToOwnerBasedOn->getDisplayName() !== ''
					&& $this->feedStringWithParams($compareToOwnerBasedOn->getDisplayName(), $params)
					   !== $basedOn->getDisplayName()) {
					throw new Exception('wrong basedOn.displayName');
				}
				if ($compareToOwnerBasedOn->getConfig() > 0
					&& $compareToOwnerBasedOn->getConfig() !== $basedOn->getConfig()) {
					throw new Exception('wrong basedOn.config');
				}
			}
		}

	}


	/**
	 * @param bool $localIncluded
	 *
	 * @return array
	 */
	private function getInstances(bool $localIncluded = true): array {
		$instances = self::$INSTANCES;
		if (!$localIncluded) {
			$instances = array_diff($instances, [$this->local]);
		}

		return $instances;
	}


	/**
	 * @param string $instance
	 * @param string $key
	 *
	 * @return string
	 * @throws ItemNotFoundException
	 */
	private function getConfig(string $instance, string $key): string {
		$config = $this->getConfigInstance($instance);

		return $this->get($key, $config);
	}

	/**
	 * @param string $instance
	 * @param string $key
	 *
	 * @return array
	 * @throws ItemNotFoundException
	 */
	private function getConfigArray(string $instance, string $key): array {
		$config = $this->getConfigInstance($instance);

		return $this->getArray($key, $config);
	}


	/**
	 * @param string $instance
	 *
	 * @return array
	 * @throws ItemNotFoundException
	 */
	private function getConfigInstance(string $instance): array {
		foreach ($this->getArray('instances', $this->config) as $item) {
			if (strtolower($this->get('id', $item)) === strtolower($instance)) {
				return $item;
			}
		}

		throw new ItemNotFoundException($instance . ' not found');
	}


	/**
	 * @param string $instance
	 * @param string $cmd
	 * @param bool $exceptionOnFail
	 * @param bool $jsonAsOutput
	 *
	 * @return array
	 * @throws ItemNotFoundException
	 * @throws Exception
	 */
	private function occ(
		string $instance,
		string $cmd,
		bool $exceptionOnFail = true,
		bool $jsonAsOutput = true
	): ?array {
		$configInstance = $this->getConfigInstance($instance);
		$path = $this->get('path', $configInstance);
		$occ = rtrim($path, '/') . '/occ';

		$command = array_merge([$occ], explode(' ', $cmd));
		if ($jsonAsOutput) {
			$command = array_merge($command, ['--output=json']);
		}
		$process = new Process($command);
		$process->run();

		if ($exceptionOnFail && !$process->isSuccessful()) {
			throw new Exception(implode(' ', $command) . ' failed');
		}

		$output = json_decode($process->getOutput(), true);
		if (!is_array($output)) {
			return null;
		}

		return $output;
	}



	//
	//
	//


	/**
	 * @param string $title
	 */
	private function t(string $title): void {
		$this->output->writeln('');
		$this->output->writeln('<comment>### ' . $title . '</comment>');
		$this->output->writeln('');
	}

	/**
	 * @param string $processing
	 */
	private function p(string $processing): void {
		$this->pOn = true;
		$this->output->write('- ' . $processing . ': ');
	}

	/**
	 * @param string $more
	 */
	private function pm(string $more): void {
		$this->output->write($more . ' ');
	}

	/**
	 * @param bool $result
	 * @param string $info
	 */
	private function r(bool $result = true, string $info = ''): void {
		$this->pOn = false;
		if ($result) {
			$this->output->writeln('<info>' . (($info !== '') ? $info : 'done') . '</info>');
		} else {
			$this->output->writeln('<error>' . (($info !== '') ? $info : 'done') . '</error>');
		}
	}

}

