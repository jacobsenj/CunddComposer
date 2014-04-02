<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Daniel Corn <info@cundd.net>, cundd
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/



/**
 *
 *
 * @package cundd_composer
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 *
 */
class Tx_CunddComposer_Controller_PackageController extends Tx_Extbase_MVC_Controller_ActionController {

	/**
	 * The path to the PHP executable
	 *
	 * @var string
	 */
	protected $phpExecutable = '';

	/**
	 * The minimum stability
	 * http://getcomposer.org/doc/04-schema.md#minimum-stability
	 * @var string
	 */
	protected $minimumStability = 'dev';

	/**
	 * packageRepository
	 *
	 * @var Tx_CunddComposer_Domain_Repository_PackageRepository
	 */
	protected $packageRepository;

	/**
	 * Asset installer
	 * @var Tx_CunddComposer_Installer_AssetInstaller
	 */
	protected $assetInstaller;

	/**
	 * The merged composer.json
	 * @var array
	 */
	protected $mergedComposerJson;

	/**
	 * Enable or disable installation of development dependencies
	 *
	 * @var boolean
	 */
	protected $developmentDependencies = FALSE;


	/**
	 * injectPackageRepository
	 *
	 * @param Tx_CunddComposer_Domain_Repository_PackageRepository $packageRepository
	 * @return void
	 */
	public function injectPackageRepository(Tx_CunddComposer_Domain_Repository_PackageRepository $packageRepository) {
		$this->packageRepository = $packageRepository;
	}

	/**
	 * injectPackageRepository
	 *
	 * @param Tx_CunddComposer_Installer_AssetInstaller $assetInstaller
	 * @return void
	 */
	public function injectAssetInstaller(Tx_CunddComposer_Installer_AssetInstaller $assetInstaller) {
		$this->assetInstaller = $assetInstaller;
	}

	/**
	 * Initialize the action
	 */
	public function initializeAction() {
		if (isset($this->settings['minimum-stability'])) {
			$this->minimumStability = $this->settings['minimum-stability'];
		}
	}

	/**
	 * action list
	 *
	 * @return void
	 */
	public function listAction() {
		$packages = NULL;
		$mergedComposerJson = NULL;
		$mergedComposerJsonString = '';

		try {
			$packages = $this->packageRepository->findAll();
		} catch (\DomainException $exception) {
			$this->view->assign('error', $exception->getMessage());
		}
		$this->view->assign('packages', $packages);

		// Set the development mode to TRUE to see the dev-requirements
		$this->developmentDependencies = TRUE;
		$this->writeMergedComposerJson();
		$this->assignViewVariables();

		if (!$this->getPHPExecutable()) {
			$this->view->assign('error', 'PHP executable could not be found');
		}
	}

	/**
	 * Assign the view variables
	 */
	protected function assignViewVariables() {
		$command = 'install';
		$this->makeSureTempPathExists();
		$fullCommand = $this->getPHPExecutable() . ' '
			. '-c ' . php_ini_loaded_file() . ' '
			. '"' . $this->getComposerPath() . '" ' . $command . ' --working-dir '
			. '"' . $this->getTempPath() . '" '
			. '--no-interaction '
			. '--no-ansi '
			. '--verbose '
			. '--profile '
			. '--dev '
			. '--optimize-autoloader';
		$this->view->assign('manualInstallTip', $fullCommand);


		$this->view->assign('usedPHPBin', $this->getPHPExecutable());
		$this->view->assign('workingDirectory', $this->getTempPath());
		$this->view->assign('composerPath', $this->getComposerPath());

		// The merged composer install
		$mergedComposerJson = $this->getMergedComposerJson();
		$mergedComposerJsonString = $this->formatJSON($mergedComposerJson);
		$this->view->assign('mergedComposerJson', $mergedComposerJson);
		$this->view->assign('mergedComposerJsonString', $mergedComposerJsonString);

	}

	/**
	 * Write the composer.json file
	 *
	 * @return boolean Returns TRUE on success, otherwise FALSE
	 */
	public function writeMergedComposerJson() {
		$composerJson = $this->getMergedComposerJson();
		$composerJson = json_encode($composerJson);
		if ($composerJson) {
			$this->makeSureTempPathExists();
			return file_put_contents($this->getTempPath() . 'composer.json', $composerJson);
		}
		return FALSE;
	}

	/**
	 * Returns the composer.json array merged with the template
	 *
	 * @param boolean $development Indicates if the dev-requirements should be merged
	 * @throws UnexpectedValueException if the composer.json template could not be loaded
	 * @return array
	 */
	public function getMergedComposerJson($development = FALSE) {
		if (!$this->mergedComposerJson) {
			$composerJson = file_get_contents($this->getPathToResource() . 'Private/Templates/composer.json');
			if (!$composerJson) {
				throw new \UnexpectedValueException('Could not load the composer.json template file', 1355952845);
			}
			$composerJson = str_replace('%EXT_PATH%', $this->getExtensionPath(), $composerJson);
			$composerJson = str_replace('%RESOURCE_PATH%', $this->getPathToResource(), $composerJson);
			$composerJson = str_replace('%MINIMUM_STABILITY%', $this->minimumStability, $composerJson);

			$composerJson = json_decode($composerJson, TRUE);

			$this->pd($composerJson);
			$composerJson['require'] = $this->getMergedComposerRequirements();
			$composerJson['autoload'] = $this->getMergedComposerAutoload();
			$composerJson['repositories'] = $this->getMergedComposerData('repositories');

			if ($development || $this->developmentDependencies) {
				$composerJson['require-dev'] = $this->getMergedComposerDevelopmentRequirements();
			}
			if (!isset($composerJson['require-dev']) || !$composerJson['require-dev']) {
				unset($composerJson['require-dev']);
			}

			$this->pd($composerJson);
			$this->mergedComposerJson = $composerJson;
		}

		return $this->mergedComposerJson;
	}

	/**
	 * Retrieve the merged composer.json requirements
	 *
	 * @return array<string>
	 */
	public function getMergedComposerRequirements() {
		return $this->getMergedComposerData('require');
	}

	/**
	 * Retrieve the merged composer.json development requirements
	 *
	 * @return array<string>
	 */
	public function getMergedComposerDevelopmentRequirements() {
		return $this->getMergedComposerData('require-dev');
	}

    /**
	 * Retrieve the merged composer.json autoload settings
	 *
	 * @return array<string>
	 */
	public function getMergedComposerAutoload() {
		return $this->getMergedComposerData('autoload');
	}

	/**
	 * Returns the merged composer.json data for the given key
	 * @param  string $key The key for which to merge the data
	 * @return array
	 */
	protected function getMergedComposerData($key) {
		$jsonData = array();
		try {
			$composerJson = $this->packageRepository->getComposerJson();
		} catch (\DomainException $exception) {
			$this->view->assign('error', $exception->getMessage());
			return array();
		}
		foreach ($composerJson as $currentJsonData) {
			if (isset($currentJsonData[$key])) {
				$mergeData = $currentJsonData[$key];
				if (is_array($mergeData)) {
					$jsonData = static::arrayMergeRecursive($jsonData, $mergeData, FALSE);
					#$jsonData = static::arrayMergeRecursive($jsonData, $mergeData, TRUE);
				}
			}
		}
		return $jsonData;
	}

	/**
	 * Merge two arrays recursively.
	 *
	 * Unlike the implementation of array_merge_recursive() the second value will
	 * overwrite the first, if a key is already set.
	 *
	 * Thanks to Gabriel Sobrinho http://www.php.net/manual/en/function.array-merge-recursive.php#92195
	 *
	 * @param array   $array1
	 * @param array   $array2
	 * @param boolean $strict If set to TRUE an exception will be thrown if a key already is set with a different value
	 * @throws UnexpectedValueException if the strict mode is enabled and a key already exists
	 * @return  array Returns the merged array
	 */
	static protected function arrayMergeRecursive($array1, $array2, $strict = FALSE) {
		$merged = $array1;
		foreach ($array2 as $key => &$value) {
			if ($strict && isset($merged[$key]) && !is_array($merged[$key]) && $merged[$key] != $value) {
				throw new \UnexpectedValueException('Key "' . $key . '" already exists with a different value', 1360672930);
			}
			if (is_array($value) // If the current value is an array it may has to be merged
				&& !is_integer($key) // Check if we are not inside of an array (only merge objects)
				&& isset($merged[$key])
				&& is_array($merged[$key])) {
				$value = self::arrayMergeRecursive($merged[$key], $value);
			}

			if (is_integer($key)) {
				$merged[] = $value;
			} else {
				$merged[$key] = $value;
			}
		}
		return $merged;
	}

	/**
	 * Call composer on the command line to install the dependencies.
	 *
	 * @param boolean	$dev 		Call it with the --dev flag
	 * @return string 				Returns the composer output
	 */
	protected function install($dev = -1) {
		return $this->executeComposerCommand('install', $dev);
	}

	/**
	 * Call composer on the command line to update the dependencies.
	 *
	 * @param boolean	$dev 		Call it with the --dev flag
	 * @return string 				Returns the composer output
	 */
	protected function update($dev = -1) {
		return $this->executeComposerCommand('update', $dev);
	}

	/**
	 * Execute the given composer command
	 *
	 * @param string 	$command 	The composer command to execute
	 * @param boolean	$dev 		Call it with the --dev flag
	 * @return string 				Returns the composer output
	 */
	protected function executeComposerCommand($command, $dev = -1) {
		$output = '';
		$pathToComposer = $this->getComposerPath();

		if ($dev === -1) {
			$dev = $this->developmentDependencies;
		}


		$this->makeSureTempPathExists();
		$fullCommand = $this->getPHPExecutable() . ' '
//			. '-c ' . php_ini_loaded_file() . ' '
			. '"' . $pathToComposer . '" ' . $command . ' '
			. '--working-dir ' . '"' . $this->getTempPath() . '" '
//			. '--no-interaction '
//			. '--no-ansi '
			. '--verbose '
//			. '--profile '
//			. '--optimize-autoloader '
//			. ($dev ? '--dev ' : '')
			. '2>&1'
		;

		$fullCommand = 'COMPOSER_HOME=' . $this->getTempPath() . ' ' . $fullCommand;

		$output = $this->executeShellCommand($fullCommand);

		$this->pd($fullCommand);
		$this->pd($output);
		return $output;
	}

	/**
	 * Execute the shell command
	 *
	 * @param string $fullCommand Full composer command
	 * @return string
	 */
	protected function executeShellCommand($fullCommand) {
		$useShellExec = FALSE;
		if ($useShellExec) {
			return shell_exec($fullCommand);
		}

		$output = '';
		$descriptorSpec = array(
			0 => array('pipe', 'r'),  // stdin is a pipe that the child will read from
			1 => array('pipe', 'w'),  // stdout is a pipe that the child will write to
			2 => array('pipe', sys_get_temp_dir() . '/error-output.txt', 'a') // stderr is a file to write to
		);

		$cwd = $this->getTempPath();
		$env = $_ENV;

		$process = proc_open($fullCommand, $descriptorSpec, $pipes, $cwd, $env);

		if (is_resource($process)) {
			// $pipes now looks like this:
			// 0 => writeable handle connected to child stdin
			// 1 => readable handle connected to child stdout
			// Any error output will be appended to /tmp/error-output.txt

			// fwrite($pipes[0], '<?php print_r($_ENV); ? >');
			fclose($pipes[0]);

			$output = stream_get_contents($pipes[1]);
			fclose($pipes[1]);

			// It is important that you close any pipes before calling
			// proc_close in order to avoid a deadlock
			$returnValue = proc_close($process);

			$this->pd('Return value:', $returnValue);
		}
		return $output;
	}

	/**
	 * Returns the path to the composer phar
	 *
	 * @return string
	 */
	public function getComposerPath() {
		return $this->getPathToResource() . '/Private/PHP/composer.phar';
	}

	/**
	 * Returns the path to the PHP executable
	 *
	 * @return string
	 */
	public function getPHPExecutable() {
		if (!$this->phpExecutable) {
			$this->phpExecutable = $this->getConfiguration('phpExecutable');
			if (!$this->phpExecutable) {
				if (isset($this->settings['phpExecutable'])) {
					$this->phpExecutable = $this->settings['phpExecutable'];
				} else {
					$this->phpExecutable = $this->getPHPExecutableFromPath();
				}
			}
			$this->phpExecutable = trim($this->phpExecutable);
		}
		return $this->phpExecutable;
	}

	/**
	 * Sets the path to the PHP executable
	 *
	 * @param $phpExecutable
	 * @return void
	 */
	public function setPHPExecutable($phpExecutable) {
		$this->phpExecutable = $phpExecutable;
	}

	/**
	 * Tries to find the PHP executable.
	 *
	 * @return string Returns the path to the PHP executable, or FALSE on error
	 */
	public function getPHPExecutableFromPath() {
		if (defined('PHP_BINDIR') && file_exists(PHP_BINDIR . '/php') && is_executable(PHP_BINDIR . '/php')) {
			return PHP_BINDIR . '/php';
		}
		$paths = explode(PATH_SEPARATOR, getenv('PATH'));
		foreach ($paths as $path) {
			// we need this for XAMPP (Windows)
			if (strstr($path, 'php.exe') && isset($_SERVER['WINDIR']) && file_exists($path) && is_file($path)) {
				return $path;
			} else {
				$php_executable = $path . DIRECTORY_SEPARATOR . 'php' . (isset($_SERVER['WINDIR']) ? '.exe' : '');
				if (file_exists($php_executable) && is_file($php_executable)) {
					return $php_executable;
				}
			}
		}
		return FALSE; // not found
	}

	/**
	 * Returns the path to the extensions base
	 *
	 * @return string
	 */
	public function getExtensionPath() {
		return __DIR__ . '/../../';
	}

	/**
	 * Returns the path to the resources folder
	 *
	 * @return string
	 */
	public function getPathToResource() {
		return $this->getExtensionPath() . 'Resources/';
	}

	/**
	 * Returns the path to the temporary directory
	 *
	 * @return string
	 */
	public function getTempPath() {
		return $this->getPathToResource() . 'Private/Temp/';
	}

	/**
	 * Make sure that the temporary directory exists
	 *
	 * @throws RuntimeException if the temporary directory does not exist
	 * @return void
	 */
	protected function makeSureTempPathExists() {
		$workingDir = $this->getTempPath();

		// Check if the working/temporary directory exists
		if (!file_exists($workingDir)) {
			@mkdir($workingDir);

			if (!file_exists($workingDir)) {
				throw new \RuntimeException('Working directory "' . $workingDir . '" doesn\'t exists and can not be created', 1359541465);
			}
		}
	}

	/**
	 * Format the given JSON data
	 *
	 * @param  array $json
	 * @return string
	 */
	protected function formatJSON($json) {
		// Prepare the composer.json to be displayed
		if (defined('JSON_PRETTY_PRINT')) {
			$jsonString = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		} else {
			$jsonString = json_encode($json);
			$jsonString = str_replace('\\/', '/', $jsonString);
			$jsonString = str_replace(',', ',' . PHP_EOL, $jsonString);
			$jsonString = str_replace('{', '{' . PHP_EOL, $jsonString);
			$jsonString = str_replace('}', PHP_EOL . '}', $jsonString);
			$jsonString = str_replace('[{', '[' . PHP_EOL . '{', $jsonString);
			$jsonString = str_replace('{{', '{' . PHP_EOL . '{', $jsonString);
			$jsonString = str_replace('}]', '}' . PHP_EOL . ']', $jsonString);
			$jsonString = str_replace('}}', '}' . PHP_EOL . '}', $jsonString);
		}
		$jsonString = rtrim($jsonString);
		return $jsonString;
	}

	/**
	 * action to show the manual installation
	 *
	 * @return void
	 */
	public function manualInstallationAction() {
		$this->writeMergedComposerJson();
		$this->assignViewVariables();
	}

	/**
	 * action show
	 *
	 * @param Tx_CunddComposer_Domain_Model_Package $package
	 * @return void
	 */
	public function showAction(Tx_CunddComposer_Domain_Model_Package $package) {
		$this->view->assign('package', $package);
	}

	/**
	 * action new
	 *
	 * @param Tx_CunddComposer_Domain_Model_Package $newPackage
	 * @dontvalidate $newPackage
	 * @return void
	 */
	public function newAction(Tx_CunddComposer_Domain_Model_Package $newPackage = NULL) {
		$this->view->assign('newPackage', $newPackage);
	}

	/**
	 * action create
	 *
	 * @param Tx_CunddComposer_Domain_Model_Package $newPackage
	 * @return void
	 */
	public function createAction(Tx_CunddComposer_Domain_Model_Package $newPackage) {
		$this->packageRepository->add($newPackage);
		$this->flashMessageContainer->add('Your new Package was created.');
		$this->redirect('list');
	}

	/**
	 * action edit
	 *
	 * @param Tx_CunddComposer_Domain_Model_Package $package
	 * @return void
	 */
	public function editAction(Tx_CunddComposer_Domain_Model_Package $package) {
		$this->view->assign('package', $package);
	}

	/**
	 * action delete
	 *
	 * @param Tx_CunddComposer_Domain_Model_Package $package
	 * @return void
	 */
	public function deleteAction(Tx_CunddComposer_Domain_Model_Package $package) {
		$this->packageRepository->remove($package);
		$this->flashMessageContainer->add('Your Package was removed.');
		$this->redirect('list');
	}

	/**
	 * action install
	 *
	 * @param boolean $development Indicates if the dev flag should be specified
	 * @return void
	 */
	public function installAction($development = FALSE) {
		$this->developmentDependencies = $development;

		$didWriteComposerJson = $this->writeMergedComposerJson();
		$composerOutput = rtrim($this->install());

		$this->postUpdate();
		$this->view->assign('composerOutput', $composerOutput);
		$this->view->assign('developmentDependencies', $this->developmentDependencies);
	}

	/**
	 * action update
	 *
	 * @param boolean $development Indicates if the dev flag should be specified
	 * @return void
	 */
	public function updateAction($development = TRUE) {
		$this->developmentDependencies = $development;

		$didWriteComposerJson = $this->writeMergedComposerJson();
		$composerOutput = rtrim($this->update());

		$this->postUpdate();
		$this->view->assign('composerOutput', $composerOutput);
		$this->view->assign('developmentDependencies', $this->developmentDependencies);
	}

	/**
	 * Install the assets
	 * @return void
	 */
	public function installAssetsAction() {
		if (!$this->getConfiguration('allowInstallAssets')) {
			$this->view->assign('error', 'Asset installation disabled in Extension Manager');
		}
		$this->assetInstaller->manuallyInjectController($this);
		$installedAssets = $this->assetInstaller->installAssets();
		$this->view->assign('installedAssets', $installedAssets);
	}

	/**
	 * Invoked after the install/update action
	 * @return void
	 */
	public function postUpdate() {
		if ($this->getConfiguration('automaticallyInstallAssets')) {
			$this->assetInstaller->manuallyInjectController($this);
			$installedAssets = $this->assetInstaller->installAssets();
			$this->view->assign('installedAssets', $installedAssets);
		}
	}

	/**
	 * Returns the extension configuration for the given key
	 * @param  string $key Configuration key
	 * @return mixed      Configuration value
	 */
	public function getConfiguration($key) {
		static $configuration = NULL;

		// Read the configuration from the globals
		if ($configuration === NULL) {
			if (isset($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cundd_composer'])) {
				$configuration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['cundd_composer']);
			}
		}

		// Return the configuration value
		if ($configuration && isset($configuration[$key])) {
			return $configuration[$key];
		}
		return FALSE;
	}

	/**
	 * Dumps a given variable (or the given variables) wrapped into a 'pre' tag.
	 *
	 * @param	mixed	$var1
	 * @return	string The printed content
	 */
	public function pd($var1 = '__iresults_pd_noValue') {
		if (class_exists('Tx_Iresults')) {
			$arguments = func_get_args();
			call_user_func_array(array('Tx_Iresults', 'pd'), $arguments);
		}
	}

}
?>
